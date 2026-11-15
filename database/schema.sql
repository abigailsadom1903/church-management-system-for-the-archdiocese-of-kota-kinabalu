-- =====================================================================
-- Web-Based Church Management System - Archdiocese of Kota Kinabalu
-- Database Schema (Module 1 & 2: User Management, Church Information)
-- =====================================================================
--
-- Import this in phpMyAdmin, or run:  mysql -u root -p < schema.sql
--
-- NOTE ON TABLE ORDER:
-- `admin` and `church_info` reference each other (admin.church_id ->
-- church_info, church_info.admin_id -> admin). MySQL can't create two
-- tables that each depend on the other in one CREATE TABLE statement,
-- so we create `admin` first WITHOUT the church_id foreign key, create
-- `church_info` with its foreign key to `admin`, then ALTER `admin`
-- afterwards to add the missing foreign key back in.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS church_management_system;
USE church_management_system;

-- ---------------------------------------------------------------------
-- Table: admin
-- Covers both Super Admin (church_id NULL) and Church Admin
-- (church_id = the one church they manage).
-- ---------------------------------------------------------------------
CREATE TABLE admin (
    admin_id       INT AUTO_INCREMENT PRIMARY KEY,
    admin_name     VARCHAR(100) NOT NULL,
    admin_address  VARCHAR(255),
    admin_contact  VARCHAR(50),
    admin_email    VARCHAR(100) NOT NULL UNIQUE,
    admin_password VARCHAR(255) NOT NULL,
    admin_role     ENUM('super_admin','church_admin') NOT NULL DEFAULT 'church_admin',
    church_id      INT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    admin_photo    VARCHAR(100) NULL,            -- own profile photo, inside uploads/admin_photos/
    invite_token_hash CHAR(64) NULL,             -- one-time "set your password" link (superadmin/admins.php), sha256 of its token
    invite_expires DATETIME NULL
);

-- ---------------------------------------------------------------------
-- Table: church_info
-- ---------------------------------------------------------------------
CREATE TABLE church_info (
    church_info_id         INT AUTO_INCREMENT PRIMARY KEY,
    admin_id               INT,                      -- creator/owner admin (usually the Super Admin who added the church)
    church_name            VARCHAR(100) NOT NULL,
    church_location        VARCHAR(255),             -- the town shown in the Directory (e.g. Likas)
    church_address         VARCHAR(255) NULL,        -- full street address, used for the map
    church_lat             DECIMAL(10,7) NULL,       -- geocoded coordinates, for the Directory's "Nearest Church" feature
    church_lng             DECIMAL(10,7) NULL,
    church_location_verified TINYINT(1) NOT NULL DEFAULT 0,  -- 1 = the point above was checked to be at the church (see church_coordinates.sql)
    church_contact_details VARCHAR(50),              -- phone number
    church_email           VARCHAR(100) NULL,
    church_website         VARCHAR(150) NULL,
    church_facebook        VARCHAR(150) NULL,
    church_whatsapp        VARCHAR(255) NULL,        -- WhatsApp Community invite link (chat.whatsapp.com/...)
    church_office_hours    VARCHAR(255),             -- one line per day range, separated by newlines
    church_history         TEXT NULL,                -- short parish history: paragraphs, then "Year – event" lines (filled by church_history.sql)
    church_history_source  VARCHAR(255) NULL,        -- web page the history was summarised from
    church_virtual_tour    VARCHAR(1000) NULL,       -- Google Maps 360° embed address (maps/embed?pb=...), see church_virtual_tour.sql
    church_priest          VARCHAR(100),             -- (older) only used by church_details.sql, which moves it into church_priests
    church_priest_title    VARCHAR(50)  NULL,
    church_photo           VARCHAR(100) NULL,        -- file name inside assets/images/churches/
    donation_bank_name      VARCHAR(100) NULL,       -- where donors send money (Donation & Offering)
    donation_account_name   VARCHAR(100) NULL,
    donation_account_number VARCHAR(40)  NULL,
    donation_qr_image       VARCHAR(100) NULL,       -- file name inside uploads/donation_qr/
    created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_church_admin FOREIGN KEY (admin_id) REFERENCES admin(admin_id) ON DELETE SET NULL
);

