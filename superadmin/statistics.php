<?php
require __DIR__ . '/../includes/auth.php';
require_role('super_admin');
require __DIR__ . '/../config/db.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

const STAT_MAX = 999999999;

$rows   = $pdo->query('SELECT stat_key, stat_label, stat_value FROM site_stats ORDER BY sort_order')->fetchAll();
$values = array_column($rows, 'stat_value', 'stat_key');
$errors = [];
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $formError = 'Your session expired. Please try again.';
    } else {
        $clean = [];
        foreach ($rows as $r) {
            $key = $r['stat_key'];
            $raw = (string)($_POST['stats'][$key] ?? '');
            $values[$key] = $raw;                       // keep what they typed if we redisplay the form
            $num = str_replace([',', ' '], '', $raw);   // accept "250,255"
            $int = filter_var($num, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => STAT_MAX]]);
            if ($int === false) {
                $errors[$key] = 'Enter a whole number between 0 and ' . number_format(STAT_MAX) . '.';
            } else {
                $clean[$key] = $int;
            }
        }

        if (!$errors) {
            $upd = $pdo->prepare('UPDATE site_stats SET stat_value = ?, updated_by = ? WHERE stat_key = ?');
            $pdo->beginTransaction();
            foreach ($clean as $key => $int) {
                $upd->execute([$int, (int)$_SESSION['user_id'], $key]);
            }
            $pdo->commit();
            header('Location: ../index.php?saved=1');
            exit;
        }
    }
}

$pageTitle = 'Edit Statistics';
$roleLabel = 'Super Admin';
include __DIR__ . '/../includes/dashboard_header.php';
?>
<h1 class="dash-title">Edit Home Page Statistics</h1>
<p class="dash-sub">These figures are shown on the public Home page.</p>

<section class="panel stats-form">
    <?php if ($formError): ?><div class="alert" role="alert"><?= e($formError) ?></div><?php endif; ?>

    <form method="post" action="statistics.php" novalidate>
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">

        <?php foreach ($rows as $r): $k = $r['stat_key']; ?>
            <div class="<?= isset($errors[$k]) ? 'field has-error' : 'field' ?>">
                <label for="stat-<?= e($k) ?>"><?= e($r['stat_label']) ?></label>
                <input type="text" inputmode="numeric" id="stat-<?= e($k) ?>" name="stats[<?= e($k) ?>]" value="<?= e((string)$values[$k]) ?>" required<?= ($_GET['focus'] ?? '') === $k ? ' autofocus' : '' ?>>
                <?php if (isset($errors[$k])): ?><span class="error-text"><?= e($errors[$k]) ?></span><?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="form-actions">
            <button type="submit" class="btn-primary">Save Changes</button>
            <a href="dashboard.php">Cancel</a>
        </div>
    </form>
</section>
<?php include __DIR__ . '/../includes/dashboard_footer.php'; ?>
