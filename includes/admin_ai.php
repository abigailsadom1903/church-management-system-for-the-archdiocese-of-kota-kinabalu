<?php
// AI that helps the church admin (admin-ai.php answers the pages):
//   1. "This week" briefing of the pastoral care requests (pastoral-manage.php list)
//   2. Suggested note for the parishioner on a request / application (the Decision panel, includes/ui.php)
//
// Privacy: the AI only sees what it needs. Never sent: names (except a first name for the greeting of a
// reply), phone numbers, email addresses, home addresses, or what people wrote in their own words
// (prayer intentions, counselling overviews, notes). The briefing works from request numbers, services,
// statuses and dates; the admin opens the request to see who it is.
require_once __DIR__ . '/gemini.php';
require_once __DIR__ . '/sacrament_helpers.php';
require_once __DIR__ . '/pastoral_helpers.php';

/** The non-private answers of a request: dates/times and choices (radio / select), as "Label: value". */
function admin_ai_safe_fields(?array $cfg, array $details): array
{
    $out = [];
    foreach ($cfg ? sx_fields($cfg) : [] as $k => $fd) {
        if (!in_array($fd['t'], ['date', 'datetime', 'radio', 'select'], true)) continue;
        $v = sx_show($fd, (string)($details[$k] ?? ''));
        if ($v !== '') $out[] = $fd['l'] . ': ' . $v;
    }
    return $out;
}

/** The requests the briefing is about: sent in the last 7 days, or still waiting for a decision. */
function admin_ai_week_facts(PDO $pdo, int $churchId): array
{
    $st = $pdo->prepare(
        "SELECT request_id, service, status, details, created_at FROM pastoral_requests
          WHERE church_id = ? AND status <> 'draft' AND (created_at >= NOW() - INTERVAL 7 DAY OR status IN ('submitted', 'review'))
          ORDER BY created_at LIMIT 60"
    );
    $st->execute([$churchId]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $cfg = pc_form($pdo, $r['service'], $churchId);
        $days = (int)floor((time() - strtotime($r['created_at'])) / 86400);
        $rows[] = [
            'ref' => pc_ref((int)$r['request_id']),
            'id' => (int)$r['request_id'],
            'service' => pc_title($pdo, $r['service'], $churchId),
            'status' => pc_status($r['status'])[0],
            'open' => in_array($r['status'], ['submitted', 'review'], true),
            'sent' => date('D j M', strtotime($r['created_at'])),
            'waiting' => $days,
            'fields' => admin_ai_safe_fields($cfg, json_decode((string)$r['details'], true) ?: []),
        ];
    }
    return $rows;
}

/** The briefing written without the AI (no AI set up, or it is busy). */
function admin_ai_week_plain(array $rows): array
{
    $open = array_values(array_filter($rows, fn($r) => $r['open']));
    $new = count(array_filter($rows, fn($r) => $r['waiting'] < 7));
    $by = [];
    foreach ($open as $r) $by[$r['service']] = ($by[$r['service']] ?? 0) + 1;
    arsort($by);
    $points = [];
    foreach (array_slice($by, 0, 3, true) as $svc => $n) $points[] = "$svc: $n waiting for a decision.";
    usort($open, fn($a, $b) => $b['waiting'] <=> $a['waiting']);
    if ($open && $open[0]['waiting'] >= 2) $points[] = $open[0]['ref'] . ' (' . $open[0]['service'] . ') has waited the longest: ' . $open[0]['waiting'] . ' days.';
    return [
        'headline' => $rows ? "$new new " . ($new === 1 ? 'request' : 'requests') . ' this week; ' . count($open) . ' waiting for a decision.' : 'No pastoral care requests this week.',
        'points' => $points,
    ];
}

/**
 * The briefing: ['headline', 'points' => [...], 'ai' => bool, 'count' => open requests].
 * Saved per church in logs/admin-ai/ until the requests change or an hour passes.
 */
