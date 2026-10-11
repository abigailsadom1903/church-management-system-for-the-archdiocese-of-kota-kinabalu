-- Demo Public User for the login page's "Demo accounts" buttons (local testing only).
-- For databases imported before this row existed:
--   mysql -u root church_management_system < database/demo_user.sql
-- Login: demo.user@example.com / Password123!   Safe to run more than once.
USE church_management_system;

INSERT INTO user (admin_id, church_id, user_name, user_email, user_password, user_phone, user_verified)
SELECT a.admin_id, a.church_id, 'Abigail Robert', 'demo.user@example.com',
       '$2y$10$UwZarrCQZMtfcOewuvZOju2jCrIQdi.GglqJVigLpGvcVdhY9ftyy', '+60 12-345 6789', 1
  FROM admin a
 WHERE a.admin_email = 'admin.sacredheart@akk.org'
   AND NOT EXISTS (SELECT 1 FROM user WHERE user_email = 'demo.user@example.com');
