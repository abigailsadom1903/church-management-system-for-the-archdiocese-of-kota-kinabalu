-- Church Information: a 360° virtual tour per church, using Google Maps' own 360° views (Street View or a 360° photo).
-- Admins paste the Google Maps "Share > Embed a map" code on the Church Information page; only the embed address
-- (https://www.google.com/maps/embed?pb=...) is stored. Churches without one get a "Look around in Street View" button.
-- Run on any database (safe to run again):
--   mysql -u root church_management_system < database/church_virtual_tour.sql
USE church_management_system;

ALTER TABLE church_info
    ADD COLUMN IF NOT EXISTS church_virtual_tour VARCHAR(1000) NULL AFTER church_history_source;
