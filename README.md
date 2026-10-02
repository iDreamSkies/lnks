[![License: MIT](https://img.shields.io/badge/license-MIT-c8ff00.svg)](LICENSE)
![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)
![SQLite](https://img.shields.io/badge/SQLite-zero%20config-003b57.svg)
![Dependencies: none](https://img.shields.io/badge/dependencies-none-success.svg)

**English** · [Русский](README.ru.md)

# lnks

Minimal self-hosted URL shortener. One small PHP app, SQLite, zero dependencies.

> ### Works without SSH and MySQL
> Upload the files over **FTP**, open your domain in a browser, set a login and password — done.
> No shell access, no database server to create, no Composer, no Docker, no Node.js.
> If your hosting runs PHP 8.0+ (almost every shared hosting does), lnks runs there.

No tracking pixels, no third-party scripts, no external services: QR codes are drawn in the browser, statistics stay in your SQLite file.

<p align="center">
  <img src="docs/screenshots/admin.png" alt="Admin panel: links with click sparklines, expiry and click limits" width="49%">
  <img src="docs/screenshots/stats.png" alt="Per-link statistics and settings" width="49%">
</p>
<p align="center">
  <img src="docs/screenshots/qr.png" alt="QR code dialog with PNG and SVG download" width="49%">
  <img src="docs/screenshots/import.png" alt="CSV import preview recognising a YOURLS export" width="49%">
</p>
<p align="center">
  <img src="docs/screenshots/home.png" alt="Public page" width="49%">
  <img src="docs/screenshots/mobile-ru.png" alt="Mobile layout, Russian interface" width="22%">
</p>

## How it compares

| | **lnks** | YOURLS | Shlink | Kutt |
|---|---|---|---|---|
| Runtime | PHP 8.0+ | PHP 8.1+ | PHP 8.4+ | Node.js 20+ |
| Database | SQLite, created automatically | MySQL / MariaDB (create it yourself) | MySQL, MariaDB, PostgreSQL, SQL Server or SQLite | SQLite, PostgreSQL or MySQL / MariaDB |
| Required PHP extensions | `pdo_sqlite` | `pdo_mysql` | `curl`, `intl`, `gd`, `gmp`/`bcmath`, a PDO driver | — |
| Install over FTP only (no SSH) | ✅ web installer | ✅ edit `config.php`, then open `/admin` | ❌ CLI installer must run on the server | ❌ needs a Node.js process or Docker |
| Typical shared PHP hosting | ✅ | ✅ (with MySQL) | rarely | ❌ |
| Built-in admin UI | ✅ | ✅ | separate app (Shlink Web Client) | ✅ |
| QR codes | ✅ | plugin | in the web client | ✅ |
| Expiry date / click limit | ✅ / ✅ | plugins | ✅ / ✅ | ✅ / — |
| CSV import (incl. from YOURLS) | ✅ in the admin and API | plugin | ✅ CLI importer | — |

<sub>Compared with each project's own repository and README in October 2026 (YOURLS 1.10, Shlink 5, Kutt 3). Spotted something outdated? [Open an issue](https://github.com/iDreamSkies/lnks/issues).</sub>

**Pick lnks** when you want a shortener on cheap shared hosting in a minute, without a database server or console. **Pick YOURLS** if you need its plugin ecosystem and already have MySQL, **Shlink** for multi-domain setups with a separate API-first backend, **Kutt** if you run Node.js or Docker anyway.

## Features

**Shortening**
- Create links from the public form, the admin panel or the REST API
- Optional title for every link
- Public mode (anyone can shorten, per-IP rate limit) or private mode (admin + API only)
- Links pointing back to the shortener itself are rejected (no redirect loops)
- **Expiry date and click limit** per link: afterwards the link answers `410 Gone` with a short notice page; the limit is enforced atomically, even under concurrent clicks

**Admin panel**
- Login + password (you choose both in the installer), brute-force lockout
- Dashboard: total links, total clicks, clicks today and over 7 days
- Search by code, title or URL; filter by status (active / expired / disabled); sort by date, clicks or last click
- Time left and "12 / 100 clicks" shown in the list; edit title, expiry and limit on the link page
- Per-link 7-day sparkline and a statistics page (30-day chart, top referrers)
- Enable / disable / delete, one-click copy, pagination
- **CSV import & export** with preview, duplicate detection and an error report; **YOURLS exports import as is** (keywords keep working)
- **QR code** for every link: preview and PNG/SVG download (generated in the browser, no external service)

**Clicks**
- Per-link counters with a click log (timestamp + referrer, no IPs, no cookies)
- Bots, crawlers, link previews, `HEAD` requests and browser prefetches are **not** counted

**Interface**
- **EN / RU language switcher** (remembered in a cookie, defaults to the browser language)
- Responsive layout, automatic light / dark theme, no JavaScript frameworks

**API**
- Bearer-token REST API: create, list, details with daily clicks, enable / disable, delete

**Operations**
- Web installer with environment checks, locks itself after install
- Automatic database migrations on upgrade
- Strict Content-Security-Policy, CSRF protection, prepared statements everywhere

## Requirements

- PHP 8.0 or newer with `pdo_sqlite` (enabled on virtually every hosting; the installer checks it)
- Apache with `mod_rewrite` (standard on shared hosting), or Nginx (config below)
- lnks must live at the **root of a domain or subdomain** (`https://s.example.com/`), not in a subfolder

## Installation

### Install over FTP (shared hosting, no SSH)

1. **Download** lnks: on GitHub click **Code → Download ZIP** and unpack it on your computer.
2. **Create a domain or subdomain** in your hosting panel (cPanel, ISPmanager, Plesk, DirectAdmin…), e.g. `s.example.com`, and select **PHP 8.0 or newer** for it.
3. **Upload the files** with an FTP client (FileZilla, WinSCP or the hosting's file manager) into the folder of that domain — usually `public_html`, `www` or `s.example.com/`. Upload the *contents* of the `lnks-main` folder, including the hidden `.htaccess` file (in FileZilla: *Server → Force showing hidden files*).
4. **Make `storage/` writable**: right-click the `storage` folder → *File permissions* → `775` (or `777` if your host needs it). The root folder must be writable too, once, so the installer can create `config.php`.
5. **Open your domain** in a browser. The setup wizard checks PHP, SQLite and permissions, then asks for an **admin login and password**.
6. **Save the API token** shown at the end. Optionally delete `install.php` over FTP.

The admin panel is at `https://your-domain/admin.php`. Turn on HTTPS in the hosting panel (most offer free Let's Encrypt certificates).

<details>
<summary>Troubleshooting</summary>

- **500 error or every short link shows 404** — `.htaccess` was not uploaded or `mod_rewrite` is off. Re-upload `.htaccess`; on Nginx-only hosting use the config below or ask support to route all requests to `index.php`.
- **"storage/ writable" is red in the installer** — set permissions of `storage` to `775`, or `777` on hosts where PHP runs as another user.
- **"PDO SQLite" is red** — enable the `pdo_sqlite` (sometimes called `sqlite3`) extension in the hosting panel's PHP settings.
- **Styles are missing** — lnks was uploaded into a subfolder; move it to the root of a domain or subdomain.

</details>

### Install with a shell (VPS, manual config)

```bash
git clone https://github.com/iDreamSkies/lnks.git
cd lnks
cp config.example.php config.php
```

Edit `config.php`:

```bash
# Generate the admin password hash (put it into admin_pass_hash)
php -r "echo password_hash('your-password', PASSWORD_BCRYPT), PHP_EOL;"

# Generate the API token (put it into api_token)
php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
```

Set `admin_user` to the login you want (default `admin`), then make sure `storage/` is writable by the web server:

```bash
chmod 775 storage
```

**Local dev:** `php -S localhost:8080 router.php`

### Upgrading

Pull the new files and keep your `config.php` and `storage/`. The database is migrated automatically on the first request. Configs from older versions keep working: a missing `admin_user` defaults to `admin`.

### Nginx

```nginx
server {
    listen 80;
    server_name lnks.example.com;
    root /var/www/lnks;
    index index.php;

    location /storage/ { deny all; }
    location /lang/    { deny all; }
    location /tests/   { deny all; }
    location ~ ^/(config|bootstrap|layout|i18n|csv|router|schema|\.git) { deny all; }

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

## Import & export (CSV)

**Admin → Import / Export.**

- **Export** downloads every link: `code,url,title,clicks,status,created_at,last_click_at,expires_at,max_clicks` (UTF-8 with BOM, opens in Excel). Cells that start with `=`, `+`, `-` or `@` get a leading `'` so spreadsheets do not run them as formulas; the importer removes it again.
- **Import** takes a CSV with a header row. Only `url` is required; the other export columns are optional, in any order. Comma, semicolon (Excel in many locales) and tab delimiters are detected automatically, as is a single header-less column of URLs.
- You first see a **preview**: how many rows are new, which codes are already taken (skipped, never overwritten), and every row with an error and its line number. Nothing is written until you confirm.
- Rows without a code get a generated one. Imported codes may be 1–32 characters: letters, digits, `-`, `_`. Reserved words (`admin`, `api`, `public`, …) are rejected.
- Limits: 5 MB, 50 000 rows per file.

### Migrating from YOURLS

lnks imports a CSV export of the YOURLS link table, and **your existing short links keep working** because keywords are imported as codes.

1. **Export from YOURLS.** Either use an export plugin, or in phpMyAdmin run the query below and export the result as CSV with the column names in the first row:
   ```sql
   SELECT keyword, url, title, timestamp, ip, clicks FROM yourls_url;
   ```
2. **Install lnks** on the same domain (or point the domain to it later).
3. Open **Admin → Import / Export**, choose the file, check the preview (look for the "YOURLS format detected" note) and click **Import**.
4. Point the domain to lnks. `https://your.domain/abc` now redirects exactly as before, and click counters continue from the YOURLS numbers.

Notes: YOURLS stores `timestamp` in the server's time zone; lnks imports it as-is and treats it as UTC. A literal `NULL` (how phpMyAdmin writes empty values) is treated as empty. The per-click log (`yourls_log`) is not imported — only totals. Keywords with characters other than letters, digits, `-` and `_` are listed as errors in the preview.

## Language

The interface is available in English and Russian. Visitors switch with the **EN | RU** toggle in the header; the choice is saved in a cookie. Without a choice, the browser language is used (falls back to English). To force a default for everyone, set `'lang' => 'en'` or `'ru'` in `config.php`.

Want another language? Copy `lang/en.php` to `lang/xx.php`, translate the values and add the code to `LNKS_LANGS` in `i18n.php`.

API responses are always in English.

## API

All requests need `Authorization: Bearer YOUR_API_TOKEN`.

```bash
# Create (title, expires_at and max_clicks are optional)
curl -X POST https://lnks.example.com/api.php \
  -H "Authorization: Bearer YOUR_API_TOKEN" -H "Content-Type: application/json" \
  -d '{"url": "https://example.com/very/long/url", "title": "Docs", "expires_at": "2026-12-31 23:59", "max_clicks": 100}'

# List (q, state=active|expired|disabled, limit 1-100, offset)
curl "https://lnks.example.com/api.php?q=docs&state=active&limit=20" -H "Authorization: Bearer YOUR_API_TOKEN"

# Details + clicks for the last 30 days
curl "https://lnks.example.com/api.php?code=aB3xYz" -H "Authorization: Bearer YOUR_API_TOKEN"

# Update any of: status (0/1), title, expires_at, max_clicks — null or "" removes a limit
curl -X PATCH "https://lnks.example.com/api.php?code=aB3xYz" -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" -d '{"status": 1, "expires_at": null, "max_clicks": 500}'

# Delete
curl -X DELETE "https://lnks.example.com/api.php?code=aB3xYz" -H "Authorization: Bearer YOUR_API_TOKEN"
```

Create response (201):

```json
{ "ok": true, "id": 1, "code": "aB3xYz", "short_url": "https://lnks.example.com/aB3xYz", "url": "https://example.com/very/long/url", "title": "Docs" }
```

`expires_at` is UTC: `YYYY-MM-DD HH:MM[:SS]`, ISO 8601 with an offset (`2026-12-31T23:59:00+03:00`, converted to UTC), or `YYYY-MM-DD` (end of that day). Link objects include `state`: `active`, `disabled`, `expired` (date passed) or `limit` (click limit reached).

CSV over the API:

```bash
# Export everything (same file as Admin → Import / Export)
curl "https://lnks.example.com/api.php?export=csv" -H "Authorization: Bearer YOUR_API_TOKEN" -o links.csv

# Import: validate first with dry_run=1, then run for real (raw body or multipart field "file")
curl -X POST "https://lnks.example.com/api.php?import=csv&dry_run=1" -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: text/csv" --data-binary @links.csv
```

The import answers `{ "ok": true, "imported": 5, "duplicates": 1, "errors": [{ "line": 7, "error": "..." }] }` (`would_import` instead of `imported` on a dry run).

Errors return `{ "ok": false, "error": "..." }` with an appropriate HTTP status (400, 401, 404, 405, 422, 503).

## Configuration

| Key | Description |
|---|---|
| `base_url` | Public URL of the instance; empty = auto-detect from the request |
| `lang` | `auto` (browser language), `en` or `ru` — default language |
| `admin_user` | Admin login (default `admin`) |
| `admin_pass_hash` | bcrypt hash of the admin password |
| `api_token` | Bearer token for `/api.php`; empty = API disabled |
| `public_form` | `true` — anyone can shorten from the homepage; `false` — admin/API only |
| `rate_limit` | `['max' => 20, 'window_min' => 60]` — public form limit per IP |
| `trust_proxy` | `true` to read the client IP from `X-Forwarded-For` (only behind your own reverse proxy) |
| `code.length` | Short code length (default 6, clamped to 4–12) |
| `timezone` | PHP timezone (the UI shows times in UTC) |

## Security notes

- CSRF protection on all forms; logout is a POST
- Prepared statements everywhere; user input is always escaped on output
- Login requires username **and** password; 5 failed attempts per 15 minutes lock the IP out; session ID regenerated on login; `HttpOnly` + `SameSite` cookies
- Strict Content-Security-Policy (no inline scripts or styles), `X-Frame-Options: DENY`, `nosniff`
- `storage/`, `lang/`, config and internal files are blocked at the web-server level
- Redirects send `X-Robots-Tag: noindex`
- No cookies for visitors who only follow short links

## Project layout

```
index.php      homepage + redirects        admin.php   admin panel
api.php        REST API                    install.php web installer
bootstrap.php  config, DB, helpers         layout.php  page layout, support links
csv.php        CSV import / export
i18n.php       translations loader         lang/       en.php, ru.php
schema.sql     database schema             public/     styles.css, app.js, vendor/qrcode.js
tests/         automated checks (php tests/run.php)
```

## Tests

```bash
php tests/run.php          # everything
php tests/run.php qr       # only tests whose name contains "qr"
```

Needs only the PHP CLI with `pdo_sqlite` — no PHPUnit. The runner starts a throw-away copy of the app on the PHP built-in server, checks it over HTTP and removes it afterwards; your `config.php` and database are never touched.

## Third-party code

- `public/vendor/qrcode.js` — [QR Code Generator](https://github.com/kazuhikoarase/qrcode-generator) 2.0.4, © 2009 Kazuhiko Arase, MIT license (see `public/vendor/LICENSE-qrcode-generator.txt`). Unmodified. "QR Code" is a registered trademark of DENSO WAVE INCORPORATED.

## Need more?

lnks is the intentionally minimal open-source core. A full-featured edition exists with:

- Custom slugs and link editing
- Multi-domain support
- Password-protected links
- UTM builder and per-click analytics (geo, device, browser, unique visitors)
- A/B split testing
- Telegram bot integration
- Multi-user access with roles

Interested in the full edition, a hosted instance, or custom development?
Reach out: [dreamskies.dev](https://dreamskies.dev) · Telegram [@dreamskies](https://t.me/dreamskies)

## Support the project

lnks is free and MIT licensed. If it saves you time, you can support its development:

- Crypto: [pay.oxapay.com](https://pay.oxapay.com/14606636/)
- From Russia: [YooMoney](https://yoomoney.ru/fundraise/1KGTK8NPPQN.260925)

Bugs and ideas: [open an issue](https://github.com/iDreamSkies/lnks/issues).

## License

MIT — see [LICENSE](LICENSE).

---

Built by [DreamSkies](https://dreamskies.dev)
