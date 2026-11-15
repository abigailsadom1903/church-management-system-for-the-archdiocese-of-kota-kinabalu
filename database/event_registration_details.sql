-- Event registration: the extra answers (group, parish, other participants, preferences), the admission
-- pass code shown as a QR code, and when the person was checked in at the door.
-- For databases that already have event_registrations (run ONCE):
--   mysql -u root church_management_system < database/event_registration_details.sql
-- (The site also adds these columns by itself the first time a registration page opens.)
-- events.sql / schema.sql already include them for fresh installs.
USE church_management_system;

ALTER TABLE event_registrations
    ADD COLUMN details       TEXT NULL     AFTER people,          -- JSON: type, parish_id, participants[], first_time, questions, access
    ADD COLUMN pass_code     CHAR(12) NULL AFTER details,         -- secret code on the admission pass (QR); NULL for older/admin-added rows
    ADD COLUMN checked_in_at DATETIME NULL AFTER reminded_at,     -- when the pass was scanned at the door
    ADD UNIQUE KEY uq_reg_pass (pass_code);
