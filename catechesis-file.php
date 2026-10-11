<?php
// Serves one catechesis registration document. The files are private: uploads/sacrament_docs/ is blocked from direct web access,
// and this script only hands a file to the parishioner who uploaded it, to the Church Admin of that church, or to the Super Admin.
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/sacrament_helpers.php';
require __DIR__ . '/includes/catechesis_helpers.php';

$stmt = $pdo->prepare('SELECT enrolment_id, user_id, church_id, files FROM catechesis_enrolments WHERE enrolment_id = ?');
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$row = $stmt->fetch();

$doc = (string)($_GET['doc'] ?? '');
$isOwner = $row && ($_SESSION['role'] ?? '') === 'user' && (int)($_SESSION['user_id'] ?? 0) === (int)$row['user_id'];
$files = $row ? (json_decode((string)$row['files'], true) ?: []) : [];

// Same answer whether the file does not exist or the person may not see it.
if (!$row || !isset(cat_config()['docs'][$doc]) || !($isOwner || can_manage_church($pdo, (int)$row['church_id'])) || !($path = sx_doc_path($files[$doc] ?? null))) {
    http_response_code(404);
    exit('Not found');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$type = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'][$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $type);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $doc . '-' . (int)$row['enrolment_id'] . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
