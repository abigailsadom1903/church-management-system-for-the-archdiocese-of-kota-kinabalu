-- Church Information: a small photo gallery per church (interior, altar, feast days ...), shown under the History.
-- Photos are uploaded by the church's admins on the Church Information page; files live in uploads/church_gallery/.
-- Run on any database (safe to run again):
--   mysql -u root church_management_system < database/church_gallery.sql
USE church_management_system;

CREATE TABLE IF NOT EXISTS church_gallery (
    photo_id    INT AUTO_INCREMENT PRIMARY KEY,
    church_id   INT NOT NULL,
    photo_file  VARCHAR(100) NOT NULL,          -- file name inside uploads/church_gallery/
    caption     VARCHAR(120) NULL,              -- e.g. "Altar", "Feast day 2025"
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_church_gallery_church (church_id),
    CONSTRAINT fk_church_gallery_church FOREIGN KEY (church_id) REFERENCES church_info (church_info_id) ON DELETE CASCADE
);
