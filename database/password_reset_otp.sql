-- Forgot Password by a 6-digit code (OTP) instead of a link: the code is typed on reset-password.php.
-- For databases created before this column existed (safe to run more than once):
--   mysql -u root church_management_system < database/password_reset_otp.sql
-- schema.sql already includes it for fresh installs.
USE church_management_system;

ALTER TABLE password_resets
    ADD COLUMN IF NOT EXISTS attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER expires_at;   -- wrong tries on this code

-- Links sent before the change can no longer be used.
DELETE FROM password_resets WHERE attempts = 0 AND created_at < NOW();
