<?php
require __DIR__ . '/../includes/auth.php';
require_role('super_admin');
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/church_helpers.php';
require __DIR__ . '/../includes/report_tools.php';
require __DIR__ . '/../includes/analytics.php';

$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

// The filter row scopes everything below it: one church (?church=) or every church, over ?range= months.
$months = an_months($_GET['range'] ?? null);
$churchNames = $pdo->query('SELECT church_info_id, church_name FROM church_info ORDER BY church_name')->fetchAll(PDO::FETCH_KEY_PAIR);
$churchId = (int)($_GET['church'] ?? 0);
if (!isset($churchNames[$churchId])) $churchId = 0;   // 0 = every church

$data = an_data($pdo, $churchId ?: null, $months);

$stats = $pdo->query(
    "SELECT (SELECT COUNT(*) FROM church_info)                            AS churches,
            (SELECT COUNT(*) FROM admin WHERE admin_role = 'church_admin') AS church_admins"
)->fetch();

$stmt = $pdo->prepare(
    "SELECT u.user_name, u.user_email, u.created_at, c.church_name
       FROM user u
  LEFT JOIN church_info c ON c.church_info_id = u.church_id
      WHERE ? = 0 OR u.church_id = ?
   ORDER BY u.created_at DESC, u.user_id DESC
      LIMIT 5"
);
$stmt->execute([$churchId, $churchId]);
$recent = $stmt->fetchAll();

$pageTitle = 'Super Admin Dashboard';
$roleLabel = 'Super Admin';
include __DIR__ . '/../includes/dashboard_header.php';
?>
<header class="dash-hero">
    <div>
        <p class="dash-eyebrow">Super Admin &middot; Welcome, <?= e($_SESSION['user_name']) ?></p>
        <h1 class="dash-title"><?= $churchId ? e($churchNames[$churchId]) : 'Archdiocese of Kota Kinabalu' ?></h1>
        <p class="dash-sub"><?= $churchId
            ? 'Figures for this church only. Pick &ldquo;All churches&rdquo; below to see the whole archdiocese.'
            : (int)$stats['churches'] . ' churches &middot; ' . (int)$stats['church_admins'] . ' church admins' ?></p>
    </div>
    <div class="dash-hero-side">
        <?php report_tools('../report-all.php' . ($churchId ? '?church=' . $churchId : ''), 'Print this dashboard'); ?>
        <span class="dash-date"><?= e(date('l, j F Y')) ?></span>
    </div>
</header>

<h2 class="dash-section-title">Waiting for review</h2>
<section class="dash-attn" aria-label="Waiting for review">
    <?php
    require_once __DIR__ . '/../includes/review_queue.php';
    $reviewScope = null;   // which church's items (null = every church)
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

<h2 class="dash-section-title">Analytics</h2>
<?php an_filters($months, $churchNames, $churchId); ?>
<?php an_render($data); ?>

<section class="panel" id="parishioners">
    <?php if ($userFlash = $_SESSION['user_flash'] ?? null): unset($_SESSION['user_flash']); ?>
        <div class="alert success cm-flash" role="status"><?= e($userFlash) ?></div>
    <?php endif; ?>
    <div class="panel-head">
        <h2>Recent Registrations</h2>
        <div class="panel-tools"><a class="btn-new" href="../user-form.php<?= $churchId ? '?church=' . $churchId : '' ?>"><?= ann_icon('plus', 16) ?> Add Parishioner</a></div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Name</th><th>Email</th><th>Home Parish</th><th>Registered</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $u): ?>
                <tr>
                    <td><?= e($u['user_name']) ?></td>
                    <td><?= e($u['user_email']) ?></td>
                    <td><?= e($u['church_name'] ?? '—') ?></td>
                    <td><?= e(date('j M Y', strtotime($u['created_at']))) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recent): ?><tr><td colspan="4" class="empty">No registrations yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php include __DIR__ . '/../includes/dashboard_footer.php'; ?>
