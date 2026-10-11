<?php
// Clergy details and photos from the Archdiocese of Kota Kinabalu's Clergy page (checked October 2026):
//   https://www.catholicadkk.org/clergy/
// Run once from the project folder:   php database/seed_clergy.php      (safe to run again)
//
//  * Adds the detail columns to church_priests if they are missing (same as database/church_priests.sql).
//  * Every priest already listed under a church is matched by name and gets their date of birth, ordination date,
//    contact details and photo. Only EMPTY fields are filled; names, titles and photos already saved are kept.
//  * The photos are bundled in assets/images/priests/ (one file per priest, shared by every church they serve).
//  * The Archbishop is added to Sacred Heart Cathedral, the Archbishop's own cathedral, if not listed anywhere yet.
//  * Clergy with no parish in this system (retired, on study leave, serving elsewhere) are only reported, not added.

require __DIR__ . '/../config/db.php';

// name and details exactly as published; title is the published position, written out in full.
// aka: other spellings of the same priest already used in this system.
$clergy = [
    ['name' => 'Most Rev John Wong Soo Kau', 'title' => 'Archbishop of Kota Kinabalu', 'dob' => '1968-06-06', 'ordained' => '1999-01-21', 'photo' => 'most-rev-john-wong-soo-kau.png',
     'details' => "Episcopal Ordination: 1 October 2010\nInstalled Archbishop: 24 January 2013\n\nArchdiocese of Kota Kinabalu\nP.O.Box 10289\n88803 KOTA KINABALU Sabah\nTel: 088-203300/276006\nFax: 088-273688"],
    ['name' => 'Msgr Nicholas Stephen', 'title' => 'Vicar General', 'dob' => '1967-11-19', 'ordained' => '1997-01-09', 'photo' => 'msgr-nicholas-stephen.png',
     'details' => "Sacred Heart Cathedral\n14B Jln Mentri\nOff Jln Tengku Abdul Rahman\nP.O.Box 10049\n88800 KOTA KINABALU Sabah\nTel: 088-224741 / 201188 / 262068\nWebsite: www.shckk.org\nFB: sacred Heart cathedral kota kinabalu\nIG: sacred Heart KK"],
    ['name' => 'Rev Wilfred Atin', 'title' => 'Chancellor', 'dob' => '1972-10-25', 'ordained' => '2005-12-03', 'photo' => 'rev-wilfred-atin.png',
     'details' => "St Michael Church\nP.O.Box 110\n89507 PENAMPANG Sabah\nTel: 088-711009\nFax: 088-712614\nEmail: chancerykk@gmail.com"],
    ['name' => 'Rev Abel Madisang', 'title' => 'St Peter\'s College, Kuching', 'dob' => '1980-04-16', 'ordained' => '2015-05-04', 'photo' => 'rev-abel-madisang.png',
     'details' => null],
    ['name' => 'Rev Aloysius Fidelis', 'title' => 'Chaplain, Holy Family Residence', 'dob' => '1957-01-20', 'ordained' => '1992-06-13', 'photo' => 'rev-aloysius-fidelis.png',
     'details' => "Holy Family Residence\nSenior Citizens\nP.O.Box 332\n89608 PAPAR Sabah\nTel: 088-911334"],
    ['name' => 'Rev Ambrose Atang', 'title' => 'Parish Priest', 'dob' => '1961-06-05', 'ordained' => '1989-11-21', 'photo' => 'rev-ambrose-atang.png',
     'details' => "St Peter Claver Church\nP.O.Box 312\n89308 RANAU Sabah\nTel: 088-875250\nFax: 088-876250"],
    ['name' => 'Rev Bradley Belly Stephen', 'title' => 'Assistant Parish Priest', 'dob' => '1989-03-31', 'ordained' => '2023-11-08', 'photo' => 'rev-bradley-belly-stephen.png',
     'details' => "Holy Nativity Church Terawi\nP.O.Box 809\n88858 Tg Aru Sabah\nTel: 088-761495\nFax: 088-761567"],
    ['name' => 'Rev Canisius Benjamin', 'title' => 'Assistant Parish Priest', 'dob' => '1993-12-15', 'ordained' => '2025-12-06', 'photo' => 'rev-canisius-benjamin.png',
     'details' => "Stella Maris Church Tg Aru\nP.O.Box 10533\n88806 Kota Kinabalu Sabah\nTel: 088-254321"],
    ['name' => 'Rev Cosmas Lee', 'title' => 'Assistant Parish Priest', 'dob' => '1950-05-31', 'ordained' => '1976-12-27', 'photo' => 'rev-cosmas-lee.png',
     'details' => "St Simon Catholic Church\nP.O.Box 45\n89407 Likas Sabah\nTel: 088-212713\nFax: 088-288713"],
    ['name' => 'Rev Cruzender Edmond Alex', 'title' => 'Assistant Parish Priest', 'dob' => '1978-10-25', 'ordained' => '2019-10-22', 'photo' => 'rev-cruzender-edmond-alex.png', 'aka' => ['cruzender e alex'],
     'details' => "St Michael Church\nP.O.Box 110\n89507 PENAMPANG Sabah\nTel: 088-711009\nFax: 088-712614"],
    ['name' => 'Rev David Sham', 'title' => 'Parish Priest', 'dob' => '1952-05-05', 'ordained' => '1985-11-24', 'photo' => 'rev-david-sham.png',
     'details' => "St. Catherine Church\nWDT 10\n89359 Inanam, Sabah\nTel: 088-421293"],
    ['name' => 'Rev Edward Raymond', 'title' => 'Diocese of Sibu', 'dob' => '1966-06-14', 'ordained' => '1999-08-07', 'photo' => 'rev-edward-raymond.png',
     'details' => null],
    ['name' => 'Rev Florian Marcus', 'title' => 'Assistant Parish Priest', 'dob' => '1974-01-16', 'ordained' => '2011-12-03', 'photo' => 'rev-florian-marcus.png',
     'details' => "St Joseph Church\nP.O.Box 1\n89607 PAPAR, Sabah\nTel: 088-910206\nEmail: sjp.papar@gmail.com\nFB: st.joseph's catholic church, Papar"],
    ['name' => 'Rev Federick Raymond', 'title' => 'Parish Priest', 'dob' => '1971-09-11', 'ordained' => '2002-07-20', 'photo' => 'rev-federick-raymond.png', 'aka' => ['frederick raymond'],
     'details' => "St Pius X Church\nP.O.Box 1032\n89308 Ranau, Sabah\nTel: 088-889945"],
    ['name' => 'Rev Gerald Terence Saimel', 'title' => 'Assistant Parish Priest', 'dob' => '1986-11-14', 'ordained' => '2022-07-02', 'photo' => 'rev-gerald-terence-saimel.png', 'aka' => ['gerald t saimel'],
     'details' => "St Michael Church\nP.O.Box 110\n89507 Penampang, Sabah\nPhone: 088-711009"],
    ['name' => 'Rev Gilbert Marcus', 'title' => 'Catholic Diocesan Centre', 'dob' => '1981-11-21', 'ordained' => '2018-11-20', 'photo' => 'rev-gilbert-marcus.png',
     'details' => null],
    ['name' => 'Rev Isidore Gondilou', 'title' => 'Parish Priest', 'dob' => '1976-04-04', 'ordained' => '2009-09-21', 'photo' => 'rev-isidore-gondilou.png',
     'details' => "St Edmund Church\nP.O.Box 50\n89157 KOTA BELUD, Sabah\nTel: 088-973261\nEmail: st.edmund_church@yahoo.com\nFB: paroki st.edmund kota belud"],
    ['name' => 'Rev Jack Johimi Sigam', 'title' => 'Assistant Parish Priest', 'dob' => '1981-09-01', 'ordained' => '2014-06-28', 'photo' => 'rev-jack-johimi-sigam.png', 'aka' => ['jack johimi'],
     'details' => "St. Theresa Church, Kota Marudu\nTel: 088-663075"],
    ['name' => 'Rev Jalius Sading', 'title' => 'Parish Priest', 'dob' => '1966-03-05', 'ordained' => '2001-06-04', 'photo' => 'rev-jalius-sading.png',
     'details' => "St John's Church\nJalan Tanjong Lubuaya\nP.O.Box 8\n89207 TUARAN, Sabah\nTel: 088-788472\nFax: 088-788149\nFB: St.John Tuaran Parish"],
    ['name' => 'Rev Jeffri Gumu', 'title' => 'Marriage Ecclesiastical Tribunal Office', 'dob' => '1980-09-24', 'ordained' => '2012-08-04', 'photo' => 'rev-jeffri-gumu.png',
     'details' => "Marriage Ecclesiastical Tribunal Office\nCatholic Archdiocesan Centre\nJalan Sang Kancil 3, Karamunsing\n88300 Kota Kinabalu, Sabah\nTel: 088-203300"],
    ['name' => 'Rev Jerry Joseph Muhamat', 'title' => 'Assistant Parish Priest', 'dob' => '1978-09-03', 'ordained' => '2024-06-07', 'photo' => 'rev-jerry-joseph-muhamat.png',
     'details' => "St. Michael Church\nP.O.Box 110\n89507 Penampang Sabah\nTel: 088-711009"],
    ['name' => 'Rev Johnny Raju', 'title' => 'Assistant Parish Priest', 'dob' => '1965-06-02', 'ordained' => '2002-07-20', 'photo' => 'rev-johnny-raju.png',
     'details' => "Sacred Heart Church Inobong\nJalan Inobong Putaton Bansadon\nKampung Inobong\n89500 Penampang\nKota Kinabalu, Sabah\nTel: 018-3155688"],
    ['name' => 'Rev Joshua Liew', 'title' => 'Assistant Parish Priest', 'dob' => '1985-07-22', 'ordained' => '2016-05-03', 'photo' => 'rev-joshua-liew.png',
     'details' => "St Joseph Church\nP.O.Box 1\n89607 PAPAR, Sabah\nTel: 088-910206"],
    ['name' => 'Rev Lasius Gantis', 'title' => 'Assistant Parish Priest', 'dob' => '1991-12-16', 'ordained' => '2022-08-04', 'photo' => 'rev-lasius-gantis.png',
     'details' => "St Peter Claver Church\nP.O.Box 312\n89308 RANAU Sabah\nTel: 088-875250\nHP: 011-26271801\nEmail: stpeterclaverranau2017@gmail.com\nFB: Catholic Church St Peter Claver, Ranau"],
    ['name' => 'Rev Mattheus Luta', 'title' => 'IY Formator', 'dob' => '1976-09-20', 'ordained' => '2010-08-04', 'photo' => 'rev-mattheus-luta.png',
     'details' => "St Peter's College (IY)\nP.O.Box 10225\n88802 Kota Kinabalu Sabah\nTel: 088-236102"],
    ['name' => 'Rev Maxmillianno Hantor', 'title' => 'Assistant Parish Priest', 'dob' => '1977-07-25', 'ordained' => '2014-06-15', 'photo' => 'rev-maxmillianno-hantor.png', 'aka' => ['maxmiliano hontor'],
     'details' => "St Edmund Church\nP.O.Box 50\n89157 Kota Belud, Sabah\nTel: 088-976186\nFax: 088-976758"],
    ['name' => 'Rev Michael Modoit', 'title' => 'Parish Priest', 'dob' => '1969-07-21', 'ordained' => '2000-02-12', 'photo' => 'rev-michael-modoit.png',
     'details' => "St Simon Catholic Church\nP.O.Box 45\n89407 Likas, Sabah\nTel: 088-212713"],
    ['name' => 'Rev Mitchelly Kiun', 'title' => 'Diocese of Sandakan', 'dob' => '1980-09-26', 'ordained' => '2014-02-26', 'photo' => 'rev-mitchelly-kiun.png',
     'details' => "Currently assigned at Diocese of Sandakan\nSt Mary Cathedral Parish\nTel: 089-271331/089-212623\nFax: 089-272025"],
    ['name' => 'Rev Moses Yap', 'title' => 'Assistant Parish Priest', 'dob' => null, 'ordained' => null, 'photo' => 'rev-moses-yap.png',
     'details' => "Sacred Heart Cathedral\nP.O.Box 10049\n88800 Kota Kinabalu, Sabah\nTel: 088-201188\nFax: 088-224068"],
    ['name' => 'Rev Ned Columba', 'title' => 'Assistant Parish Priest', 'dob' => '1960-12-12', 'ordained' => '2014-06-29', 'photo' => 'rev-ned-columba.png', 'aka' => ['ned columba moujing'],
     'details' => "St Pius X Church\nP.O.Box 1032\n89308 RANAU Sabah\nTel: 088-889945\nFax: 088-889940"],
    ['name' => 'Rev Patrick Jerome', 'title' => 'Parish Priest', 'dob' => '1964-05-13', 'ordained' => '1991-01-06', 'photo' => 'rev-patrick-jerome.png',
     'details' => "St Joseph Husband of Mary\nP.O.Box 160\nPejabat Pos Mini\n89250 KIULU, Sabah\nTel: 088-783996\nFax: 088-782996"],
    ['name' => 'Rev Paul Lo', 'title' => 'Parish Priest', 'dob' => '1973-02-04', 'ordained' => '2005-12-10', 'photo' => 'rev-paul-lo.png',
     'details' => "St Joseph Church\nJalan Pos\nP.O.Box 1\n89607 Papar Sabah\nTel: 088-910206"],
    ['name' => 'Rev Peter Abas', 'title' => 'Assistant Parish Priest', 'dob' => '1960-05-18', 'ordained' => '1986-02-02', 'photo' => 'rev-peter-abas.png',
     'details' => "Stella Maris Church Tg Aru\nP.O.Box 10533\n88806 KOTA KINABALU Sabah\nTel: 010-4323484"],
    ['name' => 'Rev Peter Chung', 'title' => 'Assistant Parish Priest', 'dob' => '1978-06-07', 'ordained' => '2022-08-22', 'photo' => 'rev-peter-chung.png',
     'details' => "St Joseph Husband of Mary Church\nP.O.Box 160\nPejabat Pos Mini Kiulu\n89250 Kiulu Sabah\nTel: 016-4947466"],
    ['name' => 'Rev Postinus Kurup', 'title' => 'Assistant Parish Priest', 'dob' => '1976-10-27', 'ordained' => '2011-12-03', 'photo' => 'rev-postinus-kurup.png',
     'details' => "St Catherine Church\nWDT 10\n89359 Inanam, Sabah\nTel: 088-421293"],
    ['name' => 'Rev Primus Jouil', 'title' => 'Assistant Parish Priest', 'dob' => '1949-05-27', 'ordained' => '1980-05-01', 'photo' => 'rev-primus-jouil.png',
     'details' => "Stella Maris Church Tg Aru\nP.O.Box 10533\n88806 Kota Kinabalu Sabah\nTel: 010-4323484"],
    ['name' => 'Rev Rayner Bisius', 'title' => 'Parish Priest', 'dob' => '1975-04-13', 'ordained' => '2009-09-16', 'photo' => 'rev-rayner-bisius.png',
     'details' => "St Peter Church\nP.O.Box 26\n89057 KUDAT Sabah\nTel: 088-611271\nFax: 088-613428"],
    ['name' => 'Rev Rhobby Mojolou', 'title' => 'Parish Priest', 'dob' => '1981-12-21', 'ordained' => '2014-02-24', 'photo' => 'rev-rhobby-mojolou.png',
     'details' => "Stella Maris Church Tg Aru\nP.O.Box 10533\n88806 Kota Kinabalu Sabah\nTel: 010-4323484"],
    ['name' => 'Rev Russell Lawrine', 'title' => 'On Study Leave', 'dob' => '1986-12-13', 'ordained' => '2018-11-10', 'photo' => 'rev-russell-lawrine.png',
     'details' => null],
    ['name' => 'Rev Saimon William', 'title' => 'Emmaus Home', 'dob' => '1963-06-13', 'ordained' => '1998-07-06', 'photo' => 'rev-saimon-william.png',
     'details' => "Sacred Heart Cathedral\nP.O.Box 10049\n88800 Kota Kinabalu, Sabah\nTel: 088-201188"],
    ['name' => 'Rev Sunny Chung', 'title' => 'Parish Priest', 'dob' => '1978-07-17', 'ordained' => '2010-08-04', 'photo' => 'rev-sunny-chung.png',
     'details' => "Sacred Heart Church Inobong\nJalan Inobong Putaton Bansadon\nKampung Inobong\n89500 Penampang\nKOTA KINABALU Sabah\nTel: 018-3155688"],
    ['name' => 'Rev Sylvester Wong', 'title' => 'Assistant Parish Priest', 'dob' => '1979-12-24', 'ordained' => '2022-06-24', 'photo' => 'rev-sylvester-wong.png',
     'details' => "St Catherine Church, INANAM\nWDT 10\n89359 Inanam, Sabah\nTel: 088-421293"],
    ['name' => 'Rev Terans Thadeus', 'title' => 'On Study Leave', 'dob' => '1988-01-10', 'ordained' => '2022-07-16', 'photo' => 'rev-terans-thadeus.png',
     'details' => null],
    ['name' => 'Rev Thomas Madanan', 'title' => 'Parish Priest', 'dob' => '1972-12-29', 'ordained' => '2004-12-04', 'photo' => 'rev-thomas-madanan.png',
     'details' => "Blessed Sacrament Church\nJalan OKK Abdullah\nP.O.Box 81039\n87020 FT Labuan\nTel: 087-424285"],
    ['name' => 'Rev Thomas Yip', 'title' => 'Parish Priest', 'dob' => '1959-12-15', 'ordained' => '1988-06-29', 'photo' => 'rev-thomas-yip.png',
     'details' => "Holy Nativity Church\nP.O.Box 809\n88858 Tanjung Aru, Sabah\nTel: 088-772632"],
    ['name' => 'Rev Tony Mojiwot', 'title' => 'Itinerant Mission Abroad', 'dob' => '1966-10-02', 'ordained' => '1999-08-07', 'photo' => 'rev-tony-mojiwot.png',
     'details' => null],
    ['name' => 'Rev Wiandigool Runsab', 'title' => 'Assistant Parish Priest', 'dob' => '1978-01-09', 'ordained' => '2010-08-04', 'photo' => 'rev-wiandigool-runsab.png',
     'details' => "St John Church, Tuaran\nP.O.Box 8\n89207 Tuaran, Sabah\nTel: 088-788472"],
    ['name' => 'Rev Wilson Francis', 'title' => 'Assistant Parish Priest', 'dob' => '1990-11-14', 'ordained' => '2021-10-25', 'photo' => 'rev-wilson-francis.png',
     'details' => "Sacred Heart Cathedral\n14 Jln Mentri\nOff Jln Tengku Abdul Rahman\nP.O.Box 10049\n88800 Kota Kinabalu, Sabah\nTel: 088-224741, 088-201188\nFax: 088-876250"],
    ['name' => 'Rev Francis Tsen', 'title' => 'Emmaus Home', 'dob' => '1941-09-20', 'ordained' => '1966-01-09', 'photo' => 'rev-francis-tsen.png',
     'details' => "Emmaus Home\nc/o Sacred Heart Cathedral\nP.O.Box 10049\n88800 KOTA KINABALU Sabah\nTel: 088-224741"],
    ['name' => 'Rev Felix Chung', 'title' => 'Emmaus Home', 'dob' => '1937-09-22', 'ordained' => '1969-12-14', 'photo' => 'rev-felix-chung.png',
     'details' => "Emmaus Home\nc/o Sacred Heart Cathedral\nP.O.Box 10049\n88800 KOTA KINABALU Sabah\nTel: 011-10513255"],
    ['name' => 'Most Rev Abp Emeritus John Lee', 'title' => 'Archbishop Emeritus', 'dob' => '1933-10-05', 'ordained' => '1964-12-27', 'photo' => 'most-rev-abp-emeritus-john-lee.png',
     'details' => "Archdiocese of Kota Kinabalu\nP.O.Box 10289\n88803 KOTA KINABALU Sabah\nTel: 088-711771"],
];

