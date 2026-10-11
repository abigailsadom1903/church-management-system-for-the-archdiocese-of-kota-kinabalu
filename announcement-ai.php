<?php
// Back end of "Generate with AI" on announcement-form.php: takes the details an admin typed, returns a draft
// as JSON. It never saves anything; the admin reviews the draft and saves the announcement themselves.
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/announcement_ai.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'Use POST.');
if (empty($_SESSION['user_id'])) fail(401, 'Please sign in again.');

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    fail(403, 'Your session expired. Please reload the page.');
}

// Only an admin who may manage this church's announcements (Super Admin: any; Church Admin: their own).
$churchId = (int)($in['church'] ?? 0);
if (!can_manage_church($pdo, $churchId)) fail(403, 'You do not have permission to write announcements for this church.');
$stmt = $pdo->prepare('SELECT church_name FROM church_info WHERE church_info_id = ?');
$stmt->execute([$churchId]);
$churchName = (string)$stmt->fetchColumn();

$field = fn(string $k, int $max) => mb_substr(trim((string)($in[$k] ?? '')), 0, $max);
$data = [
    'church'   => $churchName,
    'title'    => $field('title', 150),
    'when'     => $field('when', 150),
    'location' => $field('location', 150),
    'audience' => $field('audience', 150),
    'details'  => $field('details', 2000),
    'language' => isset(ANNOUNCEMENT_AI_LANGUAGES[$in['language'] ?? '']) ? $in['language'] : 'en',
];
if (mb_strlen($data['details']) < 10 && ($data['title'] === '' || ($data['when'] === '' && $data['location'] === ''))) {
    fail(400, 'Describe the announcement in a sentence or two first.');
}

// At most 10 drafts every 5 minutes per admin.
$now = time();
$recent = array_values(array_filter($_SESSION['ai_gen_times'] ?? [], fn($t) => $t > $now - 300));
if (count($recent) >= 10) fail(429, 'You have generated several drafts in a row. Please wait a few minutes.');
$recent[] = $now;
$_SESSION['ai_gen_times'] = $recent;
session_write_close();   // the AI call can take a few seconds; do not hold the session meanwhile

$result = generate_announcement($data);
if (isset($result['error'])) fail(announcement_ai_ready() ? 502 : 503, $result['error']);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
