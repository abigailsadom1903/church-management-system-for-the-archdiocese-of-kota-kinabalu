<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/donation_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Super Admin only: bank details for every church on one page.
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'super_admin') {
    header('Location: ' . (empty($_SESSION['user_id']) ? 'login.php' : 'donations.php'));
    exit;
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$churches = $pdo->query(
    'SELECT church_info_id, church_name, church_location, donation_bank_name, donation_account_name, donation_account_number, donation_qr_image
       FROM church_info ORDER BY church_name'
)->fetchAll();
$byId = array_column($churches, null, 'church_info_id');

$flash = $_SESSION['donation_flash'] ?? null;
unset($_SESSION['donation_flash']);
$errors = [];
$form = [];   // what is shown in the inputs
foreach ($churches as $c) {
    $form[$c['church_info_id']] = ['bank' => (string)$c['donation_bank_name'], 'name' => (string)$c['donation_account_name'], 'no' => (string)$c['donation_account_number']];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['form'] = 'Your session expired. Please try again.';

    } elseif ($action === 'save_all') {
        $clean = [];
        foreach ($byId as $id => $c) {
            // A church that was not part of the submitted form is left exactly as it is (never cleared by omission).
            if (!isset($_POST['rows'][$id]) || !is_array($_POST['rows'][$id])) continue;
            $r = $_POST['rows'][$id];
            $row = ['bank' => trim((string)($r['bank'] ?? '')), 'name' => trim((string)($r['name'] ?? '')), 'no' => trim((string)($r['no'] ?? ''))];
            $form[$id] = $row;
            $filled = array_filter($row, fn($v) => $v !== '');
            if ($filled && count($filled) < 3) {
                $errors[$id] = 'Fill in all three fields, or leave all three empty.';
            } elseif (mb_strlen($row['bank']) > 100 || mb_strlen($row['name']) > 100) {
                $errors[$id] = 'Keep the bank and account names under 100 characters.';
            } elseif ($row['no'] !== '' && !preg_match('/^[0-9][0-9 \-]{4,38}$/', $row['no'])) {
                $errors[$id] = 'The account number should contain digits only (spaces and dashes allowed).';
            }
            $clean[$id] = $row;
        }

        if (!$errors) {
            // All or nothing: either every row saves or none does.
            $upd = $pdo->prepare('UPDATE church_info SET donation_bank_name = ?, donation_account_name = ?, donation_account_number = ? WHERE church_info_id = ?');
            $changed = 0;
            $pdo->beginTransaction();
            foreach ($clean as $id => $row) {
                $c = $byId[$id];
                if ($row['bank'] === (string)$c['donation_bank_name'] && $row['name'] === (string)$c['donation_account_name'] && $row['no'] === (string)$c['donation_account_number']) continue;
                $upd->execute([$row['bank'] ?: null, $row['name'] ?: null, $row['no'] ?: null, $id]);
                $changed++;
            }
            $pdo->commit();
            $_SESSION['donation_flash'] = $changed ? "Saved payment details for $changed " . ($changed === 1 ? 'church.' : 'churches.') : 'No changes to save.';
            header('Location: donation-settings.php');
            exit;
        }
    }
}

$ready = 0;
foreach ($churches as $c) if ($c['donation_bank_name'] && $c['donation_account_name'] && $c['donation_account_number']) $ready++;

$pageTitle = 'Payment Details - All Churches';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="dm-page ds-page">
    <?php back_button('donation-report.php', 'Back to donation reports'); ?>
    <header class="page-heading">
        <h1>Donation Payment Details</h1>
        <p>The bank account each church shows to donors. <strong><?= $ready ?> of <?= count($churches) ?></strong> churches are ready to receive donations.</p>
    </header>

    <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>
    <?php if (isset($errors['form'])): ?><div class="alert info-alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
    <?php if ($errors && !isset($errors['form'])): ?><div class="alert info-alert" role="alert">Nothing was saved. Please fix the <?= count($errors) ?> highlighted <?= count($errors) === 1 ? 'row' : 'rows' ?>.</div><?php endif; ?>

    <div class="dm-links">
        <a href="donation-report.php">Donation reports</a>
        <a href="donation-manage.php">Manage donations</a>
    </div>

    <p class="ds-note">Enter each parish's <strong>own</strong> account exactly as its parish office gave it to you, because donors send real money to it.
        A church with no details shows &ldquo;Online giving is not set up yet&rdquo;. Church Admins can also edit their own church's details, and add a DuitNow QR image, on their Manage Donations page.</p>

    <form method="post" action="donation-settings.php" class="ds-form" novalidate>
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="save_all">
        <div class="dm-table-card">
            <div class="table-wrap">
                <table class="dm-table ds-table">
                    <thead><tr><th>Church</th><th>Bank</th><th>Account Name</th><th>Account Number</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($churches as $c): $id = (int)$c['church_info_id']; $r = $form[$id];
                        $ok = $r['bank'] !== '' && $r['name'] !== '' && $r['no'] !== '';
                        $sample = stripos($r['name'], '(SAMPLE)') !== false; ?>
                        <tr class="<?= isset($errors[$id]) ? 'has-error' : '' ?>">
                            <td><span class="dn-name"><?= e($c['church_name']) ?></span><span class="dn-sub"><?= e($c['church_location']) ?></span></td>
                            <td><input type="text" name="rows[<?= $id ?>][bank]" value="<?= e($r['bank']) ?>" maxlength="100" placeholder="Bank" aria-label="Bank for <?= e($c['church_name']) ?>"></td>
                            <td><input type="text" name="rows[<?= $id ?>][name]" value="<?= e($r['name']) ?>" maxlength="100" placeholder="Account name" aria-label="Account name for <?= e($c['church_name']) ?>"></td>
                            <td><input type="text" name="rows[<?= $id ?>][no]" value="<?= e($r['no']) ?>" maxlength="40" inputmode="numeric" placeholder="Account number" aria-label="Account number for <?= e($c['church_name']) ?>"></td>
                            <td>
                                <?php if ($sample): ?><span class="st-badge st-pending">Sample</span>
                                <?php elseif ($ok): ?><span class="st-badge st-approved"><?= don_icon('check', 13) ?> Ready</span>
                                <?php else: ?><span class="st-badge st-none">Not set</span><?php endif; ?>
                                <?php if (qr_image_url($c['donation_qr_image'])): ?><span class="dn-sub">+ QR image</span><?php endif; ?>
                                <?php if (isset($errors[$id])): ?><span class="row-error"><?= e($errors[$id]) ?></span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="don-actions ds-actions">
            <a href="donation-settings.php" class="btn-cancel">Reset</a>
            <button type="submit" class="btn-save">Save all</button>
        </div>
    </form>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