function admin_ai_pastoral_week(PDO $pdo, int $churchId, string $churchName): array
{
    $rows = admin_ai_week_facts($pdo, $churchId);
    $open = count(array_filter($rows, fn($r) => $r['open']));
    $key = md5(json_encode([$churchId, date('Y-m-d H'), $rows]));
    $dir = dirname(__DIR__) . '/logs/admin-ai';
    $file = "$dir/week-$key.json";
    if (is_file($file) && is_array($saved = json_decode((string)@file_get_contents($file), true))) return $saved + ['count' => $open];

    $out = null;
    if ($rows && gemini_ready()) {
        $lines = array_map(fn($r) => '- ' . $r['ref'] . ' | ' . $r['service'] . ' | ' . $r['status'] . ' | sent ' . $r['sent']
            . ($r['open'] ? ' | waiting ' . $r['waiting'] . ' day' . ($r['waiting'] === 1 ? '' : 's') : '')
            . ($r['fields'] ? ' | ' . implode('; ', $r['fields']) : ''), $rows);
        $system = 'You help the parish office of a Catholic church in Sabah, Malaysia, plan its week. You get a list of pastoral care '
                . 'requests (request number, service, status, when it was sent, how long it has waited, and the dates people asked for). '
                . 'Write a short briefing for the church admin: a one-sentence headline with the numbers, then at most 5 points saying what '
                . 'needs attention first and why (sick visits and funerals, requested dates that are soon, and requests that have waited long '
                . 'come first), then any useful pattern. Always name requests by their number (e.g. PC-00012). Use ONLY the facts given: '
                . 'never invent names, dates or details. Today is ' . date('l j F Y') . '. Plain, practical English; no greeting, no markdown.';
        $res = gemini_generate($system, [['role' => 'user', 'parts' => [['text' => "Church: $churchName\nRequests:\n" . implode("\n", $lines)]]]], [
            'temperature' => 0.3, 'maxOutputTokens' => 500, 'responseMimeType' => 'application/json',
            'responseSchema' => ['type' => 'OBJECT', 'properties' => [
                'headline' => ['type' => 'STRING', 'description' => 'One sentence with the numbers'],
                'points' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'At most 5 short points, most urgent first'],
            ], 'required' => ['headline', 'points']],
        ], 20, 2);
        $j = json_decode(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim((string)($res['text'] ?? ''))), true);
        if (is_array($j) && !empty($j['headline'])) {
            $clean = fn($s) => trim(preg_replace('/[*_#`]+/', '', strip_tags((string)$s)));
            $out = ['headline' => $clean($j['headline']), 'points' => array_slice(array_values(array_filter(array_map($clean, (array)($j['points'] ?? [])))), 0, 5), 'ai' => true];
        }
    }
    $out ??= admin_ai_week_plain($rows) + ['ai' => false];

    if ($out['ai']) {
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents($file, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        foreach (glob("$dir/week-*.json") ?: [] as $old) if (filemtime($old) < time() - 2 * 86400) @unlink($old);
    }
    return $out + ['count' => $open];
}

/**
 * A suggested note for the parishioner. $in: kind ('pastoral request' | 'sacrament application'), title, intent
 * ('approve' | 'info' | 'decline'), first (first name), church, fields (safe "Label: value" lines), missing (documents
 * not provided), gist (what the admin typed, may be ''). Returns ['text'] or ['error' => message].
 */
function admin_ai_reply(array $in): array
{
    if (!gemini_ready()) return ['error' => 'The AI is not set up. Add GEMINI_API_KEY or GROQ_API_KEY to the .env file.'];
    $goal = [
        'approve' => 'The request is APPROVED. Confirm it warmly and say the parish office will be in touch with any next steps.',
        'info'    => 'The office needs MORE INFORMATION or documents before it can decide. Ask clearly and kindly for what is needed.',
        'decline' => 'The request is DECLINED. Explain the reason (from the admin\'s notes) gently and suggest contacting the parish office.',
    ][$in['intent']] ?? '';
    $system = 'You write the short note a Catholic parish office in Sabah, Malaysia sends to a parishioner with an update on their '
            . $in['kind'] . '. ' . $goal . ' Write 1 to 3 warm, respectful, plain sentences, at most 230 characters in all. '
            . 'Start with "Dear <first name>," when a first name is given. Use only the facts given and the admin\'s notes; never invent '
            . 'dates, times, fees or requirements. If the admin\'s notes are in another language, write in that language. '
            . 'Plain text only: no markdown, no sign-off, no placeholders in brackets.';
    $prompt = 'Service: ' . $in['title'] . "\nChurch: " . $in['church'] . "\nFirst name: " . ($in['first'] ?: '(not given)')
            . ($in['fields'] ? "\nDetails: " . implode('; ', $in['fields']) : '')
            . ($in['missing'] ? "\nDocuments not provided: " . implode(', ', $in['missing']) : '')
            . "\nAdmin's notes: " . ($in['gist'] !== '' ? $in['gist'] : '(none)');
    $res = gemini_generate($system, [['role' => 'user', 'parts' => [['text' => $prompt]]]], ['temperature' => 0.5, 'maxOutputTokens' => 160], 15, 2);
    if (empty($res['text'])) {
        return ['error' => [
            'limit_day' => "Today's free AI allowance has been used up. Please write the note yourself.",
            'limit' => 'The AI is busy. Please wait a minute and try again.',
        ][$res['error'] ?? ''] ?? 'The AI could not write a note just now. Please try again, or write it yourself.'];
    }
    $text = trim(preg_replace('/\s+/u', ' ', preg_replace('/[*_#`]+/', '', strip_tags($res['text']))), " \"'");
    if (mb_strlen($text) > 255) $text = rtrim(mb_substr($text, 0, mb_strrpos(mb_substr($text, 0, 255), '.') ?: 252), ' ,') . (mb_strrpos(mb_substr($text, 0, 255), '.') ? '.' : '...');
    return ['text' => $text];
}
