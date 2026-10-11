-- Digital Bulletins: the language each bulletin is written in (English, Bahasa Malaysia, Mandarin, Kadazan).
-- For databases created before this column existed (run ONCE):
--   mysql -u root church_management_system < database/bulletins_language.sql
-- announcements.sql / schema.sql already include it for fresh installs.
USE church_management_system;

ALTER TABLE bulletins
    ADD COLUMN language VARCHAR(5) NOT NULL DEFAULT 'en' AFTER topic;   -- en, ms, zh or kzj (see BULLETIN_LANGUAGES)
