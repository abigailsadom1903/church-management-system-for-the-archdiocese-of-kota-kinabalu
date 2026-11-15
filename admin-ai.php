<?php
// AI help for the church admin, answered as JSON (see includes/admin_ai.php):
//   POST action=week   church=<id>                                   -> the pastoral care briefing
//   POST action=reply  kind=pastoral|sacrament id=<id> intent=... gist=  -> a suggested note for the parishioner
// Church Admins only (the Super Admin views but does not answer requests), and only for their own church.
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/admin_ai.php';

header('Content-Type: application/json');
function out(array $a): never { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$role = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($role, ['church_admin', 'super_admin'], true)) out(['error' => 'Please sign in as a church admin.']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) out(['error' => 'Your session expired. Please reload the page.']);

// A little brake so a stuck button cannot use up the day's AI allowance: 30 requests per 10 minutes per admin.
$now = time();
$_SESSION['admin_ai_calls'] = array_values(array_filter($_SESSION['admin_ai_calls'] ?? [], fn($t) => $t > $now - 600));
if (count($_SESSION['admin_ai_calls']) >= 30) out(['error' => 'That is a lot of AI requests in a short time. Please wait a few minutes.']);
$_SESSION['admin_ai_calls'][] = $now;
session_write_close();   // the AI can take a few seconds; do not hold the session meanwhile

$action = (string)($_POST['action'] ?? '');

if ($action === 'week') {
    $churchId = (int)($_POST['church'] ?? 0);
    if (!can_manage_church($pdo, $churchId)) out(['error' => 'You cannot view this church.']);
    $name = (string)$pdo->query('SELECT church_name FROM church_info WHERE church_info_id = ' . $churchId)->fetchColumn();
    out(admin_ai_pastoral_week($pdo, $churchId, $name));
}

if ($action === 'reply') {
    if ($role !== 'church_admin') out(['error' => 'Only the church admin answers requests.']);
    $intent = (string)($_POST['intent'] ?? '');
    if (!in_array($intent, ['approve', 'info', 'decline'], true)) out(['error' => 'Unknown kind of note.']);
    $gist = mb_substr(trim((string)($_POST['gist'] ?? '')), 0, 255);
    if ($intent === 'decline' && $gist === '') out(['error' => 'Type the reason in a few words first (e.g. "documents unclear"), then press the button again.']);
    $id = (int)($_POST['id'] ?? 0);
    $kind = (string)($_POST['kind'] ?? '');

    if ($kind === 'pastoral') {
        $st = $pdo->prepare('SELECT r.service, r.details, r.church_id, c.church_name, u.user_name FROM pastoral_requests r JOIN church_info c ON c.church_info_id = r.church_id JOIN user u ON u.user_id = r.user_id WHERE r.request_id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r || !can_manage_church($pdo, (int)$r['church_id'])) out(['error' => 'Request not found.']);
        $cfg = pc_form($pdo, $r['service'], (int)$r['church_id']);
        $in = ['kind' => 'pastoral care request', 'title' => pc_title($pdo, $r['service'], (int)$r['church_id']), 'missing' => [],
               'fields' => admin_ai_safe_fields($cfg, json_decode((string)$r['details'], true) ?: [])];
    } elseif ($kind === 'sacrament') {
        $st = $pdo->prepare('SELECT a.sacrament, a.details, a.files, a.church_id, c.church_name, u.user_name FROM sacrament_applications a JOIN church_info c ON c.church_info_id = a.church_id JOIN user u ON u.user_id = a.user_id WHERE a.application_id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r || !can_manage_church($pdo, (int)$r['church_id'])) out(['error' => 'Application not found.']);
        $cfg = sx_config($r['sacrament']);
        $files = json_decode((string)$r['files'], true) ?: [];
        $missing = [];
        foreach ($cfg['docs'] ?? [] as $key => $doc) if (empty($files[$key]) || !sx_doc_path($files[$key])) $missing[] = $doc[0];
        $in = ['kind' => 'sacrament application', 'title' => ($cfg['title'] ?? $r['sacrament']) . ' application', 'missing' => $missing,
               'fields' => admin_ai_safe_fields($cfg, json_decode((string)$r['details'], true) ?: [])];
    } else {
        out(['error' => 'Unknown request.']);
    }
    $first = preg_split('/\s+/', trim((string)$r['user_name']))[0] ?? '';
    out(admin_ai_reply($in + ['intent' => $intent, 'gist' => $gist, 'first' => $first, 'church' => $r['church_name']]));
}

out(['error' => 'Unknown action.']);
