<?php
// Helpers shared by the pages that show one church at a time
// (church-information.php, announcements.php).

/** Languages a bulletin can be published in: code stored in bulletins.language => label. */
const BULLETIN_LANGUAGES = [
    'en' => 'English',
    'ms' => 'Bahasa Malaysia',
    'zh' => 'Mandarin',
    'kzj' => 'Kadazan',
];

/** Where a saved church photo lives: admin uploads first, then the bundled assets folder. */
function church_photo_url(?string $file): ?string
{
    if (!$file || !preg_match('/^[\w.-]+$/', $file)) return null;
    $root = dirname(__DIR__);
    foreach (['uploads/church_photos/', 'assets/images/churches/'] as $dir) {
        if (is_file($root . '/' . $dir . $file)) return $dir . $file;
    }
    return null;
}

/**
 * Which church a page should show: the requested one if it exists, otherwise the signed-in
 * person's own parish (their home parish, or the church they administer), otherwise the first
 * church created. Returns 0 when there are no churches.
 *
 * @param array<int,array> $byId churches keyed by church_info_id
 */
function pick_church_id(PDO $pdo, array $byId, int $requested): int
{
    if (isset($byId[$requested])) return $requested;

    $own = 0;
    if (!empty($_SESSION['user_id'])) {
        $role = $_SESSION['role'] ?? '';
        if ($role === 'church_admin') {
            $stmt = $pdo->prepare('SELECT church_id FROM admin WHERE admin_id = ? AND admin_role = \'church_admin\'');
        } elseif ($role === 'user') {
            $stmt = $pdo->prepare('SELECT church_id FROM user WHERE user_id = ?');
        }
        if (isset($stmt)) {
            $stmt->execute([(int)$_SESSION['user_id']]);
            $own = (int)$stmt->fetchColumn();
        }
    }
    if (isset($byId[$own])) return $own;

    return $byId ? min(array_keys($byId)) : 0;
}

/**
 * May the signed-in person add, change and delete content for this church?
 * Super Admin: any church. Church Admin: only the church they are assigned to (read from the
 * database, not trusted from the session). Everyone else: no.
 */
function can_manage_church(PDO $pdo, int $churchId): bool
{
    if ($churchId <= 0 || empty($_SESSION['user_id'])) return false;

    $role = $_SESSION['role'] ?? '';
    if ($role === 'super_admin') {
        $stmt = $pdo->prepare('SELECT 1 FROM church_info WHERE church_info_id = ?');
        $stmt->execute([$churchId]);
        return (bool)$stmt->fetchColumn();
    }
    if ($role === 'church_admin') {
        $stmt = $pdo->prepare('SELECT church_id FROM admin WHERE admin_id = ? AND admin_role = \'church_admin\'');
        $stmt->execute([(int)$_SESSION['user_id']]);
        return (int)$stmt->fetchColumn() === $churchId;
    }
    return false;
}

/**
 * Where "back to the dashboard" goes for the signed-in admin, e.g. after saving an announcement.
 * $anchor is the panel to scroll to. The Super Admin has no announcements or bulletins panel on the dashboard,
 * so those two go to the Announcements page for that church instead.
 * Falls back to the public page for anyone who is not an admin.
 */
function dashboard_path(string $anchor, int $churchId): string
{
    $content = ['announcements' => '', 'bulletins' => '&tab=bulletins'];   // both admins manage these on the Announcements page
    if (isset($content[$anchor])) return 'announcements.php?church=' . $churchId . $content[$anchor];
    return match ($_SESSION['role'] ?? '') {
        'super_admin'  => 'superadmin/dashboard.php#' . $anchor,
        'church_admin' => 'churchadmin/dashboard.php#' . $anchor,
        default        => 'announcements.php?church=' . $churchId,
    };
}

/** Small line icons for the admin controls (announcement / bulletin buttons). */
function ann_icon(string $name, int $size = 15): string
{
    $paths = [
        'pencil' => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/>',
        'trash'  => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'plus'   => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'bell'   => '<path d="M10.27 21a2 2 0 0 0 3.46 0"/><path d="M3.26 15.33A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.67C19.41 13.96 18 12.5 18 8A6 6 0 0 0 6 8c0 4.5-1.41 5.96-2.74 7.33"/>',
        'megaphone' => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        'file'   => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
        'calendar' => '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
        'mail'   => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'check'  => '<path d="M20 6 9 17l-5-5"/>',
        'pin'    => '<path d="M12 17v5"/><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"/>',
    ];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[$name] . '</svg>';
}
// ---- Priests (a church can have several; see database/church_priests.sql) ----------------------

