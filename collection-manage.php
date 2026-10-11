<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/donation_helpers.php';
require __DIR__ . '/includes/report_tools.php';

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

$churches = $pdo->query('SELECT church_info_id, church_name, church_location FROM church_info ORDER BY church_name')->fetchAll();
$byId = array_column($churches, null, 'church_info_id');
$selectedId = pick_church_id($pdo, $byId, (int)($_POST['church'] ?? $_GET['church'] ?? 0));
// A Church Admin who asks for another church is sent to their own.
if (!can_manage_church($pdo, $selectedId)) {
    $own = pick_church_id($pdo, $byId, 0);
    if ($own === $selectedId || !can_manage_church($pdo, $own)) {
        header('Location: donations.php');
        exit;
    }
    header('Location: collection-manage.php?church=' . $own);
    exit;
}
$c = $byId[$selectedId];
$selfUrl = 'collection-manage.php?church=' . $selectedId;

$flash = $_SESSION['collection_flash'] ?? null;
unset($_SESSION['collection_flash']);
$errors = [];

// Editing an existing week (?edit=id). The church always comes from the record.
$editId = (int)($_POST['id'] ?? $_GET['edit'] ?? 0);
$editing = null;
if ($editId) {
    $stmt = $pdo->prepare('SELECT * FROM weekly_collections WHERE collection_id = ? AND church_id = ?');
    $stmt->execute([$editId, $selectedId]);
    $editing = $stmt->fetch() ?: null;
    if (!$editing) $editId = 0;
}

$lastSunday = date('Y-m-d', strtotime(date('w') === '0' ? 'today' : 'last sunday'));
$f = [
    'week'   => $editing['week_date'] ?? $lastSunday,
    'amount' => $editing ? number_format((float)$editing['amount'], 2, '.', '') : '',
    'note'   => $editing['note'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['form'] = 'Your session expired. Please try again.';
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM weekly_collections WHERE collection_id = ? AND church_id = ?')->execute([$editId, $selectedId]);
        $_SESSION['collection_flash'] = 'Weekly collection deleted.';
        header('Location: ' . $selfUrl);
        exit;
    } elseif ($action === 'save') {
        $f['week']   = trim($_POST['week'] ?? '');
        $f['amount'] = trim($_POST['amount'] ?? '');
        $f['note']   = trim($_POST['note'] ?? '');
        $amount = str_replace([',', ' '], '', preg_replace('/^\s*RM\s*/i', '', $f['amount']));

        $dt = DateTime::createFromFormat('Y-m-d', $f['week']);
        if (!$dt || $dt->format('Y-m-d') !== $f['week'] || $f['week'] < '2000-01-01') {
            $errors['week'] = 'Enter a valid date.';
        } elseif ($f['week'] > date('Y-m-d')) {
            $errors['week'] = 'The date cannot be in the future.';
        }
        if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $amount) || (float)$amount > 100000000) {
            $errors['amount'] = 'Enter an amount in RM, e.g. 1250.50.';
        }
        if (mb_strlen($f['note']) > 150) $errors['note'] = 'Keep the note under 150 characters.';

        if (!isset($errors['week'])) {
            $stmt = $pdo->prepare('SELECT collection_id FROM weekly_collections WHERE church_id = ? AND week_date = ? AND collection_id <> ?');
            $stmt->execute([$selectedId, $f['week'], $editId]);
            if ($dupId = (int)$stmt->fetchColumn()) {
                $errors['week'] = 'A collection is already recorded for this date. Edit that entry instead.';
                $errors['dup'] = $dupId;
            }
        }

        if (!$errors) {
            $values = [$f['week'], number_format((float)$amount, 2, '.', ''), $f['note'] ?: null];
            if ($editing) {
                $pdo->prepare('UPDATE weekly_collections SET week_date = ?, amount = ?, note = ? WHERE collection_id = ? AND church_id = ?')
                    ->execute([...$values, $editId, $selectedId]);
            } else {
                $pdo->prepare('INSERT INTO weekly_collections (week_date, amount, note, church_id, created_by) VALUES (?, ?, ?, ?, ?)')
                    ->execute([...$values, $selectedId, (int)$_SESSION['user_id']]);
            }
            $_SESSION['collection_flash'] = $editing ? 'Weekly collection updated.' : 'Weekly collection added.';
            header('Location: ' . $selfUrl);
            exit;
        }
    }
}

$stmt = $pdo->prepare('SELECT collection_id, week_date, amount, note FROM weekly_collections WHERE church_id = ? ORDER BY week_date DESC' . (($_GET['export'] ?? '') === 'csv' ? '' : ' LIMIT 104'));
$stmt->execute([$selectedId]);
$rows = $stmt->fetchAll();

// ---- Generate Report: every recorded week, as a CSV ----
if (($_GET['export'] ?? '') === 'csv') {
    send_report('weekly-collection-' . $c['church_name'],
        ['Week of', 'Amount (RM)', 'Note', 'Church'],
        array_map(fn($r) => [$r['week_date'], number_format((float)$r['amount'], 2, '.', ''), $r['note'], $c['church_name']], $rows));
}
$chartRows = array_reverse(array_slice($rows, 0, 12));
$recent = array_map(fn($r) => (float)$r['amount'], $chartRows);

function fc(array $errors, string $key): string { return isset($errors[$key]) ? 'pfield has-error' : 'pfield'; }

