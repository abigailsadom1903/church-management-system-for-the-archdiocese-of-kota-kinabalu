<?php
// "Generate Report" on the admin dashboards: one CSV with every application of every kind —
// sacraments, pastoral care, catechesis enrolments, event registrations and donations.
// Church Admin: their own church. Super Admin: every church, or one with ?church=.
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/sacrament_helpers.php';
require __DIR__ . '/includes/pastoral_helpers.php';
require __DIR__ . '/includes/event_helpers.php';
require __DIR__ . '/includes/catechesis_helpers.php';
require __DIR__ . '/includes/donation_helpers.php';
require __DIR__ . '/includes/report_tools.php';

$role = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($role, ['church_admin', 'super_admin'], true)) {
    header('Location: login.php');
    exit;
}
$isSuper = $role === 'super_admin';

$churches = $pdo->query('SELECT church_info_id, church_name FROM church_info ORDER BY church_name')->fetchAll();
$byId = array_column($churches, null, 'church_info_id');
if ($isSuper) {
    $churchId = isset($byId[(int)($_GET['church'] ?? 0)]) ? (int)$_GET['church'] : 0;   // 0 = every church
} else {
    $churchId = pick_church_id($pdo, $byId, 0);   // always their own, never from the request
    if (!can_manage_church($pdo, $churchId)) {
        header('Location: churchadmin/dashboard.php');
        exit;
    }
}
// "AND x.church_id = N" for one church, nothing for all.
$scope = fn(string $col) => $churchId ? " AND $col = " . $churchId : '';

$rows = [];
$add = function (string $type, string $ref, string $church, string $name, ?string $email, ?string $phone, string $details, string $status, ?string $date, string $amount = '') use (&$rows) {
    $rows[] = [$type, $ref, $church, $name, (string)$email, (string)$phone, $details, $status, $date ? date('Y-m-d', strtotime($date)) : '', $amount];
};

// Sacraments (Confession bookings have their own page and are not applications)
foreach ($pdo->query(
    "SELECT a.application_id, a.sacrament, a.status, a.details, a.submitted_at, a.created_at, c.church_name, u.user_name, u.user_email, u.user_phone
       FROM sacrament_applications a JOIN church_info c ON c.church_info_id = a.church_id JOIN user u ON u.user_id = a.user_id
      WHERE a.status <> 'draft' AND a.sacrament <> 'confession'" . $scope('a.church_id') . ' ORDER BY a.submitted_at DESC'
) as $r) {
    $subject = sx_subject($r['sacrament'], json_decode((string)$r['details'], true) ?: []);
    $add('Sacrament', sx_ref((int)$r['application_id']), $r['church_name'], $r['user_name'], $r['user_email'], $r['user_phone'],
        (SACRAMENTS[$r['sacrament']][0] ?? $r['sacrament']) . ($subject !== '' ? ': ' . $subject : ''), sx_status($r['status'])[0], $r['submitted_at'] ?: $r['created_at']);
}

// Pastoral & spiritual care
foreach ($pdo->query(
    "SELECT r.request_id, r.service, r.church_id, r.status, r.details, r.created_at, c.church_name, u.user_name, u.user_email, u.user_phone
       FROM pastoral_requests r JOIN church_info c ON c.church_info_id = r.church_id JOIN user u ON u.user_id = r.user_id
      WHERE r.status <> 'draft'" . $scope('r.church_id') . ' ORDER BY r.created_at DESC'
) as $r) {
    $about = pc_summary($r['service'], json_decode((string)$r['details'], true) ?: []);
    $add('Pastoral Care', pc_ref((int)$r['request_id']), $r['church_name'], $r['user_name'], $r['user_email'], $r['user_phone'],
        pc_title($pdo, $r['service'], (int)$r['church_id']) . ($about !== '' ? ': ' . $about : ''), pc_status($r['status'])[0], $r['created_at']);
}

// Catechesis enrolments
foreach ($pdo->query(
    "SELECT en.enrolment_id, en.status, en.joined_at, en.created_at, p.title, p.term_label, c.church_name, u.user_name, u.user_email, u.user_phone
       FROM catechesis_enrolments en JOIN catechesis_programs p ON p.program_id = en.program_id
       JOIN church_info c ON c.church_info_id = p.church_id JOIN user u ON u.user_id = en.user_id
      WHERE en.status <> 'draft'" . $scope('p.church_id') . ' ORDER BY en.created_at DESC'
) as $r) {
    $add('Catechesis', 'CE-' . str_pad((string)$r['enrolment_id'], 5, '0', STR_PAD_LEFT), $r['church_name'], $r['user_name'], $r['user_email'], $r['user_phone'],
        $r['title'] . ($r['term_label'] ? ' ' . $r['term_label'] : ''), cat_status($r['status'])[0], $r['joined_at'] ?: $r['created_at']);
}

// Event registrations
foreach ($pdo->query(
    "SELECT g.registration_id, g.name, g.email, g.phone, g.people, g.created_at, e.title, e.event_date, c.church_name
       FROM event_registrations g JOIN events e ON e.event_id = g.event_id JOIN church_info c ON c.church_info_id = e.church_id
      WHERE 1" . $scope('e.church_id') . ' ORDER BY g.created_at DESC'
) as $r) {
    $add('Event Registration', 'ER-' . str_pad((string)$r['registration_id'], 5, '0', STR_PAD_LEFT), $r['church_name'], $r['name'], $r['email'], $r['phone'],
        $r['title'] . ' (' . date('j M Y', strtotime($r['event_date'])) . ', ' . (int)$r['people'] . ((int)$r['people'] === 1 ? ' person' : ' people') . ')', 'Registered', $r['created_at']);
}

// Donations
foreach ($pdo->query(
    "SELECT d.donation_id, d.donor_name, d.donor_email, d.donor_phone, d.purpose, d.method, d.amount, d.status, d.created_at, c.church_name
       FROM donations d JOIN church_info c ON c.church_info_id = d.church_id
      WHERE 1" . $scope('d.church_id') . ' ORDER BY d.created_at DESC'
) as $r) {
    $add('Donation', donation_ref((int)$r['donation_id']), $r['church_name'], $r['donor_name'], $r['donor_email'], $r['donor_phone'],
        $r['purpose'] . ' (' . DONATION_METHOD_SHORT[$r['method']] . ')', donation_status($r['status'])[0], $r['created_at'], number_format((float)$r['amount'], 2, '.', ''));
}

send_report('all-applications-' . ($churchId ? $byId[$churchId]['church_name'] : 'all-churches'),
    ['Type', 'Reference', 'Church', 'Name', 'Email', 'Phone', 'Details', 'Status', 'Date', 'Amount (RM)'], $rows);
