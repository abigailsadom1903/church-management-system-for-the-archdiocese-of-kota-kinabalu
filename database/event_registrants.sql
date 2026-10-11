-- Event registrations count PEOPLE (a couple registering together is one registrant, two participants),
-- and an email address is optional (walk-ins added by an admin may only have a phone number).
-- For databases that already have event_registrations (run ONCE):
--   mysql -u root church_management_system < database/event_registrants.sql
-- events.sql / schema.sql already include this for fresh installs.
USE church_management_system;

ALTER TABLE event_registrations
    ADD COLUMN people TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER phone,   -- how many people this registration covers
    MODIFY email VARCHAR(100) NULL;                                      -- the unique (event, email) key still stops duplicates when an email is given
