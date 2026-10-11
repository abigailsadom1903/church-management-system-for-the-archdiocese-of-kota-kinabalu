<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/donation_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT d.*, c.church_name, c.church_location, c.church_email, c.church_contact_details,
            c.donation_bank_name, c.donation_account_name, c.donation_account_number, c.donation_qr_image,
            a.admin_name AS reviewer
       FROM donations d
       JOIN church_info c ON c.church_info_id = d.church_id
  LEFT JOIN admin a ON a.admin_id = d.reviewed_by
      WHERE d.donation_id = ?'
);
$stmt->execute([$id]);
$d = $stmt->fetch();

// Super Admin: any church. Church Admin: only their own church's donations.
if (!$d || !can_manage_church($pdo, (int)$d['church_id'])) {
    header('Location: donations.php');
    exit;
}
$churchId = (int)$d['church_id'];
$backUrl = 'donation-manage.php?church=' . $churchId;

$errors = [];
$reason = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $reason = trim($_POST['reason'] ?? '');
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['form'] = 'Your session expired. Please try again.';
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM donations WHERE donation_id = ?')->execute([$id]);
        delete_receipt_file($d['receipt_file']);
        $_SESSION['donation_flash'] = 'Donation deleted.';
        header('Location: ' . $backUrl);
        exit;
    } elseif ($action === 'approve' || $action === 'reject') {
        $new = $action === 'approve' ? 'approved' : 'rejected';
        if (mb_strlen($reason) > 255) {
            $errors['reason'] = 'Please keep the note under 255 characters.';
        } else {
            $changed = $d['status'] !== $new;
            $pdo->prepare('UPDATE donations SET status = ?, reject_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE donation_id = ?')
                ->execute([$new, $new === 'rejected' ? ($reason ?: null) : null, (int)$_SESSION['user_id'], $id]);

            // Tell the donor, but only when the decision actually changed (no duplicate emails).
            $sent = false;
            if ($changed) {
                $d['status'] = $new;
                $d['reject_reason'] = $new === 'rejected' ? ($reason ?: null) : null;
                $sent = send_donation_result_email($d, $d);   // $d also carries the church's name, email and phone
                if ($sent) $pdo->prepare('UPDATE donations SET email_sent_at = NOW() WHERE donation_id = ?')->execute([$id]);
            }
            $_SESSION['donation_flash'] = ($new === 'approved' ? 'Donation approved' : 'Donation rejected')
                . ($changed ? ($sent ? ' and the donor was emailed.' : '. The donor could not be emailed.') : '.');
            header('Location: ' . $backUrl);
            exit;
        }
    }
}

[$stLabel, $stClass, $stIcon] = donation_status($d['status']);
$file = receipt_path($d['receipt_file']);
$isPdf = $file && strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'pdf';
$qrUrl = qr_image_url($d['donation_qr_image']);

