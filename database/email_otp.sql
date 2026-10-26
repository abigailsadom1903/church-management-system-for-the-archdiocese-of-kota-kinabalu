-- Email verification by a 6-digit code (OTP) instead of a link: the code is typed on verify-email.php.
-- verify_token_hash now holds the SHA-256 of the user id and the code; these columns limit how long and how often it can be tried.
-- For databases created before these columns existed (safe to run more than once):
--   mysql -u root church_management_system < database/email_otp.sql
-- schema.sql already includes them for fresh installs.
USE church_management_system;

ALTER TABLE user
    ADD COLUMN IF NOT EXISTS verify_expires  DATETIME NULL AFTER verify_token_hash,                          -- the code stops working after this
    ADD COLUMN IF NOT EXISTS verify_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER verify_expires;        -- wrong tries on the current code

-- Codes from the old links cannot be typed in; clear them so the next email starts fresh.
UPDATE user SET verify_token_hash = NULL, updated_at = updated_at WHERE verify_expires IS NULL;
