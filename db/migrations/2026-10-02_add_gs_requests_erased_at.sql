-- Marks a GS-441524 case whose personal data has been erased (see
-- includes/data-protection.php and admin/data-erasure/). NULL = not erased. Without this
-- column the data-erasure admin page cannot run; every other page is unaffected.
ALTER TABLE gs_requests ADD COLUMN erased_at DATETIME NULL;
