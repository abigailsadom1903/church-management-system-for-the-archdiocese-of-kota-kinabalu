<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/sacrament_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Church Admins edit the notes and open/closed state of their own church's sacrament forms; the Super Admin views every church (view only).
$role = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($role, ['church_admin', 'super_admin'], true)) {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$isSuper = $role === 'super_admin';
$dashboard = $isSuper ? 'superadmin/dashboard.php' : 'churchadmin/dashboard.php';
$canEdit = !$isSuper;   // the Super Admin monitors; the Church Admin manages
$flash = $_SESSION['sacrament_flash'] ?? null;
unset($_SESSION['sacrament_flash']);

$churches = $pdo->query('SELECT church_info_id, church_name FROM church_info ORDER BY church_name')->fetchAll();
$byId = array_column($churches, null, 'church_info_id');
$churchId = pick_church_id($pdo, $byId, $isSuper ? (int)($_GET['church'] ?? 0) : 0);   // a Church Admin's own church, never from the request
if (!can_manage_church($pdo, $churchId)) {
    header('Location: ' . $dashboard);
    exit;
}
$church = $byId[$churchId];

// Only sacraments with their own application form (Confession is booked from a calendar and has its own slot manager).
$forms = array_filter(SACRAMENTS, fn(array $s, string $k) => $k !== 'confession', ARRAY_FILTER_USE_BOTH);

// Every sacrament form (except Confession, which has its own slot manager) shows a notes sidebar.
$noteFields = [
    'baptism'      => ['line_date' => 'Date', 'line_brief' => 'Godparent Briefing', 'line_close' => 'Registration Deadline'],
    'eucharist'    => ['line_date' => 'Date', 'line_brief' => 'Note', 'line_close' => 'Registration Deadline'],
    'confirmation' => ['line_date' => 'Date', 'line_brief' => 'Note', 'line_close' => 'Registration Deadline'],
    'anointing'    => ['line_date' => 'After-hours Emergency Number'],
    'matrimony'    => ['line_date' => 'Date', 'line_brief' => 'Note', 'line_close' => 'Registration Deadline'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = (string)($_POST['sacrament'] ?? '');
    if (!isset($forms[$type])) {
        $_SESSION['sacrament_flash'] = 'That sacrament could not be found.';
        header('Location: sacrament-schedule.php' . ($isSuper ? '?church=' . $churchId : ''));
        exit;
    } elseif (!$canEdit) {
        $_SESSION['sacrament_flash'] = 'The Super Admin can view sacrament schedules but not change them.';
        header('Location: sacrament-schedule.php?church=' . $churchId);
        exit;
    } elseif (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $_SESSION['sacrament_flash'] = 'Your session expired. Please try again.';
    } else {
        $isOpen = !empty($_POST['is_open']) ? 1 : 0;
        // Last day to apply (optional). An invalid date is not saved; the rest of the card still is.
        $until = trim((string)($_POST['apply_until'] ?? ''));
        $untilDt = DateTime::createFromFormat('Y-m-d', $until);
        $untilNote = '';
        if ($until !== '' && (!$untilDt || $untilDt->format('Y-m-d') !== $until || $until < '2000-01-01' || $until > '2100-12-31')) {
            $until = '';
            $untilNote = ' The deadline was not a valid date, so it was left empty.';
        }
        // The switch and the date always agree: a past deadline means closed.
        //  - the date was just changed to a past day -> it wins, the form closes
        //  - the old deadline has passed but the switch was ticked -> the admin is reopening: the old date is cleared
        $today = date('Y-m-d');
        if ($until !== '' && $until < $today && $isOpen) {
            $stmt = $pdo->prepare('SELECT apply_until FROM sacrament_schedules WHERE church_id = ? AND sacrament = ?');
            $stmt->execute([$churchId, $type]);
            if ((string)$stmt->fetchColumn() === $until) {
                $until = '';
                $untilNote = ' The old deadline had passed, so it was cleared and applications are open again.';
            } else {
                $isOpen = 0;
                $untilNote = ' That deadline has already passed, so applications are closed.';
            }
        }
        $fields = $noteFields[$type] ?? [];
        $lineDate = isset($fields['line_date']) ? mb_substr(trim((string)($_POST['line_date'] ?? '')), 0, 150) : null;
        $lineBrief = isset($fields['line_brief']) ? mb_substr(trim((string)($_POST['line_brief'] ?? '')), 0, 200) : null;
        $lineClose = isset($fields['line_close']) ? mb_substr(trim((string)($_POST['line_close'] ?? '')), 0, 150) : null;
        $pdo->prepare(
            'INSERT INTO sacrament_schedules (church_id, sacrament, line_date, line_brief, line_close, apply_until, is_open) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE line_date = VALUES(line_date), line_brief = VALUES(line_brief), line_close = VALUES(line_close),
                                     apply_until = VALUES(apply_until), is_open = VALUES(is_open)'
        )->execute([$churchId, $type, $lineDate ?: null, $lineBrief ?: null, $lineClose ?: null, $until ?: null, $isOpen]);
        $_SESSION['sacrament_flash'] = $forms[$type][0] . ' updated.' . $untilNote;
    }
    header('Location: sacrament-schedule.php' . ($isSuper ? '?church=' . $churchId : '') . '#' . $type);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM sacrament_schedules WHERE church_id = ?');
$stmt->execute([$churchId]);
$sched = [];
foreach ($stmt->fetchAll() as $row) $sched[$row['sacrament']] = $row;

$pageTitle = 'Sacrament Forms';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="sx-page cat-page">
    <?php back_button($dashboard, 'Back to dashboard'); ?>
    <header class="page-heading">
        <h1>Sacrament Forms</h1>
        <?php if (!$isSuper): ?><p><?= e($church['church_name']) ?></p><?php endif; ?>
    </header>
    <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>

    <?php if ($isSuper): ?>
        <form method="get" class="sr-church">
            <label for="ss-church">Select Church</label>
            <select id="ss-church" name="church" onchange="this.form.submit()">
                <?php foreach ($churches as $c): ?><option value="<?= (int)$c['church_info_id'] ?>" <?= (int)$c['church_info_id'] === $churchId ? 'selected' : '' ?>><?= e($c['church_name']) ?></option><?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn-save">Show</button></noscript>
        </form>
    <?php endif; ?>

    <div class="ss-grid">
        <?php foreach ($forms as $key => [$name, $icon]):
            $row = $sched[$key] ?? null;
            $until = $row['apply_until'] ?? null;
            $pastDeadline = $until !== null && $until < date('Y-m-d');
            // What parishioners actually see: the switch is off, or the last day to apply has passed -> closed.
            $isOpen = ($row === null || (int)$row['is_open'] === 1) && !$pastDeadline;
            [$stateText, $stateClass] = !$isOpen ? [$pastDeadline ? 'Closed · deadline passed' : 'Closed', 'is-closed']
                : [$until ? 'Open until ' . sx_day($until) : 'Open', 'is-open'];
            $fields = $noteFields[$key] ?? [];
        ?>
            <section class="ss-card" id="<?= e($key) ?>">
                <div class="ss-card-head">
                    <span class="ss-ico"><?= sx_icon($icon, 22) ?></span>
                    <h2><?= e($name) ?></h2>
                    <span class="ss-state <?= $stateClass ?>"><?= e($stateText) ?></span>
                </div>
                <form method="post" action="sacrament-schedule.php<?= $isSuper ? '?church=' . $churchId : '' ?>">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="sacrament" value="<?= e($key) ?>">
                    <?php if ($fields): ?>
                        <?php foreach ($fields as $col => $label): ?>
                            <div class="pfield">
                                <label for="<?= e($key . '_' . $col) ?>"><?= e($label) ?></label>
                                <input type="text" id="<?= e($key . '_' . $col) ?>" name="<?= e($col) ?>" maxlength="<?= $col === 'line_brief' ? 200 : 150 ?>" value="<?= e((string)($row[$col] ?? '')) ?>"<?= $canEdit ? '' : ' disabled' ?> placeholder="Not set">
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="ss-nonote">This sacrament has no notes sidebar on its application form yet &mdash; only the open/closed state below applies to it.</p>
                    <?php endif; ?>
                    <div class="pfield ss-until">
                        <label for="<?= e($key) ?>_until">Applications open until <span class="opt">(optional)</span></label>
                        <input type="date" id="<?= e($key) ?>_until" name="apply_until" value="<?= e((string)$until) ?>" data-until<?= $canEdit ? '' : ' disabled' ?>>
                        <span class="ss-hint">The last day parishioners can apply. After it the form closes by itself. Leave empty to keep it open until you turn it off.</span>
                    </div>
                    <label class="ss-toggle">
                        <input type="checkbox" name="is_open" value="1" data-open <?= $isOpen ? 'checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>>
                        <span>Applications open</span>
                    </label>
                    <p class="ss-hint">Turn this off to close the form once the deadline has passed or the intake is full. Parishioners will see "Applications closed" instead of the form.</p>
                    <?php if ($pastDeadline): ?><p class="ss-warn">The deadline (<?= e(sx_day($until)) ?>) has passed, so applications are closed. To reopen, set a later date or tick &ldquo;Applications open&rdquo;.</p><?php endif; ?>
                    <?php if ($canEdit): ?><button type="submit" class="cat-tool">Save</button><?php endif; ?>
                </form>
            </section>
        <?php endforeach; ?>
    </div>
</main>
<script>
// Keep "Applications open" and "Applications open until" in agreement while editing:
// a date from today on ticks the box, a past date unticks it, and ticking the box while the date has passed clears that date.
document.querySelectorAll('.ss-card form').forEach(function (form) {
    var date = form.querySelector('[data-until]'), box = form.querySelector('[data-open]');
    if (!date || !box || box.disabled) return;
    var t = new Date(), today = t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0');
    date.addEventListener('change', function () {
        if (date.value) box.checked = date.value >= today;
    });
    box.addEventListener('change', function () {
        if (box.checked && date.value && date.value < today) date.value = '';
    });
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
