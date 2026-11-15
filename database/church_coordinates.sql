-- Church map locations, checked against OpenStreetMap (data © OpenStreetMap contributors, ODbL) on 5 Nov 2026.
-- Several stored points were wrong (e.g. Sacred Heart Cathedral pointed near the Anglican All Saints Cathedral,
-- St Peter Claver Ranau pointed at Bundu Tuhan, St John Tuaran was ~16 km off). The 16 churches below were found on
-- OpenStreetMap by name and are set to that point and marked verified. The other 7 could not be confirmed, so they
-- stay unverified: the Church Information page then sends people to Google Maps by name instead of to Street View.
-- Used by: the Street View / 360° virtual tour, "Get directions", and the Directory's "Find Nearest Church".
-- Run on any database (safe to run again):
--   mysql -u root church_management_system < database/church_coordinates.sql
USE church_management_system;

ALTER TABLE church_info
    ADD COLUMN IF NOT EXISTS church_location_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER church_lng;

UPDATE church_info SET church_lat = 5.9648724, church_lng = 116.0722679, church_location_verified = 1 WHERE church_location = 'Kota Kinabalu';   -- Sacred Heart Cathedral
UPDATE church_info SET church_lat = 5.9819203, church_lng = 116.1013057, church_location_verified = 1 WHERE church_location = 'Likas';           -- St Simon
UPDATE church_info SET church_lat = 5.9879580, church_lng = 116.1417103, church_location_verified = 1 WHERE church_location = 'Inanam';          -- St Catherine (Jalan Kiansom)
UPDATE church_info SET church_lat = 6.0881850, church_lng = 116.1956094, church_location_verified = 1 WHERE church_location = 'Telipok';         -- Holy Family
UPDATE church_info SET church_lat = 5.9559449, church_lng = 116.0518581, church_location_verified = 1 WHERE church_location = 'Tanjung Aru';     -- Stella Maris
UPDATE church_info SET church_lat = 5.8220584, church_lng = 116.0485068, church_location_verified = 1 WHERE church_location = 'Kinarut';         -- St Augustine
UPDATE church_info SET church_lat = 5.7162336, church_lng = 115.9489489, church_location_verified = 1 WHERE church_location = 'Limbahau';        -- Holy Rosary
UPDATE church_info SET church_lat = 5.7321428, church_lng = 115.9309732, church_location_verified = 1 WHERE church_location = 'Papar';           -- St Joseph
UPDATE church_info SET church_lat = 5.9510499, church_lng = 116.1048973, church_location_verified = 1 WHERE church_location = 'Bukit Padang';    -- Mary Immaculate (Jalan Kolam)
UPDATE church_info SET church_lat = 5.9868270, church_lng = 116.5334746, church_location_verified = 1 WHERE church_location = 'Bundu Tuhan';     -- St Pius X
UPDATE church_info SET church_lat = 6.3418174, church_lng = 116.4267121, church_location_verified = 1 WHERE church_location = 'Kota Belud';      -- St Edmund
UPDATE church_info SET church_lat = 6.4961340, church_lng = 116.7796516, church_location_verified = 1 WHERE church_location = 'Kota Marudu';     -- St Theresa
UPDATE church_info SET church_lat = 6.8948218, church_lng = 116.8538898, church_location_verified = 1 WHERE church_location = 'Kudat';           -- St Peter
UPDATE church_info SET church_lat = 5.2866766, church_lng = 115.2535801, church_location_verified = 1 WHERE church_location = 'Labuan';          -- Blessed Sacrament
UPDATE church_info SET church_lat = 5.9547823, church_lng = 116.6634800, church_location_verified = 1 WHERE church_location = 'Ranau';           -- St Peter Claver
UPDATE church_info SET church_lat = 6.1806215, church_lng = 116.2345144, church_location_verified = 1 WHERE church_location = 'Tuaran';          -- St John
