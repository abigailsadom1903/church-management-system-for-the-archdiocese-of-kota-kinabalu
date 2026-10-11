-- Church Information page: extra details for each church.
-- For databases imported before these columns existed (run ONCE):
--   mysql -u root church_management_system < database/church_information.sql
-- schema.sql already includes them for fresh installs.
USE church_management_system;

ALTER TABLE church_info
    MODIFY church_office_hours VARCHAR(255) NULL,       -- one line per day range, separated by newlines
    ADD COLUMN church_priest_title VARCHAR(50)  NULL AFTER church_priest,   -- e.g. Rector, Parish Priest
    ADD COLUMN church_address      VARCHAR(255) NULL AFTER church_location, -- full street address (church_location = town)
    ADD COLUMN church_email        VARCHAR(100) NULL AFTER church_contact_details,
    ADD COLUMN church_website      VARCHAR(150) NULL AFTER church_email,
    ADD COLUMN church_facebook     VARCHAR(150) NULL AFTER church_website,
    ADD COLUMN church_photo        VARCHAR(100) NULL;   -- file name inside assets/images/churches/

-- Sacred Heart Cathedral: real details (replaces the placeholder sample values).
UPDATE church_info SET
    church_priest          = 'Rev. Msgr. Nicholas Huang',
    church_priest_title    = 'Rector',
    church_contact_details = '+60 88-223 222',
    church_address         = '14B, Jalan Tunku Abdul Rahman, 88000 Kota Kinabalu, Sabah, Malaysia',
    church_office_hours    = 'Mon - Fri: 8:30 AM - 4:30 PM\nSat: 8:30 AM - 12:30 PM\nSun: Closed',
    church_email           = 'office@shckk.org',
    church_website         = 'www.shckk.org',
    church_facebook        = 'facebook.com/sacredheartcathedralkk',
    church_photo           = 'sacred-heart-cathedral.jpg'
WHERE church_info_id = 1 AND church_name = 'Sacred Heart Cathedral';
