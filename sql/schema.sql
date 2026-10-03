-- RelayPony signaling relay schema.
--
-- Ephemeral store-and-forward for the ~1 KB WAN-direct handshake between two already-paired
-- devices. The file data never touches this table; only the signaling blob does, and only until
-- the peer polls it or it expires.
--
--   mysql -u root -p relaypony_relay < sql/schema.sql
CREATE TABLE IF NOT EXISTS relaypony_signal (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipient  VARCHAR(128) NOT NULL,          -- age1 handle the blob is addressed to
  payload    MEDIUMTEXT   NOT NULL,          -- opaque base64 (the client may seal it end-to-end)
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP    NOT NULL,          -- server drops it after this
  PRIMARY KEY (id),
  KEY idx_recipient (recipient, id),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Relay-forward data fallback: engaged only when the direct UDP punch fails. Holds the
-- E2E-encrypted stream datagrams briefly while the two peers pull them through. Higher
-- volume, very short TTL, delete-on-fetch. The relay never sees plaintext.
CREATE TABLE IF NOT EXISTS relaypony_relaydata (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipient  VARCHAR(128) NOT NULL,
  session    VARCHAR(64)  NOT NULL,
  payload    MEDIUMTEXT   NOT NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP    NOT NULL,
  PRIMARY KEY (id),
  KEY idx_recipient_session (recipient, session, id),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-IP rate limiting (4.0). One row per counted request, keyed by a salted SHA-256 of the client
-- IP (the IP itself is never stored). Rows older than the 60 s window are reaped inline. Safe to
-- re-run this whole file on an existing relay: every table is CREATE TABLE IF NOT EXISTS.
CREATE TABLE IF NOT EXISTS relaypony_ratelimit (
  id     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bucket CHAR(64)    NOT NULL,                    -- sha256(salt | ip), hex
  op     VARCHAR(16) NOT NULL,
  at     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bucket (bucket, op, at),
  KEY idx_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Word-code nameplates (4.0). A small number the relay hands out so two people can pair by reading
-- a short code; the PAKE messages themselves travel through mailboxes in relaypony_signal. Only the
-- claimer's random token can release a nameplate early; otherwise it expires after 10 minutes.
CREATE TABLE IF NOT EXISTS relaypony_nameplate (
  nameplate  INT UNSIGNED NOT NULL,
  token      CHAR(64)     NOT NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP    NOT NULL,
  PRIMARY KEY (nameplate),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