/**
 * SQL for "all this church's priests, comma-separated", to use as a column where only the names are needed:
 *   SELECT c.church_name, <PRIEST_NAMES_SQL> AS church_priest FROM church_info c ...
 * (the table must be aliased c).
 */
const PRIEST_NAMES_SQL = "(SELECT GROUP_CONCAT(p.priest_name ORDER BY p.sort_order, p.priest_id SEPARATOR ', ') FROM church_priests p WHERE p.church_id = c.church_info_id)";

/** A church's priests in display order. */
function church_priests(PDO $pdo, int $churchId): array
{
    try {
        $stmt = $pdo->prepare('SELECT * FROM church_priests WHERE church_id = ? ORDER BY sort_order, priest_id');
        $stmt->execute([$churchId]);
        return $stmt->fetchAll();
    } catch (PDOException $ex) {
        return [];   // table not created yet
    }
}

/** Where a priest's photo lives: admin uploads first, then the bundled clergy photos (database/seed_clergy.php). */
function priest_photo_url(?string $file): ?string
{
    if (!$file || !preg_match('/^[\w.-]+$/', $file)) return null;
    $root = dirname(__DIR__);
    foreach (['uploads/priest_photos/', 'assets/images/priests/'] as $dir) {
        if (is_file($root . '/' . $dir . $file)) return $dir . $file;
    }
    return null;
}

/** Only ever removes a photo an admin uploaded; the bundled clergy photos are shared between churches and stay. */
function delete_priest_photo(?string $file): void
{
    if ($file && preg_match('/^[\w.-]+$/', $file)) @unlink(dirname(__DIR__) . '/uploads/priest_photos/' . $file);
}

/**
 * Areas of the Archdiocese, used to group churches (Directory of Churches, Church Information).
 * key => [area name, small label, towns in display order]. A church whose town is not listed falls into
 * "Other Parishes", so a newly added church always shows up somewhere.
 */
const CHURCH_AREAS = [
    'kk'     => ['Kota Kinabalu', 'City', ['Kota Kinabalu', 'Tanjung Aru', 'Likas', 'Bukit Padang', 'Kepayan', 'Inanam', 'Manggatal', 'Telipok']],
    'pmg'    => ['Penampang', 'West Coast', ['Penampang', 'Dontozidon', 'Inobong', 'Terawi']],
    'papar'  => ['Papar', 'West Coast South', ['Papar', 'Limbahau', 'Kinarut']],
    'tuaran' => ['Tuaran & Kota Belud', 'West Coast North', ['Tuaran', 'Kiulu', 'Kota Belud']],
    'ranau'  => ['Ranau', 'Interior', ['Ranau', 'Bundu Tuhan']],
    'kudat'  => ['Kudat', 'Northern Tip', ['Kudat', 'Kota Marudu']],
    'labuan' => ['Labuan', 'Federal Territory', ['Labuan']],
];

/**
 * Group church rows (each needs church_name and church_location) by area.
 * Returns [key => ['name' => ..., 'tag' => ..., 'churches' => [...]]], only areas that have churches,
 * churches in the area's town order.
 */
function church_group_by_area(array $churches): array
{
    $groups = [];
    foreach (CHURCH_AREAS as $key => [$name, $tag]) $groups[$key] = ['name' => $name, 'tag' => $tag, 'churches' => []];
    $groups['other'] = ['name' => 'Other Parishes', 'tag' => 'Other', 'churches' => []];
    foreach ($churches as $c) {
        $town = mb_strtolower(trim((string)$c['church_location']));
        $c['_order'] = 0;
        $area = 'other';
        foreach (CHURCH_AREAS as $key => [, , $towns]) {
            $i = array_search($town, array_map('mb_strtolower', $towns), true);
            if ($i !== false) { $c['_order'] = $i; $area = $key; break; }
        }
        $groups[$area]['churches'][] = $c;
    }
    foreach ($groups as &$g) {
        usort($g['churches'], fn($a, $b) => [$a['_order'], $a['church_name']] <=> [$b['_order'], $b['church_name']]);
    }
    unset($g);
    return array_filter($groups, fn($g) => $g['churches']);
}

/**
 * A church's history as HTML: paragraphs, then the "Year – event" lines as a timeline.
 * A timeline line starts with something holding a year or decade ("1903", "1970–1971", "1960s", "Jan 1958")
 * followed by a dash; any other line is ordinary text.
 */
