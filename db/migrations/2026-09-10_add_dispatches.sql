-- Run this against your existing database if you already imported db/schema.sql
-- before this table existed (schema.sql itself now includes this table too, so a
-- fresh install doesn't need this file).

CREATE TABLE dispatches (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title            VARCHAR(200) NOT NULL,
    slug             VARCHAR(200) NOT NULL UNIQUE,
    excerpt          VARCHAR(400) NULL,
    body             TEXT NOT NULL,
    status           ENUM('draft','published') NOT NULL DEFAULT 'draft',
    author_staff_id  INT UNSIGNED NULL,
    published_at     DATETIME NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dispatch_author FOREIGN KEY (author_staff_id) REFERENCES staff_users(id),
    INDEX idx_dispatch_status_published (status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
