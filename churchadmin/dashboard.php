<?php
require __DIR__ . '/../includes/auth.php';
require_role('church_admin');
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/church_helpers.php';
require __DIR__ . '/../includes/report_tools.php';
require __DIR__ . '/../includes/analytics.php';

$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

$adminId = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare(
    "SELECT a.church_id, c.church_name, c.church_location, c.church_contact_details, c.church_office_hours, " . PRIEST_NAMES_SQL . " AS church_priest
       FROM admin a
  LEFT JOIN church_info c ON c.church_info_id = a.church_id
      WHERE a.admin_id = ?"
);
$stmt->execute([$adminId]);
$church = $stmt->fetch();
$churchId = (int)($church['church_id'] ?? 0);   // 0 = not linked to a church, matches no users

$months = an_months($_GET['range'] ?? null);
$data = $churchId ? an_data($pdo, $churchId, $months) : null;   // never fall back to every church

$stmt = $pdo->prepare(
    "SELECT user_name, user_email, user_phone, created_at
       FROM user WHERE church_id = ?
   ORDER BY created_at DESC, user_id DESC LIMIT 10"
);
$stmt->execute([$churchId]);
$members = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM donations WHERE church_id = ? AND status = 'pending'");
$stmt->execute([$churchId]);
$pendingDonations = (int)$stmt->fetchColumn();

$pageTitle = 'Church Admin Dashboard';
$roleLabel = 'Church Admin';
$dashPending = $pendingDonations;   // shown as a badge on the navbar's Donations item
include __DIR__ . '/../includes/dashboard_header.php';
?>
<header class="dash-hero">
    <div>
        <p class="dash-eyebrow">Church Admin &middot; Welcome, <?= e($_SESSION['user_name']) ?></p>
        <h1 class="dash-title"><?= e($church['church_name'] ?? 'No church assigned') ?></h1>
        <?php
        $waiting = array_filter([
            'sacrament application' => $dashSacraments, 'pastoral request' => $dashPastoral, 'donation' => $dashPending,
        ]);
        $waitingText = implode(', ', array_map(fn($what, $n) => $n . ' ' . $what . ($n === 1 ? '' : 's'), array_keys($waiting), $waiting));
        ?>
        <p class="dash-sub"><?= $waiting ? e($waitingText . ' waiting for your review') . ' &mdash; see below.' : 'Nothing is waiting for review. Manage your parish announcements, bulletins, events and donations.' ?></p>
    </div>
    <div class="dash-hero-side">
        <?php report_tools('../report-all.php', 'Print this dashboard'); ?>
        <span class="dash-date"><?= e(date('l, j F Y')) ?></span>
    </div>
</header>

<h2 class="dash-section-title">Waiting for review</h2>
<section class="dash-attn" aria-label="Waiting for review">
    <?php
    require_once __DIR__ . '/../includes/review_queue.php';
    $reviewScope = $churchId;   // which church's items (null = every church)
    foreach ([
        ['Sacrament applications', $dashSacraments, 'sacraments'],
        ['Pastoral requests', $dashPastoral, 'pastoral'],
        ['Donations to review', $dashPending, 'donations'],
    ] as [$label, $n, $queue]):
        $href = '../' . ($n ? review_link($pdo, $queue, $reviewScope) : REVIEW_QUEUES[$queue]['page']);
    ?>
        <a class="attn-card<?= $n ? ' has-items' : '' ?>" href="<?= e($href) ?>">
            <b><?= (int)$n ?></b>
            <span><?= e($label) ?></span>
            <small><?= $n ? '<u>Review now &rarr;</u>' : 'All clear' ?></small>
        </a>
    <?php endforeach; ?>
</section>

<?php if ($data): ?>
    <h2 class="dash-section-title">Analytics</h2>
    <?php an_filters($months); ?>
    <?php an_render($data); ?>
<?php endif; ?>

<section class="panel">
    <div class="panel-head">
        <h2>Church Information</h2>
        <div class="panel-tools"><a class="btn-new" href="../church-information.php"><?= dash_icon('pencil', 15) ?> Edit</a></div>
    </div>
    <?php if ($church['church_name'] ?? null): ?>
        <dl class="info-grid">
            <div><dt>Location</dt><dd><?= e($church['church_location']) ?: '—' ?></dd></div>
            <div><dt>Contact</dt><dd><?= e($church['church_contact_details']) ?: '—' ?></dd></div>
            <div><dt><?= str_contains((string)$church['church_priest'], ',') ? 'Priests' : 'Parish Priest' ?></dt><dd><?= e($church['church_priest']) ?: '—' ?></dd></div>
            <div><dt>Office Hours</dt><dd><?= e($church['church_office_hours']) ?: '—' ?></dd></div>
        </dl>
    <?php else: ?>
        <p class="empty">Your account is not linked to a church yet. Please contact the Super Admin.</p>
    <?php endif; ?>
</section>

<section class="panel" id="parishioners">
    <?php if ($userFlash = $_SESSION['user_flash'] ?? null): unset($_SESSION['user_flash']); ?>
        <div class="alert success cm-flash" role="status"><?= e($userFlash) ?></div>
    <?php endif; ?>
    <div class="panel-head">
        <h2>Latest Parishioners</h2>
        <?php if ($churchId): ?>
            <div class="panel-tools"><a class="btn-new" href="../user-form.php"><?= ann_icon('plus', 16) ?> Add Parishioner</a></div>
        <?php endif; ?>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Registered</th></tr></thead>
            <tbody>
            <?php foreach ($members as $m): ?>
                <tr>
                    <td><?= e($m['user_name']) ?></td>
                    <td><?= e($m['user_email']) ?></td>
                    <td><?= e($m['user_phone']) ?></td>
                    <td><?= e(date('j M Y', strtotime($m['created_at']))) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$members): ?><tr><td colspan="4" class="empty">No parishioners have registered with your church yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php include __DIR__ . '/../includes/dashboard_footer.php'; ?>
