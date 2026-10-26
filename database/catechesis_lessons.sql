-- Catechesis lesson materials: the Church Admin adds lessons (text, a video link, a handout) to a program,
-- and each enrolled parishioner can open them and mark them as done.
-- For databases created before this existed (safe to run more than once):
--   mysql -u root church_management_system < database/catechesis_lessons.sql
-- schema.sql already includes it for fresh installs.
USE church_management_system;

CREATE TABLE IF NOT EXISTS catechesis_lessons (
    lesson_id    INT AUTO_INCREMENT PRIMARY KEY,
    program_id   INT NOT NULL,
    position     SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- order in the program (1, 2, 3 ...)
    title        VARCHAR(150) NOT NULL,
    summary      VARCHAR(300) NULL,                     -- one line shown in the lesson list
    body         MEDIUMTEXT NULL,                       -- the lesson text (plain text; blank lines start a new paragraph)
    video_url    VARCHAR(300) NULL,                     -- YouTube / Vimeo (shown in the page) or any other link
    file         VARCHAR(60) NULL,                      -- handout: file name inside uploads/catechesis_lessons/ (private)
    file_name    VARCHAR(150) NULL,                     -- the handout's original name, for the download
    is_published TINYINT(1) NOT NULL DEFAULT 1,         -- 0 = draft, only the admins see it
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_program (program_id, position),
    CONSTRAINT fk_catles_program FOREIGN KEY (program_id) REFERENCES catechesis_programs (program_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which lessons a student has marked as done.
CREATE TABLE IF NOT EXISTS catechesis_lesson_progress (
    enrolment_id INT NOT NULL,
    lesson_id    INT NOT NULL,
    completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (enrolment_id, lesson_id),
    KEY idx_lesson (lesson_id),
    CONSTRAINT fk_catlp_enrolment FOREIGN KEY (enrolment_id) REFERENCES catechesis_enrolments (enrolment_id) ON DELETE CASCADE,
    CONSTRAINT fk_catlp_lesson    FOREIGN KEY (lesson_id)    REFERENCES catechesis_lessons (lesson_id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