-- A church's priests (several per church, each with an optional photo).
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

-- Photo gallery per church (interior, altar, feast days ...), shown under the History on Church Information.
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

-- Now that church_info exists, add the foreign key admin.church_id was missing.
ALTER TABLE admin
    ADD CONSTRAINT fk_admin_church FOREIGN KEY (church_id) REFERENCES church_info(church_info_id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
-- Table: user
-- A user's "home parish" is expressed indirectly: user.admin_id points
-- to the Church Admin who manages that user's church. To find which
-- church a user belongs to, join user -> admin -> church_info.
-- ---------------------------------------------------------------------
CREATE TABLE user (
    user_id       INT AUTO_INCREMENT PRIMARY KEY,
    admin_id      INT NULL,                          -- (older) the Church Admin of the home parish; church_id below is what the site uses
    church_id     INT NULL,                          -- home parish -> church_info
    user_name     VARCHAR(100) NOT NULL,
    user_email    VARCHAR(100) NOT NULL UNIQUE,
    user_password VARCHAR(255) NOT NULL,
    user_phone    VARCHAR(20),
    date_of_birth DATE NULL,
    user_address  VARCHAR(255) NULL,
    user_photo    VARCHAR(100) NULL,                 -- file name inside uploads/profile_photos/
    user_role     VARCHAR(50) DEFAULT 'user',
    user_verified TINYINT(1) DEFAULT 0,
    verify_token_hash CHAR(64) NULL,               -- SHA-256 of the user id and the 6-digit code in the verification email
    verify_expires  DATETIME NULL,                 -- the code stops working after this
    verify_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- wrong tries on the current code
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_admin  FOREIGN KEY (admin_id)  REFERENCES admin(admin_id) ON DELETE SET NULL,
    CONSTRAINT fk_user_church FOREIGN KEY (church_id) REFERENCES church_info(church_info_id) ON DELETE SET NULL
);

-- =====================================================================
-- SEED DATA
-- Passwords below are real bcrypt hashes of "Password123!" generated
-- with PHP's password_hash(), so you can log in immediately once
-- login.php exists. CHANGE/remove these before your final submission.
-- =====================================================================

-- 1 Super Admin (church_id left NULL: manages the whole Archdiocese, not one church)
INSERT INTO admin (admin_name, admin_email, admin_password, admin_role, church_id)
VALUES ('System Super Admin', 'superadmin@akk.org', '$2y$10$UwZarrCQZMtfcOewuvZOju2jCrIQdi.GglqJVigLpGvcVdhY9ftyy', 'super_admin', NULL);

-- 2 sample churches, created/owned by the Super Admin (admin_id = 1)
INSERT INTO church_info (admin_id, church_name, church_location, church_address, church_contact_details, church_email, church_website, church_facebook, church_office_hours, church_priest, church_priest_title, church_photo)
VALUES
(1, 'Sacred Heart Cathedral', 'Kota Kinabalu', '14B, Jalan Tunku Abdul Rahman, 88000 Kota Kinabalu, Sabah, Malaysia', '+60 88-223 222', 'office@shckk.org', 'www.shckk.org', 'facebook.com/sacredheartcathedralkk', 'Mon - Fri: 8:30 AM - 4:30 PM\nSat: 8:30 AM - 12:30 PM\nSun: Closed', 'Rev. Msgr. Nicholas Huang', 'Rector', 'sacred-heart-cathedral.jpg'),
(1, 'St Simon Church',         'Likas',         NULL, '088-654321', NULL, NULL, NULL, 'Mon-Fri 9am-5pm', 'Fr. Peter Lim', NULL, NULL);

-- The other 21 parishes shown on the public Directory page (church_location = town).
INSERT INTO church_info (admin_id, church_name, church_location) VALUES
(1, 'St Paul Church', 'Dontozidon'),
(1, 'St Catherine Church', 'Inanam'),
(1, 'Good Shepherd Church', 'Manggatal'),
(1, 'Holy Family Church', 'Telipok'),
(1, 'St Thomas Church', 'Kepayan'),
(1, 'Stella Maris Church', 'Tanjung Aru'),
(1, 'Sacred Heart Church', 'Inobong'),
(1, 'St Augustine Church', 'Kinarut'),
(1, 'St Michael Church', 'Penampang'),
(1, 'Holy Rosary Church', 'Limbahau'),
(1, 'St Joseph Church', 'Papar'),
(1, 'Church of Mary Immaculate', 'Bukit Padang'),
(1, 'St Pius X Church', 'Bundu Tuhan'),
(1, 'St Joseph Husband of Mary Church', 'Kiulu'),
(1, 'St Edmund Church', 'Kota Belud'),
(1, 'St Theresa Church', 'Kota Marudu'),
(1, 'St Peter Church', 'Kudat'),
(1, 'Blessed Sacrament Church', 'Labuan'),
(1, 'St Peter Claver Church', 'Ranau'),
(1, 'Holy Nativity Church', 'Terawi'),
(1, 'St John Church', 'Tuaran');

-- 2 Church Admin accounts, one per church, so registration has churches to list
INSERT INTO admin (admin_name, admin_email, admin_password, admin_role, church_id)
VALUES
('Sacred Heart Admin', 'admin.sacredheart@akk.org', '$2y$10$UwZarrCQZMtfcOewuvZOju2jCrIQdi.GglqJVigLpGvcVdhY9ftyy', 'church_admin', 1),
('St. Simon Admin',    'admin.stsimon@akk.org',     '$2y$10$UwZarrCQZMtfcOewuvZOju2jCrIQdi.GglqJVigLpGvcVdhY9ftyy', 'church_admin', 2);

-- 1 demo Public User (home parish = Sacred Heart Cathedral) for the login page's demo buttons
INSERT INTO user (admin_id, church_id, user_name, user_email, user_password, user_phone, user_verified)
VALUES (2, 1, 'Abigail Robert', 'demo.user@example.com', '$2y$10$UwZarrCQZMtfcOewuvZOju2jCrIQdi.GglqJVigLpGvcVdhY9ftyy', '+60 12-345 6789', 1);

-- ---------------------------------------------------------------------
-- Table: password_resets (Forgot Password flow)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
    reset_id     INT AUTO_INCREMENT PRIMARY KEY,
    account_type ENUM('admin','user') NOT NULL,   -- which table account_id points into
    account_id   INT NOT NULL,
    token_hash   CHAR(64) NOT NULL UNIQUE,        -- SHA-256 of the account and the emailed 6-digit code; the code is never stored
    expires_at   DATETIME NOT NULL,
    attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- wrong tries on this code
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reset_account (account_type, account_id)
);

