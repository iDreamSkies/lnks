# lnks

Minimal self-hosted URL shortener. Single small PHP app, SQLite, zero dependencies.

No Composer, no framework, no Docker required, no accounts, no tracking pixels. Upload to any PHP hosting and it works.

## Features

- One-minute web installer — upload and open in a browser
- Short link creation via web form, admin panel, or REST API
- Click counting per link
- Admin panel: list, search, enable/disable, delete links
- Optional public mode (anyone can shorten) or private mode (admin + API only)
- Per-IP rate limiting for the public form
- SQLite database, auto-created on first run — nothing to configure
- Whole app is ~700 lines of readable PHP

## Requirements

- PHP 8.0+ with `pdo_sqlite` (present in every default PHP build)
- Apache with `mod_rewrite`, or Nginx (config below)

## Installation

**Web installer (easiest):** upload the files, point your domain's document root at the project directory, open the site in a browser — the setup wizard runs automatically. Set an admin password, and the API token is generated for you. The installer locks itself once `config.php` exists.

**Manual:**

```bash
git clone https://github.com/iDreamSkies/lnks.git
cd lnks
cp config.example.php config.php
```

Edit `config.php`:

```bash
# Generate admin password hash
php -r "echo password_hash('your-password', PASSWORD_BCRYPT), PHP_EOL;"

# Generate API token
php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
```

Make sure `storage/` is writable by the web server:

```bash
chmod 775 storage
```

**Local dev:** `php -S localhost:8080 router.php`

### Nginx

```nginx
server {
    listen 80;
    server_name lnks.example.com;
    root /var/www/lnks;
    index index.php;

    location /storage/ { deny all; }
    location ~ ^/(config|bootstrap|schema) { deny all; }

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

## API

```bash
curl -X POST https://lnks.example.com/api.php \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"url": "https://example.com/very/long/url"}'
```

Response:

```json
{ "ok": true, "code": "aB3xYz", "short_url": "https://lnks.example.com/aB3xYz", "url": "https://example.com/very/long/url" }
```

Errors return `{ "ok": false, "error": "..." }` with an appropriate HTTP status (401, 422, 503).

## Configuration

| Key | Description |
|---|---|
| `base_url` | Public URL of the instance; empty = auto-detect from request |
| `admin_pass_hash` | bcrypt hash for the admin panel (`/admin.php`) |
| `api_token` | Bearer token for `/api.php`; empty = API disabled |
| `public_form` | `true` — anyone can shorten from the homepage; `false` — admin/API only |
| `code.length` | Short code length (default 6) |

## Security notes

- CSRF protection on all forms
- Prepared statements everywhere
- `storage/` and config files blocked at web-server level and via `.htaccess`
- Login brute-force slowed down; session ID regenerated on login
- Redirects send `X-Robots-Tag: noindex`

## Need more?

lnks is the intentionally minimal open-source core. A full-featured edition exists with:

- Custom slugs and link editing
- Multi-domain support
- Link expiration, click limits, password-protected links
- UTM builder and per-click analytics (geo, device, browser, unique visitors)
- A/B split testing
- Telegram bot integration
- Multi-user access with roles
- Import/export

Interested in the full edition, a hosted instance, or custom development?
Reach out: [dreamskies.dev](https://dreamskies.dev) · Telegram [@dreamskies](https://t.me/dreamskies)

## License

MIT — see [LICENSE](LICENSE).

---

Built by [DreamSkies](https://dreamskies.dev)
