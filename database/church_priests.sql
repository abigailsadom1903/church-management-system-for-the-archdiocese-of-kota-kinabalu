-- Parish priests: a church can have several (parish priest, assistant priests...), each with an optional photo.
-- Replaces the single church_info.church_priest / church_priest_title columns, which are copied over and cleared.
-- For databases imported before this existed (run ONCE; running it again changes nothing):
--   mysql -u root church_management_system < database/church_priests.sql
-- schema.sql already creates the table for fresh installs, and church_details.sql copies its priests in.
USE church_management_system;

CREATE TABLE IF NOT EXISTS church_priests (
    priest_id    INT AUTO_INCREMENT PRIMARY KEY,
    church_id    INT NOT NULL,
    priest_name  VARCHAR(100) NOT NULL,
    priest_title VARCHAR(50)  NULL,                 -- e.g. Parish Priest, Assistant Priest, Rector
    priest_photo VARCHAR(100) NULL,                 -- file name inside uploads/priest_photos/ (or assets/images/priests/)
    priest_dob      DATE NULL,                      -- shown when the priest is clicked on the Church Information page
    priest_ordained DATE NULL,                      -- ordained priest
    priest_details  TEXT NULL,                      -- anything else, one item per line (other dates, address, Tel, Email)
    sort_order   SMALLINT NOT NULL DEFAULT 0,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_priest_church (church_id, sort_order),
    CONSTRAINT fk_priest_church FOREIGN KEY (church_id) REFERENCES church_info(church_info_id) ON DELETE CASCADE
);

-- Details shown when a priest is clicked, for databases that already had this table (database/seed_clergy.php fills them in).
ALTER TABLE church_priests
    ADD COLUMN IF NOT EXISTS priest_dob      DATE NULL AFTER priest_photo,
    ADD COLUMN IF NOT EXISTS priest_ordained DATE NULL AFTER priest_dob,
    ADD COLUMN IF NOT EXISTS priest_details  TEXT NULL AFTER priest_ordained;

-- Move the old single priest across (only for churches that have no priests yet), then clear the old columns.
INSERT INTO church_priests (church_id, priest_name, priest_title)
SELECT c.church_info_id, c.church_priest, NULLIF(c.church_priest_title, '')
  FROM church_info c
 WHERE c.church_priest IS NOT NULL AND c.church_priest <> ''
   AND NOT EXISTS (SELECT 1 FROM church_priests p WHERE p.church_id = c.church_info_id);
UPDATE church_info SET church_priest = NULL, church_priest_title = NULL WHERE church_priest IS NOT NULL OR church_priest_title IS NOT NULL;