-- ---------------------------------------------------------------------
-- Table: site_stats (editable Home page figures)
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
-- Table: site_content (editable "About Us" section and footer contact details)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_content (
    content_key   VARCHAR(40) PRIMARY KEY,
    content_value TEXT NOT NULL,
    updated_by    INT NULL,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_content_admin FOREIGN KEY (updated_by) REFERENCES admin(admin_id) ON DELETE SET NULL
);

-- ---------------------------------------------------------------------
-- Real parish details (source: catholicadkk.org/parishes, Sept 2026). Unlisted details stay empty.
-- ---------------------------------------------------------------------
UPDATE church_info SET church_priest='Fr Thomas Madanan', church_priest_title='Parish Priest', church_contact_details='087-424285',
  church_address='Jalan OKK Abdullah, P.O. Box 38, 87008 Labuan FT'
 WHERE church_name='Blessed Sacrament Church';

UPDATE church_info SET church_priest='Msgr Nicholas Stephen', church_priest_title='Parish Priest', church_contact_details='088-244345',
  church_address='Jalan Kolam, Bukit Padang, P.O. Box 21283, 88770 Luyang, Kota Kinabalu, Sabah', church_website='https://www.cmikk.org/'
 WHERE church_name='Church of Mary Immaculate';

UPDATE church_info SET church_priest='Fr David Sham', church_priest_title='Parish Priest'
 WHERE church_name='Good Shepherd Church';

