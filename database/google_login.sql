-- Sign in / register with Google: remembers which Google account belongs to which Public User.
-- For databases imported before this existed (run ONCE):
--   mysql -u root church_management_system < database/google_login.sql
-- schema.sql already includes it for fresh installs.
USE church_management_system;

ALTER TABLE user
    ADD COLUMN google_sub VARCHAR(64) NULL AFTER user_password,   -- Google's permanent account id ("sub")
    ADD UNIQUE KEY uq_user_google (google_sub);
