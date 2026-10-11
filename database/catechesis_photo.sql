-- Catechesis programs: an optional cover photo, shown on the program's card.
-- For databases created before this column existed (safe to run more than once):
--   mysql -u root church_management_system < database/catechesis_photo.sql
-- catechesis.sql / schema.sql already include it for fresh installs.
USE church_management_system;

ALTER TABLE catechesis_programs
    ADD COLUMN IF NOT EXISTS photo VARCHAR(100) NULL AFTER description;   -- file name inside uploads/catechesis_photos/
