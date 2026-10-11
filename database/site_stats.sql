-- Editable figures shown on the public Home page (edited by the Super Admin).
-- For databases imported before this table existed:
--   mysql -u root church_management_system < database/site_stats.sql
-- It is also appended to schema.sql for fresh installs.
USE church_management_system;

CREATE TABLE IF NOT EXISTS site_stats (
    stat_key    VARCHAR(30) PRIMARY KEY,
    stat_label  VARCHAR(50) NOT NULL,
    stat_value  INT UNSIGNED NOT NULL DEFAULT 0,
    sort_order  TINYINT NOT NULL DEFAULT 0,
    updated_by  INT NULL,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_stats_admin FOREIGN KEY (updated_by) REFERENCES admin(admin_id) ON DELETE SET NULL
);

INSERT IGNORE INTO site_stats (stat_key, stat_label, stat_value, sort_order) VALUES
('population',  'Catholic Population', 250255, 1),
('parishes',    'Parishes',            23,     2),
('outstations', 'Outstations',         284,    3),
('priests',     'Priests',             45,     4);
