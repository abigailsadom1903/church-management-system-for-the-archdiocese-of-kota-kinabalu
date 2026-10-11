-- Real contact details for the parishes on the Directory / Church Information pages.
-- Source: the Archdiocese's own parish pages, https://www.catholicadkk.org/parishes/ (checked Sept 2026).
-- Only details those pages actually list are filled in; anything they do not list is left empty.
-- Sacred Heart Cathedral keeps the details supplied for the project (see church_information.sql).
-- For databases already imported:  mysql -u root church_management_system < database/church_details.sql
-- Safe to run more than once.
USE church_management_system;

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

-- Priests now live in church_priests (a church can have several): move the ones set above across.
INSERT INTO church_priests (church_id, priest_name, priest_title)
SELECT c.church_info_id, c.church_priest, NULLIF(c.church_priest_title, '')
  FROM church_info c
 WHERE c.church_priest IS NOT NULL AND c.church_priest <> ''
   AND NOT EXISTS (SELECT 1 FROM church_priests p WHERE p.church_id = c.church_info_id);
UPDATE church_info SET church_priest = NULL, church_priest_title = NULL WHERE church_priest IS NOT NULL OR church_priest_title IS NOT NULL;
