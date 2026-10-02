# Changelog

All notable changes to lnks. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and versions follow [Semantic Versioning](https://semver.org/). Upgrading never needs manual database
work: the schema is migrated automatically on the first request.

## [2.0.0]

The "runs anywhere, does everything" release: everything a team needs from a shortener, still with
no SSH, no MySQL and no dependencies.

### Added
- QR code for every link (PNG / SVG), generated in the browser.
- Link expiry date and click limit; expired links answer `410 Gone`.
- Password-protected links with per-IP attempt limits.
- Custom short names and several domains (`'domains'` in `config.php`), links bound to a domain.
- UTM tags on create and saved UTM templates.
- Extended statistics: browsers, OS, devices, clicks by hour, 7 / 30 / 90-day periods compared with
  the previous period; optional country statistics from a locally built table (`scripts/build-geoip.php`).
- Tags with filtering, and bulk actions (enable, disable, tag, untag, delete).
- CSV import (with preview and duplicate detection) and export; imports YOURLS exports as they are.
- REST API for all of the above; `?export=csv`, `?import=csv`, `?bulk=1`, `?tags=1`, `?utm_templates=1`.
- Visitor-friendly homepage ("How it works") and a compact admin panel with icon actions.
- Project website in `docs/` for GitHub Pages (EN / RU).
- Automated tests (`php tests/run.php`, no PHPUnit) and CI on PHP 8.0–8.3, including a build
  without `mbstring` and `ctype`.
- Release tooling: `scripts/build-release.php` and a workflow that publishes a ZIP for every `v*` tag.
- "New version available" notice in the admin panel (GitHub latest release, checked once a day; `'update_check'`).

### Changed
- Short codes may now be 1–32 characters of `[A-Za-z0-9_-]` (YOURLS keywords keep working);
  generated codes are unchanged; reserved words such as `admin` or `api` are refused.
- Admin navigation: "Public page" replaced by "UTM templates" (the logo still links home).

### Fixed
- Concurrent clicks could fail with `database is locked` (HTTP 500): open read cursors are now
  closed before writes.
- Click limits are enforced atomically, even under concurrent requests.
- `mbstring` is no longer required.

## [1.2.0]

### Added
- Interface in English and Russian with a language switch (`'lang'` in `config.php`).
- Admin sign-in with a username and a password (`'admin_user'`, default `admin`).
- Support and donation links in the footer and the admin panel.
- `README.ru.md`.

## [1.1.0]

### Added
- Redesigned interface (light / dark, responsive), dashboard, filters, sorting, per-link statistics.
- Link titles; API list / details / enable / disable / delete.
- Login lockout after repeated failures, strict Content-Security-Policy, automatic DB migrations.

### Fixed
- Several issues found in a code review: duplicate short codes in rare cases, `/index.php` returning
  404, sessions created for every redirect, the `Authorization` header lost under CGI/FastCGI,
  internal files served by the development router.

## [1.0.0]

- First public version: shortening form, admin panel, REST API, web installer, SQLite.

[2.0.0]: https://github.com/iDreamSkies/lnks/releases/tag/v2.0.0
