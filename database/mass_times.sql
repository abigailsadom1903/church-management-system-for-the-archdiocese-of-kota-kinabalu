-- Sunday Mass times for Sacred Heart Cathedral, taken from the design (7:30 AM Mandarin, 9:30 AM English, 11:30 AM Bahasa Malaysia).
-- They repeat every Sunday and appear on the Church Calendar and in the Church Assistant's answers. Edit them from Church Calendar.
-- (run ONCE:  mysql -u root church_management_system < database/mass_times.sql)
USE church_management_system;

INSERT INTO events (church_id, title, category, event_type, event_date, start_time, end_time, is_all_day, location, repeat_weekly, created_by)
SELECT c.church_info_id, m.title, 'mass', 'Mass', '2026-01-04', m.start_time, m.end_time, 0, 'Sacred Heart Cathedral', 1,
       COALESCE((SELECT a.admin_id FROM admin a WHERE a.church_id = c.church_info_id LIMIT 1), 1)
  FROM church_info c
  JOIN (
        SELECT 'Sunday Mass (Mandarin)' AS title, '07:30:00' AS start_time, '08:30:00' AS end_time
        UNION ALL SELECT 'Sunday Mass (English)', '09:30:00', '10:30:00'
        UNION ALL SELECT 'Sunday Mass (Bahasa Malaysia)', '11:30:00', '12:30:00'
       ) m
 WHERE c.church_name = 'Sacred Heart Cathedral'
   AND NOT EXISTS (SELECT 1 FROM events e WHERE e.church_id = c.church_info_id AND e.category = 'mass');
