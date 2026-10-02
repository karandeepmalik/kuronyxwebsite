-- Records which verified Brevo sender address an email actually went out from, now
-- that staff pick it explicitly in the compose form instead of it always being the
-- single hardcoded SENDER_EMAIL. Safe to run against an already-provisioned database.
ALTER TABLE case_emails ADD COLUMN sender VARCHAR(190) NULL AFTER staff_id;