/** "Friar Cruzender E. Alex, OFM" -> "cruzender e alex": the name without titles, order initials or punctuation. */
function clergy_key(string $name): string
{
    $skip = ['rev', 'fr', 'msgr', 'friar', 'most', 'very', 'abp', 'emeritus', 'dr', 'ofm'];
    $words = preg_split('/\s+/', trim(preg_replace('/[^a-z\s]/', '', strtolower($name))));
    return implode(' ', array_filter($words, fn($w) => $w !== '' && !in_array($w, $skip, true)));
}

$have = array_column($pdo->query('SHOW COLUMNS FROM church_priests')->fetchAll(), 'Field');
foreach (['priest_dob' => 'DATE NULL AFTER priest_photo', 'priest_ordained' => 'DATE NULL AFTER priest_dob', 'priest_details' => 'TEXT NULL AFTER priest_ordained'] as $col => $def) {
    if (!in_array($col, $have, true)) $pdo->exec("ALTER TABLE church_priests ADD COLUMN $col $def");
}

$byKey = [];
foreach ($clergy as $i => $c) {
    $byKey[clergy_key($c['name'])] = $i;
    foreach ($c['aka'] ?? [] as $a) $byKey[$a] = $i;
}
$photoDir = __DIR__ . '/../assets/images/priests/';

