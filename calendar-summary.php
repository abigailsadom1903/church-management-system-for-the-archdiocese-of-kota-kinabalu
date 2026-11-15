<?php
// The Church Calendar's "This week" summary, fetched by the page after it has loaded (the AI can take a few
// seconds, so the calendar itself never waits for it). Signed-in people only, like the calendar.
// GET ?church=<id or 0>&type=<'' | mass | event>  ->  {"text": "...", "ai": true, "range": "Thu 12 Nov to Wed 18 Nov"}
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/calendar_ai.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in.']);
    exit;
}
session_write_close();   // do not hold the session while the AI is writing

$churchId = (int)($_GET['church'] ?? 0);
$filter = in_array($_GET['type'] ?? '', ['mass', 'event'], true) ? $_GET['type'] : '';
$place = 'all churches';
if ($churchId > 0) {
    $st = $pdo->prepare('SELECT church_name FROM church_info WHERE church_info_id = ?');
    $st->execute([$churchId]);
    $place = $st->fetchColumn();
    if ($place === false) {
        http_response_code(404);
        echo json_encode(['error' => 'Unknown church.']);
        exit;
    }
}

echo json_encode(calendar_summary($pdo, $churchId, $filter, $place), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
