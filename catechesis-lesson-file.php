<?php
// Serves one lesson handout. The files are private: uploads/catechesis_lessons/ is blocked from direct web access,
// and this script only hands a file to a student of the program (active or completed, published lessons only),
// to the Church Admin of that church, or to the Super Admin.
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/catechesis_helpers.php';

$stmt = $pdo->prepare('SELECT l.lesson_id, l.program_id, l.file, l.file_name, l.is_published, p.church_id FROM catechesis_lessons l JOIN catechesis_programs p ON p.program_id = l.program_id WHERE l.lesson_id = ?');
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$row = $stmt->fetch();

$allowed = false;
if ($row && ($_SESSION['role'] ?? '') === 'user' && $row['is_published']) {
    $chk = $pdo->prepare("SELECT 1 FROM catechesis_enrolments WHERE program_id = ? AND user_id = ? AND status IN ('active', 'completed')");
    $chk->execute([(int)$row['program_id'], (int)($_SESSION['user_id'] ?? 0)]);
    $allowed = (bool)$chk->fetchColumn();
} elseif ($row) {
    $allowed = can_manage_church($pdo, (int)$row['church_id']);
}

// Same answer whether the file does not exist or the person may not see it.
if (!$allowed || !($path = cat_lesson_path($row['file']))) {
    http_response_code(404);
    exit('Not found');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$types = [
    'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'mp3' => 'audio/mpeg',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
];
$name = preg_replace('/[^\w .()-]+/', '_', pathinfo((string)$row['file_name'], PATHINFO_FILENAME)) ?: 'lesson-' . (int)$row['lesson_id'];

header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
// PDFs, pictures and audio open in the browser; Office files download
header('Content-Disposition: ' . (in_array($ext, ['docx', 'pptx'], true) ? 'attachment' : 'inline') . '; filename="' . $name . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
