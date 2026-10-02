CREATE TABLE IF NOT EXISTS links (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    code          TEXT NOT NULL UNIQUE,
    url           TEXT NOT NULL,
    title         TEXT,
    clicks_total  INTEGER NOT NULL DEFAULT 0,
    last_click_at TEXT,
    expires_at    TEXT,                -- UTC 'Y-m-d H:i:s'; NULL = never
    max_clicks    INTEGER,             -- NULL = unlimited
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
