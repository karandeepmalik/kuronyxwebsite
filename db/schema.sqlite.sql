-- SQLite-compatible mirror of schema.sql, for local dev (DB_DRIVER=sqlite) and the
-- test suite under tests/. SQLite has no ENUM/AUTO_INCREMENT/ENGINE syntax, so this
-- file translates those to INTEGER PRIMARY KEY AUTOINCREMENT + CHECK constraints.
-- There is no build step to generate this automatically — if you add/change a table
-- in schema.sql, mirror the change here by hand.

CREATE TABLE staff_users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          VARCHAR(150) NOT NULL,
    email         VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          TEXT NOT NULL DEFAULT 'pharmacy_staff' CHECK (role IN ('admin','pharmacy_staff')),
    active        INTEGER NOT NULL DEFAULT 1,
    password_reset_token_hash  VARCHAR(64) NULL,
    password_reset_expires_at  DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE login_attempts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    identifier  VARCHAR(190) NOT NULL,
    succeeded   INTEGER NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_identifier_time ON login_attempts (identifier, created_at);

CREATE TABLE rate_limit_hits (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    bucket      VARCHAR(190) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_rate_limit_bucket_time ON rate_limit_hits (bucket, created_at);

CREATE TABLE vet_applications (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name             VARCHAR(150) NOT NULL,
    professional_email    VARCHAR(190) NOT NULL,
    mobile                VARCHAR(30) NOT NULL,
    registration_number   VARCHAR(100) NOT NULL,
    registration_state    VARCHAR(100) NOT NULL,
    registration_country  VARCHAR(100) NOT NULL DEFAULT 'India',
    qualification         VARCHAR(150) NOT NULL,
    year_qualified        SMALLINT NOT NULL,
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
    status                TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','under_review','approved','rejected','suspended')),
    internal_notes        TEXT NULL,
    reviewed_by           INTEGER NULL REFERENCES staff_users(id),
    reviewed_at           DATETIME NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- MySQL's ON UPDATE CURRENT_TIMESTAMP has no SQLite equivalent as a column
-- default — without this trigger, updated_at would freeze at insert time forever
-- in local dev/tests, unlike production.
CREATE TRIGGER trg_vet_applications_updated_at AFTER UPDATE ON vet_applications
BEGIN
    UPDATE vet_applications SET updated_at = CURRENT_TIMESTAMP WHERE id = OLD.id;
END;
CREATE INDEX idx_vetapp_status ON vet_applications (status);

CREATE TABLE vet_application_documents (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    application_id     INTEGER NOT NULL REFERENCES vet_applications(id) ON DELETE CASCADE,
    stored_filename    VARCHAR(64) NOT NULL,
    original_filename  VARCHAR(255) NOT NULL,
    mime_type          VARCHAR(100) NOT NULL,
    size_bytes         INTEGER NOT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_vetappdoc_app ON vet_application_documents (application_id);

CREATE TABLE vet_accounts (
    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
    vet_application_id      INTEGER NOT NULL UNIQUE REFERENCES vet_applications(id),
    email                    VARCHAR(190) NOT NULL UNIQUE,
    password_hash            VARCHAR(255) NULL,
    status                   TEXT NOT NULL DEFAULT 'pending_activation' CHECK (status IN ('pending_activation','active','suspended')),
    activation_token_hash    VARCHAR(64) NULL,
    activation_expires_at    DATETIME NULL,
    password_reset_token_hash VARCHAR(64) NULL,
    password_reset_expires_at DATETIME NULL,
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- MySQL's ON UPDATE CURRENT_TIMESTAMP has no SQLite equivalent as a column
-- default — without this trigger, updated_at would freeze at insert time forever
-- in local dev/tests, unlike production.
CREATE TRIGGER trg_vet_accounts_updated_at AFTER UPDATE ON vet_accounts
BEGIN
    UPDATE vet_accounts SET updated_at = CURRENT_TIMESTAMP WHERE id = OLD.id;
END;

CREATE TABLE gs_requests (
    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
    source                   TEXT NOT NULL CHECK (source IN ('veterinarian','cat_owner')),
    vet_account_id           INTEGER NULL REFERENCES vet_accounts(id),
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
    patient_sex              TEXT NOT NULL DEFAULT 'unknown' CHECK (patient_sex IN ('male','female','unknown')),
    patient_dob              DATE NULL,
    patient_weight_kg        DECIMAL(5,2) NULL,
    patient_neutered         INTEGER NULL,
    patient_microchip        VARCHAR(100) NULL,
    clinical_notes           TEXT NULL,
    vet_name                 VARCHAR(150) NULL,
    vet_clinic               VARCHAR(190) NULL,
    vet_email                VARCHAR(190) NULL,
    vet_phone                VARCHAR(30)  NULL,
    vet_registration_info    VARCHAR(190) NULL,
    requested_formulation    TEXT NOT NULL CHECK (requested_formulation IN ('injection','oral')),
    status                   TEXT NOT NULL DEFAULT 'submitted' CHECK (status IN (
                                 'submitted','under_review','awaiting_information',
                                 'communication_in_progress','formulation_discussion',
                                 'approved','compounding','ready_for_dispatch',
                                 'dispatched','finished','closed','cancelled','rejected'
                             )),
    assigned_staff_id        INTEGER NULL REFERENCES staff_users(id),
    final_formulation        VARCHAR(100) NULL,
    final_concentration      VARCHAR(100) NULL,
    final_quantity           VARCHAR(100) NULL,
    final_price              DECIMAL(10,2) NULL,
    closure_reason           TEXT NULL,
    courier                  VARCHAR(100) NULL,
    tracking_number          VARCHAR(100) NULL,
    dispatch_date            DATE NULL,
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- MySQL's ON UPDATE CURRENT_TIMESTAMP has no SQLite equivalent as a column
-- default — without this trigger, updated_at would freeze at insert time forever
-- in local dev/tests, unlike production.
CREATE TRIGGER trg_gs_requests_updated_at AFTER UPDATE ON gs_requests
BEGIN
    UPDATE gs_requests SET updated_at = CURRENT_TIMESTAMP WHERE id = OLD.id;
END;
CREATE INDEX idx_gsreq_status ON gs_requests (status);
CREATE INDEX idx_gsreq_source ON gs_requests (source);
CREATE INDEX idx_gsreq_created ON gs_requests (created_at);
CREATE INDEX idx_gsreq_vet_account ON gs_requests (vet_account_id);

CREATE TABLE gs_request_documents (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    gs_request_id      INTEGER NOT NULL REFERENCES gs_requests(id) ON DELETE CASCADE,
    doc_type           TEXT NOT NULL CHECK (doc_type IN ('prescription','supporting')),
    stored_filename    VARCHAR(64) NOT NULL,
    original_filename  VARCHAR(255) NOT NULL,
    mime_type          VARCHAR(100) NOT NULL,
    size_bytes         INTEGER NOT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_gsreqdoc_req ON gs_request_documents (gs_request_id);

CREATE TABLE case_status_history (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    gs_request_id     INTEGER NOT NULL REFERENCES gs_requests(id) ON DELETE CASCADE,
    previous_status   VARCHAR(50) NULL,
    new_status        VARCHAR(50) NOT NULL,
    changed_by        INTEGER NULL REFERENCES staff_users(id),
    note              TEXT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_history_req ON case_status_history (gs_request_id);

CREATE TABLE case_internal_notes (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    gs_request_id  INTEGER NOT NULL REFERENCES gs_requests(id) ON DELETE CASCADE,
    staff_id       INTEGER NOT NULL REFERENCES staff_users(id),
    content        TEXT NOT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_note_req ON case_internal_notes (gs_request_id);

CREATE TABLE case_emails (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    gs_request_id      INTEGER NOT NULL REFERENCES gs_requests(id) ON DELETE CASCADE,
    staff_id           INTEGER NULL REFERENCES staff_users(id),
    recipient          VARCHAR(190) NOT NULL,
    sender             VARCHAR(190) NULL,
    subject            VARCHAR(255) NOT NULL,
    body               TEXT NOT NULL,
    brevo_message_id   VARCHAR(190) NULL,
    delivery_status    VARCHAR(50) NULL,
    sent_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_email_req ON case_emails (gs_request_id);

CREATE TABLE consent_records (
    id                     INTEGER PRIMARY KEY AUTOINCREMENT,
    gs_request_id          INTEGER NOT NULL REFERENCES gs_requests(id) ON DELETE CASCADE,
    consent_type           VARCHAR(50) NOT NULL,
    consent_text_version   VARCHAR(50) NOT NULL,
    ip_address             VARCHAR(45) NULL,
    user_agent             VARCHAR(255) NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE audit_log (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_type     TEXT NOT NULL CHECK (actor_type IN ('staff','system','public')),
    actor_id       INTEGER NULL,
    action         VARCHAR(100) NOT NULL,
    entity_type    VARCHAR(50) NOT NULL,
    entity_id      INTEGER NULL,
    metadata_json  TEXT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_audit_entity ON audit_log (entity_type, entity_id);
CREATE INDEX idx_audit_created ON audit_log (created_at);

CREATE TABLE dispatches (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    title            VARCHAR(200) NOT NULL,
    slug             VARCHAR(200) NOT NULL UNIQUE,
    excerpt          VARCHAR(400) NULL,
    body             TEXT NOT NULL,
    status           TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published')),
    author_staff_id  INTEGER NULL REFERENCES staff_users(id),
    published_at     DATETIME NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- MySQL's ON UPDATE CURRENT_TIMESTAMP has no SQLite equivalent as a column
-- default — without this trigger, updated_at would freeze at insert time forever
-- in local dev/tests, unlike production.
CREATE TRIGGER trg_dispatches_updated_at AFTER UPDATE ON dispatches
BEGIN
    UPDATE dispatches SET updated_at = CURRENT_TIMESTAMP WHERE id = OLD.id;
END;
CREATE INDEX idx_dispatch_status_published ON dispatches (status, published_at);
