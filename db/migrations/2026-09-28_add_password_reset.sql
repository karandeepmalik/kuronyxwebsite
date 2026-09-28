-- Self-service password reset for staff and veterinarian accounts (hashed one-time token,
-- short expiry). Safe to run once against an already-provisioned database.
ALTER TABLE staff_users  ADD COLUMN password_reset_token_hash VARCHAR(64) NULL,
                         ADD COLUMN password_reset_expires_at DATETIME NULL;
ALTER TABLE vet_accounts ADD COLUMN password_reset_token_hash VARCHAR(64) NULL,
                         ADD COLUMN password_reset_expires_at DATETIME NULL;
