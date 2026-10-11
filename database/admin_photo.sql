-- Admin accounts: their own profile photo (uploaded on account.php). Without one, a Church Admin shows their
-- church's photo and the Super Admin the Catholic Archdiocesan Centre (see admin_profile_photo() in includes/ui.php).
-- For databases imported before this existed (run ONCE):
--   mysql -u root church_management_system < database/admin_photo.sql
-- schema.sql already includes it for fresh installs.
USE church_management_system;

ALTER TABLE admin
    ADD COLUMN admin_photo VARCHAR(100) NULL;   -- file name inside uploads/admin_photos/
