-- Announcement & Bulletin page: announcements, digital bulletins and email subscribers.
-- For databases imported before these tables existed:
--   mysql -u root church_management_system < database/announcements.sql
-- Safe to run more than once. (schema.sql already includes them for fresh installs.)
USE church_management_system;

-- Announcements shown on a church's page while active:
-- publish_date <= today and (expires_date is empty or >= today).
CREATE TABLE IF NOT EXISTS announcements (
    announcement_id INT AUTO_INCREMENT PRIMARY KEY,
    church_id       INT NOT NULL,
    title           VARCHAR(150) NOT NULL,
    body            TEXT NOT NULL,
    category        VARCHAR(50)  NOT NULL DEFAULT 'General',   -- e.g. Liturgical, Community
    is_urgent       TINYINT(1)   NOT NULL DEFAULT 0,
    is_pinned       TINYINT(1)   NOT NULL DEFAULT 0,           -- pinned ones are listed first
    publish_date    DATE NOT NULL,
    expires_date    DATE NULL,
    notified_at     DATETIME NULL,                             -- when it was emailed to subscribers (NULL = not yet)
    created_by      INT NULL,                                  -- the admin who posted it
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ann_church_date (church_id, publish_date),
    CONSTRAINT fk_ann_church FOREIGN KEY (church_id) REFERENCES church_info(church_info_id) ON DELETE CASCADE,
    CONSTRAINT fk_ann_admin  FOREIGN KEY (created_by) REFERENCES admin(admin_id) ON DELETE SET NULL
);

-- Weekly / special bulletins, stored as PDF files in uploads/bulletins/.
CREATE TABLE IF NOT EXISTS bulletins (
    bulletin_id   INT AUTO_INCREMENT PRIMARY KEY,
    church_id     INT NOT NULL,
    title         VARCHAR(150) NOT NULL,
    topic         VARCHAR(100) NULL,                            -- e.g. "4th Sunday of Easter"
    language      VARCHAR(5) NOT NULL DEFAULT 'en',               -- en, ms, zh or kzj (see BULLETIN_LANGUAGES)
    bulletin_date DATE NOT NULL,
    file_name     VARCHAR(150) NOT NULL,
    cover_image   VARCHAR(150) NULL,                            -- image file name inside uploads/bulletins/
    created_by    INT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bul_church_date (church_id, bulletin_date),
    CONSTRAINT fk_bul_church FOREIGN KEY (church_id) REFERENCES church_info(church_info_id) ON DELETE CASCADE,
    CONSTRAINT fk_bul_admin  FOREIGN KEY (created_by) REFERENCES admin(admin_id) ON DELETE SET NULL
);

-- "Stay Updated" sign-ups: one row per email per church.
CREATE TABLE IF NOT EXISTS newsletter_subscribers (
    subscriber_id INT AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(100) NOT NULL,
    church_id     INT NOT NULL,
    unsub_token   CHAR(32) NULL,                                -- secret in the email unsubscribe link
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_subscriber (email, church_id),
    UNIQUE KEY uq_unsub_token (unsub_token),
    CONSTRAINT fk_sub_church FOREIGN KEY (church_id) REFERENCES church_info(church_info_id) ON DELETE CASCADE
);

-- The example announcement from the page design, for Sacred Heart Cathedral.
INSERT INTO announcements (church_id, title, body, category, is_urgent, publish_date)
SELECT c.church_info_id, 'Parish Family Day 2026: Registration Now Open',
       'The annual Parish Family Day will be held on the last Sunday of May. We invite all families to register their participation at the parish office by the end of this week. Volunteers are also needed for various committees.',
       'Liturgical', 1, '2026-04-25'
  FROM church_info c
 WHERE c.church_name = 'Sacred Heart Cathedral'
   AND NOT EXISTS (SELECT 1 FROM announcements a WHERE a.church_id = c.church_info_id AND a.title = 'Parish Family Day 2026: Registration Now Open');
