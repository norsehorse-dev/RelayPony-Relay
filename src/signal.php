<?php
/**
 * Signaling handler: send + poll. Included by the front controller for POST /api/signal.
 *
 *   { "op":"send", "to":"age1...", "payload":"<base64, <=16 KB>" }  -> { "ok":true }
 *   { "op":"poll", "for":"age1..." }                                -> { "ok":true, "blobs":[ "<base64>", ... ] }
 *
 * Relay 2.0 (RelayPony 4.0, see PROTOCOL_v3.md section 8):
 *   { "op":"info" }                                   -> { "ok":true, "version":"2.0", "features":[...] }
 *   { "op":"mbox.send", "to":"<43-char id>", "payload":"<base64>" } -> { "ok":true }
 *   { "op":"mbox.poll", "for":"<43-char id>" }        -> { "ok":true, "blobs":[ ... ] }
 *   { "op":"nameplate.claim" }                        -> { "ok":true, "nameplate":47, "token":"<hex>", "ttl":600 }
 *   { "op":"nameplate.release", "nameplate":47, "token":"<hex>" } -> { "ok":true }
 *
 * Mailbox ids are opaque 32-byte values a device shares only with paired devices, so knowing a
 * device's public handle no longer reaches its queue.
 *
 * poll returns and removes every blob queued for that handle (delete-on-fetch; the sender re-sends
 * on its own timer, so a lost poll self-heals). Expired rows are reaped inline, so no cron is needed.
 */

declare(strict_types=1);

require __DIR__ . '/db.php';

const SIGNAL_TTL_SECONDS = 120;     // how long an unfetched blob lives
const SIGNAL_MAX_PAYLOAD = 16384;   // 16 KB base64 cap per blob
const SIGNAL_MAX_QUEUE    = 200;    // max pending blobs per recipient (flood guard)
const SIGNAL_POLL_LIMIT   = 64;     // max blobs returned per poll
const RELAYDATA_TTL_SECONDS = 30;   // relayed stream datagrams are short-lived
const RELAYDATA_MAX_CHUNK   = 8192; // base64 cap per datagram
const RELAYDATA_MAX_BATCH   = 64;   // datagrams accepted per data POST
const RELAYDATA_FETCH_LIMIT = 128;  // datagrams returned per fetch
const RELAYDATA_MAX_QUEUE   = 4000; // per (recipient,session) backlog guard
// Per-IP rate limits (4.0). A receiving device polls every 1.5 s (40/min), so the poll ceilings
// leave room for a carrier NAT with many devices behind one address. Counted over a sliding 60 s
// window on a salted hash of the IP, never the IP itself, and the rows expire with the window.
// data/fetch (the relay-forward stream) is not limited.
const RATE_WINDOW_SECONDS = 60;
const RATE_LIMITS = [
    'poll' => 1200, 'mbox.poll' => 1200,
    'send' => 300,  'mbox.send' => 300,
    'nameplate.claim' => 20,
];
const RELAY_VERSION = '2.0';
const RELAY_FEATURES = ['mbox', 'nameplate', 'sealed', 'ratelimit'];
const NAMEPLATE_TTL_SECONDS = 600;  // a word code is valid for 10 minutes
const NAMEPLATE_SMALL_MAX = 999;    // short numbers while the relay is quiet
const NAMEPLATE_LARGE_MAX = 9999;   // widened once half the short range is in use

function respond(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}
function ok(array $extra = []): void  { respond(200, ['ok' => true] + $extra); }
function fail(int $status, string $msg): void { respond($status, ['ok' => false, 'error' => $msg]); }

function valid_session(string $x): bool { return (bool) preg_match('/^[0-9a-f]{8,64}$/', $x); }

/** Salted SHA-256 of the client IP. The salt comes from config.php ('rate_salt') when set. */
function client_bucket(): string {
    $ip   = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $salt = (string)(rp_config()['rate_salt'] ?? 'relaypony-relay');
    return hash('sha256', $salt . '|' . $ip);
}

/**
 * Sliding-window limiter on (client, op). Uses only SELECT / INSERT / DELETE, so the relay's
 * database user keeps the same grants. Fails open on a database error: a broken limiter must not
 * take signaling down with it.
 */
