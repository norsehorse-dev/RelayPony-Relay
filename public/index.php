<?php
/**
 * RelayPony signaling relay — front controller.
 *
 * The only job of this service is to broker the tiny WAN-direct handshake (offer / answer / ICE
 * candidates) between two ALREADY-PAIRED RelayPony devices that can't reach each other on a LAN.
 * It stores each ~1 KB blob for a couple of minutes, addressed by age handle, until the peer polls
 * it. The file transfer is peer-to-peer over the hole-punched socket and NEVER touches this server,
 * and the payload is opaque, so a self-hoster who runs this never sees candidates, IPs, or content.
 */

declare(strict_types=1);
date_default_timezone_set('UTC');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/', '/');

if ($method === 'OPTIONS') { http_response_code(204); exit; }

// The app posts to /api/signal by default; /v1/signal and /signal are accepted aliases so you can
// mount this at whatever path suits your host.
if ($method === 'POST' && in_array($path, ['/api/signal', '/v1/signal', '/signal'], true)) {
    require __DIR__ . '/../src/signal.php';
    exit;
}

http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'not_found']);
