-- Makes first-admin creation (admin/setup.php) genuinely concurrency-safe: claiming this
-- single fixed-PRIMARY-KEY row is atomic and mutually exclusive at the database level,
-- unlike the SELECT ... WHERE NOT EXISTS check it replaces. Safe to run against an
-- already-provisioned database — if setup has already happened, insert this row once by
-- hand afterward (INSERT INTO setup_lock (id) VALUES (1)) so a stray concurrent request
-- can't be misread as "setup not yet done."
CREATE TABLE setup_lock (
    id         TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
