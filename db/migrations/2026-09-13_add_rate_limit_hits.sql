-- Backs the generic rate_limited()/record_rate_limit_hit() helpers in includes/auth.php,
-- used to throttle public unauthenticated endpoints by IP. Safe to run against an
-- already-provisioned database.
CREATE TABLE rate_limit_hits (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket      VARCHAR(190) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bucket_time (bucket, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
