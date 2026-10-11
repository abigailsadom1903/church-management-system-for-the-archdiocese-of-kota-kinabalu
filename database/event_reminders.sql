-- Event reminders: when each registration was emailed its "the event is tomorrow" reminder.
-- For databases that already have event_registrations (run ONCE):
--   mysql -u root church_management_system < database/event_reminders.sql
-- events.sql / schema.sql already include it for fresh installs.
USE church_management_system;

ALTER TABLE event_registrations
    ADD COLUMN reminded_at DATETIME NULL AFTER people;   -- NULL = the automatic reminder has not been sent yet
