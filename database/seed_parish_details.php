<?php
// Parish details from the Archdiocese of Kota Kinabalu's official "List of Parishes and Mass Times" (updated January 2025):
//   https://www.catholicadkk.org/wp-content/uploads/2025/01/Masses-Time-ArchKK_Updated_2025_compressed.pdf
// Run once from the project folder:   php database/seed_parish_details.php
//
//  * Mass times become weekly events (category "mass") on each church's calendar, so they also appear in the Church Assistant.
//    A church that already has Mass times (Sacred Heart Cathedral, entered from your design) is left alone.
//  * Phone, e-mail, website, Facebook and address are filled in ONLY where they are still empty; nothing already saved is changed.
//  * Bank accounts and office hours are not published per parish in that list, so they are not filled in here.

require __DIR__ . '/../config/db.php';

const LANG = ['EN' => 'English', 'BM' => 'Bahasa Malaysia', 'CH' => 'Chinese', 'KD' => 'Kadazan-Dusun'];
const DOW = ['Su' => 0, 'Mo' => 1, 'Tu' => 2, 'We' => 3, 'Th' => 4, 'Fr' => 5, 'Sa' => 6];

/** "Mo-Sa", "Mo,Tu,Fr" -> [1,2,...] */
function days(string $spec): array
{
    $out = [];
    foreach (explode(',', $spec) as $tok) {
        if (str_contains($tok, '-')) { [$a, $b] = explode('-', $tok); for ($d = DOW[$a]; $d <= DOW[$b]; $d++) $out[] = $d; }
        else $out[] = DOW[$tok];
    }
    return $out;
}

// church_id => list of [kind, days, time, language, place]   kind: D daily, S sunset (Saturday evening), U Sunday, H Holy Hour then Mass
$D = fn(string $days, string $t, string $lang = '', string $place = '') => ['D', $days, $t, $lang, $place];
$S = fn(string $t, string $lang = '', string $place = '') => ['S', 'Sa', $t, $lang, $place];
$U = fn(string $t, string $lang = '', string $place = '') => ['U', 'Su', $t, $lang, $place];
$H = fn(string $days, string $t, string $lang = '', string $place = '') => ['H', $days, $t, $lang, $place];

