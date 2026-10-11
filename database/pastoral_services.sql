-- Pastoral & Spiritual Care: the services each church offers. A Church Admin can rename, describe, add a photo to,
-- close, remove and add services for their own church (pastoral-services.php); the Super Admin can only look.
-- For databases that already exist (safe to run more than once):
--   mysql -u root church_management_system < database/pastoral_services.sql
-- schema.sql already creates the table for fresh installs.
--
-- Nothing is inserted here: the six standard services (see PASTORAL in includes/pastoral_helpers.php) are added for
-- a church the first time its services are read, so every church starts with the same list.
USE church_management_system;

CREATE TABLE IF NOT EXISTS pastoral_services (
    service_id  INT AUTO_INCREMENT PRIMARY KEY,
    church_id   INT NOT NULL,
    service_key VARCHAR(30) NOT NULL,               -- a standard form (mass_intention, prayer, ...) or c_<random> for one the church added
    title       VARCHAR(60) NOT NULL,
    description VARCHAR(255) NOT NULL,
    photo       VARCHAR(100) NULL,                  -- cover photo: file name inside uploads/pastoral_photos/
    is_open     TINYINT(1) NOT NULL DEFAULT 1,      -- 0 = shown, but not taking requests at the moment
    sort_order  SMALLINT NOT NULL DEFAULT 0,
    deleted_at  DATETIME NULL,                      -- removed by the church: hidden, kept so old requests still show its name
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_church_service (church_id, service_key),
    CONSTRAINT fk_pcsvc_church FOREIGN KEY (church_id) REFERENCES church_info (church_info_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
