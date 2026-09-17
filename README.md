# RelayPony Relay

A tiny, self-hostable **signaling relay** for RelayPony's WAN-direct file transfer.

RelayPony sends files straight from one device to another, end-to-end encrypted, with no server in
the data path. On the same Wi-Fi that's pure LAN discovery. Across the internet, the two devices
still need to trade a small handshake (their offer / answer / ICE candidates) before they can punch
a direct hole through their NATs — and that's all this relay does.

## What it is (and isn't)

- It brokers the **~1 KB signaling handshake** between two *already-paired* devices, addressed by
  age handle, holding each blob for at most a couple of minutes until the peer polls it.
- The **file itself never touches this server.** Once the two sides have swapped candidates they
  connect peer-to-peer over the hole-punched socket; the transfer flows directly between the phones.
- The signaling payload is **opaque** to the relay (base64, and the client may seal it to the peer),
  so a relay operator never sees candidates, IPs, or file contents.
- No accounts, no push, no logs of content. Rows expire on their own.

It is deliberately small: one endpoint, one table, ~120 lines of PHP.

## Wire contract

`POST /api/signal` (aliases: `/v1/signal`, `/signal`), JSON in, JSON out:

```
{ "op":"send", "to":"age1...", "payload":"<base64, <=16 KB>" }   ->  { "ok":true }
{ "op":"poll", "for":"age1..." }                                 ->  { "ok":true, "blobs":[ "<base64>", ... ] }
```

`poll` returns and deletes every blob queued for that handle (delete-on-fetch; the sender re-sends
on its own timer, so a dropped poll self-heals).

## Run your own

See [INSTALL.md](INSTALL.md). In short: PHP 8+, MySQL/MariaDB, point a vhost at `public/`, import
`sql/schema.sql`, copy `config.sample.php` to `config.php`. Then set your relay's URL in the
RelayPony app (Settings → relay server) and your transfers broker through your box instead of the
default one.

## License

Apache-2.0.