$mass = [
    2  => [$D('Mo,Tu,We,Fr', '18:30'), $D('Sa', '07:00'), $H('Th', '19:30'), $U('08:30', 'EN'), $U('11:30', 'EN')],                     // St Simon, Likas
    3  => [$D('Mo-Sa', '06:30', 'EN'), $U('08:00', 'KD')],                                                                           // St Paul, Dontozidon
    4  => [$D('Mo-Sa', '06:30'), $S('18:00', 'KD'), $U('08:00', 'BM')],                                                              // St Catherine, Inanam
    5  => [$U('08:00', 'CH'), $U('10:30', 'BM')],                                                                                    // Good Shepherd, Manggatal
    6  => [$S('18:00', 'BM'), $U('08:00', 'BM'), $U('10:30', 'CH'), $U('10:30', 'BM', 'Divine Mercy Church, KKIP (Telipok)')],        // Holy Family, Telipok
    7  => [$D('Mo-Sa', '06:00'), $U('08:00', 'KD'), $U('17:00', 'BM')],                                                              // St Thomas More, Kepayan
    8  => [$D('Mo-Fr', '06:00', 'EN'), $S('17:00', 'EN'), $U('07:30', 'EN'), $U('10:00', 'BM')],                                     // Stella Maris, Tanjung Aru
    9  => [$D('Tu-Fr', '06:30'), $D('Sa', '07:00'), $S('18:00', 'KD'), $S('18:00', 'KD', 'Sts. Peter & Paul, Babagon'), $U('08:00', 'BM'), $U('08:00', 'BM', 'Sts. Peter & Paul, Babagon')], // Sacred Heart, Inobong
    10 => [$D('Mo,We,Fr', '17:30', 'BM'), $D('Tu,Th', '17:30', 'KD'), $S('18:00', 'KD'), $U('08:00', 'KD'), $U('10:30', 'BM')],      // St Augustine, Kinarut
    11 => [$D('Mo,We,Fr', '06:30', 'EN'), $D('Tu,Th', '06:30', 'KD'), $S('18:00', 'EN'), $U('08:00', 'KD'), $U('10:30', 'BM'), $U('17:00', 'EN'),
           $S('18:00', 'EN', 'St. Aloysius Church, Limbanak'), $U('08:00', 'KD', 'St. Aloysius Church, Limbanak')],                   // St Michael, Penampang (+ Limbanak)
    12 => [$D('Tu', '06:30', 'BM'), $D('We', '06:30', 'EN'), $D('Th', '06:30', 'BM'), $D('Sa', '06:30', 'BM'), $S('18:00', 'BM'), $U('08:00', 'KD')], // Holy Rosary, Limbahau
    13 => [$D('Mo', '06:30', 'EN'), $D('Tu', '06:30', 'BM'), $D('We', '06:30', 'EN'), $D('Fr', '06:30', 'EN'), $D('Sa', '06:30', 'BM'),
           $S('18:00', 'EN'), $U('08:00', 'BM'), $U('10:30', 'BM')],                                                                 // St Joseph the Worker, Papar
    14 => [$D('Mo-Sa', '06:30', 'EN'), $S('18:00', 'CH'), $U('08:00', 'EN')],                                                        // Church of Mary Immaculate, Bukit Padang
    15 => [$S('18:00', 'BM'), $U('08:00', 'BM')],                                                                                    // St Pius X, Bundu Tuhan (weekday Masses are in outstation chapels)
    16 => [$D('Mo,Tu,Sa', '06:15'), $D('We,Th,Fr', '18:30'), $S('18:00', 'EN'), $U('08:00', 'BM')],                                  // St Joseph Husband of Mary, Kiulu
    17 => [$D('Mo,Tu,We,Fr', '06:30'), $H('Th', '18:30'), $S('18:00', 'BM', 'an outstation chapel (changes weekly)'), $U('08:00', 'BM')], // St Edmund, Kota Belud
    18 => [$D('Tu,We,Th,Fr', '06:30'), $H('Th', '19:30'), $S('18:00', 'KD'), $U('08:00', 'BM')],                                     // St Theresa, Kota Marudu (no morning Mass on Monday)
    19 => [$D('Tu,We,Fr', '06:30'), $H('Th', '19:00'), $S('18:00', 'EN'), $U('08:00', 'BM')],                                        // St Peter, Kudat
    20 => [$D('Mo-Sa', '06:00'), $S('18:00', 'EN'), $U('07:30', 'EN'), $U('10:00', 'BM')],                                           // Blessed Sacrament, Labuan
    21 => [$D('Mo-Sa', '06:30'), $H('Th', '18:30'), $S('18:00', 'EN'), $U('07:00', 'KD'), $U('09:30', 'BM')],                        // St Peter Claver, Ranau
    22 => [$D('Mo-Sa', '06:30'), $S('18:00', 'EN'), $U('07:30', 'KD'), $U('09:30', 'BM')],                                           // Holy Nativity, Terawi
    23 => [$D('Mo-Sa', '06:30'), $S('18:00', 'EN'), $U('08:00', 'KD'), $U('10:30', 'BM')],                                           // St John, Tuaran
];

