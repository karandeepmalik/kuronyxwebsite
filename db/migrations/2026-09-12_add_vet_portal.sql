-- Run this against your existing database if you already imported db/schema.sql
-- before this migration existed (schema.sql itself now includes these changes too,
-- so a fresh install doesn't need this file).
--
-- Adds: staff_users.active (revocable staff accounts) and the vet_accounts table +
-- gs_requests.vet_account_id (veterinarian portal login, tied to an approved
-- vet_applications row, so a vet can sign in and submit GS-441524 requests directly).

ALTER TABLE staff_users
    ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER role;

CREATE TABLE vet_accounts (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vet_application_id      INT UNSIGNED NOT NULL UNIQUE,
    email                    VARCHAR(190) NOT NULL UNIQUE,
    password_hash            VARCHAR(255) NULL,
    status                   ENUM('pending_activation','active','suspended') NOT NULL DEFAULT 'pending_activation',
    activation_token_hash    VARCHAR(64) NULL,
    activation_expires_at    DATETIME NULL,
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_vetaccount_app FOREIGN KEY (vet_application_id) REFERENCES vet_applications(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE gs_requests
    ADD COLUMN vet_account_id INT UNSIGNED NULL AFTER source,
    ADD CONSTRAINT fk_gsreq_vetaccount FOREIGN KEY (vet_account_id) REFERENCES vet_accounts(id),
    ADD INDEX idx_gsreq_vet_account (vet_account_id);
