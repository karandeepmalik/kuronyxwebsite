-- Adds a 'vet' actor type to the audit log so a veterinarian's own actions (login, logout,
-- submitting a case, downloading their documents, activating their account) are recorded as
-- theirs - with their vet_accounts id in actor_id - instead of as an anonymous 'public' visitor.
-- MUST be applied before the code that writes 'vet' goes live, or those inserts will fail.
ALTER TABLE audit_log MODIFY COLUMN actor_type ENUM('staff','system','public','vet') NOT NULL;
