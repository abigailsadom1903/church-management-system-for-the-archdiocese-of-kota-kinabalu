<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/donation_helpers.php';
require __DIR__ . '/includes/report_tools.php';
require __DIR__ . '/includes/review_queue.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Church Admins and the Super Admin only.
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['church_admin', 'super_admin'], true)) {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$isSuper = $_SESSION['role'] === 'super_admin';

$churches = $pdo->query('SELECT * FROM church_info ORDER BY church_name')->fetchAll();
$byId = array_column($churches, null, 'church_info_id');
$selectedId = pick_church_id($pdo, $byId, (int)($_POST['church'] ?? $_GET['church'] ?? ($isSuper ? review_busiest_church($pdo, 'donations') : 0)));
// A Church Admin who asks for another church is sent to their own.
if (!can_manage_church($pdo, $selectedId)) {
    $own = pick_church_id($pdo, $byId, 0);
    if ($own === $selectedId || !can_manage_church($pdo, $own)) {
        header('Location: donations.php');
        exit;
    }
    header('Location: donation-manage.php?church=' . $own);
    exit;
}
$c = $byId[$selectedId];

$flash = $_SESSION['donation_flash'] ?? null;
unset($_SESSION['donation_flash']);
$errors = [];
$payForm = [
    'bank' => $c['donation_bank_name'] ?? '',
    'name' => $c['donation_account_name'] ?? '',
    'no'   => $c['donation_account_number'] ?? '',
];

// ---- POST: delete a donation, or save the church's payment details ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['form'] = 'Your session expired. Please try again.';
    } elseif ($action === 'delete_donation') {
        // The church is read from the record, never trusted from the form.
        $stmt = $pdo->prepare('SELECT church_id, receipt_file FROM donations WHERE donation_id = ?');
        $stmt->execute([(int)($_POST['id'] ?? 0)]);
        $row = $stmt->fetch();
        if (!$row || !can_manage_church($pdo, (int)$row['church_id'])) {
            $errors['form'] = 'You do not have permission to delete this donation.';
        } else {
            $pdo->prepare('DELETE FROM donations WHERE donation_id = ?')->execute([(int)$_POST['id']]);
            delete_receipt_file($row['receipt_file']);
            $_SESSION['donation_flash'] = 'Donation deleted.';
            header('Location: donation-manage.php?church=' . (int)$row['church_id']);
            exit;
        }
    } elseif ($action === 'save_payment') {
        $payForm = ['bank' => trim($_POST['bank'] ?? ''), 'name' => trim($_POST['account_name'] ?? ''), 'no' => trim($_POST['account_no'] ?? '')];
        $filled = array_filter($payForm, fn($v) => $v !== '');
        if ($filled && count($filled) < 3) $errors['pay'] = 'Fill in all three bank details, or leave all three empty.';
        elseif (mb_strlen($payForm['bank']) > 100 || mb_strlen($payForm['name']) > 100) $errors['pay'] = 'The bank and account names must be under 100 characters.';
        elseif ($payForm['no'] !== '' && !preg_match('/^[0-9][0-9 \-]{4,38}$/', $payForm['no'])) $errors['pay'] = 'The account number should contain digits only (spaces and dashes allowed).';

        $qrName = null;
        $qr = $_FILES['qr'] ?? null;
        if (!$errors && $qr && $qr['error'] !== UPLOAD_ERR_NO_FILE) {
            $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
            $info = $qr['error'] === UPLOAD_ERR_OK ? @getimagesize($qr['tmp_name']) : false;
            if ($qr['error'] !== UPLOAD_ERR_OK) $errors['qr'] = 'The QR image could not be uploaded. Please try again.';
            elseif ($qr['size'] > 3 * 1024 * 1024) $errors['qr'] = 'The QR image must be 3 MB or smaller.';
            elseif (!$info || !isset($types[$info[2]])) $errors['qr'] = 'Please choose a JPG, PNG or WebP image.';
            else {
                $dir = __DIR__ . '/uploads/donation_qr';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $qrName = 'qr-' . $selectedId . '-' . bin2hex(random_bytes(6)) . '.' . $types[$info[2]];
                if (!move_uploaded_file($qr['tmp_name'], $dir . '/' . $qrName)) { $qrName = null; $errors['qr'] = 'The QR image could not be saved.'; }
            }
        }

        if (!$errors) {
            $newQr = $qrName ?? (!empty($_POST['remove_qr']) ? null : $c['donation_qr_image']);
            $pdo->prepare('UPDATE church_info SET donation_bank_name = ?, donation_account_name = ?, donation_account_number = ?, donation_qr_image = ? WHERE church_info_id = ?')
                ->execute([$payForm['bank'] ?: null, $payForm['name'] ?: null, $payForm['no'] ?: null, $newQr, $selectedId]);
            if ($c['donation_qr_image'] && $newQr !== $c['donation_qr_image']) delete_qr_file($c['donation_qr_image']);
            $_SESSION['donation_flash'] = 'Payment details saved.';
            header('Location: donation-manage.php?church=' . $selectedId . '#payment');
            exit;
        }
        if ($qrName) delete_qr_file($qrName);   // don't keep an upload from a failed save
    }
}

