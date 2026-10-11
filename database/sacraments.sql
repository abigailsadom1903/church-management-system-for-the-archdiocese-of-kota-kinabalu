-- Sacrament applications (Baptism first) submitted by parishioners, plus the schedule notes shown beside a form.
-- For databases that already exist (run ONCE):
--   mysql -u root church_management_system < database/sacraments.sql
USE church_management_system;

CREATE TABLE IF NOT EXISTS sacrament_applications (
    application_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT NOT NULL,                          -- the parishioner who applied
    church_id      INT NOT NULL,                          -- always the parishioner's home parish, never taken from the form
    sacrament      VARCHAR(30) NOT NULL,                  -- baptism, eucharist, confirmation, ...
    status         ENUM('draft', 'submitted', 'verifying', 'review', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'draft',
    details        TEXT NULL,                             -- the form answers, as JSON
    files          TEXT NULL,                             -- uploaded document names (in uploads/sacrament_docs, private), as JSON
    admin_note     VARCHAR(255) NULL,
    submitted_at   DATETIME NULL,
    reviewed_by    INT NULL,
    reviewed_at    DATETIME NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_user (user_id),
    KEY idx_church (church_id, status),
    CONSTRAINT fk_sacapp_user   FOREIGN KEY (user_id)   REFERENCES user (user_id)              ON DELETE CASCADE,
    CONSTRAINT fk_sacapp_church FOREIGN KEY (church_id) REFERENCES church_info (church_info_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notes shown in the side card of a sacrament form (plain text, one row per church and sacrament).
CREATE TABLE IF NOT EXISTS sacrament_schedules (
    church_id  INT NOT NULL,
    sacrament  VARCHAR(30) NOT NULL,
    line_date  VARCHAR(150) NULL,    -- e.g. "17 May 2026 (Sun.), 2.00 pm"
    line_brief VARCHAR(200) NULL,    -- e.g. "10 May 2026 (Sun.), 2.00 pm at St. Matthew Room"
    line_close VARCHAR(150) NULL,    -- e.g. "Last date to register is 4 May 2026."
    is_open    TINYINT(1) NOT NULL DEFAULT 1,   -- a Church Admin closes this sacrament's application form when it's full or past its deadline
    PRIMARY KEY (church_id, sacrament),
    CONSTRAINT fk_sacsch_church FOREIGN KEY (church_id) REFERENCES church_info (church_info_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- For a database created before is_open existed (safe to run again).
ALTER TABLE sacrament_schedules ADD COLUMN IF NOT EXISTS is_open TINYINT(1) NOT NULL DEFAULT 1 AFTER line_close;

-- The example schedule from the design for Sacred Heart Cathedral (edit it to the real dates).
INSERT IGNORE INTO sacrament_schedules (church_id, sacrament, line_date, line_brief, line_close)
SELECT church_info_id, 'baptism', '17 May 2026 (Sun.), 2.00 pm', '10 May 2026 (Sun.), 2.00 pm at St. Matthew Room', 'Last date to register is 4 May 2026.'
  FROM church_info WHERE church_name = 'Sacred Heart Cathedral';

-- Anointing of the Sick: the after-hours emergency number shown beside the form (line_date holds the number).
INSERT IGNORE INTO sacrament_schedules (church_id, sacrament, line_date, line_brief, line_close)
SELECT church_info_id, 'anointing', '+60 88 123 4567', NULL, NULL
  FROM church_info WHERE church_name = 'Sacred Heart Cathedral';

-- Confession times set up by a Church Admin: one row per day + time. max_bookings people can book the same time.
CREATE TABLE IF NOT EXISTS confession_slots (
    slot_id      INT AUTO_INCREMENT PRIMARY KEY,
    church_id    INT NOT NULL,
    slot_date    DATE NOT NULL,
    slot_time    TIME NOT NULL,
    location     VARCHAR(100) NULL,                 -- e.g. "Confessional, Main Church"
    max_bookings TINYINT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uq_slot (church_id, slot_date, slot_time),
    CONSTRAINT fk_confslot_church FOREIGN KEY (church_id) REFERENCES church_info (church_info_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- For a database created with the first version of this file: allow the two review stages (safe to run again).
ALTER TABLE sacrament_applications
    MODIFY status ENUM('draft', 'submitted', 'verifying', 'review', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'draft';