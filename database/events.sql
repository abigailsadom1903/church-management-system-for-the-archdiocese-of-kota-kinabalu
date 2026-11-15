-- Church Calendar: parish events / Mass schedule, and the people registered for them.
-- For databases imported before these tables existed:
--   mysql -u root church_management_system < database/events.sql
-- Safe to run more than once. (schema.sql already includes them for fresh installs.)
USE church_management_system;

CREATE TABLE IF NOT EXISTS events (
    event_id              INT AUTO_INCREMENT PRIMARY KEY,
    church_id             INT NOT NULL,
    title                 VARCHAR(150) NOT NULL,
    category              ENUM('mass','event') NOT NULL DEFAULT 'event',   -- the calendar's "Mass Schedule" / "Event" filter
    event_type            VARCHAR(50)  NOT NULL DEFAULT 'Event',           -- label shown on the chip: Mass, Adoration, Meeting, Practice, ...
    event_date            DATE NOT NULL,                                   -- the date, or the first date when it repeats
    start_time            TIME NULL,
    end_time              TIME NULL,
    is_all_day            TINYINT(1) NOT NULL DEFAULT 0,
    location              VARCHAR(150) NULL,
    description           TEXT NULL,
    repeat_weekly         TINYINT(1) NOT NULL DEFAULT 0,                   -- repeats every week on the same weekday
    repeat_until          DATE NULL,                                       -- last date of a weekly repeat (empty = no end)
    registration_required TINYINT(1) NOT NULL DEFAULT 0,                   -- one-off events only
    capacity              INT UNSIGNED NULL,                               -- empty = unlimited
    created_by            INT NULL,
    created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_event_church_date (church_id, event_date),
    CONSTRAINT fk_event_church FOREIGN KEY (church_id)  REFERENCES church_info(church_info_id) ON DELETE CASCADE,
    CONSTRAINT fk_event_admin  FOREIGN KEY (created_by) REFERENCES admin(admin_id)             ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS event_registrations (
    registration_id INT AUTO_INCREMENT PRIMARY KEY,
    event_id        INT NOT NULL,
    user_id         INT NULL,                                              -- set when the person was signed in
    name            VARCHAR(100) NOT NULL,
    email           VARCHAR(100) NULL,                                     -- optional (an admin may add someone by phone only)
    phone           VARCHAR(30)  NULL,
    people          TINYINT UNSIGNED NOT NULL DEFAULT 1,                   -- how many people this registration covers
    details         TEXT NULL,                                             -- JSON: type, parish_id, participants[], first_time, questions, access
    pass_code       CHAR(12) NULL,                                         -- secret code on the admission pass (QR)
    reminded_at     DATETIME NULL,                                         -- when the "event is tomorrow" reminder was emailed
    checked_in_at   DATETIME NULL,                                         -- when the pass was scanned at the door
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_event_email (event_id, email),                           -- one registration per email per event
    UNIQUE KEY uq_reg_pass (pass_code),
    CONSTRAINT fk_reg_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
    CONSTRAINT fk_reg_user  FOREIGN KEY (user_id)  REFERENCES user(user_id)    ON DELETE SET NULL
);
