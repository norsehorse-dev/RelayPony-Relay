<?php

/** Load config.php once (copied from config.sample.php). */
function rp_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $path = __DIR__ . '/../config.php';
        $cfg  = is_file($path) ? require $path : [];
    }
    return is_array($cfg) ? $cfg : [];
}

/** One shared PDO handle to the relay database. */
function rp_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $d = rp_config()['db'] ?? [];
    $pdo = new PDO(
        (string)($d['dsn'] ?? ''),
        (string)($d['user'] ?? ''),
        (string)($d['pass'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    return $pdo;
}
