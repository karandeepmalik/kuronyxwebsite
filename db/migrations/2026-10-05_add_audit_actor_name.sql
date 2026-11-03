-- audit() (includes/auth.php) now copies the acting staff member's / vet's name onto the row
-- when it is written, so the audit log still shows who did it after an account is renamed or
-- removed. A real column, not metadata_json, because data erasure nulls metadata_json on case
-- entries. NULL on every row written before this migration - the audit log page falls back to
-- the current name via a join for those.
--
-- MUST be run BEFORE the code that needs it is deployed: the new audit() INSERT names this
-- column, so without it every audited action (including signing in) fails.
ALTER TABLE audit_log ADD COLUMN actor_name VARCHAR(150) NULL;
