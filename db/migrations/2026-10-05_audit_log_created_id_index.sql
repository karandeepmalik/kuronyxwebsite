-- admin/audit-log/index.php now pages with ORDER BY created_at DESC, id DESC (id breaks ties,
-- since created_at has only 1-second resolution). InnoDB already appends the primary key to
-- idx_audit_created (created_at), so that index could serve the new ordering as it was; this
-- makes the (created_at, id) intent explicit and replaces the single-column index with it.
-- Run the two statements in this order (add the new index first, then drop the old one).
ALTER TABLE audit_log ADD INDEX idx_audit_created_id (created_at, id);
ALTER TABLE audit_log DROP INDEX idx_audit_created;
