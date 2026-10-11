-- Directory of Churches: the 23 parishes listed on the public Directory page.
-- For databases imported before these rows existed:
--   mysql -u root church_management_system < database/directory_churches.sql
-- Safe to run more than once. (schema.sql already includes these for fresh installs.)
USE church_management_system;

-- The two original sample rows are renamed to match the directory listing
-- (church_location now holds the town shown next to the name). Priest, contact
-- and office hours are left untouched.
UPDATE church_info SET church_name = 'Sacred Heart Cathedral', church_location = 'Kota Kinabalu'
 WHERE church_info_id = 1 AND church_name = 'Sacred Heart Cathedral';
UPDATE church_info SET church_name = 'St Simon Church', church_location = 'Likas'
 WHERE church_info_id = 2 AND church_name LIKE 'St. Simon%';

INSERT INTO church_info (admin_id, church_name, church_location)
SELECT 1, v.n, v.l
  FROM (
        SELECT 'St Paul Church' n, 'Dontozidon' l
  UNION ALL SELECT 'St Catherine Church', 'Inanam'
  UNION ALL SELECT 'Good Shepherd Church', 'Manggatal'
  UNION ALL SELECT 'Holy Family Church', 'Telipok'
  UNION ALL SELECT 'St Thomas Church', 'Kepayan'
  UNION ALL SELECT 'Stella Maris Church', 'Tanjung Aru'
  UNION ALL SELECT 'Sacred Heart Church', 'Inobong'
  UNION ALL SELECT 'St Augustine Church', 'Kinarut'
  UNION ALL SELECT 'St Michael Church', 'Penampang'
  UNION ALL SELECT 'Holy Rosary Church', 'Limbahau'
  UNION ALL SELECT 'St Joseph Church', 'Papar'
  UNION ALL SELECT 'Church of Mary Immaculate', 'Bukit Padang'
  UNION ALL SELECT 'St Pius X Church', 'Bundu Tuhan'
  UNION ALL SELECT 'St Joseph Husband of Mary Church', 'Kiulu'
  UNION ALL SELECT 'St Edmund Church', 'Kota Belud'
  UNION ALL SELECT 'St Theresa Church', 'Kota Marudu'
  UNION ALL SELECT 'St Peter Church', 'Kudat'
  UNION ALL SELECT 'Blessed Sacrament Church', 'Labuan'
  UNION ALL SELECT 'St Peter Claver Church', 'Ranau'
  UNION ALL SELECT 'Holy Nativity Church', 'Terawi'
  UNION ALL SELECT 'St John Church', 'Tuaran'
       ) v
 WHERE NOT EXISTS (SELECT 1 FROM church_info c WHERE c.church_name = v.n);
