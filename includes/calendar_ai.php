<?php
// "This week" summary on the Church Calendar: a few friendly sentences about the next 7 days at a church,
// written by the AI (includes/gemini.php) from the calendar's own entries only, so it cannot invent times.
// Each summary is kept in logs/calendar-summaries/ and reused until the schedule changes or the day turns over,
// so the AI is asked about once a day per church. With no AI set up, or when it is busy, a plain summary is
// written without it, so the card is never empty.
require_once __DIR__ . '/gemini.php';
require_once __DIR__ . '/event_helpers.php';
require_once __DIR__ . '/liturgical.php';

const CAL_AI_DAYS = 7;

/**
 * The facts the summary may use, as lines of text, for the next CAL_AI_DAYS days from $today.
 * Regular Masses are folded together ("Sunday Mass (English): Sun 15 Nov 9:30 AM, Sun 22 Nov ..."), so a church
 * with a daily Mass does not drown out its one parish event.
 */
function cal_ai_facts(PDO $pdo, int $churchId, string $filter, string $today): array
{
    $to = date('Y-m-d', strtotime($today . ' +' . (CAL_AI_DAYS - 1) . ' days'));
    $where = ['e.event_date <= ?', '((e.repeat_weekly = 0 AND e.event_date >= ?) OR (e.repeat_weekly = 1 AND (e.repeat_until IS NULL OR e.repeat_until >= ?)))'];
    $params = [$to, $today, $today];
    if ($churchId > 0) { $where[] = 'e.church_id = ?'; $params[] = $churchId; }
    if ($filter !== '') { $where[] = 'e.category = ?'; $params[] = $filter; }
    $st = $pdo->prepare('SELECT e.*, c.church_name FROM events e JOIN church_info c ON c.church_info_id = e.church_id WHERE ' . implode(' AND ', $where));
    $st->execute($params);

    $masses = [];
    $events = [];
    foreach ($st->fetchAll() as $ev) {
        foreach (event_occurrences($ev, $today, $to) as $d) {
            $when = date('D j M', strtotime($d)) . ($ev['is_all_day'] ? ' (all day)' : ' ' . fmt_time($ev['start_time']));
            $where = trim(($ev['location'] ?: '') . ($churchId === 0 ? ($ev['location'] ? ', ' : '') . $ev['church_name'] : ''));
            if ($ev['category'] === 'mass') {
                $masses[$ev['title'] . ($churchId === 0 ? ' at ' . $ev['church_name'] : '')][] = [$d . ' ' . $ev['start_time'], $when];
            } else {
                $reg = $ev['registration_required'] && !$ev['repeat_weekly'] ? ' (registration needed)' : '';
                $events[] = [$d . ' ' . $ev['start_time'], '- ' . $ev['title'] . ': ' . $when . ($where !== '' ? ' at ' . $where : '') . $reg];
            }
        }
    }
    usort($events, fn($a, $b) => strcmp($a[0], $b[0]));

    $lines = [];
    foreach ($masses as $title => $times) {
        usort($times, fn($a, $b) => strcmp($a[0], $b[0]));
        $lines[] = '- ' . $title . ': ' . implode(', ', array_column($times, 1));
    }
    ksort($lines);
    $feasts = [];
    for ($d = $today; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        if ($f = lit_feast($d)) $feasts[] = '- ' . date('D j M', strtotime($d)) . ': ' . $f[0];
    }
    $season = lit_season($today);
    return [
        'range'   => date('D j M', strtotime($today)) . ' to ' . date('D j M', strtotime($to)),
        'season'  => $season['name'] . ' (' . $season['next'] . ')',
        'masses'  => $lines,
        'events'  => array_column($events, 1),
        'feasts'  => $feasts,
    ];
}

