<?php
// Bulletin Builder (bulletin-builder.php / bulletin-view.php): a weekly bulletin made from the parish's
// announcements, the week's Masses and events, and a few notices. When it is published, what it shows is frozen
// into bulletins.content (a JSON "snapshot"), so the archive stays the same when an announcement or event is
// edited later. It is shown as a print-ready page; the browser's "Save as PDF" makes the PDF.
require_once __DIR__ . '/event_helpers.php';

// Section headings in the bulletin's language (anything else falls back to English).
const BULLETIN_LABELS = [
    'en' => ['bulletin' => 'Weekly Bulletin', 'welcome' => 'From the Parish', 'schedule' => 'This Week at the Parish', 'mass' => 'Mass',
             'announcements' => 'Announcements', 'notices' => 'Parish Notices', 'contact' => 'Parish Office', 'urgent' => 'Important',
             'week' => 'Week of', 'nothing' => 'Nothing is scheduled this week.'],
    'ms' => ['bulletin' => 'Buletin Mingguan', 'welcome' => 'Daripada Paroki', 'schedule' => 'Minggu Ini di Paroki', 'mass' => 'Misa',
             'announcements' => 'Pengumuman', 'notices' => 'Notis Paroki', 'contact' => 'Pejabat Paroki', 'urgent' => 'Penting',
             'week' => 'Minggu', 'nothing' => 'Tiada acara dijadualkan minggu ini.'],
    'zh' => ['bulletin' => '每周堂区通讯', 'welcome' => '堂区的话', 'schedule' => '本周堂区活动', 'mass' => '弥撒',
             'announcements' => '公告', 'notices' => '堂区通知', 'contact' => '堂区办公室', 'urgent' => '重要',
             'week' => '本周', 'nothing' => '本周没有安排活动。'],
];

const BULLETIN_INTRO_MAX = 2000;
const BULLETIN_NOTICES_MAX = 20;

function bulletin_labels(string $lang): array
{
    return BULLETIN_LABELS[$lang] ?? BULLETIN_LABELS['en'];
}

/** The coming Sunday (today if it is Sunday): the usual date of a weekly bulletin. */
function bulletin_next_sunday(): string
{
    return date('w') === '0' ? date('Y-m-d') : date('Y-m-d', strtotime('next sunday'));
}

/** The last day the bulletin covers: six days after its date. */
function bulletin_week_end(string $date): string
{
    return date('Y-m-d', strtotime($date . ' +6 days'));
}

/**
 * What can go into the bulletin dated $date: the announcements that are live during its week (not expired by
 * $date, published by the end of the week), and every Mass and event happening in the week.
 * Returns ['announcements' => [id => row], 'schedule' => [key => item]]; a schedule key is "eventId:date".
 */
function bulletin_candidates(PDO $pdo, int $churchId, string $date): array
{
    $end = bulletin_week_end($date);

    $stmt = $pdo->prepare(
        'SELECT announcement_id, title, body, category, is_urgent, is_pinned, publish_date, expires_date
           FROM announcements
          WHERE church_id = ? AND publish_date <= ? AND (expires_date IS NULL OR expires_date >= ?)
       ORDER BY is_pinned DESC, is_urgent DESC, publish_date DESC, announcement_id DESC'
    );
    $stmt->execute([$churchId, $end, $date]);
    $announcements = [];
    foreach ($stmt as $a) $announcements[(int)$a['announcement_id']] = $a;

    $stmt = $pdo->prepare('SELECT * FROM events WHERE church_id = ? AND event_date <= ? AND (repeat_weekly = 1 OR event_date >= ?)');
    $stmt->execute([$churchId, $end, $date]);
    $occ = [];
    foreach ($stmt as $e) {
        foreach (event_occurrences($e, $date, $end) as $day) $occ[] = ['date' => $day, 'event' => $e];
    }
    sort_occurrences($occ);
    $schedule = [];
    foreach ($occ as ['date' => $day, 'event' => $e]) {
        $schedule[$e['event_id'] . ':' . $day] = [
            'date' => $day, 'time' => event_time_text($e), 'title' => $e['title'], 'type' => $e['event_type'],
            'location' => (string)$e['location'], 'mass' => $e['category'] === 'mass',
        ];
    }
    return ['announcements' => $announcements, 'schedule' => $schedule];
}

