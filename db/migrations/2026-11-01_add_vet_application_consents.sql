-- The proof that a veterinary applicant ticked the consent box (time, IP address, browser, and which version of
-- the Privacy Policy they agreed to). Cases already keep theirs in consent_records; applications used to have it
-- only inside the audit log's metadata, which holds no browser details and is not meant to be a consent register.
-- Written by for-veterinarians/apply/index.php inside the same transaction as the application, and deleted by
-- erase_vet_application() (includes/data-protection.php) when the application is erased.
-- Run this BEFORE deploying: without the table every new application fails to save. Applications submitted
-- before this have no row here (their audit entry is all there is).
CREATE TABLE vet_application_consents (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id         INT UNSIGNED NOT NULL,
    consent_type           VARCHAR(50) NOT NULL,
    consent_text_version   VARCHAR(50) NOT NULL,
    ip_address             VARCHAR(45) NULL,
    user_agent             VARCHAR(255) NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_vetappconsent_app FOREIGN KEY (application_id) REFERENCES vet_applications(id) ON DELETE CASCADE,
    INDEX idx_vetappconsent_app (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