$rows = $pdo->query('SELECT p.*, c.church_name FROM church_priests p JOIN church_info c ON c.church_info_id = p.church_id ORDER BY p.church_id, p.sort_order, p.priest_id')->fetchAll();
$hasParishPriest = [];
foreach ($rows as $r) if (strcasecmp((string)$r['priest_title'], 'Parish Priest') === 0) $hasParishPriest[$r['church_id']] = true;

$filled = 0;
$placed = [];
$unknown = [];
foreach ($rows as $r) {
    $i = $byKey[clergy_key($r['priest_name'])] ?? null;
    if ($i === null) { $unknown[] = "{$r['priest_name']} ({$r['church_name']})"; continue; }
    $c = $clergy[$i];
    $placed[$i] = true;

    $set = [];
    $empty = fn(string $col) => $r[$col] === null || trim((string)$r[$col]) === '';
    if ($empty('priest_dob') && $c['dob']) $set['priest_dob'] = $c['dob'];
    if ($empty('priest_ordained') && $c['ordained']) $set['priest_ordained'] = $c['ordained'];
    if ($empty('priest_details') && $c['details']) $set['priest_details'] = $c['details'];
    if ($empty('priest_photo') && $c['photo'] && is_file($photoDir . $c['photo'])) $set['priest_photo'] = $c['photo'];
    // A church keeps one Parish Priest: a priest who leads another parish is not given that title here as well.
    if ($empty('priest_title') && !($c['title'] === 'Parish Priest' && isset($hasParishPriest[$r['church_id']]))) $set['priest_title'] = $c['title'];
    if (!$set) continue;

    $sql = implode(', ', array_map(fn($col) => "$col = ?", array_keys($set)));
    $pdo->prepare("UPDATE church_priests SET $sql WHERE priest_id = ?")->execute([...array_values($set), $r['priest_id']]);
    $filled += count($set);
}
echo "Priests: $filled empty fields filled across " . count($rows) . " entries\n";

