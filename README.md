# lnks

Minimal self-hosted URL shortener. Single small PHP app, SQLite, zero dependencies.

No Composer, no framework, no Docker required, no accounts, no tracking pixels. Upload to any PHP hosting and it works.

## Features

- One-minute web installer — upload and open in a browser
- Short link creation via web form, admin panel, or REST API
- Click counting per link (bots, HEAD requests and prefetches are ignored)
- Admin panel: dashboard tiles, search, status filter, sorting, per-link stats (30-day chart, top referrers), enable/disable, delete
- Optional link titles, 7-day sparklines, light/dark theme, responsive UI
- API: create, list, details, enable/disable, delete
- Login brute-force lockout, strict CSP, automatic DB migrations
- Optional public mode (anyone can shorten) or private mode (admin + API only)
- Per-IP rate limiting for the public form
- SQLite database, auto-created on first run — nothing to configure
- Small, readable PHP codebase

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
    location ~ ^/(config|bootstrap|layout|router|schema|\.git) { deny all; }

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

All requests need `Authorization: Bearer YOUR_API_TOKEN`.

```bash
# Create (title is optional)
curl -X POST https://lnks.example.com/api.php \
  -H "Authorization: Bearer YOUR_API_TOKEN" -H "Content-Type: application/json" \
  -d '{"url": "https://example.com/very/long/url", "title": "Docs"}'

# List (q, limit 1-100, offset)
curl "https://lnks.example.com/api.php?q=docs&limit=20" -H "Authorization: Bearer YOUR_API_TOKEN"

# Details + clicks for the last 30 days
curl "https://lnks.example.com/api.php?code=aB3xYz" -H "Authorization: Bearer YOUR_API_TOKEN"

# Disable (0) / enable (1)
curl -X PATCH "https://lnks.example.com/api.php?code=aB3xYz" -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" -d '{"status": 0}'

# Delete
curl -X DELETE "https://lnks.example.com/api.php?code=aB3xYz" -H "Authorization: Bearer YOUR_API_TOKEN"
```

Create response (201):

```json
{ "ok": true, "id": 1, "code": "aB3xYz", "short_url": "https://lnks.example.com/aB3xYz", "url": "https://example.com/very/long/url", "title": "Docs" }
```

Errors return `{ "ok": false, "error": "..." }` with an appropriate HTTP status (400, 401, 404, 405, 422, 503).

## Configuration

| Key | Description |
|---|---|
| `base_url` | Public URL of the instance; empty = auto-detect from request |
| `admin_pass_hash` | bcrypt hash for the admin panel (`/admin.php`) |
| `api_token` | Bearer token for `/api.php`; empty = API disabled |
| `public_form` | `true` — anyone can shorten from the homepage; `false` — admin/API only |
| `code.length` | Short code length (default 6, clamped to 4–12) |
| `trust_proxy` | `true` to read the client IP from `X-Forwarded-For` (only behind your own reverse proxy) |
| `rate_limit` | `['max' => 20, 'window_min' => 60]` — public form limit per IP |

## Security notes

- CSRF protection on all forms
- Prepared statements everywhere
- `storage/` and config files blocked at web-server level and via `.htaccess`
- Login lockout after 5 failed attempts per 15 min; session ID regenerated on login; logout is a CSRF-protected POST
- Strict Content-Security-Policy, `X-Frame-Options: DENY`, no inline scripts
- Links pointing back to the shortener itself are rejected (no redirect loops)
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
Reach out: [dreamskies.dev](https://dreamskies.dev) · Telegram [@dreamskies](https://t.me/dreamskies) · [Issues](https://github.com/iDreamSkies/lnks/issues)

## License

MIT — see [LICENSE](LICENSE).

---

Built by [DreamSkies](https://dreamskies.dev)
