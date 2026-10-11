-- Catechesis attendance by date: one row per student per lesson date, marked present or absent.
-- For databases that already ran catechesis.sql before this existed (run ONCE):
--   mysql -u root church_management_system < database/catechesis_attendance.sql
-- catechesis.sql / schema.sql already include it for fresh installs.
USE church_management_system;

-- Lessons that were counted before dates were kept (the old +/- counter). They still count towards progress.
ALTER TABLE catechesis_enrolments
    ADD COLUMN attendance_undated SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER attendance;
UPDATE catechesis_enrolments SET attendance_undated = attendance, updated_at = updated_at;

CREATE TABLE IF NOT EXISTS catechesis_attendance (
    attendance_id INT AUTO_INCREMENT PRIMARY KEY,
    enrolment_id  INT NOT NULL,
    session_date  DATE NOT NULL,                          -- the day of the lesson
    status        ENUM('present', 'absent') NOT NULL,
    marked_by     INT NULL,                               -- the admin who marked it
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_enrolment_date (enrolment_id, session_date),   -- one mark per student per day
    CONSTRAINT fk_catatt_enrolment FOREIGN KEY (enrolment_id) REFERENCES catechesis_enrolments (enrolment_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