UPDATE church_info SET church_priest='Fr David Sham', church_priest_title='Parish Priest', church_contact_details='088-491681',
  church_address='Jalan Tuaran, P.O. Box 10975, 88810 Kota Kinabalu, Sabah'
 WHERE church_name='Holy Family Church';

UPDATE church_info SET church_priest='Fr Thomas Yip', church_priest_title='Parish Priest', church_contact_details='088-761495',
  church_address='P.O. Box 218, 89507 Penampang, Sabah', church_email='hnctterawi@yahoo.com',
  church_website='https://hnctterawi.blogspot.com/p/about-us.html'
 WHERE church_name='Holy Nativity Church';

UPDATE church_info SET church_priest='Msgr Nicholas Stephen', church_priest_title='Parish Priest', church_contact_details='088-913773',
  church_address='Jalan Lama, P.O. Box 9, 89607 Papar, Sabah', church_email='hrc.limbahau@gmail.com'
 WHERE church_name='Holy Rosary Church';

UPDATE church_info SET church_priest='Fr Sunny Chung', church_priest_title='Parish Priest',
  church_address='P.O. Box 1150, 89507 Penampang, Sabah, Malaysia'
 WHERE church_name='Sacred Heart Church';

UPDATE church_info SET church_website='https://www.staugustineparishkinarut.com/'
 WHERE church_name='St Augustine Church';

UPDATE church_info SET church_priest='Fr David Sham', church_priest_title='Parish Priest', church_contact_details='088-421293',
  church_address='Jalan Kiansom, WDT 10, 89359 Inanam, Sabah'
 WHERE church_name='St Catherine Church';

UPDATE church_info SET church_priest='Fr Isidore Gondilou', church_priest_title='Parish Priest', church_contact_details='088-976186',
  church_address='Jalan St Edmund, P.O. Box 50, 89157 Kota Belud, Sabah'
 WHERE church_name='St Edmund Church';

UPDATE church_info SET church_priest='Fr Jalius Sading', church_priest_title='Parish Priest', church_contact_details='088-788472',
  church_address='Jalan Tanjong Lubuaya, P.O. Box 8, 89207 Tuaran, Sabah'
 WHERE church_name='St John Church';

UPDATE church_info SET church_priest='Fr Paul Lo', church_priest_title='Parish Priest', church_contact_details='088-913542',
  church_address='Jalan Pos, P.O. Box 1, 89607 Papar, Sabah'
 WHERE church_name='St Joseph Church';

UPDATE church_info SET church_priest='Fr Erik Jerome', church_priest_title='Parish Priest', church_contact_details='088-783996',
  church_address='P.O. Box 160, Pejabat Pos Mini Kiulu, 89250 Kiulu, Sabah'
 WHERE church_name='St Joseph Husband of Mary Church';

UPDATE church_info SET church_priest='Fr Wilfred Atin', church_priest_title='Parish Priest', church_contact_details='088-711009 / 088-715804',
  church_address='Jalan Dakata, P.O. Box 110, 89507 Penampang, Sabah', church_website='https://www.stmichaelparishpenampang.com/'
 WHERE church_name='St Michael Church';

UPDATE church_info SET church_priest='Msgr Nicholas Stephen', church_priest_title='Parish Priest',
  church_address='Mile 4.5, Jalan Penampang Lama, P.O. Box 11580, 88817 Kota Kinabalu, Sabah'
 WHERE church_name='St Paul Church';

UPDATE church_info SET church_priest='Fr Rayner Bisius', church_priest_title='Parish Priest', church_contact_details='088-611271',
  church_address='Jalan Tanjung Kapur, P.O. Box 26, 89057 Kudat, Sabah'
 WHERE church_name='St Peter Church';

UPDATE church_info SET church_priest='Fr Ambrose Atang', church_priest_title='Parish Priest', church_contact_details='088-875250',
  church_address='P.O. Box 312, 89308 Ranau, Sabah'
 WHERE church_name='St Peter Claver Church';

