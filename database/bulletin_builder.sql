-- Bulletin Builder: a weekly bulletin made in the portal from announcements, the week's schedule and notices
-- (bulletin-builder.php), shown and printed / saved as PDF by bulletin-view.php.
-- A generated bulletin keeps a snapshot of what it showed (content, JSON) instead of an uploaded PDF file,
-- so the archive does not change when an announcement or event is edited later.
-- For databases created before these changes (safe to run more than once):
--   mysql -u root church_management_system < database/bulletin_builder.sql
-- schema.sql already includes them for fresh installs.
USE church_management_system;

ALTER TABLE bulletins
    MODIFY COLUMN file_name VARCHAR(150) NULL,                          -- uploaded PDF; NULL for a generated bulletin
    ADD COLUMN IF NOT EXISTS content MEDIUMTEXT NULL AFTER cover_image; -- generated bulletin: its snapshot (JSON)
