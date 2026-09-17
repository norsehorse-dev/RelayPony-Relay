<?php
/**
 * Signaling handler: send + poll. Included by the front controller for POST /api/signal.
 *
 *   { "op":"send", "to":"age1...", "payload":"<base64, <=16 KB>" }  -> { "ok":true }
 *   { "op":"poll", "for":"age1..." }                                -> { "ok":true, "blobs":[ "<base64>", ... ] }
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

function respond(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}
function ok(array $extra = []): void  { respond(200, ['ok' => true] + $extra); }
function fail(int $status, string $msg): void { respond($status, ['ok' => false, 'error' => $msg]); }

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

$raw  = file_get_contents('php://input');
$data = json_decode($raw ?: '[]', true);
if (!is_array($data)) { fail(400, 'bad_json'); }
$op = strtolower(trim((string)($data['op'] ?? '')));

if ($op === 'send') {
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

fail(400, 'bad_op');
