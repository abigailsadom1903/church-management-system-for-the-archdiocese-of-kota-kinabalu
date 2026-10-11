-- Pastoral & Spiritual Care requests (Mass Intentions, Prayer Request, Counselling Session, Sick Visit).
-- For databases that already exist (run ONCE):
--   mysql -u root church_management_system < database/pastoral.sql
USE church_management_system;

CREATE TABLE IF NOT EXISTS pastoral_requests (
    request_id  INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,                       -- the parishioner who asked
    church_id   INT NOT NULL,                       -- always the parishioner's home parish, never taken from the form
    service     VARCHAR(30) NOT NULL,               -- mass_intention, prayer, counselling, sick_visit
    details     TEXT NULL,                          -- the form answers, as JSON
    files       TEXT NULL,                          -- uploaded receipt (private, in uploads/sacrament_docs), as JSON
    submitted_at DATETIME NULL,
    status      ENUM('draft', 'submitted', 'review', 'given', 'declined') NOT NULL DEFAULT 'draft',
    admin_note  VARCHAR(255) NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_user (user_id),
    KEY idx_church (church_id, status),
    CONSTRAINT fk_pcreq_user   FOREIGN KEY (user_id)   REFERENCES user (user_id)               ON DELETE CASCADE,
    CONSTRAINT fk_pcreq_church FOREIGN KEY (church_id) REFERENCES church_info (church_info_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- For a database created with the first version of this file (safe to run again):
ALTER TABLE pastoral_requests
    ADD COLUMN IF NOT EXISTS files TEXT NULL AFTER details,
    ADD COLUMN IF NOT EXISTS submitted_at DATETIME NULL AFTER files,
    MODIFY status ENUM('draft', 'submitted', 'review', 'given', 'declined') NOT NULL DEFAULT 'draft';
