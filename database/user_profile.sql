-- Profile Information page: extra user details, and a direct link to the user's home parish.
-- Until now a user's parish was implied by the Church Admin (user.admin_id), which meant only
-- churches that have a Church Admin account could be chosen. user.church_id points straight at
-- church_info, so all churches can be selected.
-- For databases imported before these columns existed (run ONCE):
--   mysql -u root church_management_system < database/user_profile.sql
-- schema.sql already includes them for fresh installs.
USE church_management_system;

ALTER TABLE user
    ADD COLUMN church_id     INT          NULL AFTER admin_id,   -- home parish
    ADD COLUMN date_of_birth DATE         NULL AFTER user_phone,
    ADD COLUMN user_address  VARCHAR(255) NULL AFTER date_of_birth,
    ADD COLUMN user_photo    VARCHAR(100) NULL AFTER user_address,  -- file name inside uploads/profile_photos/
    ADD CONSTRAINT fk_user_church FOREIGN KEY (church_id) REFERENCES church_info(church_info_id) ON DELETE SET NULL;

-- Existing users keep their parish: copy it across from their Church Admin.
UPDATE user u JOIN admin a ON a.admin_id = u.admin_id SET u.church_id = a.church_id WHERE u.church_id IS NULL;