function rate_limit(PDO $pdo, string $op): void {
    $limit = RATE_LIMITS[$op] ?? 0;
    if ($limit <= 0) { return; }
    $bucket = client_bucket();
    try {
        $c = $pdo->prepare(
            'SELECT COUNT(*) AS n FROM relaypony_ratelimit
             WHERE bucket = :b AND op = :o AND at >= UTC_TIMESTAMP() - INTERVAL :w SECOND'
        );
        $c->bindValue(':b', $bucket);
        $c->bindValue(':o', $op);
        $c->bindValue(':w', RATE_WINDOW_SECONDS, PDO::PARAM_INT);
        $c->execute();
        if ((int)($c->fetch()['n'] ?? 0) >= $limit) { fail(429, 'rate_limited'); }
        // `at` is written as UTC_TIMESTAMP() explicitly, like expires_at elsewhere: the window
        // checks compare against UTC_TIMESTAMP(), and CURRENT_TIMESTAMP is session-local time.
        $ins = $pdo->prepare('INSERT INTO relaypony_ratelimit (bucket, op, at) VALUES (:b, :o, UTC_TIMESTAMP())');
        $ins->execute([':b' => $bucket, ':o' => $op]);
    } catch (Throwable $e) {
        error_log('[relaypony-relay] rate_limit: ' . $e->getMessage());
    }
}

/** Opaque mailbox id: 32 bytes, base64url without padding. */
function valid_mbox(string $x): bool { return (bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $x); }

/** Store one blob for $to (a handle or a mailbox id; the caller validated it). Exits on error. */
function queue_send(PDO $pdo, string $to, string $payload): void {
    if ($payload === '' || strlen($payload) > SIGNAL_MAX_PAYLOAD) { fail(422, 'bad_payload'); }
    if (!preg_match('#^[A-Za-z0-9+/=]+$#', $payload)) { fail(422, 'bad_payload'); }
    try {
        $c = $pdo->prepare('SELECT COUNT(*) AS n FROM relaypony_signal WHERE recipient = :r');
        $c->execute([':r' => $to]);
        if ((int)($c->fetch()['n'] ?? 0) >= SIGNAL_MAX_QUEUE) { fail(429, 'queue_full'); }
        $ins = $pdo->prepare(
            'INSERT INTO relaypony_signal (recipient, payload, expires_at)
             VALUES (:r, :p, UTC_TIMESTAMP() + INTERVAL :ttl SECOND)'
        );
        $ins->bindValue(':r', $to);
        $ins->bindValue(':p', $payload);
        $ins->bindValue(':ttl', SIGNAL_TTL_SECONDS, PDO::PARAM_INT);
        $ins->execute();
    } catch (Throwable $e) {
        error_log('[relaypony-relay] queue_send: ' . $e->getMessage());
        fail(500, 'db_error');
    }
}

/** Return and remove every live blob queued for $for. Exits on error. */
function queue_poll(PDO $pdo, string $for): array {
    $blobs = [];
    try {
        $sel = $pdo->prepare(
            'SELECT id, payload FROM relaypony_signal
             WHERE recipient = :r AND expires_at >= UTC_TIMESTAMP()
             ORDER BY id ASC LIMIT ' . (int)SIGNAL_POLL_LIMIT
        );
        $sel->execute([':r' => $for]);
        $rows = $sel->fetchAll();
        if ($rows) {
            $ids = array_map(static fn($row) => (int)$row['id'], $rows);
            foreach ($rows as $row) { $blobs[] = $row['payload']; }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $del = $pdo->prepare('DELETE FROM relaypony_signal WHERE id IN (' . $in . ')');
            $del->execute($ids);
        }
    } catch (Throwable $e) {
        error_log('[relaypony-relay] queue_poll: ' . $e->getMessage());
        fail(500, 'db_error');
    }
    return $blobs;
}

function valid_handle(string $h): bool {
    // age1 bech32 recipient: lowercase alphanumeric, bounded length.
    return (bool) preg_match('/^age1[0-9a-z]{20,120}$/', $h);
}

try {
    $pdo = rp_db();
} catch (Throwable $e) {
    error_log('[relaypony-relay] DB connect: ' . $e->getMessage());
    fail(500, 'db_unavailable');
}

// Inline reaper: drop expired rows on every request (cheap with idx_expires).
try { $pdo->exec('DELETE FROM relaypony_signal WHERE expires_at < UTC_TIMESTAMP()'); }
catch (Throwable $e) { /* non-fatal */ }
try { $pdo->exec('DELETE FROM relaypony_relaydata WHERE expires_at < UTC_TIMESTAMP()'); }
catch (Throwable $e) { /* non-fatal */ }
try { $pdo->exec('DELETE FROM relaypony_ratelimit WHERE at < UTC_TIMESTAMP() - INTERVAL ' . (int)RATE_WINDOW_SECONDS . ' SECOND'); }
catch (Throwable $e) { /* non-fatal: table missing on a relay that hasn't run the 4.0 schema yet */ }
try { $pdo->exec('DELETE FROM relaypony_nameplate WHERE expires_at < UTC_TIMESTAMP()'); }
catch (Throwable $e) { /* non-fatal, as above */ }