/**
 * Freezes the chosen content into the snapshot that is stored and shown.
 * $f: title, topic, lang, date, intro, notices (array of lines); $ann / $sched: the chosen candidate rows, keyed.
 */
function bulletin_snapshot(array $church, array $f, array $ann, array $sched): array
{
    return [
        'v' => 1,
        'church' => [
            'name' => $church['church_name'], 'location' => (string)$church['church_location'], 'address' => (string)$church['church_address'],
            'phone' => (string)$church['church_contact_details'], 'email' => (string)$church['church_email'],
            'website' => (string)$church['church_website'], 'hours' => (string)$church['church_office_hours'],
            'priest' => trim($church['church_priest'] ?? '') === '' ? '' : trim($church['church_priest']) . ($church['church_priest_title'] ? ', ' . $church['church_priest_title'] : ''),
        ],
        'title' => $f['title'], 'topic' => $f['topic'], 'language' => $f['lang'],
        'date' => $f['date'], 'week_end' => bulletin_week_end($f['date']),
        'intro' => $f['intro'],
        'announcements' => array_map(fn($a) => [
            'id' => (int)$a['announcement_id'], 'title' => $a['title'], 'body' => $a['body'], 'category' => $a['category'], 'urgent' => (bool)$a['is_urgent'],
        ], array_values($ann)),
        'schedule' => array_map(fn($key, $s) => ['key' => $key] + $s, array_keys($sched), array_values($sched)),
        'notices' => array_values($f['notices']),
    ];
}

/** The stored snapshot of a bulletin row, or null when it is an uploaded PDF (or unreadable). */
function bulletin_snapshot_of(array $row): ?array
{
    $snap = json_decode((string)($row['content'] ?? ''), true);
    return is_array($snap) && ($snap['v'] ?? 0) === 1 ? $snap : null;
}

/** Plain text as HTML paragraphs (blank line = new paragraph). */
function bulletin_paragraphs(string $text): string
{
    $out = '';
    foreach (preg_split("/\n\s*\n/", trim(str_replace("\r\n", "\n", $text))) as $p) {
        if (trim($p) !== '') $out .= '<p>' . nl2br(htmlspecialchars(trim($p), ENT_QUOTES, 'UTF-8')) . '</p>';
    }
    return $out;
}