/** The plain summary written without the AI (also used whenever the AI cannot answer). */
function cal_ai_plain(array $facts, string $place): string
{
    $parts = [];
    $n = count($facts['events']);
    if ($n) {
        $first = preg_replace('/^- /', '', $facts['events'][0]);
        $parts[] = $n === 1 ? "Coming up at $place: $first." : "$n parish events at $place this week, starting with $first.";
    }
    if ($facts['masses']) {
        $titles = array_map(fn($l) => preg_replace('/^- ([^:]+):.*$/', '$1', $l), $facts['masses']);
        $parts[] = 'Mass times this week: ' . implode(', ', array_slice($titles, 0, 4)) . (count($titles) > 4 ? ' and more' : '') . '.';
    }
    if ($facts['feasts']) $parts[] = 'Feasts: ' . implode('; ', array_map(fn($l) => preg_replace('/^- /', '', $l), $facts['feasts'])) . '.';
    if (!$parts) $parts[] = "Nothing is on the calendar at $place for the next 7 days.";
    return implode(' ', $parts);
}

/**
 * The summary for a church (0 = all churches) and filter ('' | 'mass' | 'event'):
 * ['text' => ..., 'ai' => true if written by the AI, 'range' => 'Thu 12 Nov to Wed 18 Nov'].
 * $cachedOnly: only return a saved summary (null when there is none yet), never ask the AI.
 */
function calendar_summary(PDO $pdo, int $churchId, string $filter, string $place, bool $cachedOnly = false): ?array
{
    $today = date('Y-m-d');
    $facts = cal_ai_facts($pdo, $churchId, $filter, $today);
    $key = md5(json_encode([$churchId, $filter, $today, $facts]));   // a schedule change or a new day makes a new key
    $dir = dirname(__DIR__) . '/logs/calendar-summaries';
    $file = $dir . '/' . $key . '.json';

    if (is_file($file)) {
        $saved = json_decode((string)@file_get_contents($file), true);
        if (is_array($saved) && isset($saved['text'])) return $saved + ['range' => $facts['range']];
    }
    if ($cachedOnly) return null;

    $out = null;
    if (gemini_ready()) {
        $system = 'You write the short "This week" note at the top of a Catholic parish calendar in Sabah, Malaysia. '
                . 'Write 2 or 3 warm, plain sentences (at most 70 words) telling parishioners what is coming up in the next 7 days: '
                . 'the parish events first (with day and time), then the Masses in a compact way (for example the languages offered), '
                . 'and any feast day. Use ONLY the facts given; never add a time, place, event or detail that is not listed. '
                . 'Write times and dates exactly as given, in figures (e.g. "7:30 AM", "Sat 14 Nov"), never in words. '
                . 'If nothing is listed, say so kindly. Plain text only: no markdown, no lists, no emoji, no greeting, no sign-off.';
        $prompt = "Church: $place\nDays: {$facts['range']}\nLiturgical season: {$facts['season']}\n\nParish events:\n"
                . ($facts['events'] ? implode("\n", $facts['events']) : '- none') . "\n\nMasses:\n"
                . ($facts['masses'] ? implode("\n", array_slice($facts['masses'], 0, 15)) : '- none') . "\n\nFeast days:\n"
                . ($facts['feasts'] ? implode("\n", $facts['feasts']) : '- none');
        $res = gemini_generate($system, [['role' => 'user', 'parts' => [['text' => $prompt]]]], ['temperature' => 0.4, 'maxOutputTokens' => 220], 15, 2);
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($res['text'] ?? ''))), " \"\n");
        $text = preg_replace('/[*_#`]+/', '', $text);   // in case the model used markdown anyway
        if ($text !== '' && mb_strlen($text) <= 700) $out = ['text' => $text, 'ai' => true];
    }
    $out ??= ['text' => cal_ai_plain($facts, $place), 'ai' => false];

    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if ($out['ai']) {   // only AI answers are kept, so a plain fallback is retried next time
        @file_put_contents($file, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        foreach (glob($dir . '/*.json') ?: [] as $old) if (filemtime($old) < time() - 3 * 86400) @unlink($old);   // tidy old days
    }
    return $out + ['range' => $facts['range']];
}