// Contact details: only the empty fields are filled.  church_id => [column => value]
$contact = [
    3  => ['church_contact_details' => '088-714359'],
    4  => ['church_facebook' => 'https://www.facebook.com/stcatherineinanam'],
    5  => ['church_contact_details' => '088-421293 (St. Catherine\'s Inanam parish office)', 'church_facebook' => 'https://www.facebook.com/gsm.menggatal'],
    6  => ['church_contact_details' => '088-421293 (St. Catherine\'s Inanam parish office)', 'church_facebook' => 'https://www.facebook.com/HFCTelipok'],
    7  => ['church_contact_details' => '088-215193', 'church_email' => 'st.thomaskpyn.mpp@gmail.com'],
    8  => ['church_contact_details' => '010-4323484', 'church_facebook' => 'https://www.facebook.com/stellamarisparishtanjungaru'],
    9  => ['church_contact_details' => '018-3155688', 'church_email' => 'htpinobong@gmail.com', 'church_facebook' => 'https://www.facebook.com/SHPInobong'],
    10 => ['church_contact_details' => '011-54245733', 'church_address' => 'Paroki St. Augustine Kinarut, Jalan Papar-Penampang, Kinarut, 89600 Papar, Sabah', 'church_website' => 'https://www.staugustineparishkinarut.com'],
    19 => ['church_email' => 'stpeterchurchkudat@gmail.com', 'church_facebook' => 'https://www.facebook.com/spcckudat'],
    20 => ['church_website' => 'https://cbslabuan.com'],
];

$refDate = '2026-01-04';   // a Sunday: every weekly event starts on the right weekday counted from here
$made = 0;
$skipped = [];
foreach ($mass as $churchId => $rows) {
    $has = $pdo->prepare("SELECT COUNT(*) FROM events WHERE church_id = ? AND category = 'mass'");
    $has->execute([$churchId]);
    if ((int)$has->fetchColumn() > 0) { $skipped[] = $churchId; continue; }

    $c = $pdo->prepare('SELECT church_name FROM church_info WHERE church_info_id = ?');
    $c->execute([$churchId]);
    $church = (string)$c->fetchColumn();
    if ($church === '') continue;
    $admin = $pdo->prepare('SELECT admin_id FROM admin WHERE church_id = ? LIMIT 1');
    $admin->execute([$churchId]);
    $by = (int)($admin->fetchColumn() ?: 1);   // the Super Admin when the church has no admin yet

    $ins = $pdo->prepare("INSERT INTO events (church_id, title, category, event_type, event_date, start_time, is_all_day, location, description, repeat_weekly, created_by)
                          VALUES (?, ?, 'mass', ?, ?, ?, 0, ?, ?, 1, ?)");
    foreach ($rows as [$kind, $daySpec, $time, $lang, $place]) {
        $label = ['D' => 'Daily Mass', 'S' => 'Sunset Mass', 'U' => 'Sunday Mass', 'H' => 'Holy Hour followed by Mass'][$kind];
        $title = $label . ($lang ? ' (' . LANG[$lang] . ')' : '');
        $note = $kind === 'S' ? 'Sunset Mass is the Saturday evening Mass.' : '';
        foreach (days($daySpec) as $dow) {
            $ins->execute([$churchId, $title, $kind === 'H' ? 'Adoration' : 'Mass', date('Y-m-d', strtotime($refDate . " +$dow days")), $time . ':00',
                           $place !== '' ? $place : $church, trim($note . ' Source: Archdiocese of Kota Kinabalu list of Mass times, January 2025.'), $by]);
            $made++;
        }
    }
}
echo "Mass times: $made weekly events added";
echo $skipped ? '; churches left alone because they already have Mass times: ' . implode(', ', $skipped) : '';
echo "\n";

$filled = 0;
$differs = [];
foreach ($contact as $churchId => $cols) {
    $row = $pdo->prepare('SELECT * FROM church_info WHERE church_info_id = ?');
    $row->execute([$churchId]);
    $cur = $row->fetch();
    foreach ($cols as $col => $val) {
        if ($cur[$col] === null || trim((string)$cur[$col]) === '') {
            $pdo->prepare("UPDATE church_info SET $col = ? WHERE church_info_id = ?")->execute([$val, $churchId]);
            $filled++;
        } elseif (trim((string)$cur[$col]) !== $val) {
            $differs[] = "{$cur['church_name']} $col: kept '{$cur[$col]}' (list says '$val')";
        }
    }
}
echo "Contact details: $filled empty fields filled\n";
foreach ($differs as $d) echo "  kept: $d\n";