$raw  = file_get_contents('php://input');
$data = json_decode($raw ?: '[]', true);
if (!is_array($data)) { fail(400, 'bad_json'); }
$op = strtolower(trim((string)($data['op'] ?? '')));

if ($op === 'send') {
    rate_limit($pdo, 'send');
    $to = trim((string)($data['to'] ?? ''));
    $payload = (string)($data['payload'] ?? '');
    if (!valid_handle($to)) { fail(422, 'bad_recipient'); }
    if ($payload === '' || strlen($payload) > SIGNAL_MAX_PAYLOAD) { fail(422, 'bad_payload'); }
    if (!preg_match('#^[A-Za-z0-9+/=]+$#', $payload)) { fail(422, 'bad_payload'); }

    try {
        $c = $pdo->prepare('SELECT COUNT(*) AS n FROM relaypony_signal WHERE recipient = :r');
        $c->execute([':r' => $to]);
        if ((int)($c->fetch()['n'] ?? 0) >= SIGNAL_MAX_QUEUE) { fail(429, 'queue_full'); }

        $ins = $pdo->prepare(
            'INSERT INTO relaypony_signal (recipient, payload, expires_at)
             VALUES (:r, :p, UTC_TIMESTAMP() + INTERVAL :ttl SECOND)'
        );
        $ins->bindValue(':r', $to);
        $ins->bindValue(':p', $payload);
        $ins->bindValue(':ttl', SIGNAL_TTL_SECONDS, PDO::PARAM_INT);
        $ins->execute();
    } catch (Throwable $e) {
        error_log('[relaypony-relay] send: ' . $e->getMessage());
        fail(500, 'db_error');
    }
    ok();
}

if ($op === 'poll') {
    rate_limit($pdo, 'poll');
    $for = trim((string)($data['for'] ?? ''));
    if (!valid_handle($for)) { fail(422, 'bad_recipient'); }

    $blobs = [];
    try {
        $sel = $pdo->prepare(
            'SELECT id, payload FROM relaypony_signal
             WHERE recipient = :r AND expires_at >= UTC_TIMESTAMP()
             ORDER BY id ASC LIMIT ' . (int)SIGNAL_POLL_LIMIT
        );
        $sel->execute([':r' => $for]);
        $rows = $sel->fetchAll();
        if ($rows) {
            $ids = array_map(static fn($row) => (int)$row['id'], $rows);
            foreach ($rows as $row) { $blobs[] = $row['payload']; }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $del = $pdo->prepare('DELETE FROM relaypony_signal WHERE id IN (' . $in . ')');
            $del->execute($ids);
        }
    } catch (Throwable $e) {
        error_log('[relaypony-relay] poll: ' . $e->getMessage());
        fail(500, 'db_error');
    }
    ok(['blobs' => $blobs]);
}

/* ---- relay 2.0: capability detection, private mailboxes, nameplates ---- */
if ($op === 'info') {
    ok(['version' => RELAY_VERSION, 'features' => RELAY_FEATURES]);
}

if ($op === 'mbox.send') {
    rate_limit($pdo, 'mbox.send');
    $to = trim((string)($data['to'] ?? ''));
    if (!valid_mbox($to)) { fail(422, 'bad_mailbox'); }
    queue_send($pdo, $to, (string)($data['payload'] ?? ''));
    ok();
}

if ($op === 'mbox.poll') {
    rate_limit($pdo, 'mbox.poll');
    $for = trim((string)($data['for'] ?? ''));
    if (!valid_mbox($for)) { fail(422, 'bad_mailbox'); }
    ok(['blobs' => queue_poll($pdo, $for)]);
}

if ($op === 'nameplate.claim') {
    rate_limit($pdo, 'nameplate.claim');
    try {
        $c = $pdo->query('SELECT COUNT(*) AS n FROM relaypony_nameplate WHERE expires_at >= UTC_TIMESTAMP()');
        $active = (int)($c->fetch()['n'] ?? 0);
        $max = $active < intdiv(NAMEPLATE_SMALL_MAX, 2) ? NAMEPLATE_SMALL_MAX : NAMEPLATE_LARGE_MAX;
        if ($active >= NAMEPLATE_LARGE_MAX) { fail(503, 'nameplates_exhausted'); }
        $token = bin2hex(random_bytes(32));
        $ins = $pdo->prepare(
            'INSERT INTO relaypony_nameplate (nameplate, token, expires_at)
             VALUES (:n, :t, UTC_TIMESTAMP() + INTERVAL :ttl SECOND)'
        );
        for ($try = 0; $try < 40; $try++) {
            $n = random_int(1, $max);
            try {
                $ins->bindValue(':n', $n, PDO::PARAM_INT);
                $ins->bindValue(':t', $token);
                $ins->bindValue(':ttl', NAMEPLATE_TTL_SECONDS, PDO::PARAM_INT);
                $ins->execute();
                ok(['nameplate' => $n, 'token' => $token, 'ttl' => NAMEPLATE_TTL_SECONDS]);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) !== 1062) { throw $e; }   // 1062: duplicate key, try another
            }
        }
    } catch (Throwable $e) {
        error_log('[relaypony-relay] nameplate.claim: ' . $e->getMessage());
        fail(500, 'db_error');
    }
    fail(503, 'nameplates_busy');
}

