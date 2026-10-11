-- Digital Bulletins tab: a topic to search on and an optional cover image for each bulletin.
-- For databases that already ran announcements.sql before these columns existed (run ONCE):
--   mysql -u root church_management_system < database/bulletins_filters.sql
-- announcements.sql / schema.sql already include them for fresh installs.
USE church_management_system;

ALTER TABLE bulletins
    ADD COLUMN topic       VARCHAR(100) NULL AFTER title,        -- e.g. "4th Sunday of Easter"
    ADD COLUMN cover_image VARCHAR(150) NULL AFTER file_name;    -- image file name inside uploads/bulletins/
