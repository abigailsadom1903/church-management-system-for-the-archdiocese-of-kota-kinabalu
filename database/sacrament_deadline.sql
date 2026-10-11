-- Sacrament forms: the last day applications are accepted ("Applications open until ...").
-- After this date the form closes by itself, as if the "Applications open" switch had been turned off.
-- For databases imported before this existed (run ONCE):
--   mysql -u root church_management_system < database/sacrament_deadline.sql
-- schema.sql already includes it for fresh installs.
USE church_management_system;

ALTER TABLE sacrament_schedules
    ADD COLUMN apply_until DATE NULL AFTER line_close;   -- NULL = no deadline, open until the switch is turned off
