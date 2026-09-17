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