// The Archbishop (first in $clergy) belongs to no parish; the cathedral is where people will look.
if (!isset($placed[0])) {
    $stmt = $pdo->prepare('SELECT church_info_id FROM church_info WHERE church_name = ?');
    $stmt->execute(['Sacred Heart Cathedral']);
    if ($cathedral = (int)$stmt->fetchColumn()) {
        $c = $clergy[0];
        $stmt = $pdo->prepare('SELECT COALESCE(MIN(sort_order), 1) - 1 FROM church_priests WHERE church_id = ?');
        $stmt->execute([$cathedral]);
        $pdo->prepare('INSERT INTO church_priests (church_id, priest_name, priest_title, priest_photo, priest_dob, priest_ordained, priest_details, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$cathedral, $c['name'], $c['title'], is_file($photoDir . $c['photo']) ? $c['photo'] : null, $c['dob'], $c['ordained'], $c['details'], (int)$stmt->fetchColumn()]);
        $placed[0] = true;
        echo "Added {$c['name']} to Sacred Heart Cathedral\n";
    }
}

foreach ($unknown as $u) echo "  not on the Clergy page, left as it is: $u\n";
foreach ($clergy as $i => $c) {
    if (!isset($placed[$i])) echo "  not listed under any church here: {$c['name']} ({$c['title']})\n";
}
