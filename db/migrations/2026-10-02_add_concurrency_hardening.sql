-- Second batch of concurrency/abuse hardening from the same review round as
-- 2026-10-02_add_gs_requests_lock_version.sql.

-- cleanup_old_rows() (includes/auth.php) deletes login_attempts/rate_limit_hits rows by
-- created_at with no other predicate. The existing composite indexes
-- (identifier, created_at) / (bucket, created_at) can't serve that as a range scan
-- (created_at isn't the leading column), so without a standalone index on created_at
-- alone, that DELETE would scan and lock the entire table — exactly what moving cleanup
-- out of the per-request transaction was trying to avoid.
ALTER TABLE login_attempts ADD INDEX idx_login_attempts_created_at (created_at);
ALTER TABLE rate_limit_hits ADD INDEX idx_rate_limit_hits_created_at (created_at);

-- vet_applications.internal_notes is a plain TEXT column (64KB max). A long-lived
-- application with many review actions can in principle exceed that, after which every
-- further approve/reject/suspend/request_info action (all of which append a note) would
-- fail outright. MEDIUMTEXT raises the cap to 16MB, effectively unbounded for this.
ALTER TABLE vet_applications MODIFY COLUMN internal_notes MEDIUMTEXT NULL;

-- Optimistic-lock token for the dispatch editor (admin/dispatches/edit.php), same
-- reasoning as gs_requests.lock_version in the earlier migration: two staff editing the
-- same draft at once would otherwise silently overwrite each other with no warning, and
-- a DATETIME-precision updated_at comparison can't reliably catch edits within the same
-- second.
ALTER TABLE dispatches ADD COLUMN lock_version INT UNSIGNED NOT NULL DEFAULT 0;