// ---- Stats and the filtered list -----------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(status = 'pending'), 0)  AS pending,
            COALESCE(SUM(status = 'approved'), 0) AS approved,
            COALESCE(SUM(status = 'rejected'), 0) AS rejected,
            COALESCE(SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END), 0) AS received
       FROM donations WHERE church_id = ?"
);
$stmt->execute([$selectedId]);
$stats = $stmt->fetch();

const PER_PAGE = 15;
$status = in_array($_GET['status'] ?? '', ['pending', 'approved', 'rejected'], true) ? $_GET['status'] : '';
$method = isset(DONATION_METHODS[$_GET['method'] ?? '']) ? $_GET['method'] : '';
$q      = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$page   = max(1, (int)($_GET['page'] ?? 1));

$where = ['church_id = ?']; $params = [$selectedId];
if ($status !== '') { $where[] = 'status = ?'; $params[] = $status; }
if ($method !== '') { $where[] = 'method = ?'; $params[] = $method; }
if ($q !== '') {
    $where[] = '(donor_name LIKE ? OR donor_email LIKE ? OR donor_phone LIKE ? OR purpose LIKE ?)';
    $like = '%' . addcslashes($q, '%_\\') . '%';
    array_push($params, $like, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);

// ---- Generate Report: every donation matching the filters (all pages), as a CSV ----
if (($_GET['export'] ?? '') === 'csv') {
    $stmt = $pdo->prepare("SELECT * FROM donations WHERE $whereSql ORDER BY created_at DESC, donation_id DESC");
    $stmt->execute($params);
    send_report('donations-' . $c['church_name'],
        ['Reference', 'Date', 'Donor', 'Email', 'Phone', 'Purpose', 'Method', 'Amount (RM)', 'Status', 'Reviewed', 'Church'],
        array_map(fn($d) => [
            donation_ref((int)$d['donation_id']), date('Y-m-d', strtotime($d['created_at'])), $d['donor_name'], $d['donor_email'], $d['donor_phone'], $d['purpose'],
            DONATION_METHOD_SHORT[$d['method']], number_format((float)$d['amount'], 2, '.', ''), donation_status($d['status'])[0],
            $d['reviewed_at'] ? date('Y-m-d', strtotime($d['reviewed_at'])) : '', $c['church_name'],
        ], $stmt->fetchAll()));
}
$stmt = $pdo->prepare("SELECT COUNT(*) FROM donations WHERE $whereSql");
$stmt->execute($params);
$found = (int)$stmt->fetchColumn();
$pages = print_all() ? 1 : max(1, (int)ceil($found / PER_PAGE));   // printing: every donation on one page
$page = min($page, $pages);
$stmt = $pdo->prepare("SELECT * FROM donations WHERE $whereSql ORDER BY (status = 'pending') DESC, created_at DESC, donation_id DESC" . (print_all() ? '' : ' LIMIT ' . PER_PAGE . ' OFFSET ' . (($page - 1) * PER_PAGE)));
$stmt->execute($params);
$rows = $stmt->fetchAll();

/** Link that keeps the church and the other filters. */
function manage_url(int $church, array $over = []): string
{
    $p = array_merge(['church' => $church, 'status' => $_GET['status'] ?? '', 'method' => $_GET['method'] ?? '', 'q' => $_GET['q'] ?? '', 'page' => 1], $over);
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null && $v !== 1);
    return 'donation-manage.php?' . http_build_query($p, '', '&amp;');
}

