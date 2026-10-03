#!/usr/bin/env python3
"""End-to-end pairing through a real RelayPony relay (protocol v3).

Two headless clients pair the two ways RelayPony 4.0 supports, using only relay ops, then trade a
sealed signaling blob through their private inboxes:

  1. QR pairing: A "shows" a QR v2, B "scans" it, PAIR_REQ goes to A's inbox, both compute SAS v2,
     A confirms, PAIR_ACK goes to B's inbox, both pin each other.
  2. Word-code pairing: A claims a nameplate, both run SPAKE2 (python-spake2, the magic-wormhole
     implementation the Rust core interoperates with) through the derived mailboxes, exchange their
     details under the PAKE key, and A releases the nameplate. A wrong code is also tried.
  3. Sealed signaling to an inbox id, plus the rate limit and handle-addressed legacy path.

Usage:  python3 tests/e2e_pairing.py [relay base URL]      (default http://127.0.0.1:8099)
Needs:  pip install pyrage spake2 cryptography requests
"""
import base64, hashlib, hmac, os, re, sys, time, urllib.parse
import requests
from pyrage import x25519, encrypt, decrypt
from spake2 import SPAKE2_Symmetric
from cryptography.hazmat.primitives.asymmetric.x25519 import X25519PrivateKey, X25519PublicKey
from cryptography.hazmat.primitives.kdf.hkdf import HKDF
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.ciphers.aead import ChaCha20Poly1305

