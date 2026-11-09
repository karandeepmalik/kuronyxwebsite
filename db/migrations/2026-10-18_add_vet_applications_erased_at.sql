-- Marks a veterinary application whose personal data has been erased (see
-- includes/data-protection.php erase_vet_application() and admin/data-erasure/). NULL = not erased.
-- Without this column the veterinary-application erasure and the read-only "erased" view of an
-- application cannot work (every page that reads vet_applications selects it); run it BEFORE deploying.
ALTER TABLE vet_applications ADD COLUMN erased_at DATETIME NULL;
