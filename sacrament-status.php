<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/login_gate.php';
require_signed_in('Sacrament Application Status');   // visitors get "please login or register" instead of this page
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/sacrament_helpers.php';
require __DIR__ . '/includes/sacrament_view.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$isUser = ($_SESSION['role'] ?? '') === 'user';
$userId = $isUser ? (int)$_SESSION['user_id'] : 0;

// Only the person who applied can see their own status (the church's staff use the review page).
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT a.*, c.church_name FROM sacrament_applications a JOIN church_info c ON c.church_info_id = a.church_id WHERE a.application_id = ? AND a.user_id = ?');
$stmt->execute([$id, $userId]);
$app = $userId ? $stmt->fetch() : false;
$cfg = $app ? sx_config($app['sacrament']) : null;
if (!$app || !$cfg) {
    header('Location: sacraments.php');
    exit;
}
if ($app['status'] === 'draft') {   // not sent yet: carry on with the form
    header('Location: ' . ($cfg['kind'] === 'booking' ? 'sacrament-confession.php' : 'sacrament-apply.php?type=' . urlencode($app['sacrament']) . '&id=' . $id));
    exit;
}

$flash = $_SESSION['sacrament_flash'] ?? null;
unset($_SESSION['sacrament_flash']);
$details = json_decode((string)$app['details'], true) ?: [];
$files = json_decode((string)$app['files'], true) ?: [];
[$stLabel, $stClass] = sx_status($app['status']);
$isBooking = $app['sacrament'] === 'confession';
if ($isBooking && $app['status'] === 'submitted') $stLabel = 'Confirmed';
$done = sx_progress($app['status']);
$rejected = $app['status'] === 'rejected';
$steps = ['Application Submitted', 'Document Verification', 'Review', $rejected ? 'Not Approved' : 'Approved'];

$pageTitle = 'Sacrament Application Status';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="sx-page sx-status-page">
    <?php back_button('sacraments.php', 'Back to Sacraments'); ?>
    <header class="page-heading"><h1><?= $isBooking ? 'Confession Booking' : 'Sacrament Application Status' ?></h1></header>

    <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>

    <?php if (!$isBooking): ?>
    <ol class="st-track" aria-label="Progress">
        <?php foreach ($steps as $i => $label):
            $n = $i + 1;
            $isDone = $rejected ? $n <= 3 : $n <= $done;
            $isNo = $rejected && $n === 4;
        ?>
            <li class="<?= $isNo ? 'is-no' : ($isDone ? 'is-done' : '') ?>"<?= $n === $done || $isNo ? ' aria-current="step"' : '' ?>>
                <span class="st-dot"><?= $isNo ? sx_icon('x', 20) : sx_icon('check', 20) ?></span>
                <span class="st-label"><?= e($label) ?></span>
            </li>
        <?php endforeach; ?>
    </ol>
    <?php endif; ?>

    <div class="st-grid<?= $app['admin_note'] ? ' has-note' : '' ?>">
    <section class="st-summary" aria-label="Application summary">
        <div class="st-summary-head">
            <h2><?= $isBooking ? 'Booking Summary' : 'Application Summary' ?></h2>
            <span class="st-ref"><?= e(sx_ref((int)$app['application_id'])) ?></span>
        </div>
        <div class="st-rows">
<?php if ($isBooking): ?>
            <div class="st-row"><dt>Date</dt><dd><?= !empty($details['date']) ? e(date('jS F Y', strtotime($details['date']))) : '&mdash;' ?></dd></div>
            <div class="st-row"><dt>Time</dt><dd><?= !empty($details['time']) ? e(date('h:i a', strtotime($details['time']))) : '&mdash;' ?></dd></div>
            <div class="st-row"><dt>Church</dt><dd><?= e($app['church_name']) ?></dd></div>
<?php if (!empty($details['location'])): ?>            <div class="st-row"><dt>Where</dt><dd><?= e($details['location']) ?></dd></div>
<?php endif; ?>
<?php else: ?>
            <div class="st-row"><dt>Sacrament Type</dt><dd><?= e($cfg['title']) ?></dd></div>
            <div class="st-row"><dt>Submission Date</dt><dd><?= e(date('d M Y', strtotime($app['submitted_at'] ?: $app['created_at']))) ?></dd></div>
            <div class="st-row"><dt>Church</dt><dd><?= e($app['church_name']) ?></dd></div>
<?php endif; ?>
        </div>
        <div class="st-status-row">
            <span>Status</span>
            <span class="st-status-pill <?= e($stClass) ?>"><?= e($stLabel) ?></span>
        </div>
    </section>
    <?php if ($app['admin_note']): ?>
        <aside class="st-note-card <?= e($stClass) ?>" aria-label="Note from the church">
            <span class="st-note-ico"><?= sx_icon('message', 18) ?></span>
            <p class="st-note-label">Note from the church</p>
            <p class="st-note-text"><?= nl2br(e($app['admin_note'])) ?></p>
        </aside>
    <?php endif; ?>
    </div>

    <?php if (!$isBooking): ?>
    <details class="st-details">
        <summary><span class="st-details-ico"><?= sx_icon('file', 16) ?></span>View what you submitted<span class="st-details-chev"><?= sx_icon('chevron', 16) ?></span></summary>
        <div class="st-details-body"><?php sx_render_details($cfg, $details, $files, (int)$app['application_id']); ?></div>
    </details>
    <?php endif; ?>

    <?php if (!$isBooking && $app['status'] === 'rejected'): ?>
        <div class="st-actions">
            <a class="reg-submit st-resubmit" href="sacrament-apply.php?type=<?= e($app['sacrament']) ?>&amp;id=<?= (int)$app['application_id'] ?>"><?= sx_icon('pencil', 15) ?>Edit &amp; Resubmit</a>
        </div>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
