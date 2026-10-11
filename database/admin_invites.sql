-- Admin accounts created by the Super Admin (superadmin/admins.php) get a one-time link to set their own
-- password (admin-setup.php). Only a hash of the link's token is stored; the link expires after 48 hours.
-- The same link is used to reset a forgotten admin password.
-- For databases imported before this existed (run ONCE):
--   mysql -u root church_management_system < database/admin_invites.sql
-- schema.sql already includes it for fresh installs.
USE church_management_system;

ALTER TABLE admin
    ADD COLUMN invite_token_hash CHAR(64) NULL,    -- sha256 of the set-password link's token (NULL = no link waiting)
    ADD COLUMN invite_expires    DATETIME NULL;
