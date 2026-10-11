-- Weekly Collection: the total the parish collects each week (Sunday offering, second collection...),
-- entered by the church's admin and shown to parishioners as a chart on the Donation & Offering page.
-- For databases imported before this existed (run ONCE):
--   mysql -u root church_management_system < database/weekly_collections.sql
-- schema.sql already includes it for fresh installs.
USE church_management_system;

CREATE TABLE IF NOT EXISTS weekly_collections (
    collection_id INT AUTO_INCREMENT PRIMARY KEY,
    church_id     INT NOT NULL,
    week_date     DATE NOT NULL,                              -- the Sunday of that week
    amount        DECIMAL(12,2) NOT NULL,
    note          VARCHAR(150) NULL,                          -- e.g. "Includes second collection for missions"
    created_by    INT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wc_church_week (church_id, week_date),
    CONSTRAINT fk_wc_church FOREIGN KEY (church_id)  REFERENCES church_info(church_info_id) ON DELETE CASCADE,
    CONSTRAINT fk_wc_admin  FOREIGN KEY (created_by) REFERENCES admin(admin_id)             ON DELETE SET NULL
);
