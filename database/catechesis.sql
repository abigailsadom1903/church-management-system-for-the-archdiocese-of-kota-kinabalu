-- Catechesis programs (run by a church) and the enrolments of parishioners in them.
-- For databases that already exist (run ONCE):
--   mysql -u root church_management_system < database/catechesis.sql
USE church_management_system;

CREATE TABLE IF NOT EXISTS catechesis_programs (
    program_id     INT AUTO_INCREMENT PRIMARY KEY,
    church_id      INT NOT NULL,
    title          VARCHAR(100) NOT NULL,
    category       VARCHAR(40)  NOT NULL,               -- Children & Youth, Conversion, Growth, ...
    description    TEXT NULL,
    photo          VARCHAR(100) NULL,                   -- cover photo: file name inside uploads/catechesis_photos/
    frequency      ENUM('weekly', 'monthly_last') NOT NULL DEFAULT 'weekly',   -- every week, or the last <weekday> of the month
    weekday        TINYINT NOT NULL DEFAULT 0,          -- 0 = Sunday ... 6 = Saturday
    start_time     TIME NULL,
    end_time       TIME NULL,
    location       VARCHAR(120) NULL,
    total_sessions SMALLINT UNSIGNED NOT NULL DEFAULT 20,   -- lessons in the whole programme (progress = attendance / this)
    term_label     VARCHAR(30) NULL,                    -- e.g. "2026", shown after the title on the overview page
    notes          TEXT NULL,                           -- Church Admin's freeform update for parishioners (next lesson changes, etc.)
    notes_updated_at TIMESTAMP NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_church (church_id),
    CONSTRAINT fk_catprog_church FOREIGN KEY (church_id) REFERENCES church_info (church_info_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS catechesis_enrolments (
    enrolment_id INT AUTO_INCREMENT PRIMARY KEY,
    program_id   INT NOT NULL,
    user_id      INT NOT NULL,                          -- the parishioner who enrolled
    church_id    INT NOT NULL,                          -- the programme's church
    status       ENUM('draft', 'active', 'completed', 'dropped') NOT NULL DEFAULT 'draft',
    details      TEXT NULL,                             -- the registration form answers, as JSON
    files        TEXT NULL,                             -- uploaded documents (private, in uploads/sacrament_docs), as JSON
    attendance   SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- lessons attended = attendance_undated + the "present" rows in catechesis_attendance
    attendance_undated SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- lessons counted before dates were kept
    joined_at    DATETIME NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_program_user (program_id, user_id),   -- one enrolment per person per programme
    KEY idx_church (church_id),
    CONSTRAINT fk_catenr_program FOREIGN KEY (program_id) REFERENCES catechesis_programs (program_id) ON DELETE CASCADE,
    CONSTRAINT fk_catenr_user    FOREIGN KEY (user_id)    REFERENCES user (user_id)                    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Attendance by date: one row per student per lesson date, marked present or absent.
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

-- The three programs from the design for Sacred Heart Cathedral (edit or delete them from Catechesis Management).
INSERT INTO catechesis_programs (church_id, title, category, description, frequency, weekday, start_time, end_time, location, total_sessions, term_label)
SELECT c.church_info_id, p.title, p.category, p.description, p.frequency, p.weekday, p.start_time, p.end_time, p.location, p.total_sessions, p.term_label
  FROM church_info c
  JOIN (
        SELECT 'Sunday School' AS title, 'Children & Youth' AS category,
               'Foundational religious education for children aged 5-16, following the liturgical year.' AS description,
               'weekly' AS frequency, 0 AS weekday, '08:30:00' AS start_time, '10:00:00' AS end_time, 'Parish Community Hall' AS location, 40 AS total_sessions, '2026' AS term_label
        UNION ALL SELECT 'RCIA', 'Conversion',
               'Rite of Christian Initiation for Adults. A journey of discovery for those seeking full communion with the Church.',
               'weekly', 2, '19:30:00', '21:00:00', 'Cathedral Meeting Room', 24, '2026'
        UNION ALL SELECT 'Adult Faith Formation', 'Growth',
               'Monthly seminars and study groups focused on scripture, theology, and the social teachings of the Church.',
               'monthly_last', 6, NULL, NULL, 'Main Auditorium', 12, '2026'
       ) p
 WHERE c.church_name = 'Sacred Heart Cathedral'
   AND NOT EXISTS (SELECT 1 FROM catechesis_programs x WHERE x.church_id = c.church_info_id);
