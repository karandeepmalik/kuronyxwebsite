-- Adds an optimistic-lock token for admin/gs-requests/view.php's update_final and
-- assign_staff actions, which previously did a blind UPDATE with no concurrency check at
-- all — two staff editing the same case's formulation or assignment around the same time
-- would silently overwrite each other with no warning. updated_at was tried first, but
-- DATETIME here has only second-level precision, so two edits landing within the same
-- second would carry an identical "expected" value and the race wouldn't actually be
-- caught. lock_version is bumped by exactly 1 on every such write instead, so it can't
-- collide regardless of timing. Existing rows default to 0, which is fine — the first
-- edit after this migration will just establish the real starting value.
ALTER TABLE gs_requests ADD COLUMN lock_version INT UNSIGNED NOT NULL DEFAULT 0;
