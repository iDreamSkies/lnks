[![License: MIT](https://img.shields.io/badge/license-MIT-c8ff00.svg)](LICENSE)
![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)
![SQLite](https://img.shields.io/badge/SQLite-zero%20config-003b57.svg)
![Dependencies: none](https://img.shields.io/badge/dependencies-none-success.svg)
[![Tests](https://github.com/iDreamSkies/lnks/actions/workflows/tests.yml/badge.svg)](https://github.com/iDreamSkies/lnks/actions/workflows/tests.yml)

**English** · [Русский](README.ru.md) · [Website](https://idreamskies.github.io/lnks/)

# lnks

Minimal self-hosted URL shortener. One small PHP app, SQLite, zero dependencies.

> ### Works without SSH and MySQL
> Upload the files over **FTP**, open your domain in a browser, set a login and password — done.
> No shell access, no database server to create, no Composer, no Docker, no Node.js.
> If your hosting runs PHP 8.0+ (almost every shared hosting does), lnks runs there.

No tracking pixels, no third-party scripts, no external services: QR codes are drawn in the browser, statistics stay in your SQLite file.

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
- **Bulk actions**: tick several links (or all on the page) and enable, disable, add / remove tags or delete them at once
- **Tags**: comma-separated on create or in the link settings, `#tag` chips in the list, filter by tag
- Time left and "12 / 100 clicks" shown in the list; edit title, expiry and limit on the link page
- Per-link 7-day sparkline and a statistics page: 7 / 30 / 90-day periods compared with the previous period, clicks per day and per hour, referrers, devices, browsers, operating systems and (optionally) countries
- Enable / disable / delete, one-click copy, pagination
- **Password-protected links**: visitors enter a password before the redirect; the destination is never shown before that, and guessing is throttled per link and IP
- **Custom short names** (`/spring-sale`) and **several domains**: list extra hosts in `config.php` and bind a link to one of them, or leave it working on all
- **UTM tags and templates**: add `utm_source` / `utm_medium` / `utm_campaign` when creating a link, or pick a saved template (Admin → UTM templates); other query parameters and the `#fragment` are kept as they are
- **CSV import & export** with preview, duplicate detection and an error report; **YOURLS exports import as is** (keywords keep working)
- **QR code** for every link: preview and PNG/SVG download (generated in the browser, no external service)

**Clicks**
- Per-link counters with a click log: timestamp, referrer, browser / OS / device family parsed on the server, optional country — **no IP addresses and no cookies are stored**
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

1. **Download** the latest release: [`lnks-X.Y.Z.zip` on the Releases page](https://github.com/iDreamSkies/lnks/releases/latest), and unpack it on your computer (or use **Code → Download ZIP** for the development version).
2. **Create a domain or subdomain** in your hosting panel (cPanel, ISPmanager, Plesk, DirectAdmin…), e.g. `s.example.com`, and select **PHP 8.0 or newer** for it.
3. **Upload the files** with an FTP client (FileZilla, WinSCP or the hosting's file manager) into the folder of that domain — usually `public_html`, `www` or `s.example.com/`. Upload the *contents* of the unpacked `lnks-X.Y.Z` folder, including the hidden `.htaccess` file (in FileZilla: *Server → Force showing hidden files*).
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

### Several domains

Add the extra hosts to `config.php` (`'domains' => ['go.example.com', 'brand.link']`) and point each of them at the **same folder** as the main domain — on shared hosting that is usually "alias domain", "parked domain" or "additional domain" in the panel. When creating a link, pick a domain to bind it to; it then answers only on that host and its short URL, copy button and QR code use it. Links without a domain work on every host. Short names are unique across all domains.

### Upgrading

When a new release is out, the admin panel shows a notice with a link to it (checked once a day via the GitHub API, only from the admin panel; turn off with `'update_check' => false`). lnks never updates itself.

Download the new release and upload its files over the old ones, keeping your `config.php` and `storage/`. The database is migrated automatically on the first request. Configs from older versions keep working: a missing `admin_user` defaults to `admin`.

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
    location ~ ^/(config|bootstrap|layout|i18n|csv|ua|geo|router|schema|\.git) { deny all; }
    location /scripts/ { deny all; }

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

- **Export** downloads every link: `code,url,title,clicks,status,created_at,last_click_at,expires_at,max_clicks,domain,tags` (tags comma-separated inside one cell) (UTF-8 with BOM, opens in Excel). Cells that start with `=`, `+`, `-` or `@` get a leading `'` so spreadsheets do not run them as formulas; the importer removes it again.
- **Import** takes a CSV with a header row. Only `url` is required; the other export columns are optional, in any order. Comma, semicolon (Excel in many locales) and tab delimiters are detected automatically, as is a single header-less column of URLs.
- You first see a **preview**: how many rows are new, which codes are already taken (skipped, never overwritten), and every row with an error and its line number. Nothing is written until you confirm.
- Rows without a code get a generated one; an optional `domain` column binds a row to one of the configured domains. Imported codes may be 1–32 characters: letters, digits, `-`, `_`. Reserved words (`admin`, `api`, `public`, …) are rejected.
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

## Country statistics (optional)

Countries are **off by default**: lnks never calls an external service and does not ship a third-party IP database. To turn them on, build a compact table once from the public registration statistics of the five Regional Internet Registries (AFRINIC, APNIC, ARIN, LACNIC, RIPE NCC):

```bash
php scripts/build-geoip.php          # downloads the five "delegated" files and writes storage/geoip-v4.bin
```

Then set `'geoip' => true` in `config.php`. No shell on your hosting? Run the script on your computer and upload `storage/geoip-v4.bin` over FTP; if the download is blocked, fetch the `delegated-*-extended-latest` files yourself and pass them as arguments. Re-run it every month or two.

How it works and its limits: the table stores "range start → country" in 6 bytes per range and a lookup is a binary search in the file (no memory load). It tells which country an address block is **registered** to — accurate enough for country-level stats, not precise geolocation. Only IPv4 visitors are resolved; IPv6 and older clicks show as "Unknown". The IP itself is never stored.

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

# Tags: on create, list by tag, replace (PATCH "tags": [] clears), all tags with counts
curl -X POST https://lnks.example.com/api.php -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" -d '{"url": "https://example.com/a", "tags": ["promo", "autumn"]}'
curl "https://lnks.example.com/api.php?tag=promo" -H "Authorization: Bearer YOUR_API_TOKEN"
curl "https://lnks.example.com/api.php?tags=1" -H "Authorization: Bearer YOUR_API_TOKEN"

# Bulk: enable | disable | delete | tag | untag several links by code
curl -X POST "https://lnks.example.com/api.php?bulk=1" -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" -d '{"codes": ["aB3xYz", "spring-sale"], "op": "tag", "tags": ["q4"]}'

# Custom short name, bound to one of the configured domains
curl -X POST https://lnks.example.com/api.php \
  -H "Authorization: Bearer YOUR_API_TOKEN" -H "Content-Type: application/json" \
  -d '{"url": "https://example.com/spring", "code": "spring-sale", "domain": "go.example.com"}'

# Create with UTM tags: explicit tags and/or a saved template (by name or id); explicit tags win
curl -X POST https://lnks.example.com/api.php \
  -H "Authorization: Bearer YOUR_API_TOKEN" -H "Content-Type: application/json" \
  -d '{"url": "https://example.com/sale", "utm_template": "Newsletter", "utm": {"campaign": "black-friday"}}'

# Saved UTM templates
curl "https://lnks.example.com/api.php?utm_templates=1" -H "Authorization: Bearer YOUR_API_TOKEN"

# List (q, state=active|expired|disabled, limit 1-100, offset)
curl "https://lnks.example.com/api.php?q=docs&state=active&limit=20" -H "Authorization: Bearer YOUR_API_TOKEN"

# Details + statistics for 7, 30 (default) or 90 days: clicks vs the previous period,
# daily, hourly (UTC), referrers, devices, browsers, os, countries
curl "https://lnks.example.com/api.php?code=aB3xYz&days=7" -H "Authorization: Bearer YOUR_API_TOKEN"

# Update any of: status (0/1), title, expires_at, max_clicks, password — null or "" removes a limit / the password
curl -X PATCH "https://lnks.example.com/api.php?code=aB3xYz" -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" -d '{"status": 1, "expires_at": null, "max_clicks": 500}'

# Delete
curl -X DELETE "https://lnks.example.com/api.php?code=aB3xYz" -H "Authorization: Bearer YOUR_API_TOKEN"
```

Create response (201):

```json
{ "ok": true, "id": 1, "code": "aB3xYz", "short_url": "https://lnks.example.com/aB3xYz", "url": "https://example.com/very/long/url", "title": "Docs" }
```

`expires_at` is UTC: `YYYY-MM-DD HH:MM[:SS]`, ISO 8601 with an offset (`2026-12-31T23:59:00+03:00`, converted to UTC), or `YYYY-MM-DD` (end of that day). Link objects include `state`: `active`, `disabled`, `expired` (date passed) or `limit` (click limit reached), and `protected` (true when a password is set). `password` (4–128 characters) can be sent on create and update; it is stored as a hash and never returned or exported.

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
| `domains` | Extra hosts that serve short links, e.g. `['go.example.com']` — point them at the same folder; links can be bound to one of them |
| `geoip` | `true` to record the country of each click from `storage/geoip-v4.bin` (see *Country statistics*); default `false` |
| `update_check` | `true` (default) — once a day the admin panel asks GitHub for the latest release and shows a notice if it is newer; `false` turns it off |
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
- Link passwords are stored with `password_hash()`; 5 wrong attempts per link and IP within 15 minutes answer `429`; the password page is `no-store` and does not reveal the destination; hashes never leave the database (API, CSV)
- Redirects send `X-Robots-Tag: noindex`
- No cookies for visitors who only follow short links

## Project layout

```
index.php      homepage + redirects        admin.php   admin panel
api.php        REST API                    install.php web installer
bootstrap.php  config, DB, helpers         layout.php  page layout, support links
csv.php        CSV import / export
ua.php, geo.php  User-Agent parsing, optional IPv4 → country lookup
scripts/       build-geoip.php
i18n.php       translations loader         lang/       en.php, ru.php
schema.sql     database schema             public/     styles.css, app.js, vendor/qrcode.js
tests/         automated checks (php tests/run.php)
```

## Tests

```bash
php tests/run.php          # everything
php tests/run.php qr       # only tests whose name contains "qr"
```

Needs only the PHP CLI with `pdo_sqlite` — no PHPUnit. GitHub Actions runs the suite on PHP 8.0, 8.1, 8.2 and 8.3, plus PHP 8.0 without `mbstring` and `ctype`. The runner starts a throw-away copy of the app on the PHP built-in server, checks it over HTTP and removes it afterwards; your `config.php` and database are never touched.

## Releases

Every release on the [Releases page](https://github.com/iDreamSkies/lnks/releases) has a ready-to-upload `lnks-X.Y.Z.zip` (only the files a server needs: no tests, docs or Git data) and its SHA-256 checksum. What changed is in [CHANGELOG.md](CHANGELOG.md).

Making a release (maintainers):

1. Set `LNKS_VERSION` in `layout.php` and add a `## [X.Y.Z]` section to `CHANGELOG.md`.
2. Merge to `main`, then tag and push: `git tag vX.Y.Z && git push origin vX.Y.Z`.
3. The *Release* workflow runs the tests, builds the archive with `php scripts/build-release.php vX.Y.Z` (it refuses a tag that does not match the version) and publishes the release with the CHANGELOG section as its notes.

## Third-party code

- `public/vendor/qrcode.js` — [QR Code Generator](https://github.com/kazuhikoarase/qrcode-generator) 2.0.4, © 2009 Kazuhiko Arase, MIT license (see `public/vendor/LICENSE-qrcode-generator.txt`). Unmodified. "QR Code" is a registered trademark of DENSO WAVE INCORPORATED.

## Need more?

lnks is the intentionally minimal open-source core. A full-featured edition exists with:

- Link editing (change the destination or short name)
- Unique visitors and city-level geography
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
