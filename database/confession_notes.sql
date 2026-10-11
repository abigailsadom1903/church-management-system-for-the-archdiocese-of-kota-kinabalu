-- Notes a Church Admin writes for parishioners on the Confession booking page (confession-manage.php),
-- e.g. "Please arrive 10 minutes early". Stored on the church's 'confession' row of sacrament_schedules.
-- For databases imported before this existed (run ONCE):
--   mysql -u root church_management_system < database/confession_notes.sql
-- schema.sql already includes it for fresh installs.
USE church_management_system;

ALTER TABLE sacrament_schedules
    ADD COLUMN notes TEXT NULL AFTER line_close;   -- free text shown to parishioners (up to 1,000 characters)