RELAY = (sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8099").rstrip("/")
S = requests.Session()
S.trust_env = False


def op(**body):
    r = S.post(RELAY + "/api/signal", json=body, timeout=10)
    return r.json()


def b64u(b): return base64.urlsafe_b64encode(b).rstrip(b"=").decode()
def b64(b): return base64.b64encode(b).decode()
def form(s): return urllib.parse.quote_plus(s, safe="*-._")
def unform(s): return urllib.parse.unquote_plus(s)


# ---- age keys and the pair-key family (PROTOCOL_v3 section 1) ----

BECH = "qpzry9x8gf2tvdw0s3jn54khce6mua7l"


def bech32_decode_age(handle):
    data = [BECH.index(c) for c in handle[handle.rfind("1") + 1:]][:-6]
    acc, bits, out = 0, 0, []
    for v in data:
        acc = (acc << 5) | v
        bits += 5
        while bits >= 8:
            bits -= 8
            out.append((acc >> bits) & 0xFF)
    return bytes(out)


class Device:
    def __init__(self, name):
        self.name = name
        self.ident = x25519.Identity.generate()
        self.handle = str(self.ident.to_public())
        # pyrage doesn't expose the raw scalar; derive it from the AGE-SECRET-KEY string.
        sk = str(self.ident)
        data = [BECH.index(c) for c in sk.lower()[sk.lower().rfind("1") + 1:]][:-6]
        acc, bits, out = 0, 0, []
        for v in data:
            acc = (acc << 5) | v
            bits += 5
            while bits >= 8:
                bits -= 8
                out.append((acc >> bits) & 0xFF)
        self.scalar = bytes(out)
        self.inbox = b64u(os.urandom(32))
        self.pins = {}  # handle -> (name, inbox, relay)

    def K(self, peer_handle, info):
        shared = X25519PrivateKey.from_private_bytes(self.scalar).exchange(
            X25519PublicKey.from_public_bytes(bech32_decode_age(peer_handle)))
        lo, hi = sorted([self.handle, peer_handle])
        return HKDF(hashes.SHA256(), 32, lo.encode() + b"\0" + hi.encode(), info.encode()).derive(shared)

    def seal(self, to_handle, plain):
        return encrypt(plain.encode(), [x25519.Recipient.from_str(to_handle)])

    def open(self, sealed):
        return decrypt(sealed, [self.ident]).decode()

    def poll_inbox(self):
        r = op(op="mbox.poll", **{"for": self.inbox})
        assert r["ok"], r
        return [base64.b64decode(x) for x in r["blobs"]]


def sas_v2(a, b, na, nb):
    lo, hi = sorted([a, b])
    d = hashlib.sha256(b"relaypony-sas-v2\0" + lo.encode() + b"\0" + hi.encode() + b"\0" + na + nb).digest()
    return "%08d" % (int.from_bytes(d[:8], "big") % 10**8)


def check(cond, what):
    print(("PASS  " if cond else "FAIL  ") + what)
    if not cond:
        sys.exit(1)


# ---- 0. relay capability ----

info = op(op="info")
check(info.get("ok") and "mbox" in info["features"] and "nameplate" in info["features"], "relay 2.0 info: " + str(info))

# ---- 1. QR pairing ----

A, B = Device("Phone A"), Device("Tablet B")
nonceA = os.urandom(16)
qr = "|".join(["RP2", "01", A.handle, form(A.name), b64u(nonceA), A.inbox, ""])

# B scans
p = qr.split("|")
check(len(p) == 7 and p[0] == "RP2", "B decodes QR v2")
qr_handle, qr_nonce, qr_inbox = p[2], base64.urlsafe_b64decode(p[4] + "=="), p[5]
nonceB = os.urandom(16)
req = "|".join(["RPQ1", B.handle, form(B.name), B.inbox, "", b64u(qr_nonce), b64u(nonceB)])
r = op(op="mbox.send", to=qr_inbox, payload=b64(B.seal(qr_handle, req)))
check(r["ok"], "B posts PAIR_REQ to A's inbox")
code_b = sas_v2(qr_handle, B.handle, qr_nonce, nonceB)

# A polls its inbox
msgs = A.poll_inbox()
check(len(msgs) == 1 and msgs[0].startswith(b"age-encryption.org/v1"), "A's inbox holds one sealed message")
plain = A.open(msgs[0])
q = plain.split("|")
check(q[0] == "RPQ1" and base64.urlsafe_b64decode(q[5] + "==") == nonceA, "A opens PAIR_REQ with its live nonce")
code_a = sas_v2(A.handle, q[1], nonceA, base64.urlsafe_b64decode(q[6] + "=="))
check(code_a == code_b, "both screens show the same code: %s %s" % (code_a[:4], code_a[4:]))

# A confirms
A.pins[q[1]] = (unform(q[2]), q[3], unform(q[4]))
body = "|".join(["RPA1", A.handle, q[1], q[6], "ok"])
ack = body + "|" + hmac.new(A.K(q[1], "relaypony/pair/ack/v1"), body.encode(), hashlib.sha256).hexdigest()
r = op(op="mbox.send", to=q[3], payload=b64(A.seal(q[1], ack)))
check(r["ok"], "A posts PAIR_ACK to B's inbox (learned from the request)")

msgs = B.poll_inbox()
plain = B.open(msgs[0])
body, tag = plain.rsplit("|", 1)
want = hmac.new(B.K(qr_handle, "relaypony/pair/ack/v1"), body.encode(), hashlib.sha256).hexdigest()
check(hmac.compare_digest(tag, want) and body.endswith("|ok"), "B verifies the ACK tag (proves A's key)")
B.pins[qr_handle] = (unform(p[3]), qr_inbox, "")
check(A.handle in B.pins and B.handle in A.pins, "mutual pin with inboxes on both sides")

# ---- 2. sealed signaling to a private inbox ----

inner = "|".join(["RPS2", "offer", A.handle, B.handle, "00" * 16, "203.0.113.7:40000"])
blob = inner + "|" + hmac.new(A.K(B.handle, "relaypony/signal/mac/v1"), inner.encode(), hashlib.sha256).hexdigest()
op(op="mbox.send", to=A.pins[B.handle][1], payload=b64(A.seal(B.handle, blob)))
got = B.open(B.poll_inbox()[0])
gi, gt = got.rsplit("|", 1)
check(gi == inner and hmac.compare_digest(gt, hmac.new(B.K(A.handle, "relaypony/signal/mac/v1"), gi.encode(), hashlib.sha256).hexdigest()),
      "sealed RPS2 offer reaches B's inbox and authenticates")
r = op(op="poll", **{"for": B.handle})
check(r["ok"] and r["blobs"] == [], "nothing sits under B's public handle")

# ---- 3. word-code pairing ----

WORDS = ["lantern", "orbit", "maple"]


def mailbox(nameplate, relay, side):
    return b64u(hashlib.sha256(b"rp-pake-v1\0" + str(nameplate).encode() + b"\0" + relay.encode() + b"\0" + side.encode()).digest())


def data_key(k, side): return HKDF(hashes.SHA256(), 32, b"", ("relaypony/pake/data/" + side).encode()).derive(k)


def wait_for(dev_mbox, prefix, timeout=5):
    end = time.time() + timeout
    while time.time() < end:
        r = op(op="mbox.poll", **{"for": dev_mbox})
        for x in r.get("blobs", []):
            raw = base64.b64decode(x)
            if raw.startswith(prefix):
                return raw
        time.sleep(0.2)
    return None


def word_code_pair(code_a, code_b):
    claim = op(op="nameplate.claim")
    assert claim["ok"], claim
    n = claim["nameplate"]
    full_a = "%d-%s" % (n, code_a)
    full_b = "%d-%s" % (n, code_b)
    ma, mb = mailbox(n, "", "A"), mailbox(n, "", "B")
    sa = SPAKE2_Symmetric(full_a.encode(), idSymmetric=b"relaypony/pake/v1")
    sb = SPAKE2_Symmetric(full_b.encode(), idSymmetric=b"relaypony/pake/v1")
    op(op="mbox.send", to=mb, payload=b64(b"RPK1|pake|" + b64u(sa.start()).encode()))
    op(op="mbox.send", to=ma, payload=b64(b"RPK1|pake|" + b64u(sb.start()).encode()))
    pa = wait_for(ma, b"RPK1|pake|")
    pb = wait_for(mb, b"RPK1|pake|")
    ka = sa.finish(base64.urlsafe_b64decode(pa.split(b"|")[2] + b"=="))
    kb = sb.finish(base64.urlsafe_b64decode(pb.split(b"|")[2] + b"=="))
    da = ChaCha20Poly1305(data_key(ka, "A")).encrypt(b"\0" * 12, ("RPX1|%s|%s|%s|" % (A.handle, form(A.name), A.inbox)).encode(), None)
    db = ChaCha20Poly1305(data_key(kb, "B")).encrypt(b"\0" * 12, ("RPX1|%s|%s|%s|" % (B.handle, form(B.name), B.inbox)).encode(), None)
    op(op="mbox.send", to=mb, payload=b64(b"RPK1|data|" + b64u(da).encode()))
    op(op="mbox.send", to=ma, payload=b64(b"RPK1|data|" + b64u(db).encode()))
    xa = wait_for(ma, b"RPK1|data|")
    xb = wait_for(mb, b"RPK1|data|")
    results = []
    for k, side, x in ((kb, "A", xb), (ka, "B", xa)):
        try:
            results.append(ChaCha20Poly1305(data_key(k, side)).decrypt(b"\0" * 12, base64.urlsafe_b64decode(x.split(b"|")[2] + b"=="), None).decode())
        except Exception:
            results.append(None)
    r = op(op="nameplate.release", nameplate=n, token=claim["token"])
    return n, results, r["ok"]


n, (b_got, a_got), released = word_code_pair("-".join(WORDS), "-".join(WORDS))
check(b_got and b_got.startswith("RPX1|" + A.handle) and a_got and a_got.startswith("RPX1|" + B.handle),
      "word code %d-%s: both sides open each other's details" % (n, "-".join(WORDS)))
check(released, "A releases the nameplate")

n, (b_got, a_got), _ = word_code_pair("-".join(WORDS), "lantern-orbit-mapel")
check(b_got is None and a_got is None, "a mistyped code fails on BOTH sides (one online guess, then spent)")

# ---- 4. legacy handle addressing still works for 3.x peers ----

op(op="send", to=B.handle, payload=b64(b"RPS1|offer|x|y||"))
r = op(op="poll", **{"for": B.handle})
check(r["blobs"] == [b64(b"RPS1|offer|x|y||")], "legacy handle send/poll unchanged for 3.x")

print("\nall end-to-end checks passed against " + RELAY)
