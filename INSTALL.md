# Self-hosting the RelayPony relay

Requirements: PHP 8.0+ with PDO/MySQL, and MySQL or MariaDB. Any host that already serves PHP works.

## 1. Get the code

```
git clone https://github.com/norsehorse-dev/RelayPony-Relay.git
cd RelayPony-Relay
```

## 2. Create the database and a user

```
sudo mysql <<'SQL'
CREATE DATABASE relaypony_relay CHARACTER SET utf8mb4;
CREATE USER 'relaypony_relay'@'localhost' IDENTIFIED BY 'a-strong-password';
GRANT SELECT, INSERT, DELETE ON relaypony_relay.* TO 'relaypony_relay'@'localhost';
FLUSH PRIVILEGES;
SQL
```

## 3. Import the schema

```
mysql -u relaypony_relay -p relaypony_relay < sql/schema.sql
```

## 4. Configure

```
cp config.sample.php config.php
chmod 600 config.php
# edit config.php: set the db user/pass you created above
```

## 5. Point a web root at `public/`

Serve the `public/` directory (which contains `index.php` and `.htaccess`). Example Apache vhost:

```
<VirtualHost *:443>
    ServerName relay.example.com
    DocumentRoot /var/www/RelayPony-Relay/public
    <Directory /var/www/RelayPony-Relay/public>
        AllowOverride All
        Require all granted
    </Directory>
    # ... your TLS config ...
</VirtualHost>
```

`AllowOverride All` lets the bundled `.htaccess` route every request to `index.php`. On nginx,
rewrite all requests to `index.php` instead.

## 6. Test it

```
curl -s -X POST https://relay.example.com/api/signal \
  -d '{"op":"poll","for":"age1qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqzzzz"}'
# -> {"ok":true,"blobs":[]}
```

## 7. Point the app at it

In RelayPony: Settings → relay server → enter `https://relay.example.com`. Both devices must use the
same relay to find each other. That's it — your handshakes now broker through your server, and your
files still go straight device-to-device, never through it.

## Notes

- HTTPS only. The blobs are opaque, but the relay is an internet endpoint; run it behind TLS.
- No cron needed: expired rows are reaped inline on each request.
- Tuning lives at the top of `src/signal.php` (TTL, payload cap, per-recipient queue cap).
