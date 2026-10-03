<?php
// config.sample.php  ->  copy to config.php and fill in your database.
//
//   cp config.sample.php config.php
//   chmod 600 config.php
//
// config.php is gitignored so your credentials never land in the repo.

return [
    'db' => [
        // PDO DSN for MySQL / MariaDB.
        'dsn'  => 'mysql:host=127.0.0.1;dbname=relaypony_relay;charset=utf8mb4',
        'user' => 'relaypony_relay',
        'pass' => 'CHANGE_ME',
    ],
    // Salt for the hashed client IPs used by rate limiting. Any long random string.
    'rate_salt' => 'CHANGE_ME_TO_A_RANDOM_STRING',
];
