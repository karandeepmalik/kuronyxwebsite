-- GS-441524 platform — Phase 1 schema
-- Run once against the dedicated database created in hPanel (Databases → Management).
-- No secrets live here; credentials go in public_html/includes/db-config.php (gitignored).

CREATE TABLE staff_users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(150) NOT NULL,
    email         VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('admin','pharmacy_staff') NOT NULL DEFAULT 'pharmacy_staff',
    active        TINYINT(1) NOT NULL DEFAULT 1,
    password_reset_token_hash  VARCHAR(64) NULL,
    password_reset_expires_at  DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A single row with a fixed PRIMARY KEY value (see admin/setup.php). Claiming it via
-- INSERT is how first-admin creation is made concurrency-safe: a PRIMARY KEY violation
-- is always atomic and mutually exclusive at the database level, unlike a plain
-- SELECT ... WHERE NOT EXISTS check, which doesn't take a lock that would stop two
-- concurrent requests from both seeing an empty table and both inserting an admin.
CREATE TABLE setup_lock (
    id         TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE login_attempts (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier  VARCHAR(190) NOT NULL,
    succeeded   TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_identifier_time (identifier, created_at),
    -- Standalone index on created_at alone — cleanup_old_rows() (includes/auth.php)
    -- deletes by created_at with no identifier predicate, which idx_identifier_time
    -- above can't serve as a range scan (identifier is its leading column), so without
    -- this the DELETE would scan/lock the entire table.
    INDEX idx_login_attempts_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Generic rate-limit hit log (see rate_limited()/record_rate_limit_hit() in
-- includes/auth.php) — used to throttle public, unauthenticated endpoints
-- (intake forms, the legacy Brevo mailer endpoints) by IP.
CREATE TABLE rate_limit_hits (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket      VARCHAR(190) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bucket_time (bucket, created_at),
    -- See idx_login_attempts_created_at above — same reasoning, same fix.
    INDEX idx_rate_limit_hits_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE vet_applications (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name             VARCHAR(150) NOT NULL,
    professional_email    VARCHAR(190) NOT NULL,
    mobile                VARCHAR(30) NOT NULL,
    registration_number   VARCHAR(100) NOT NULL,
    registration_state    VARCHAR(100) NOT NULL,
    registration_country  VARCHAR(100) NOT NULL DEFAULT 'India',
    qualification         VARCHAR(150) NOT NULL,
    year_qualified        SMALLINT UNSIGNED NOT NULL,
    practice_type         VARCHAR(100) NOT NULL,
    clinic_name           VARCHAR(190) NOT NULL,
    clinic_address        VARCHAR(255) NOT NULL,
    clinic_city           VARCHAR(100) NOT NULL,
    clinic_state          VARCHAR(100) NOT NULL,
    clinic_pin            VARCHAR(20)  NOT NULL,
    clinic_country        VARCHAR(100) NOT NULL DEFAULT 'India',
    clinic_phone          VARCHAR(30)  NOT NULL,
    clinic_email          VARCHAR(190) NULL,
    clinic_website        VARCHAR(255) NULL,
    status                ENUM('pending','under_review','approved','rejected','suspended') NOT NULL DEFAULT 'pending',
    -- MEDIUMTEXT (not TEXT, 64KB max) since every review action appends to this — a
    -- long-lived application could otherwise exceed a plain TEXT column eventually.
    internal_notes        MEDIUMTEXT NULL,
    reviewed_by           INT UNSIGNED NULL,
    reviewed_at           DATETIME NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_vetapp_reviewer FOREIGN KEY (reviewed_by) REFERENCES staff_users(id),
    INDEX idx_vetapp_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE vet_application_documents (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id     INT UNSIGNED NOT NULL,
    stored_filename    VARCHAR(64) NOT NULL,
    original_filename  VARCHAR(255) NOT NULL,
    mime_type          VARCHAR(100) NOT NULL,
    size_bytes         INT UNSIGNED NOT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_vetappdoc_app FOREIGN KEY (application_id) REFERENCES vet_applications(id) ON DELETE CASCADE,
    INDEX idx_vetappdoc_app (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE vet_accounts (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vet_application_id      INT UNSIGNED NOT NULL UNIQUE,
    email                    VARCHAR(190) NOT NULL UNIQUE,
    password_hash            VARCHAR(255) NULL,
    status                   ENUM('pending_activation','active','suspended') NOT NULL DEFAULT 'pending_activation',
    activation_token_hash    VARCHAR(64) NULL,
    activation_expires_at    DATETIME NULL,
    password_reset_token_hash VARCHAR(64) NULL,
    password_reset_expires_at DATETIME NULL,
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_vetaccount_app FOREIGN KEY (vet_application_id) REFERENCES vet_applications(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE gs_requests (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source                   ENUM('veterinarian','cat_owner') NOT NULL,
    vet_account_id           INT UNSIGNED NULL,
    owner_full_name          VARCHAR(150) NOT NULL,
    owner_email              VARCHAR(190) NOT NULL,
    owner_phone              VARCHAR(30)  NOT NULL,
    owner_address            VARCHAR(255) NOT NULL,
    owner_city               VARCHAR(100) NOT NULL,
    owner_state              VARCHAR(100) NOT NULL,
    owner_pin                VARCHAR(20)  NOT NULL,
    owner_country            VARCHAR(100) NOT NULL DEFAULT 'India',
    patient_name             VARCHAR(100) NOT NULL,
    patient_species          VARCHAR(50)  NOT NULL DEFAULT 'Cat',
    patient_breed            VARCHAR(100) NULL,
    patient_sex              ENUM('male','female','unknown') NOT NULL DEFAULT 'unknown',
    patient_dob              DATE NULL,
    patient_weight_kg        DECIMAL(5,2) NULL,
    patient_neutered         TINYINT(1) NULL,
    patient_microchip        VARCHAR(100) NULL,
    clinical_notes           TEXT NULL,
    vet_name                 VARCHAR(150) NULL,
    vet_clinic               VARCHAR(190) NULL,
    vet_email                VARCHAR(190) NULL,
    vet_phone                VARCHAR(30)  NULL,
    vet_registration_info    VARCHAR(190) NULL,
    requested_formulation    ENUM('injection','oral') NOT NULL,
    status                   ENUM(
                                 'submitted','under_review','awaiting_information',
                                 'communication_in_progress','formulation_discussion',
                                 'approved','compounding','ready_for_dispatch',
                                 'dispatched','finished','closed','cancelled','rejected'
                             ) NOT NULL DEFAULT 'submitted',
    assigned_staff_id        INT UNSIGNED NULL,
    final_formulation        VARCHAR(100) NULL,
    final_concentration      VARCHAR(100) NULL,
    final_quantity           VARCHAR(100) NULL,
    final_price              DECIMAL(10,2) NULL,
    closure_reason           TEXT NULL,
    courier                  VARCHAR(100) NULL,
    tracking_number          VARCHAR(100) NULL,
    dispatch_date            DATE NULL,
    -- Set when the case's personal data was erased (includes/data-protection.php); NULL = not erased.
    erased_at                DATETIME NULL,
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Optimistic-lock token for admin/gs-requests/view.php's update_final/assign_staff
    -- actions. updated_at alone isn't safe for this: DATETIME here has only
    -- second-level precision, so two genuinely different edits landing within the same
    -- second would carry an identical "expected" value and the second one would
    -- silently win instead of being caught as a conflict. lock_version is bumped by
    -- exactly 1 on every such write, so it can't collide regardless of timing.
    lock_version              INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_gsreq_staff FOREIGN KEY (assigned_staff_id) REFERENCES staff_users(id),
    CONSTRAINT fk_gsreq_vetaccount FOREIGN KEY (vet_account_id) REFERENCES vet_accounts(id),
    INDEX idx_gsreq_status (status),
    INDEX idx_gsreq_source (source),
    INDEX idx_gsreq_created (created_at),
    INDEX idx_gsreq_vet_account (vet_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE gs_request_documents (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gs_request_id      INT UNSIGNED NOT NULL,
    doc_type           ENUM('prescription','supporting') NOT NULL,
    stored_filename    VARCHAR(64) NOT NULL,
    original_filename  VARCHAR(255) NOT NULL,
    mime_type          VARCHAR(100) NOT NULL,
    size_bytes         INT UNSIGNED NOT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_gsreqdoc_req FOREIGN KEY (gs_request_id) REFERENCES gs_requests(id) ON DELETE CASCADE,
    INDEX idx_gsreqdoc_req (gs_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE case_status_history (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gs_request_id     INT UNSIGNED NOT NULL,
    previous_status   VARCHAR(50) NULL,
    new_status        VARCHAR(50) NOT NULL,
    changed_by        INT UNSIGNED NULL,
    note              TEXT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_history_req FOREIGN KEY (gs_request_id) REFERENCES gs_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_history_staff FOREIGN KEY (changed_by) REFERENCES staff_users(id),
    INDEX idx_history_req (gs_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE case_internal_notes (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gs_request_id  INT UNSIGNED NOT NULL,
    staff_id       INT UNSIGNED NOT NULL,
    content        TEXT NOT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_note_req FOREIGN KEY (gs_request_id) REFERENCES gs_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_note_staff FOREIGN KEY (staff_id) REFERENCES staff_users(id),
    INDEX idx_note_req (gs_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Schema only for Phase 1 — no UI reads/writes this until the email composer phase.
CREATE TABLE case_emails (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gs_request_id      INT UNSIGNED NOT NULL,
    staff_id           INT UNSIGNED NULL,
    recipient          VARCHAR(190) NOT NULL,
    sender             VARCHAR(190) NULL,
    subject            VARCHAR(255) NOT NULL,
    body               TEXT NOT NULL,
    brevo_message_id   VARCHAR(190) NULL,
    delivery_status    VARCHAR(50) NULL,
    sent_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_email_req FOREIGN KEY (gs_request_id) REFERENCES gs_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_email_staff FOREIGN KEY (staff_id) REFERENCES staff_users(id),
    INDEX idx_email_req (gs_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE consent_records (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gs_request_id          INT UNSIGNED NOT NULL,
    consent_type           VARCHAR(50) NOT NULL,
    consent_text_version   VARCHAR(50) NOT NULL,
    ip_address             VARCHAR(45) NULL,
    user_agent             VARCHAR(255) NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_consent_req FOREIGN KEY (gs_request_id) REFERENCES gs_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_log (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_type     ENUM('staff','system','public','vet') NOT NULL,
    actor_id       INT UNSIGNED NULL,
    action         VARCHAR(100) NOT NULL,
    entity_type    VARCHAR(50) NOT NULL,
    entity_id      INT UNSIGNED NULL,
    metadata_json  TEXT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dispatches (field notes / articles), published by staff via /admin/dispatches/.
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
    -- Optimistic-lock token — see gs_requests.lock_version for why updated_at alone
    -- (second-level precision) isn't safe for this.
    lock_version     INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_dispatch_author FOREIGN KEY (author_staff_id) REFERENCES staff_users(id),
    INDEX idx_dispatch_status_published (status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
