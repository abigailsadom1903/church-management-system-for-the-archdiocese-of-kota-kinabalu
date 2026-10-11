-- Announcement admin screens: announcements can be pinned to the top of a church's list.
-- For databases that already ran announcements.sql before this column existed (run ONCE):
--   mysql -u root church_management_system < database/announcement_admin.sql
-- announcements.sql / schema.sql already include it for fresh installs.
USE church_management_system;

ALTER TABLE announcements
    ADD COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 0 AFTER is_urgent;   -- pinned ones are listed first