function church_history_html(string $text): string
{
    $h = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $paras = [];
    $buf = [];
    $events = [];
    foreach (preg_split('/\R/u', trim($text)) as $line) {
        $line = trim($line);
        if ($line !== '' && preg_match('/^(.{0,20}?\d{4}s?(?:\s*[–-]\s*\d{4})?)\s+[–—-]\s+(.+)$/u', $line, $m)) {
            if ($buf) { $paras[] = implode(' ', $buf); $buf = []; }
            $events[] = [$m[1], $m[2]];
        } elseif ($line === '') {
            if ($buf) { $paras[] = implode(' ', $buf); $buf = []; }
        } else {
            $buf[] = $line;
        }
    }
    if ($buf) $paras[] = implode(' ', $buf);

    $out = '';
    foreach ($paras as $p) $out .= '<p class="ch-text">' . $h($p) . '</p>';
    if ($events) {
        $out .= '<ol class="ch-timeline">';
        foreach ($events as [$when, $what]) {
            $out .= '<li><span class="ch-year">' . $h($when) . '</span><span class="ch-event">' . $h($what) . '</span></li>';
        }
        $out .= '</ol>';
    }
    return $out;
}

/** Office hours as a small table: "Mon - Fri: 8:30 AM - 4:30 PM" becomes the days on the left and the time on the right. */
function church_hours_html(string $text): string
{
    $h = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $out = '';
    foreach (preg_split('/\R/u', $text) as $line) {
        $line = trim(preg_replace('/\s+/u', ' ', $line), ' ;');
        if ($line === '') continue;
        if (preg_match('/^([^:\d]{2,30}):\s*(.+)$/u', $line, $m)) {
            $closed = (bool)preg_match('/^closed\b/i', $m[2]);
            $out .= '<span class="ch-hours-row' . ($closed ? ' is-closed' : '') . '"><span class="ch-day">' . $h(trim($m[1])) . '</span><span class="ch-time">' . $h($m[2]) . '</span></span>';
        } else {
            $out .= '<span class="ch-hours-row is-note">' . $h($line) . '</span>';
        }
    }
    return '<span class="ch-hours">' . $out . '</span>';
}

/** A web address shortened for display: no https://, no www., no trailing slash. */
function short_url(string $url): string
{
    return rtrim(preg_replace('~^https?://(www\.)?~i', '', trim($url)), '/');
}

/**
 * Read free-text office hours into a weekly schedule: [ISO weekday 1..7 => [[openMinute, closeMinute], ...]].
 * Understands lines like "Mon - Fri: 8:30 AM - 4:30 PM", "Monday to Friday: 9 am–1 pm & 2 pm–5 pm",
 * "Sat & Sun: Closed", "Sun Closed". Days that are never mentioned count as closed. Lines it cannot be sure of
 * ("Closed on first Saturdays", public holidays) are skipped. Returns null when no opening hours could be read
 * (e.g. "No separate parish office ..."), so no status is shown rather than a wrong one.
 */
