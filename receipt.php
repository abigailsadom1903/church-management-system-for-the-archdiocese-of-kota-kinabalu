<?php
// Serves one donation receipt. Receipts are private: uploads/receipts/ is blocked from direct web access,
// and this script only hands a file to the Super Admin or to the Church Admin of the donation's own church.
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/donation_helpers.php';

$stmt = $pdo->prepare('SELECT donation_id, church_id, receipt_file FROM donations WHERE donation_id = ?');
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$row = $stmt->fetch();

// Same answer whether the donation does not exist or the person may not see it.
if (!$row || !can_manage_church($pdo, (int)$row['church_id']) || !($path = receipt_path($row['receipt_file']))) {
    http_response_code(404);
    exit('Not found');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$type = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'][$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $type);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="receipt-' . donation_ref((int)$row['donation_id']) . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
