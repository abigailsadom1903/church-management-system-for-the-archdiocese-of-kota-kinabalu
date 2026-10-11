<?php
// Second pass of parish contact details, gathered from the Archdiocese's official per-parish pages
// (https://www.catholicadkk.org/<parish-slug>/), each parish's own website where it has one, and a
// targeted web search for a parish Facebook page where neither of those listed one.
// Run once from the project folder:   php database/seed_parish_details_2.php
//
// Only fields that are still empty are filled; nothing already saved is changed.
// Left blank on purpose (nothing reliable found, not guessed): a handful of small parishes have no
// published email, website or office hours at all, and are administered from a neighbouring parish office.

require __DIR__ . '/../config/db.php';

$contact = [
    2  => ['church_email' => 'ssccl010608@gmail.com', 'church_facebook' => 'https://www.facebook.com/StSimonLikas',
           'church_office_hours' => 'Mon-Fri: 9:00 AM - 1:00 PM & 2:00 PM - 5:00 PM; Sat: 9:00 AM - 1:00 PM'],   // St Simon, Likas — stsimonlikas.org
    3  => ['church_email' => 'stpauldontozidon2018@gmail.com', 'church_facebook' => 'https://www.facebook.com/OfficialStPaulDontozidon'],   // St Paul, Dontozidon
    5  => ['church_office_hours' => 'No separate parish office. For any inquiries, contact St. Catherine\'s Parish Office, Inanam, Tel: 088-421293'],   // Good Shepherd, Manggatal
    6  => ['church_office_hours' => 'No separate parish office. For any inquiries, contact St. Catherine\'s Parish Office, Inanam, Tel: 088-421293'],   // Holy Family, Telipok
    11 => ['church_email' => 'stmicparishpenampang@gmail.com', 'church_facebook' => 'https://www.facebook.com/Komiti-Komunikasi-Sosial-Paroki-St-Michael-Penampang-601745656631167/'],   // St Michael, Penampang — stmichaelparishpenampang.com
    13 => ['church_facebook' => 'https://www.facebook.com/sjccpapar/'],   // St Joseph the Worker, Papar
    15 => ['church_facebook' => 'https://www.facebook.com/p/St-Pius-X-Bundu-Tuhan-100064889520054/'],   // St Pius X, Bundu Tuhan
    17 => ['church_facebook' => 'https://www.facebook.com/p/Paroki-St-Edmund-Kota-Belud-100064361782682/'],   // St Edmund, Kota Belud
    18 => ['church_facebook' => 'https://www.facebook.com/pages/Gereja%20Katolik%20St%20Theresa%20Kota%20Marudu/500637283666634/'],   // St Theresa, Kota Marudu
    19 => ['church_website' => 'http://stpeterkudat.tripod.com'],   // St Peter, Kudat — as listed on the Archdiocese's own parish page (an old site; please confirm it still works)
];

$filled = 0;
$differs = [];
foreach ($contact as $churchId => $cols) {
    $row = $pdo->prepare('SELECT * FROM church_info WHERE church_info_id = ?');
    $row->execute([$churchId]);
    $cur = $row->fetch();
    if (!$cur) continue;
    foreach ($cols as $col => $val) {
        if ($cur[$col] === null || trim((string)$cur[$col]) === '') {
            $pdo->prepare("UPDATE church_info SET $col = ? WHERE church_info_id = ?")->execute([$val, $churchId]);
            $filled++;
            echo "  {$cur['church_name']}: $col = $val\n";
        } elseif (trim((string)$cur[$col]) !== $val) {
            $differs[] = "{$cur['church_name']} $col: kept '{$cur[$col]}' (found '$val')";
        }
    }
}
echo "Filled: $filled empty fields\n";
foreach ($differs as $d) echo "  kept: $d\n";