$pageTitle = 'Weekly Collection';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="dm-page">
    <?php back_button('donation-manage.php?church=' . $selectedId, 'Back to Donations'); ?>
    <header class="page-heading">
        <h1>Weekly Collection</h1>
        <p>Record what <?= e($c['church_name']) ?> collects each week. Parishioners see these totals as a chart on the Donation &amp; Offering page.</p>
    </header>

    <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>
    <?php if (isset($errors['form'])): ?><div class="alert info-alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>

    <?php if ($isSuper): ?>
        <form method="get" action="collection-manage.php" class="info-select" id="church-form">
            <label for="church">Church</label>
            <div class="select-wrap">
                <select id="church" name="church">
                    <?php foreach ($churches as $ch): ?>
                        <option value="<?= (int)$ch['church_info_id'] ?>" <?= (int)$ch['church_info_id'] === $selectedId ? 'selected' : '' ?>><?= e($ch['church_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="btn-primary">Show</button></noscript>
            </div>
        </form>
    <?php endif; ?>

    <div class="rpt-bar wc-toolbar">
        <a class="wc-public-link no-print" href="donations.php?church=<?= $selectedId ?>#weekly-collection">View the public chart</a>
        <?php report_tools('collection-manage.php?church=' . $selectedId . '&export=csv', 'Print the weekly collection'); ?>
    </div>

    <?php print_heading('Weekly Collection', $c['church_name'], $rows ? [
        'Weeks recorded' => (string)count($rows),
        'From' => date('j M Y', strtotime(end($rows)['week_date'])), 'To' => date('j M Y', strtotime($rows[0]['week_date'])),
        'Total' => rm(array_sum(array_column($rows, 'amount'))),
    ] : []); ?>

    <div class="wc-layout">
        <form method="post" action="<?= e($selfUrl) ?>" class="panel wc-form" id="entry" novalidate>
            <h2><?= $editing ? 'Edit week' : 'Add a week' ?></h2>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="church" value="<?= $selectedId ?>">
            <input type="hidden" name="action" value="save">
            <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editId ?>"><?php endif; ?>
            <div class="form-stack">
                <div class="<?= fc($errors, 'week') ?>">
                    <label for="wc-week">Collection date <span class="opt">(usually the Sunday)</span></label>
                    <input type="date" id="wc-week" name="week" max="<?= date('Y-m-d') ?>" value="<?= e($f['week']) ?>" required>
                    <?php if (isset($errors['week'])): ?><span class="row-error"><?= e($errors['week']) ?>
                        <?php if (isset($errors['dup'])): ?><a href="<?= e($selfUrl) ?>&amp;edit=<?= (int)$errors['dup'] ?>#entry">Edit it</a><?php endif; ?></span><?php endif; ?>
                </div>
                <div class="<?= fc($errors, 'amount') ?>">
                    <label for="wc-amount">Total collected (RM)</label>
                    <div class="amount-input"><span aria-hidden="true">RM</span><input type="text" id="wc-amount" name="amount" inputmode="decimal" placeholder="0.00" value="<?= e($f['amount']) ?>" required></div>
                    <?php if (isset($errors['amount'])): ?><span class="row-error"><?= e($errors['amount']) ?></span><?php endif; ?>
                </div>
                <div class="<?= fc($errors, 'note') ?>">
                    <label for="wc-note">Note <span class="opt">(optional, shown to parishioners)</span></label>
                    <input type="text" id="wc-note" name="note" maxlength="150" placeholder="e.g. Includes second collection for missions" value="<?= e($f['note']) ?>">
                    <?php if (isset($errors['note'])): ?><span class="row-error"><?= e($errors['note']) ?></span><?php endif; ?>
                </div>
            </div>
            <div class="profile-actions">
                <?php if ($editing): ?><a href="<?= e($selfUrl) ?>" class="btn-cancel">Cancel</a><?php endif; ?>
                <button type="submit" class="btn-save"><?= $editing ? 'Save changes' : 'Add collection' ?></button>
            </div>
        </form>

        <section class="panel wc-preview">
            <h2>Last <?= count($chartRows) ?: 12 ?> weeks</h2>
            <?php if ($chartRows): ?>
                <div class="wc-summary">
                    <div><span>Latest week</span><b><?= rm(end($recent)) ?></b></div>
                    <div><span>Weekly average</span><b><?= rm(array_sum($recent) / count($recent)) ?></b></div>
                    <div><span>Total</span><b><?= rm(array_sum($recent)) ?></b></div>
                </div>
                <?php render_collection_chart($chartRows); ?>
            <?php else: ?>
                <p class="empty">No weeks recorded yet. Add the first week and the chart will appear here.</p>
            <?php endif; ?>
        </section>
    </div>

    <div class="dm-table-card">
        <div class="table-wrap">
            <table class="dm-table">
                <thead><tr><th>Week of</th><th>Amount</th><th>Note</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr<?= (int)$r['collection_id'] === $editId ? ' class="is-editing"' : '' ?>>
                        <td class="dn-date"><?= e(date('D, j M Y', strtotime($r['week_date']))) ?></td>
                        <td class="dn-amount"><?= rm($r['amount']) ?></td>
                        <td><?= $r['note'] ? e($r['note']) : '&mdash;' ?></td>
                        <td>
                            <form method="post" action="<?= e($selfUrl) ?>" class="dm-actions">
                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                <input type="hidden" name="id" value="<?= (int)$r['collection_id'] ?>">
                                <a class="ico-btn sm" href="<?= e($selfUrl) ?>&amp;edit=<?= (int)$r['collection_id'] ?>#entry" title="Edit" aria-label="Edit week of <?= e($r['week_date']) ?>"><?= ann_icon('pencil', 13) ?></a>
                                <button type="submit" name="action" value="delete" class="ico-btn sm danger" data-confirm="Delete this week's collection? It will disappear from the public chart." title="Delete" aria-label="Delete week of <?= e($r['week_date']) ?>"><?= don_icon('trash', 14) ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="4" class="empty">No weekly collections recorded yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<script>
document.getElementById('church')?.addEventListener('change', function () { document.getElementById('church-form').submit(); });
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