/** The bulletin itself (an <article>), for the print page and the builder's preview. Style: bulletin_css(). */
function bulletin_render(array $s): string
{
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $L = bulletin_labels($s['language'] ?? 'en');
    $c = $s['church'];
    $day = fn(string $d) => date('l, j F Y', strtotime($d));
    $short = fn(string $d) => date('j M', strtotime($d));

    $o = '<article class="blt">';
    $o .= '<header class="blt-head">'
        . '<img class="blt-logo" src="assets/images/logo.png" alt="">'
        . '<div class="blt-head-main"><span class="blt-arch">Archdiocese of Kota Kinabalu</span>'
        . '<h1>' . $h($c['name']) . '</h1>'
        . ($c['location'] !== '' ? '<span class="blt-loc">' . $h($c['location']) . '</span>' : '') . '</div>'
        . '<div class="blt-head-side"><span class="blt-kind">' . $h($L['bulletin']) . '</span>'
        . ($s['title'] !== '' && $s['title'] !== $L['bulletin'] && $s['title'] !== BULLETIN_LABELS['en']['bulletin'] ? '<b class="blt-sunday">' . $h($s['title']) . '</b>' : '')
        . '<b>' . $h($day($s['date'])) . '</b>'
        . ($s['topic'] !== '' ? '<span class="blt-topic">' . $h($s['topic']) . '</span>' : '')
        . '<small>' . $h($L['week']) . ' ' . $h($short($s['date'])) . ' – ' . $h($short($s['week_end'])) . '</small></div>'
        . '</header>';

    if (trim((string)$s['intro']) !== '') {
        $o .= '<section class="blt-intro"><h2>' . $h($L['welcome']) . '</h2>' . bulletin_paragraphs($s['intro']) . '</section>';
    }

    if ($s['schedule']) {
        $o .= '<section class="blt-sec"><h2>' . $h($L['schedule']) . '</h2><table class="blt-sched">';
        $last = '';
        foreach ($s['schedule'] as $it) {
            if ($it['date'] !== $last) {
                $o .= '<tr class="blt-day"><th colspan="3">' . $h($day($it['date'])) . '</th></tr>';
                $last = $it['date'];
            }
            $o .= '<tr class="' . (!empty($it['mass']) ? 'is-mass' : '') . '"><td class="blt-t">' . $h($it['time']) . '</td>'
                . '<td><b>' . $h($it['title']) . '</b>' . (!empty($it['mass']) || $it['type'] === 'Mass' ? '' : ' <span class="blt-type">' . $h($it['type']) . '</span>') . '</td>'
                . '<td class="blt-where">' . $h($it['location']) . '</td></tr>';
        }
        $o .= '</table></section>';
    }

    if ($s['announcements']) {
        $o .= '<section class="blt-sec"><h2>' . $h($L['announcements']) . '</h2>';
        foreach ($s['announcements'] as $a) {
            $o .= '<div class="blt-ann' . ($a['urgent'] ? ' is-urgent' : '') . '"><h3>' . $h($a['title'])
                . ($a['urgent'] ? ' <span class="blt-flag">' . $h($L['urgent']) . '</span>' : '')
                . ' <span class="blt-cat">' . $h($a['category']) . '</span></h3>' . bulletin_paragraphs($a['body']) . '</div>';
        }
        $o .= '</section>';
    }

    if ($s['notices']) {
        $o .= '<section class="blt-sec"><h2>' . $h($L['notices']) . '</h2><ul class="blt-notices">';
        foreach ($s['notices'] as $n) $o .= '<li>' . $h($n) . '</li>';
        $o .= '</ul></section>';
    }

    $contact = array_filter([
        $c['address'], $c['phone'] !== '' ? 'Tel: ' . $c['phone'] : '', $c['email'], $c['website'],
    ], fn($v) => trim((string)$v) !== '');
    $o .= '<footer class="blt-foot"><div><b>' . $h($L['contact']) . '</b>'
        . ($contact ? '<span>' . implode(' · ', array_map($h, $contact)) . '</span>' : '')
        . ($c['hours'] !== '' ? '<span>' . nl2br($h($c['hours'])) . '</span>' : '')
        . ($c['priest'] !== '' ? '<span>' . $h($c['priest']) . '</span>' : '')
        . '</div><small>' . $h($c['name']) . ' · ' . $h($day($s['date'])) . '</small></footer>';

    return $o . '</article>';
}

/**
 * The cover on the Digital Bulletins card of a generated bulletin, in the same spirit as the uploaded covers:
 * the date, the Sunday or title, and the parish. Styled in style.css (.bul-gen-cover).
 */
function bulletin_cover_html(string $title, ?string $topic, string $date, string $lang, string $churchName): string
{
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $L = bulletin_labels($lang);
    $main = trim($title) !== '' && $title !== $L['bulletin'] && $title !== BULLETIN_LABELS['en']['bulletin'] ? $title : (trim((string)$topic) !== '' ? $topic : $L['bulletin']);
    $sub = $main === $topic ? '' : (string)$topic;
    return '<span class="bul-gen-cover" aria-hidden="true"><span class="bgc-frame">'
        . '<span class="bgc-date">' . $h(date('jS F Y', strtotime($date))) . '</span>'
        . '<span class="bgc-title">' . $h($main) . '</span>'
        . ($sub !== '' ? '<span class="bgc-sub">' . $h($sub) . '</span>' : '')
        . ($main !== $L['bulletin'] ? '<span class="bgc-kind">' . $h($L['bulletin']) . '</span>' : '')   // not twice when it is the title
        . '<span class="bgc-church"><img src="assets/images/logo.png" alt="">' . $h($churchName) . '</span>'
        . '</span></span>';
}