$photoUrl = church_photo_url($c['church_photo']);
$hasPay = $c['donation_bank_name'] && $c['donation_account_name'] && $c['donation_account_number'];
$pageTitle = 'Donation and Offering';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="dm-page">
    <?php back_button('donations.php?church=' . $selectedId, 'Back to Donation & Offering'); ?>
    <header class="page-heading">
        <h1>Donation and Offering</h1>
        <p>Review and manage the donations made to your parish.</p>
    </header>

    <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>
    <?php if (isset($errors['form'])): ?><div class="alert info-alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>

    <section class="info-hero dm-hero">
        <div class="hero-photo">
            <?php if ($photoUrl): ?><img src="<?= e($photoUrl) ?>" alt="<?= e($c['church_name']) ?>">
            <?php else: ?><div class="info-photo-fallback" aria-hidden="true"><?= don_icon('church', 56) ?></div><?php endif; ?>
        </div>
        <h2><?= e($c['church_name']) ?></h2>
        <p class="info-town"><?= e($c['church_location']) ?></p>
    </section>

    <?php if ($isSuper): ?>
        <form method="get" action="donation-manage.php" class="info-select" id="church-form">
            <label for="church">Church</label>
            <div class="select-wrap">
                <select id="church" name="church">
                    <?php foreach ($churches as $ch): ?>
                        <option value="<?= (int)$ch['church_info_id'] ?>" <?= (int)$ch['church_info_id'] === $selectedId ? 'selected' : '' ?>><?= e(review_option_label($pdo, 'donations', (int)$ch['church_info_id'], $ch['church_name'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    <?php endif; ?>

    <div class="dm-links">
        <a href="donations.php?church=<?= $selectedId ?>">View the public donation form</a>
        <a href="collection-manage.php?church=<?= $selectedId ?>">Weekly collection</a>
        <?php if ($isSuper): ?><a href="donation-report.php">Donation reports (all churches)</a><?php endif; ?>
    </div>

    <div class="dm-stats">
        <div class="dm-stat"><span class="dm-stat-label">Total Donations <?= don_icon('heart', 16) ?></span><span class="dm-stat-num"><?= (int)$stats['total'] ?></span></div>
        <div class="dm-stat"><span class="dm-stat-label">Pending Review <span class="c-amber"><?= don_icon('clock', 16) ?></span></span><span class="dm-stat-num"><?= (int)$stats['pending'] ?></span></div>
        <div class="dm-stat"><span class="dm-stat-label">Approved <span class="c-green"><?= don_icon('check', 16) ?></span></span><span class="dm-stat-num"><?= (int)$stats['approved'] ?></span></div>
        <div class="dm-stat"><span class="dm-stat-label">Rejected <span class="c-red"><?= don_icon('x', 16) ?></span></span><span class="dm-stat-num"><?= (int)$stats['rejected'] ?></span></div>
        <div class="dm-stat"><span class="dm-stat-label">Total Received <?= don_icon('cash', 16) ?></span><span class="dm-stat-num is-money"><?= rm($stats['received']) ?></span></div>
    </div>

    <form method="get" action="donation-manage.php" class="dm-filters">
        <input type="hidden" name="church" value="<?= $selectedId ?>">
        <div class="chips">
            <a href="<?= manage_url($selectedId, ['status' => '']) ?>" class="chip<?= $status === '' ? ' is-active' : '' ?>">All (<?= (int)$stats['total'] ?>)</a>
            <a href="<?= manage_url($selectedId, ['status' => 'pending']) ?>" class="chip<?= $status === 'pending' ? ' is-active' : '' ?>">Pending (<?= (int)$stats['pending'] ?>)</a>
            <a href="<?= manage_url($selectedId, ['status' => 'approved']) ?>" class="chip<?= $status === 'approved' ? ' is-active' : '' ?>">Approved (<?= (int)$stats['approved'] ?>)</a>
            <a href="<?= manage_url($selectedId, ['status' => 'rejected']) ?>" class="chip<?= $status === 'rejected' ? ' is-active' : '' ?>">Rejected (<?= (int)$stats['rejected'] ?>)</a>
        </div>
        <input type="hidden" name="status" value="<?= e($status) ?>">
        <select name="method" aria-label="Filter by payment method" onchange="this.form.submit()">
            <option value="">All Methods</option>
            <?php foreach (DONATION_METHOD_SHORT as $k => $label): ?><option value="<?= $k ?>" <?= $method === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <div class="dm-search"><?= don_icon('search', 16) ?><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search donor, purpose..." aria-label="Search donations" maxlength="100"></div>
        <?php report_tools(report_csv_url(['church' => $selectedId]), 'Print the donation list', print_all_url()); ?>
    </form>

    <?php print_heading('Donations', $c['church_name'], [
        'Status' => $status !== '' ? ucfirst($status) : '', 'Method' => $method !== '' ? DONATION_METHOD_SHORT[$method] : '', 'Search' => $q,
        'Donations' => (string)$found,
        'Total received' => rm($stats['received']),
    ]); ?>

    <div class="dm-table-card">
        <div class="table-wrap">
            <table class="dm-table">
                <thead><tr><th>Donor</th><th>Purpose</th><th>Method</th><th>Amount</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $d): [$stLabel, $stClass, $stIcon] = donation_status($d['status']); ?>
                    <tr<?= $d['status'] === 'pending' ? ' class="needs-review"' : '' ?>>
                        <td><span class="dn-name"><?= e($d['donor_name']) ?></span><span class="dn-sub"><?= e($d['donor_email']) ?></span><span class="dn-sub"><?= e($d['donor_phone']) ?></span></td>
                        <td><?= e($d['purpose']) ?></td>
                        <td><span class="method-tag"><?= don_icon($d['method'] === 'qr' ? 'qr' : 'bank', 14) ?> <?= e(DONATION_METHOD_SHORT[$d['method']]) ?></span></td>
                        <td class="dn-amount"><?= rm($d['amount']) ?></td>
                        <td>
                            <span class="st-badge <?= $stClass ?>"><?= don_icon($stIcon, 13) ?> <?= $stLabel ?></span><?= $d['status'] === 'pending' ? '<span class="needs-review-tag">Needs review</span>' : '' ?>
                            <?php if ($d['email_sent_at']): ?><span class="dn-sub dn-mail"><?= don_icon('mail', 12) ?> Email sent</span><?php endif; ?>
                        </td>
                        <td class="dn-date"><?= e(date('j M Y', strtotime($d['created_at']))) ?></td>
                        <td>
                            <form method="post" action="donation-manage.php?church=<?= $selectedId ?>" class="dm-actions">
                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                <input type="hidden" name="id" value="<?= (int)$d['donation_id'] ?>">
                                <a class="btn-review" href="donation-review.php?id=<?= (int)$d['donation_id'] ?>"><?= don_icon('eye', 14) ?> Review</a>
                                <button type="submit" name="action" value="delete_donation" class="ico-btn sm danger" data-confirm="Delete this donation record and its receipt? This cannot be undone." title="Delete" aria-label="Delete donation <?= e(donation_ref((int)$d['donation_id'])) ?>"><?= don_icon('trash', 14) ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" class="empty"><?= ($status || $method || $q) ? 'No donations match your filters.' : 'No donations have been submitted to this parish yet.' ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="bul-pager" aria-label="Donation pages">
            <?php if ($page > 1): ?><a class="pg-arrow" href="<?= manage_url($selectedId, ['page' => $page - 1]) ?>" aria-label="Previous page">&lsaquo;</a><?php else: ?><span class="pg-arrow is-off" aria-hidden="true">&lsaquo;</span><?php endif; ?>
            <?php for ($p = 1; $p <= $pages; $p++): ?>
                <?php if ($p === $page): ?><span class="pg-num is-current" aria-current="page"><?= $p ?></span><?php else: ?><a class="pg-num" href="<?= manage_url($selectedId, ['page' => $p]) ?>" aria-label="Page <?= $p ?>"><?= $p ?></a><?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $pages): ?><a class="pg-arrow" href="<?= manage_url($selectedId, ['page' => $page + 1]) ?>" aria-label="Next page">&rsaquo;</a><?php else: ?><span class="pg-arrow is-off" aria-hidden="true">&rsaquo;</span><?php endif; ?>
        </nav>
    <?php endif; ?>

    <details class="pay-settings" id="payment" <?= ($errors && !isset($errors['form'])) || !$hasPay ? 'open' : '' ?>>
        <summary><?= don_icon('settings', 16) ?> Payment details shown to donors
            <?php if (!$hasPay): ?><span class="badge pending">Bank details not set</span><?php endif; ?></summary>
        <form method="post" action="donation-manage.php?church=<?= $selectedId ?>" enctype="multipart/form-data" class="form-stack">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="church" value="<?= $selectedId ?>">
            <input type="hidden" name="action" value="save_payment">
            <?php if (isset($errors['pay'])): ?><div class="alert" role="alert"><?= e($errors['pay']) ?></div><?php endif; ?>
            <div class="pf-row">
                <div class="pfield"><label for="p-bank">Bank</label><input type="text" id="p-bank" name="bank" maxlength="100" placeholder="e.g. Alliance Bank Malaysia Berhad" value="<?= e($payForm['bank']) ?>"></div>
                <div class="pfield"><label for="p-name">Account Name</label><input type="text" id="p-name" name="account_name" maxlength="100" value="<?= e($payForm['name']) ?>"></div>
            </div>
            <div class="pfield"><label for="p-no">Account Number</label><input type="text" id="p-no" name="account_no" maxlength="40" inputmode="numeric" value="<?= e($payForm['no']) ?>"></div>
            <div class="<?= isset($errors['qr']) ? 'pfield has-error' : 'pfield' ?>">
                <label for="p-qr">DuitNow QR image <span class="opt">(optional)</span></label>
                <input type="file" id="p-qr" name="qr" accept="image/jpeg,image/png,image/webp">
                <?php if ($qrUrl = qr_image_url($c['donation_qr_image'])): ?>
                    <div class="cover-current"><img src="<?= e($qrUrl) ?>" alt="Current QR code" width="72" height="72"><label class="check-row"><input type="checkbox" name="remove_qr" value="1"><span>Remove the current QR image</span></label></div>
                <?php endif; ?>
                <?php if (isset($errors['qr'])): ?><span class="row-error"><?= e($errors['qr']) ?></span><?php endif; ?>
                <span class="file-note">JPG, PNG or WebP, up to 3 MB. Donors only see a payment method once its details are filled in.</span>
            </div>
            <div class="profile-actions"><button type="submit" class="btn-save">Save payment details</button></div>
        </form>
    </details>
</main>
<script>
document.getElementById('church')?.addEventListener('change', function () { document.getElementById('church-form').submit(); });
</script>
<?php print_on_load(); ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