UPDATE church_info SET church_priest='Fr Frederick Raymond', church_priest_title='Parish Priest', church_contact_details='088-889948 / 088-889942',
  church_address='Jalan Bundu Tuhan, P.O. Box 1032, 89308 Ranau, Sabah'
 WHERE church_name='St Pius X Church';

-- St Simon Church: replaces the placeholder sample priest, phone and office hours.
UPDATE church_info SET church_priest='Fr Michael Modoit', church_priest_title='Parish Priest', church_contact_details='088-212713',
  church_address='P.O. Box 45, Likas, Kota Kinabalu, Sabah', church_website='stsimonlikas.wordpress.com', church_office_hours=NULL
 WHERE church_name='St Simon Church';

UPDATE church_info SET church_priest='Fr Rayner Bisius', church_priest_title='Parish Priest', church_contact_details='088-663075',
  church_address='Jalan Tandek, P.O. Box 369, 89107 Kota Marudu, Sabah'
 WHERE church_name='St Theresa Church';

UPDATE church_info SET church_priest='Fr Rhobby Mojolou', church_priest_title='Parish Priest',
  church_address='Gereja St Thomas, 88200 Kepayan, Kota Kinabalu', church_email='st.thomaskepayan@yahoo.com',
  church_website='https://katolikkpynchurch.blogspot.com/'
 WHERE church_name='St Thomas Church';

UPDATE church_info SET church_priest='Fr Rhobby Mojolou', church_priest_title='Parish Priest', church_contact_details='088-254321',
  church_address='Jalan Tanjung Aru, Kg Baru, P.O. Box 10533, 88806 Kota Kinabalu, Sabah', church_website='stellamarisparish.org'
 WHERE church_name='Stella Maris Church';

-- ---------------------------------------------------------------------
-- Announcement & Bulletin page
-- ---------------------------------------------------------------------
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
    file_name     VARCHAR(150) NULL,                            -- uploaded PDF; NULL for a bulletin made with the Bulletin Builder
    cover_image   VARCHAR(150) NULL,                            -- image file name inside uploads/bulletins/
    content       MEDIUMTEXT NULL,                              -- Bulletin Builder: snapshot of what the bulletin shows (JSON)
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

-- ---------------------------------------------------------------------
-- Donation & Offering
-- ---------------------------------------------------------------------
-- One row per submitted donation. The receipt is a private file in uploads/receipts/ that is only
-- served to the church's admins (receipt.php). Financial records are never deleted with a church:
-- a church that has donations cannot be deleted.
CREATE TABLE IF NOT EXISTS donations (
    donation_id   INT AUTO_INCREMENT PRIMARY KEY,
    church_id     INT NOT NULL,
    donor_user_id INT NULL,                                   -- set when the donor was signed in
    donor_name    VARCHAR(100) NOT NULL,
    donor_phone   VARCHAR(30)  NOT NULL,
    donor_email   VARCHAR(100) NOT NULL,
    purpose       VARCHAR(100) NOT NULL,                      -- e.g. Weekly Offering, Tithe, Building Fund
    message       VARCHAR(500) NULL,
    amount        DECIMAL(12,2) NOT NULL,
    method        ENUM('bank','qr') NOT NULL,
    receipt_file  VARCHAR(150) NOT NULL,
    status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reject_reason VARCHAR(255) NULL,
    reviewed_by   INT NULL,
    reviewed_at   DATETIME NULL,
    email_sent_at DATETIME NULL,                              -- when the donor was told the result
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_don_church_status (church_id, status, created_at),
    INDEX idx_don_email_time (donor_email, created_at),
    CONSTRAINT fk_don_church FOREIGN KEY (church_id)     REFERENCES church_info(church_info_id) ON DELETE RESTRICT,
    CONSTRAINT fk_don_user   FOREIGN KEY (donor_user_id) REFERENCES user(user_id)               ON DELETE SET NULL,
    CONSTRAINT fk_don_admin  FOREIGN KEY (reviewed_by)   REFERENCES admin(admin_id)             ON DELETE SET NULL
);