function office_hours_schedule(string $text): ?array
{
    $dayIdx = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
    $week = array_fill(1, 7, []);
    $any = false;
    foreach (preg_split('/\R|\|/u', $text) as $line) {
        $line = preg_replace('/[\p{Zs}\t]+/u', ' ', $line);   // Google-style hours use thin / no-break spaces
        $line = strtolower(trim(str_replace(['–', '—'], ['-', '-'], $line), " ;."));
        if ($line === '' || preg_match('/\b(first|second|third|last|alternate|every other)\b/', $line)) continue;

        // the days come before the first digit
        $cut = preg_match('/\d/', $line, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : strlen($line);
        $dayPart = substr($line, 0, $cut);
        $timePart = substr($line, $cut);
        if (!preg_match_all('/\b(mon|tue|wed|thu|fri|sat|sun)[a-z]*/', $dayPart, $dm, PREG_OFFSET_CAPTURE)) continue;
        $days = [];
        $tokens = $dm[1];
        foreach ($tokens as $i => [$name, $pos]) {
            $d = $dayIdx[$name];
            $days[$d] = true;
            if ($i > 0) {
                $prevEnd = $tokens[$i - 1][1] + strlen($dm[0][$i - 1][0]);
                $between = substr($dayPart, $prevEnd, $pos - $prevEnd);
                if (preg_match('/^\s*(-|to|until|till)\s*$/', $between)) {   // a range: Mon - Fri
                    for ($x = $dayIdx[$tokens[$i - 1][0]]; $x !== $d; $x = $x % 7 + 1) $days[$x] = true;
                }
            }
        }

        $timePart = preg_replace('/\s+(to|until|till)\s+/', ' - ', $timePart);
        $ranges = [];
        if (preg_match_all('/(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm)?\s*-\s*(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm)?/', $timePart, $tm, PREG_SET_ORDER)) {
            foreach ($tm as $t) {
                $endMer = $t[6] ?? '';
                $startMer = $t[3] !== '' ? $t[3] : $endMer;
                $toMin = function (int $h, int $min, string $mer) {
                    if ($mer === 'pm' && $h < 12) $h += 12;
                    if ($mer === 'am' && $h === 12) $h = 0;
                    return $h * 60 + $min;
                };
                $s = $toMin((int)$t[1], (int)($t[2] ?? 0), $startMer);
                $e = $toMin((int)$t[4], (int)($t[5] ?? 0), $endMer);
                if ($t[3] === '' && $s > $e) $s = $toMin((int)$t[1], (int)($t[2] ?? 0), 'am');   // "9 - 4 pm"
                if ($e <= $s && $e < 720) $e += 720;                                                // "2 pm - 4:30 am" typo
                if ($e > $s && $e <= 24 * 60) $ranges[] = [$s, $e];
            }
        }
        if ($ranges) {
            foreach (array_keys($days) as $d) $week[$d] = array_merge($week[$d], $ranges);
            $any = true;
        } elseif (str_contains($line, 'closed')) {
            foreach (array_keys($days) as $d) $week[$d] = [];
        }
    }
    if (!$any) return null;
    foreach ($week as &$r) usort($r, fn($a, $b) => $a[0] <=> $b[0]);
    return $week;
}

/** "8:30 AM", "9 AM" from minutes after midnight. */
function office_time_label(int $min): string
{
    $h = intdiv($min, 60) % 24;
    $m = $min % 60;
    return ($h % 12 ?: 12) . ($m ? ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) : '') . ' ' . ($h < 12 ? 'AM' : 'PM');
}

/**
 * Open / closed right now, in Malaysia time. Returns ['open' => bool, 'text' => "Open now · closes 4:30 PM"
 * or "Closed · opens 8:30 AM Mon"], or null when there is no schedule.
 */
function office_status(?array $week, ?DateTimeInterface $now = null): ?array
{
    if (!$week) return null;
    $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur'));
    $day = (int)$now->format('N');
    $min = (int)$now->format('G') * 60 + (int)$now->format('i');
    foreach ($week[$day] as [$s, $e]) {
        if ($min >= $s && $min < $e) return ['open' => true, 'text' => 'Open now · closes ' . office_time_label($e)];
    }
    $names = [1 => 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    for ($i = 0; $i < 8; $i++) {
        $d = ($day - 1 + $i) % 7 + 1;
        foreach ($week[$d] as [$s]) {
            if ($i === 0 && $s <= $min) continue;
            $when = $i === 0 ? 'today' : ($i === 1 ? 'tomorrow' : $names[$d]);
            return ['open' => false, 'text' => 'Closed · opens ' . office_time_label($s) . ' ' . $when];
        }
    }
    return null;
}

/**
 * A Google Maps 360° embed address (Street View or a 360° photo), as produced by Google Maps
 * "Share > Embed a map": https://www.google.com/maps/embed?pb=... Anything else gives null, so only Google's own
 * player is ever put in the page.
 */
function virtual_tour_url(?string $url): ?string
{
    $url = trim((string)$url);
    return preg_match('#^https://www\.google\.com/maps/embed\?pb=[A-Za-z0-9!._~%:+\-]+$#', $url) ? $url : null;
}

/** Google Maps opened at the 360° street-level view nearest to a point (works without an API key). */
function street_view_url(float $lat, float $lng): string
{
    return 'https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=' . rawurlencode($lat . ',' . $lng);
}

/** Where a Google Maps 360° embed was taken: [lat, lng] read from its address (the "!1d<lat>!2d<lng>" part), or null. */
function virtual_tour_point(string $url): ?array
{
    return preg_match('/!1d(-?\d{1,2}\.\d+)!2d(-?\d{1,3}\.\d+)/', $url, $m) ? [(float)$m[1], (float)$m[2]] : null;
}

/** Distance in metres between two points (haversine). */
function distance_m(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 6371000 * 2 * asin(min(1, sqrt($a)));
}
