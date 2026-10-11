-- "Stay Updated": email each new announcement to the church's subscribers, with an unsubscribe link.
-- For databases that already ran announcements.sql before these columns existed (run ONCE):
--   mysql -u root church_management_system < database/announcement_emails.sql
-- announcements.sql / schema.sql already include them for fresh installs.
USE church_management_system;

ALTER TABLE announcements
    ADD COLUMN notified_at DATETIME NULL AFTER expires_date;      -- when it was emailed to subscribers (NULL = not yet)

-- Announcements that are already published are not emailed again.
UPDATE announcements SET notified_at = NOW(), updated_at = updated_at WHERE publish_date <= CURDATE();

ALTER TABLE newsletter_subscribers
    ADD COLUMN unsub_token CHAR(32) NULL AFTER church_id,         -- secret in the email's unsubscribe link
    ADD UNIQUE KEY uq_unsub_token (unsub_token);
