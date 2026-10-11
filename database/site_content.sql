-- Editable text of the public site's "About Us" section and the footer's contact details
-- (edited by the Super Admin on superadmin/about.php). A missing row falls back to the built-in text.
-- For databases imported before this existed (run ONCE):
--   mysql -u root church_management_system < database/site_content.sql
-- schema.sql already includes it for fresh installs.
USE church_management_system;

CREATE TABLE IF NOT EXISTS site_content (
    content_key   VARCHAR(40) PRIMARY KEY,
    content_value TEXT NOT NULL,
    updated_by    INT NULL,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_content_admin FOREIGN KEY (updated_by) REFERENCES admin(admin_id) ON DELETE SET NULL
);
