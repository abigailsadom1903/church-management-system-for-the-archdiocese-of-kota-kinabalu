-- Church Information: the parish's WhatsApp Community invite link (e.g. https://chat.whatsapp.com/AbC123...).
-- For databases imported before this existed (run ONCE):
--   mysql -u root church_management_system < database/church_whatsapp.sql
-- schema.sql already includes it for fresh installs.
USE church_management_system;

ALTER TABLE church_info
    ADD COLUMN church_whatsapp VARCHAR(255) NULL AFTER church_facebook;