$pageTitle = 'Review Donation';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="dm-page rv-page">
    <?php back_button($backUrl, 'Back to donations'); ?>
    <header class="rv-head">
        <h1>Review Donations</h1>
        <p><span class="st-badge <?= $stClass ?>"><?= don_icon($stIcon, 13) ?> <?= $stLabel ?></span>
           <span class="rv-ref"><?= e(donation_ref($id)) ?></span> &middot; <?= e($d['church_name']) ?></p>
    </header>

    <?php if (isset($errors['form'])): ?><div class="alert info-alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>

    <form method="post" action="donation-review.php?id=<?= $id ?>" class="rv-card">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">

        <div class="rv-grid">
            <section class="rv-step">
                <h2><span class="step-num">1</span> Details</h2>
                <dl class="rv-dl">
                    <dt>Full Name:</dt><dd><?= e($d['donor_name']) ?></dd>
                    <dt>Email:</dt><dd><a href="mailto:<?= e($d['donor_email']) ?>"><?= e($d['donor_email']) ?></a></dd>
                    <dt>Contact:</dt><dd><?= e($d['donor_phone']) ?></dd>
                    <dt>Purpose:</dt><dd><?= e($d['purpose']) ?></dd>
                    <dt>Message:</dt><dd><?= $d['message'] ? nl2br(e($d['message'])) : '<span class="muted">No message</span>' ?></dd>
                    <dt>Amount:</dt><dd class="rv-amount"><?= rm($d['amount']) ?></dd>
                    <dt>Submitted:</dt><dd><?= e(date('j M Y, g:i A', strtotime($d['created_at']))) ?></dd>
                    <?php if ($d['reviewed_at']): ?><dt>Reviewed:</dt><dd><?= e(date('j M Y, g:i A', strtotime($d['reviewed_at']))) ?><?= $d['reviewer'] ? ' by ' . e($d['reviewer']) : '' ?></dd><?php endif; ?>
                    <?php if ($d['status'] === 'rejected' && $d['reject_reason']): ?><dt>Reason:</dt><dd><?= e($d['reject_reason']) ?></dd><?php endif; ?>
                    <?php if ($d['email_sent_at']): ?><dt>Donor emailed:</dt><dd><?= don_icon('mail', 13) ?> <?= e(date('j M Y, g:i A', strtotime($d['email_sent_at']))) ?></dd><?php endif; ?>
                </dl>
            </section>

            <div class="rv-side">
                <section class="rv-step">
                    <h2><span class="step-num">2</span> Payment Method</h2>
                    <div class="rv-pay">
                        <p class="rv-pay-title"><?= don_icon($d['method'] === 'qr' ? 'qr' : 'bank', 18) ?> <?= e(DONATION_METHODS[$d['method']]) ?></p>
                        <?php if ($d['method'] === 'bank' && $d['donation_bank_name']): ?>
                            <dl class="rv-bank">
                                <dt>Bank:</dt><dd><?= e($d['donation_bank_name']) ?></dd>
                                <dt>Account Name:</dt><dd><?= e($d['donation_account_name']) ?></dd>
                                <dt>Account Number:</dt><dd><?= e($d['donation_account_number']) ?></dd>
                            </dl>
                        <?php elseif ($d['method'] === 'qr'): ?>
                            <p class="rv-pay-note">Paid by scanning the parish's DuitNow QR code<?= $qrUrl ? '.' : ' (no QR image is on file now).' ?></p>
                        <?php else: ?>
                            <p class="rv-pay-note">The parish's bank details are not set up any more.</p>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="rv-step">
                    <h2><span class="step-num">3</span> Receipt</h2>
                    <?php if (!$file): ?>
                        <p class="ann-empty">The receipt file is missing.</p>
                    <?php elseif ($isPdf): ?>
                        <a class="rv-receipt is-pdf" href="receipt.php?id=<?= $id ?>" target="_blank" rel="noopener"><?= don_icon('file', 34) ?><span>PDF receipt<small>Open in a new tab</small></span></a>
                    <?php else: ?>
                        <a class="rv-receipt" href="receipt.php?id=<?= $id ?>" target="_blank" rel="noopener" title="Open full size"><img src="receipt.php?id=<?= $id ?>" alt="Transaction receipt for <?= e(donation_ref($id)) ?>"></a>
                        <p class="rv-hint">Click the receipt to open it full size.</p>
                    <?php endif; ?>
                </section>
            </div>
        </div>

        <div class="<?= isset($errors['reason']) ? 'pfield has-error' : 'pfield' ?> rv-note">
            <label for="reason">Note for the donor <span class="opt">(optional, included in the email if you reject)</span></label>
            <textarea id="reason" name="reason" rows="2" maxlength="255"><?= e($reason ?: ($d['status'] === 'rejected' ? (string)$d['reject_reason'] : '')) ?></textarea>
            <?php if (isset($errors['reason'])): ?><span class="row-error"><?= e($errors['reason']) ?></span><?php endif; ?>
        </div>

        <div class="rv-actions">
            <button type="submit" name="action" value="delete" class="rv-delete" data-confirm="Delete this donation record and its receipt? This cannot be undone."><?= don_icon('trash', 14) ?> Delete</button>
            <a href="donation-email-preview.php?id=<?= $id ?>" class="rv-preview"><?= don_icon('mail', 14) ?> Preview email</a>
            <span class="rv-spacer"></span>
            <a href="<?= e($backUrl) ?>" class="btn-close">Close</a>
            <button type="submit" name="action" value="reject" class="btn-reject-solid"><?= don_icon('x', 16) ?> Reject<?= $d['status'] === 'rejected' ? 'ed' : '' ?></button>
            <button type="submit" name="action" value="approve" class="btn-approve-solid"><?= don_icon('check', 16) ?> <?= $d['status'] === 'approved' ? 'Approved' : 'Approve &amp; Send Email' ?></button>
        </div>
    </form>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
