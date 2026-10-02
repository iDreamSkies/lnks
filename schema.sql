CREATE TABLE IF NOT EXISTS links (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    code          TEXT NOT NULL UNIQUE,
    url           TEXT NOT NULL,
    title         TEXT,
    clicks_total  INTEGER NOT NULL DEFAULT 0,
    last_click_at TEXT,
    expires_at    TEXT,                -- UTC 'Y-m-d H:i:s'; NULL = never
    max_clicks    INTEGER,             -- NULL = unlimited
    password_hash TEXT,                -- password_hash() of the link password; NULL = public
    domain        TEXT,                -- host the link is bound to (one of the allowed hosts); NULL = any
    status        INTEGER NOT NULL DEFAULT 1,
    created_ip    TEXT,
    created_at    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS clicks (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    link_id  INTEGER NOT NULL REFERENCES links(id) ON DELETE CASCADE,
    ts       TEXT NOT NULL,
    referrer TEXT,
    browser  TEXT,        -- family from User-Agent (ua.php), e.g. Chrome
    os       TEXT,        -- e.g. Android
    device   TEXT,        -- desktop | mobile | tablet
    country  TEXT         -- ISO code when the optional GeoIP table is enabled
);

CREATE INDEX IF NOT EXISTS idx_clicks_link_ts ON clicks(link_id, ts);

CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip TEXT NOT NULL,
    ts TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_login_ip_ts ON login_attempts(ip, ts);

-- Failed password attempts on protected links (per link and IP)
CREATE TABLE IF NOT EXISTS unlock_attempts (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    link_id INTEGER NOT NULL,
    ip      TEXT NOT NULL,
    ts      TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_unlock_link_ip_ts ON unlock_attempts(link_id, ip, ts);

-- Saved sets of UTM tags applied when creating links
CREATE TABLE IF NOT EXISTS utm_templates (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL UNIQUE,
    source     TEXT,
    medium     TEXT,
    campaign   TEXT,
    created_at TEXT NOT NULL
);

-- Tags: case-insensitive names, many-to-many with links
CREATE TABLE IF NOT EXISTS tags (
    id   INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE COLLATE NOCASE
);

CREATE TABLE IF NOT EXISTS link_tags (
    link_id INTEGER NOT NULL REFERENCES links(id) ON DELETE CASCADE,
    tag_id  INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    PRIMARY KEY (link_id, tag_id)
);

CREATE INDEX IF NOT EXISTS idx_link_tags_tag ON link_tags(tag_id);
