<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/login_gate.php';
require_signed_in('Request Application Status');   // visitors get "please login or register" instead of this page
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/sacrament_helpers.php';
require __DIR__ . '/includes/sacrament_view.php';
require __DIR__ . '/includes/pastoral_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$isUser = ($_SESSION['role'] ?? '') === 'user';
$userId = $isUser ? (int)$_SESSION['user_id'] : 0;

// Only the person who asked can see their own request here (the church's staff use pastoral-manage.php).
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT r.*, c.church_name FROM pastoral_requests r JOIN church_info c ON c.church_info_id = r.church_id WHERE r.request_id = ? AND r.user_id = ?');
$stmt->execute([$id, $userId]);
$req = $userId ? $stmt->fetch() : false;
$cfg = $req ? pc_form($pdo, $req['service'], (int)$req['church_id']) : null;
if (!$req || !$cfg) {
    header('Location: pastoral-care.php');
    exit;
}
if ($req['status'] === 'draft') {   // not sent yet: carry on with the form
    header('Location: pastoral-request.php?service=' . urlencode($req['service']) . '&id=' . $id);
    exit;
}

$flash = $_SESSION['pastoral_flash'] ?? null;
unset($_SESSION['pastoral_flash']);
$details = json_decode((string)$req['details'], true) ?: [];
$files = json_decode((string)$req['files'], true) ?: [];
[$stLabel, $stClass] = pc_status($req['status']);
$done = pc_progress($req['status']);
$declined = $req['status'] === 'declined';
$steps = ['Application Submitted', 'Review', $declined ? 'Declined' : 'Approved'];

$pageTitle = 'Request Application Status';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="sx-page sx-status-page">
    <?php back_button('pastoral-care.php', 'Back to Pastoral & Spiritual Care'); ?>
    <header class="page-heading"><h1>Request Application Status</h1></header>

    <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>

    <ol class="st-track is-three" aria-label="Progress">
        <?php foreach ($steps as $i => $label):
            $n = $i + 1;
            $isNo = $declined && $n === 3;
            $isDone = !$isNo && $n <= $done;
        ?>
            <li class="<?= $isNo ? 'is-no' : ($isDone ? 'is-done' : '') ?>"<?= $n === $done || $isNo ? ' aria-current="step"' : '' ?>>
                <span class="st-dot"><?= $isNo ? sx_icon('x', 20) : sx_icon('check', 20) ?></span>
                <span class="st-label"><?= e($label) ?></span>
            </li>
        <?php endforeach; ?>
    </ol>

    <div class="st-grid<?= $req['admin_note'] ? ' has-note' : '' ?>">
    <section class="st-summary" aria-label="Application summary">
        <div class="st-summary-head">
            <h2>Application Summary</h2>
            <span class="st-ref"><?= e(pc_ref((int)$req['request_id'])) ?></span>
        </div>
        <div class="st-rows">
            <div class="st-row"><dt>Type</dt><dd><?= e(pc_title($pdo, $req['service'], (int)$req['church_id'])) ?></dd></div>
            <div class="st-row"><dt>Submission Date</dt><dd><?= e(date('d M Y', strtotime($req['submitted_at'] ?: $req['created_at']))) ?></dd></div>
            <div class="st-row"><dt>Church</dt><dd><?= e($req['church_name']) ?></dd></div>
        </div>
        <div class="st-status-row">
            <span>Status</span>
            <span class="st-status-pill <?= e($stClass) ?>"><?= e($stLabel) ?></span>
        </div>
    </section>
    <?php if ($req['admin_note']): ?>
        <aside class="st-note-card <?= e($stClass) ?>" aria-label="Note from the church">
            <span class="st-note-ico"><?= sx_icon('message', 18) ?></span>
            <p class="st-note-label">Note from the church</p>
            <p class="st-note-text"><?= nl2br(e($req['admin_note'])) ?></p>
        </aside>
    <?php endif; ?>
    </div>

    <details class="st-details">
        <summary><span class="st-details-ico"><?= sx_icon('file', 16) ?></span>View what you submitted<span class="st-details-chev"><?= sx_icon('chevron', 16) ?></span></summary>
        <div class="st-details-body"><?php sx_render_details($cfg, $details, $files, (int)$req['request_id'], 'pastoral-file.php'); ?></div>
    </details>

    <?php if ($req['status'] === 'declined'): ?>
        <div class="st-actions">
            <a class="reg-submit st-resubmit" href="pastoral-request.php?service=<?= e($req['service']) ?>&amp;id=<?= (int)$req['request_id'] ?>"><?= sx_icon('pencil', 15) ?>Edit &amp; Resubmit</a>
        </div>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