/** The bulletin's look, screen and print (A4). Everything is scoped to .blt so it can sit inside any page. */
function bulletin_css(): string
{
    return <<<'CSS'
.blt { --bp: #4a0d6e; --bg: #d4a72c; box-sizing: border-box; max-width: 210mm; margin: 0 auto; padding: 14mm 15mm 12mm; color: #24212b; background: #fff; font: 10.5pt/1.5 'Inter', Arial, sans-serif; }
.blt *, .blt *::before, .blt *::after { box-sizing: border-box; }
.blt-head { display: flex; align-items: center; gap: 14px; padding-bottom: 12px; margin-bottom: 16px; border-bottom: 3px solid var(--bg); }
.blt-logo { width: 54px; height: auto; flex: none; }
.blt-head-main { flex: 1; min-width: 0; }
.blt-arch { display: block; font: 600 8pt 'Inter', Arial, sans-serif; letter-spacing: .12em; text-transform: uppercase; color: #8a6a1a; }
.blt-head h1 { margin: 2px 0 0; font: 700 21pt/1.15 'Noto Serif', Georgia, serif; color: var(--bp); }
.blt-loc { font-size: 9.5pt; color: #6b6b76; }
.blt-head-side { display: flex; flex-direction: column; align-items: flex-end; gap: 1px; text-align: right; }
.blt-kind { padding: 3px 10px; font: 700 8pt 'Inter', Arial, sans-serif; letter-spacing: .08em; text-transform: uppercase; color: #fff; background: var(--bp); border-radius: 999px; }
.blt-head-side b { margin-top: 4px; font: 700 11pt 'Noto Serif', Georgia, serif; }
.blt-head-side b.blt-sunday { max-width: 62mm; font-size: 12.5pt; line-height: 1.25; color: var(--bp); }
.blt-head-side b.blt-sunday + b { margin-top: 0; font-size: 10pt; color: #4a4a55; }
.blt-topic { font-style: italic; color: #5b2d82; }
.blt-head-side small { font-size: 8.5pt; color: #8a8a99; }
.blt h2 { margin: 0 0 8px; padding-bottom: 4px; font: 700 13pt 'Noto Serif', Georgia, serif; color: var(--bp); border-bottom: 1px solid #e4dcee; }
.blt-intro { margin-bottom: 16px; padding: 12px 16px; background: #f8f3fc; border-left: 4px solid var(--bg); border-radius: 4px; break-inside: avoid; }
.blt-intro h2 { border: 0; padding: 0; margin-bottom: 4px; }
.blt p { margin: 0 0 6px; }
.blt-sec { margin-bottom: 16px; }
.blt-sched { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
.blt-sched td, .blt-sched th { padding: 4px 6px; text-align: left; vertical-align: top; }
.blt-sched tr { break-inside: avoid; }
.blt-day th { padding-top: 9px; font: 700 9.5pt 'Inter', Arial, sans-serif; color: #5b2d82; border-bottom: 1px solid #ece6f2; }
.blt-sched tr.is-mass td:first-child { box-shadow: inset 3px 0 0 var(--bg); }
.blt-t { width: 27%; white-space: nowrap; color: #4a4a55; font-variant-numeric: tabular-nums; }
.blt-where { width: 30%; color: #6b6b76; }
.blt-type { padding: 0 6px; font-size: 8pt; color: #5b2d82; background: #f1e9f9; border-radius: 999px; }
.blt-ann { margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px dashed #e4dcee; break-inside: avoid; }
.blt-ann:last-child { border-bottom: 0; }
.blt-ann h3 { margin: 0 0 4px; font: 700 11pt 'Inter', Arial, sans-serif; color: #1d1d24; }
.blt-cat { font: 500 8pt 'Inter', Arial, sans-serif; color: #8a8a99; }
.blt-flag { padding: 0 6px; font: 700 7.5pt 'Inter', Arial, sans-serif; letter-spacing: .05em; text-transform: uppercase; color: #fff; background: #b3261e; border-radius: 3px; }
.blt-ann.is-urgent { padding-left: 10px; border-left: 3px solid #b3261e; }
.blt-notices { margin: 0; padding-left: 18px; }
.blt-notices li { margin-bottom: 3px; }
.blt-foot { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; margin-top: 18px; padding-top: 10px; font-size: 8.5pt; color: #55555f; border-top: 3px solid var(--bg); break-inside: avoid; }
.blt-foot div { display: flex; flex-direction: column; gap: 2px; }
.blt-foot b { color: var(--bp); }
.blt-foot small { flex: none; color: #8a8a99; text-align: right; white-space: nowrap; }
@media print {
    /* the margins belong to every printed page, not only the first */
    @page { size: A4; margin: 13mm 15mm 12mm; }
    .blt { max-width: none; padding: 0; box-shadow: none !important; border: 0 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
CSS;
}