if ($op === 'nameplate.release') {
    $n = (int)($data['nameplate'] ?? 0);
    $token = strtolower(trim((string)($data['token'] ?? '')));
    if ($n < 1 || $n > NAMEPLATE_LARGE_MAX || !preg_match('/^[0-9a-f]{64}$/', $token)) { fail(422, 'bad_nameplate'); }
    try {
        $del = $pdo->prepare('DELETE FROM relaypony_nameplate WHERE nameplate = :n AND token = :t');
        $del->execute([':n' => $n, ':t' => $token]);
    } catch (Throwable $e) {
        error_log('[relaypony-relay] nameplate.release: ' . $e->getMessage());
        fail(500, 'db_error');
    }
    ok();
}

/* ---- relay-forward data fallback (only used when the direct punch fails) ---- */
if ($op === 'data') {
    $to = trim((string)($data['to'] ?? ''));
    $session = strtolower(trim((string)($data['session'] ?? '')));
    $chunks = $data['chunks'] ?? null;
    if (!valid_handle($to))        { fail(422, 'bad_recipient'); }
    if (!valid_session($session))  { fail(422, 'bad_session'); }
    if (!is_array($chunks) || count($chunks) === 0 || count($chunks) > RELAYDATA_MAX_BATCH) { fail(422, 'bad_batch'); }
    foreach ($chunks as $c) {
        if (!is_string($c) || $c === '' || strlen($c) > RELAYDATA_MAX_CHUNK || !preg_match('#^[A-Za-z0-9+/=]+$#', $c)) { fail(422, 'bad_chunk'); }
    }
    try {
        $cnt = $pdo->prepare('SELECT COUNT(*) AS n FROM relaypony_relaydata WHERE recipient = :r AND session = :s');
        $cnt->execute([':r' => $to, ':s' => $session]);
        if ((int)($cnt->fetch()['n'] ?? 0) >= RELAYDATA_MAX_QUEUE) { fail(429, 'queue_full'); }
        $ins = $pdo->prepare(
            'INSERT INTO relaypony_relaydata (recipient, session, payload, expires_at)
             VALUES (:r, :s, :p, UTC_TIMESTAMP() + INTERVAL :ttl SECOND)'
        );
        foreach ($chunks as $c) {
            $ins->bindValue(':r', $to);
            $ins->bindValue(':s', $session);
            $ins->bindValue(':p', $c);
            $ins->bindValue(':ttl', RELAYDATA_TTL_SECONDS, PDO::PARAM_INT);
            $ins->execute();
        }
    } catch (Throwable $e) {
        error_log('[relaypony-relay] data: ' . $e->getMessage());
        fail(500, 'db_error');
    }
    ok(['stored' => count($chunks)]);
}

if ($op === 'fetch') {
    $for = trim((string)($data['for'] ?? ''));
    $session = strtolower(trim((string)($data['session'] ?? '')));
    if (!valid_handle($for))       { fail(422, 'bad_recipient'); }
    if (!valid_session($session))  { fail(422, 'bad_session'); }
    $chunks = [];
    try {
        $sel = $pdo->prepare(
            'SELECT id, payload FROM relaypony_relaydata
             WHERE recipient = :r AND session = :s AND expires_at >= UTC_TIMESTAMP()
             ORDER BY id ASC LIMIT ' . (int)RELAYDATA_FETCH_LIMIT
        );
        $sel->execute([':r' => $for, ':s' => $session]);
        $rows = $sel->fetchAll();
        if ($rows) {
            $ids = array_map(static fn($row) => (int)$row['id'], $rows);
            foreach ($rows as $row) { $chunks[] = $row['payload']; }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $del = $pdo->prepare('DELETE FROM relaypony_relaydata WHERE id IN (' . $in . ')');
            $del->execute($ids);
        }
    } catch (Throwable $e) {
        error_log('[relaypony-relay] fetch: ' . $e->getMessage());
        fail(500, 'db_error');
    }
    ok(['chunks' => $chunks]);
}

fail(400, 'bad_op');
