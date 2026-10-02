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
    status        INTEGER NOT NULL DEFAULT 1,
    created_ip    TEXT,
    created_at    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS clicks (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    link_id  INTEGER NOT NULL REFERENCES links(id) ON DELETE CASCADE,
    ts       TEXT NOT NULL,
    referrer TEXT
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
