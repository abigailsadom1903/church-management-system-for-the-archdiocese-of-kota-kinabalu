-- Email verification: the link in the welcome email sets user_verified = 1.
-- For databases created before this column existed (run ONCE):
--   mysql -u root church_management_system < database/email_verification.sql
-- schema.sql already includes it for fresh installs.
USE church_management_system;

ALTER TABLE user
    ADD COLUMN verify_token_hash CHAR(64) NULL AFTER user_verified;   -- SHA-256 of the token in the verification link
