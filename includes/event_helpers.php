<?php
// Shared by the Church Calendar pages: events.php, event.php, event-form.php, event-participants.php.

/** Suggested labels for the event "type" (any label is allowed; unknown ones use the neutral colour). */
const EVENT_TYPES = ['Mass', 'Adoration', 'Confession', 'Meeting', 'Practice', 'Youth', 'Community', 'Retreat', 'Other'];

/** CSS colour class for a type label, e.g. "Youth Camp" -> "ev-t-youth" (falls back to ev-t-other). */
function event_type_class(string $type): string
{
    $slug = strtolower(preg_replace('/[^a-z]/i', '', strtok(trim($type), ' ') ?: ''));
    return 'ev-t-' . (in_array($slug, ['mass', 'adoration', 'confession', 'meeting', 'practice', 'youth', 'community', 'retreat'], true) ? $slug : 'other');
}

/** "08:00:00" -> "8:00 AM". */
function fmt_time(?string $t): string
{
    return $t ? date('g:i A', strtotime($t)) : '';
}

/** "10:00 AM - 12:00 PM", "8:00 AM" or "All Day". */
function event_time_text(array $e): string
{
    if (!empty($e['is_all_day']) || !$e['start_time']) return 'All Day';
    return fmt_time($e['start_time']) . ($e['end_time'] ? ' – ' . fmt_time($e['end_time']) : '');
}

/**
 * Dates (Y-m-d) on which an event happens inside [$from, $to]. A one-off event has one date;
 * a weekly event repeats on the same weekday from its first date until repeat_until (or forever).
 */
function event_occurrences(array $e, string $from, string $to): array
{
    $start = $e['event_date'];
    if (!$e['repeat_weekly']) return ($start >= $from && $start <= $to) ? [$start] : [];

    $last = ($e['repeat_until'] && $e['repeat_until'] < $to) ? $e['repeat_until'] : $to;
    if ($start > $last) return [];

    $d = new DateTime($start);
    if ($start < $from) {
        $days = (new DateTime($start))->diff(new DateTime($from))->days;
        $d->modify('+' . (int)(ceil($days / 7) * 7) . ' days');      // first occurrence on or after $from
    }
    $out = [];
    for ($guard = 0; $d->format('Y-m-d') <= $last && $guard < 400; $guard++) {
        $out[] = $d->format('Y-m-d');
        $d->modify('+7 days');
    }
    return $out;
}

/** Next date (>= $today) an event happens, or null if it is over. */
function event_next_date(array $e, string $today): ?string
{
    if (!$e['repeat_weekly']) return $e['event_date'] >= $today ? $e['event_date'] : null;
    if ($e['repeat_until'] && $e['repeat_until'] < $today) return null;
    return event_occurrences($e, $today, date('Y-m-d', strtotime($today . ' +8 days')))[0] ?? null;
}

/** Sorts [['date' =>, 'event' =>], ...] by date, then by start time (all-day first). */
function sort_occurrences(array &$occ): void
{
    usort($occ, fn($a, $b) => [$a['date'], (string)$a['event']['start_time'], $a['event']['title']] <=> [$b['date'], (string)$b['event']['start_time'], $b['event']['title']]);
}

/** Small line icons for the calendar pages. */
function ev_icon(string $name, int $size = 16): string
{
    $p = [
        'copy'     => '<rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
        'clock'    => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'pin'      => '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'users'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'left'     => '<path d="m15 18-6-6 6-6"/>',
        'right'    => '<path d="m9 18 6-6-6-6"/>',
        'plus'     => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'pencil'   => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/>',
        'trash'    => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'church'   => '<path d="m18 7 4 2v11a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9l4-2"/><path d="M14 22v-4a2 2 0 0 0-2-2a2 2 0 0 0-2 2v4"/><path d="M18 22V5l-6-3-6 3v17"/><path d="M12 7v5"/><path d="M10 9h4"/>',
        'repeat'   => '<path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>',
        'calendar' => '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
        'check'    => '<path d="M20 6 9 17l-5-5"/>',
        'down'     => '<path d="m6 9 6 6 6-6"/>',
        'mail'     => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'bell'     => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
    ];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p[$name] . '</svg>';
}

/** "David & Sarah Wong" -> "DS" (first letters of the first two words that start with a letter or digit). */
function person_initials(string $name): string
{
    preg_match_all('/[\p{L}\p{N}]+/u', $name, $m);
    $words = $m[0];
    $out = mb_substr($words[0] ?? '?', 0, 1) . (isset($words[1]) ? mb_substr($words[1], 0, 1) : '');
    return mb_strtoupper($out);
}

/** 1 -> "1 person", 3 -> "3 people". */
function people_label(int $n): string
{
    return $n === 1 ? '1 person' : $n . ' people';
}

/** Which of a handful of avatar colours a name gets (stable, so a person keeps their colour). */
function avatar_class(string $name): string
{
    return 'av-' . (abs(crc32(mb_strtolower($name))) % 4);
}
