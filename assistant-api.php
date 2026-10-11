<?php
// The Church Assistant's back end: takes one question, returns one answer as JSON.
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/assistant_engine.php';
require __DIR__ . '/includes/assistant_ai.php';

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
if (empty($_SESSION['user_id'])) fail(401, 'Please sign in to use the assistant.');

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) fail(403, 'Your session expired. Please reload the page.');

$message = trim((string)($in['message'] ?? ''));
if ($message === '') fail(400, 'Please type a question.');
if (mb_strlen($message) > 300) fail(400, 'Please keep your question under 300 characters.');

// At most 20 questions a minute per person.
$now = time();
$_SESSION['assistant_hits'] = array_values(array_filter($_SESSION['assistant_hits'] ?? [], fn($t) => $t > $now - 60));
if (count($_SESSION['assistant_hits']) >= 20) fail(429, 'You are asking very quickly. Please wait a moment.');
$_SESSION['assistant_hits'][] = $now;

// The church: a parishioner's own parish, a Church Admin's own church, the Super Admin's default. Never taken from the request.
// A public user with no home parish set gets churchId 0 here (never an arbitrary church) — pick_church_id()'s usual
// fallback to "the first church" is wrong for the assistant, which would otherwise confidently read out a stranger
// church's bank account and phone number as if they were the person's own parish.
$role = $_SESSION['role'] ?? '';
if ($role === 'user') {
    $stmt = $pdo->prepare('SELECT church_id FROM user WHERE user_id = ?');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $churchId = (int)$stmt->fetchColumn();
} else {
    $churches = $pdo->query('SELECT church_info_id FROM church_info')->fetchAll(PDO::FETCH_COLUMN);
    $churchId = pick_church_id($pdo, array_fill_keys($churches, 1), 0);
}

$who = ['role' => $role, 'name' => $_SESSION['user_name'] ?? '', 'id' => (int)$_SESSION['user_id']];
session_write_close();   // the AI call can take a few seconds; do not hold the session meanwhile

// 1. The portal's own lookup finds the facts (and the page buttons) in the database.
$reply = assistant_reply($pdo, $message, $churchId, $who);
$history = is_array($in['history'] ?? null) ? $in['history'] : [];

// A follow-up such as "and on weekdays?", or a question in another language, means nothing to the lookup
// as typed. Put it into one plain English question first and look that up, so the answer still comes from
// the database and not from the AI's imagination. (Without the AI: retry with the previous question added.)
$lookupMessage = $message;
if (($reply['matched'] ?? true) === false) {
    $asked = array_values(array_filter($history, fn($t) => is_array($t) && ($t['who'] ?? '') === 'me' && trim((string)($t['text'] ?? '')) !== ''));
    $retry = assistant_ai_search_question($message, $history)
        ?? ($asked && !gemini_ready() ? mb_substr(trim((string)end($asked)['text']), 0, 300) . ' ' . $message : null);
    if ($retry !== null) {
        $again = assistant_reply($pdo, $retry, $churchId, $who);
        if (($again['matched'] ?? true) !== false) {
            $reply = $again;
            $lookupMessage = $retry;
        }
    }
}

// 2. Gemini words the reply. Not for someone's own records ("status of my application"): those stay on
//    this server and are shown exactly as the lookup wrote them.
$personal = in_array('my_status', array_merge(assistant_topics($lookupMessage), assistant_topics(assistant_fix_typos($lookupMessage))), true);
if (!$personal) {
    $churchName = '';
    if ($churchId) {
        $stmt = $pdo->prepare('SELECT church_name FROM church_info WHERE church_info_id = ?');
        $stmt->execute([$churchId]);
        $churchName = (string)$stmt->fetchColumn();
    }
    $text = assistant_ai_reply($message, $reply, $history, $who, $churchName);
    if ($text !== null) {
        $reply['text'] = $text;
        $reply['ai'] = true;
    }
}
unset($reply['matched']);

echo json_encode($reply, JSON_UNESCAPED_UNICODE);