-- Weekly Collection: each week's total, entered by the church admin and charted for parishioners.
CREATE TABLE IF NOT EXISTS weekly_collections (
    collection_id INT AUTO_INCREMENT PRIMARY KEY,
    church_id     INT NOT NULL,
    week_date     DATE NOT NULL,                              -- the Sunday of that week
    amount        DECIMAL(12,2) NOT NULL,
    note          VARCHAR(150) NULL,
    created_by    INT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wc_church_week (church_id, week_date),
    CONSTRAINT fk_wc_church FOREIGN KEY (church_id)  REFERENCES church_info(church_info_id) ON DELETE CASCADE,
    CONSTRAINT fk_wc_admin  FOREIGN KEY (created_by) REFERENCES admin(admin_id)             ON DELETE SET NULL
);

-- Sacred Heart Cathedral's bank account, as given in the design.
UPDATE church_info SET
    donation_bank_name      = 'Alliance Bank Malaysia Berhad',
    donation_account_name   = 'RTDKK - Sacred Heart Caritas',
    donation_account_number = '101960010200370'
WHERE church_name = 'Sacred Heart Cathedral' AND donation_bank_name IS NULL;

-- ---------------------------------------------------------------------
-- Church Calendar
-- ---------------------------------------------------------------------
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

-- Sacrament applications (see database/sacraments.sql)
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
    notes      TEXT NULL,            -- free-text notes for parishioners (used by Confession, see confession-manage.php)
    apply_until DATE NULL,           -- last day applications are accepted; after it the form closes by itself (NULL = no deadline)
    is_open    TINYINT(1) NOT NULL DEFAULT 1,   -- a Church Admin closes this sacrament's application form when it's full or past its deadline
    PRIMARY KEY (church_id, sacrament),
    CONSTRAINT fk_sacsch_church FOREIGN KEY (church_id) REFERENCES church_info (church_info_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The example schedule from the design for Sacred Heart Cathedral (edit it to the real dates).
INSERT IGNORE INTO sacrament_schedules (church_id, sacrament, line_date, line_brief, line_close)
SELECT church_info_id, 'baptism', '17 May 2026 (Sun.), 2.00 pm', '10 May 2026 (Sun.), 2.00 pm at St. Matthew Room', 'Last date to register is 4 May 2026.'
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

-- Pastoral & Spiritual Care requests (see database/pastoral.sql)
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

-- The services each church offers under Pastoral & Spiritual Care (see database/pastoral_services.sql)
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

-- Catechesis programs and enrolments (see database/catechesis.sql)
CREATE TABLE IF NOT EXISTS catechesis_programs (
    program_id     INT AUTO_INCREMENT PRIMARY KEY,
    church_id      INT NOT NULL,
    title          VARCHAR(100) NOT NULL,
    category       VARCHAR(40)  NOT NULL,               -- Children & Youth, Conversion, Growth, ...
    description    TEXT NULL,
    photo          VARCHAR(100) NULL,                   -- cover photo: file name inside uploads/catechesis_photos/
    frequency      ENUM('weekly', 'monthly_last') NOT NULL DEFAULT 'weekly',   -- every week, or the last <weekday> of the month
    weekday        TINYINT NOT NULL DEFAULT 0,          -- 0 = Sunday ... 6 = Saturday
    start_time     TIME NULL,
    end_time       TIME NULL,
    location       VARCHAR(120) NULL,
    total_sessions SMALLINT UNSIGNED NOT NULL DEFAULT 20,   -- lessons in the whole programme (progress = attendance / this)
    term_label     VARCHAR(30) NULL,                    -- e.g. "2026", shown after the title on the overview page
    notes          TEXT NULL,                           -- Church Admin's freeform update for parishioners (next lesson changes, etc.)
    notes_updated_at TIMESTAMP NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_church (church_id),
    CONSTRAINT fk_catprog_church FOREIGN KEY (church_id) REFERENCES church_info (church_info_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS catechesis_enrolments (
    enrolment_id INT AUTO_INCREMENT PRIMARY KEY,
    program_id   INT NOT NULL,
    user_id      INT NOT NULL,                          -- the parishioner who enrolled
    church_id    INT NOT NULL,                          -- the programme's church
    status       ENUM('draft', 'active', 'completed', 'dropped') NOT NULL DEFAULT 'draft',
    details      TEXT NULL,                             -- the registration form answers, as JSON
    files        TEXT NULL,                             -- uploaded documents (private, in uploads/sacrament_docs), as JSON
    attendance   SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- lessons attended = attendance_undated + the "present" rows in catechesis_attendance
    attendance_undated SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- lessons counted before dates were kept
    joined_at    DATETIME NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_program_user (program_id, user_id),   -- one enrolment per person per programme
    KEY idx_church (church_id),
    CONSTRAINT fk_catenr_program FOREIGN KEY (program_id) REFERENCES catechesis_programs (program_id) ON DELETE CASCADE,
    CONSTRAINT fk_catenr_user    FOREIGN KEY (user_id)    REFERENCES user (user_id)                    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Attendance by date: one row per student per lesson date, marked present or absent.
CREATE TABLE IF NOT EXISTS catechesis_attendance (
    attendance_id INT AUTO_INCREMENT PRIMARY KEY,
    enrolment_id  INT NOT NULL,
    session_date  DATE NOT NULL,                          -- the day of the lesson
    status        ENUM('present', 'absent') NOT NULL,
    marked_by     INT NULL,                               -- the admin who marked it
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_enrolment_date (enrolment_id, session_date),   -- one mark per student per day
    CONSTRAINT fk_catatt_enrolment FOREIGN KEY (enrolment_id) REFERENCES catechesis_enrolments (enrolment_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lesson materials of a program (see database/catechesis_lessons.sql), and which ones each student has done.
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

CREATE TABLE IF NOT EXISTS catechesis_lesson_progress (
    enrolment_id INT NOT NULL,
    lesson_id    INT NOT NULL,
    completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (enrolment_id, lesson_id),
    KEY idx_lesson (lesson_id),
    CONSTRAINT fk_catlp_enrolment FOREIGN KEY (enrolment_id) REFERENCES catechesis_enrolments (enrolment_id) ON DELETE CASCADE,
    CONSTRAINT fk_catlp_lesson    FOREIGN KEY (lesson_id)    REFERENCES catechesis_lessons (lesson_id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The three programs from the design for Sacred Heart Cathedral (edit or delete them from Catechesis Management).
INSERT INTO catechesis_programs (church_id, title, category, description, frequency, weekday, start_time, end_time, location, total_sessions, term_label)
SELECT c.church_info_id, p.title, p.category, p.description, p.frequency, p.weekday, p.start_time, p.end_time, p.location, p.total_sessions, p.term_label
  FROM church_info c
  JOIN (
        SELECT 'Sunday School' AS title, 'Children & Youth' AS category,
               'Foundational religious education for children aged 5-16, following the liturgical year.' AS description,
               'weekly' AS frequency, 0 AS weekday, '08:30:00' AS start_time, '10:00:00' AS end_time, 'Parish Community Hall' AS location, 40 AS total_sessions, '2026' AS term_label
        UNION ALL SELECT 'RCIA', 'Conversion',
               'Rite of Christian Initiation for Adults. A journey of discovery for those seeking full communion with the Church.',
               'weekly', 2, '19:30:00', '21:00:00', 'Cathedral Meeting Room', 24, '2026'
        UNION ALL SELECT 'Adult Faith Formation', 'Growth',
               'Monthly seminars and study groups focused on scripture, theology, and the social teachings of the Church.',
               'monthly_last', 6, NULL, NULL, 'Main Auditorium', 12, '2026'
       ) p
 WHERE c.church_name = 'Sacred Heart Cathedral'
   AND NOT EXISTS (SELECT 1 FROM catechesis_programs x WHERE x.church_id = c.church_info_id);
