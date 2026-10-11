<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/sacrament_helpers.php';
require __DIR__ . '/includes/user_mail.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Church Admins and the Super Admin only.
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

// The church: a Church Admin's own church (never from the form); the Super Admin can choose.
$churches = $pdo->query('SELECT church_info_id, church_name FROM church_info ORDER BY church_name')->fetchAll();
$byId = array_column($churches, null, 'church_info_id');
$churchId = pick_church_id($pdo, $byId, (int)($_GET['church'] ?? $_POST['church'] ?? 0));
if (!can_manage_church($pdo, $churchId)) {
    header('Location: ' . $dashboard);
    exit;
}
$church = $byId[$churchId];

$today = date('Y-m-d');
$validDate = function (string $d): bool {
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d && $d >= '2000-01-01' && $d <= '2100-12-31';
};
$date = (string)($_GET['date'] ?? $_POST['date'] ?? '');
if (!$validDate($date)) $date = '';
$month = (string)($_GET['month'] ?? ($date ? substr($date, 0, 7) : date('Y-m')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');

$flash = $_SESSION['confession_flash'] ?? null;
unset($_SESSION['confession_flash']);
$error = '';
$form = null;   // values to show in the Add/Edit form after a failed save
$notesDraft = null;   // the notes as typed, after a failed save

/** The active (not cancelled) bookings of a day, per time. */
function day_bookings(PDO $pdo, int $churchId, string $date): array
{
    $stmt = $pdo->prepare(
        "SELECT a.application_id, a.status, a.details, u.user_name, u.user_phone
           FROM sacrament_applications a JOIN user u ON u.user_id = a.user_id
          WHERE a.sacrament = 'confession' AND a.church_id = ? AND a.status <> 'draft'
            AND JSON_UNQUOTE(JSON_EXTRACT(a.details, '$.date')) = ?"
    );
    $stmt->execute([$churchId, $date]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $d = json_decode((string)$r['details'], true) ?: [];
        $r['time'] = (string)($d['time'] ?? '');
        $out[] = $r;
    }
    usort($out, fn($a, $b) => strcmp($a['time'], $b['time']) ?: $a['application_id'] <=> $b['application_id']);
    return $out;
}

// ---- POST actions ---------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif ($action === 'save_notes') {
        $notes = trim(str_replace("\r\n", "\n", (string)($_POST['notes'] ?? '')));
        if (mb_strlen($notes) > 1000) {
            $error = 'Please keep the notes under 1,000 characters.';
            $notesDraft = $notes;
        } else {
            $pdo->prepare("INSERT INTO sacrament_schedules (church_id, sacrament, notes) VALUES (?, 'confession', ?)
                           ON DUPLICATE KEY UPDATE notes = VALUES(notes)")->execute([$churchId, $notes !== '' ? $notes : null]);
            $_SESSION['confession_flash'] = $notes !== '' ? 'Notes saved. Parishioners see them when they book confession.' : 'Notes removed.';
            header('Location: confession-manage.php?' . http_build_query(array_filter(['church' => $churchId, 'month' => $month, 'date' => $date])) . '#notes');
            exit;
        }
    } elseif ($action === 'save_slots') {
        $loc = mb_substr(trim((string)($_POST['location'] ?? '')), 0, 100);
        $max = (int)($_POST['max'] ?? 0);
        $times = array_values(array_unique(array_filter((array)($_POST['times'] ?? []), fn($t) => is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t))));
        sort($times);
        $form = ['location' => $loc, 'max' => $max ?: 1, 'times' => $times];
        if ($date === '' || $date < $today) $error = 'Choose a date that is today or later.';
        elseif ($max < 1 || $max > 50) $error = 'Max bookings per slot must be between 1 and 50.';
        elseif (!$times) $error = 'Add at least one time.';
        elseif (count($times) > 24) $error = 'That is too many time slots for one day.';
        else {
            $booked = [];   // active bookings per time
            foreach (day_bookings($pdo, $churchId, $date) as $b) if (!in_array($b['status'], ['cancelled', 'rejected'], true)) $booked[$b['time']] = ($booked[$b['time']] ?? 0) + 1;
            $existing = array_keys(sx_confession_slots($pdo, $churchId, $date, $date)[$date] ?? []);
            foreach (array_diff($existing, $times) as $gone) {
                if (!empty($booked[$gone])) { $error = sx_time_label($gone) . ' has bookings. Cancel them first, or keep that time.'; break; }
            }
            foreach ($times as $t) {
                if (!$error && ($booked[$t] ?? 0) > $max) $error = sx_time_label($t) . ' already has ' . $booked[$t] . ' bookings, so the maximum cannot be lower.';
            }
            if (!$error) {
                $pdo->beginTransaction();
                $del = $pdo->prepare('DELETE FROM confession_slots WHERE church_id = ? AND slot_date = ? AND slot_time = ?');
                foreach (array_diff($existing, $times) as $gone) $del->execute([$churchId, $date, $gone . ':00']);
                $up = $pdo->prepare('INSERT INTO confession_slots (church_id, slot_date, slot_time, location, max_bookings) VALUES (?, ?, ?, ?, ?)
                                     ON DUPLICATE KEY UPDATE location = VALUES(location), max_bookings = VALUES(max_bookings)');
                foreach ($times as $t) $up->execute([$churchId, $date, $t . ':00', $loc !== '' ? $loc : null, $max]);
                $pdo->commit();
                $_SESSION['confession_flash'] = 'Slots saved for ' . date('j F Y', strtotime($date)) . '.';
                header('Location: confession-manage.php?' . http_build_query(['church' => $churchId, 'month' => substr($date, 0, 7), 'date' => $date]));
                exit;
            }
        }
    } elseif ($action === 'delete_day') {
        $active = array_filter(day_bookings($pdo, $churchId, $date), fn($b) => !in_array($b['status'], ['cancelled', 'rejected'], true));
        if ($date === '') $error = 'Choose a day first.';
        elseif ($active) $error = 'This day has bookings. Cancel them before removing its slots.';
        else {
            $pdo->prepare('DELETE FROM confession_slots WHERE church_id = ? AND slot_date = ?')->execute([$churchId, $date]);
            $_SESSION['confession_flash'] = 'The slots for ' . date('j F Y', strtotime($date)) . ' were removed.';
            header('Location: confession-manage.php?' . http_build_query(['church' => $churchId, 'month' => substr($date, 0, 7)]));
            exit;
        }
    } elseif ($action === 'cancel_booking' || $action === 'delete_booking') {
        // The booking's church is read from the record, never trusted from the form.
        $stmt = $pdo->prepare("SELECT a.application_id, a.church_id, a.status, a.details, u.user_name, u.user_email FROM sacrament_applications a JOIN user u ON u.user_id = a.user_id WHERE a.application_id = ? AND a.sacrament = 'confession'");
        $stmt->execute([(int)($_POST['id'] ?? 0)]);
        $row = $stmt->fetch();
        if (!$row || !can_manage_church($pdo, (int)$row['church_id'])) {
            $error = 'That booking could not be found.';
        } elseif ($action === 'cancel_booking') {
            $pdo->prepare("UPDATE sacrament_applications SET status = 'cancelled', admin_note = 'Cancelled by the parish office.', reviewed_by = ?, reviewed_at = NOW() WHERE application_id = ? AND status <> 'draft'")
                ->execute([(int)$_SESSION['user_id'], (int)$row['application_id']]);
            if (!in_array($row['status'], ['draft', 'cancelled'], true)) {   // tell the parishioner
                $booked = json_decode((string)$row['details'], true) ?: [];
                send_confession_cancelled_email($pdo, (int)$row['church_id'], $row['user_email'], $row['user_name'], sx_ref((int)$row['application_id']), (string)($booked['date'] ?? ''), (string)($booked['time'] ?? ''));
            }
            $_SESSION['confession_flash'] = 'Booking cancelled.';
        } else {
            $pdo->prepare("DELETE FROM sacrament_applications WHERE application_id = ? AND sacrament = 'confession'")->execute([(int)$row['application_id']]);
            $_SESSION['confession_flash'] = 'Booking deleted.';
        }
        if (!$error) {
            header('Location: confession-manage.php?' . http_build_query(['church' => $churchId, 'month' => $month, 'date' => $date]));
            exit;
        }
    }
}

// ---- What to show ---------------------------------------------------------------------------------
$monthStart = $month . '-01';
$daysInMonth = (int)date('t', strtotime($monthStart));
$startDow = (int)date('w', strtotime($monthStart));
$prevMonth = date('Y-m', strtotime($monthStart . ' -1 month'));
$nextMonth = date('Y-m', strtotime($monthStart . ' +1 month'));
$monthSlots = sx_confession_slots($pdo, $churchId, $monthStart, date('Y-m-t', strtotime($monthStart)));

$daySlots = $date ? ($monthSlots[$date] ?? sx_confession_slots($pdo, $churchId, $date, $date)[$date] ?? []) : [];
$bookings = $date ? day_bookings($pdo, $churchId, $date) : [];
$activeCount = count(array_filter($bookings, fn($b) => !in_array($b['status'], ['cancelled', 'rejected'], true)));

// The Add / Edit form: the saved day, or what was typed before an error, or a blank one.
if (!$form) {
    $first = $daySlots ? reset($daySlots) : null;
    $form = ['location' => $first['location'] ?? '', 'max' => $first['max'] ?? 1, 'times' => array_keys($daySlots)];
}
function cm_url(array $q): string { return 'confession-manage.php?' . http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null)); }
$q = ['church' => $churchId];

$pageTitle = 'Confession Management';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="sx-page cm-page">
    <?php back_button($dashboard, 'Back to dashboard'); ?>
    <header class="page-heading">
        <h1>Confession Management</h1>
        <p><?= e($church['church_name']) ?></p>
    </header>

    <?php if ($isSuper): ?>
        <form method="get" class="sx-pick">
            <label for="cm-church">Church</label>
            <select id="cm-church" name="church" onchange="this.form.submit()">
                <?php foreach ($churches as $c): ?><option value="<?= (int)$c['church_info_id'] ?>" <?= (int)$c['church_info_id'] === $churchId ? 'selected' : '' ?>><?= e($c['church_name']) ?></option><?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn-save">Show</button></noscript>
        </form>
    <?php endif; ?>

    <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert info-alert" role="alert"><?= e($error) ?></div><?php endif; ?>

    <div class="cm-top">
        <section class="cm-cal-card" aria-label="Choose a date">
            <div class="cf-month">
                <a class="cf-arrow" href="<?= e(cm_url($q + ['month' => $prevMonth])) ?>" aria-label="Previous month"><?= sx_icon('left', 18) ?></a>
                <h2><?= e(date('F, Y', strtotime($monthStart))) ?></h2>
                <a class="cf-arrow" href="<?= e(cm_url($q + ['month' => $nextMonth])) ?>" aria-label="Next month"><?= sx_icon('right', 18) ?></a>
            </div>
            <div class="cf-cal" role="grid">
                <?php foreach (['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'] as $i => $d): ?><span class="cf-dow<?= $i === 0 ? ' is-sun' : '' ?>" role="columnheader"><?= $d ?></span><?php endforeach; ?>
                <?php for ($i = 0; $i < $startDow; $i++): ?><span></span><?php endfor; ?>
                <?php for ($d = 1; $d <= $daysInMonth; $d++):
                    $iso = sprintf('%s-%02d', $month, $d);
                    $sun = ($startDow + $d - 1) % 7 === 0;
                    $cls = 'cf-day' . ($sun ? ' is-sun' : '') . ($iso === $date ? ' is-selected' : '') . (isset($monthSlots[$iso]) ? ' has-slots' : '') . ($iso < $today ? ' is-past' : '');
                ?>
                    <a class="<?= $cls ?>" href="<?= e(cm_url($q + ['month' => $month, 'date' => $iso])) ?>"<?= $iso === $date ? ' aria-current="date"' : '' ?>><?= $d ?></a>
                <?php endfor; ?>
            </div>
            <div class="cm-cal-foot">
                <div>
                    <strong><?= $date ? e(date('j M Y', strtotime($date))) : 'No day chosen' ?></strong>
                    <small><?= !$date ? 'Choose a day above' : ($daySlots ? count($daySlots) . ($daySlots ? (count($daySlots) === 1 ? ' slot' : ' slots') : '') . ' configured' : 'No slots configured') ?></small>
                </div>
                <?php if ($date && $date >= $today): ?><a class="cm-add-btn" href="#slot-form"><?= sx_icon('plus', 14) ?> Add Slots</a><?php endif; ?>
            </div>
            <p class="dp-key"><span class="dp-swatch has"></span> Days with confession times are marked.</p>
        </section>

        <form method="post" action="confession-manage.php" class="cm-form" id="slot-form">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="save_slots">
            <input type="hidden" name="church" value="<?= $churchId ?>">
            <input type="hidden" name="month" value="<?= e($month) ?>">
            <h2><?= $daySlots ? 'Edit Confession Slots' : 'Add Confession Slot' ?></h2>

            <div class="pfield">
                <label for="cm-date">Date <span class="req">*</span></label>
                <input type="date" id="cm-date" name="date" value="<?= e($date) ?>" min="<?= e($today) ?>" required>
            </div>
            <div class="pfield">
                <label for="cm-loc">Location</label>
                <input type="text" id="cm-loc" name="location" maxlength="100" placeholder="e.g. Confessional, Main Church" value="<?= e($form['location']) ?>">
            </div>
            <div class="pfield">
                <label for="cm-max">Max Bookings Per Slot</label>
                <input type="number" id="cm-max" name="max" min="1" max="50" value="<?= (int)$form['max'] ?>" required>
            </div>
            <div class="pfield">
                <span class="sx-label">Time Slots</span>
                <div class="cm-chips" id="cm-chips" data-times="<?= e(json_encode($form['times'])) ?>"></div>
                <div class="cm-addtime">
                    <input type="time" id="cm-time" step="300" value="09:00" aria-label="Time to add">
                    <button type="button" class="cm-add" id="cm-add-time">Add</button>
                </div>
            </div>
            <div class="cm-form-actions">
                <a class="cm-cancel" href="<?= e(cm_url($q + ['month' => $month, 'date' => $date])) ?>">Cancel</a>
                <button type="submit" class="cm-create">Create Slots</button>
            </div>
        </form>
    </div>

    <?php
    $stmt = $pdo->prepare("SELECT notes FROM sacrament_schedules WHERE church_id = ? AND sacrament = 'confession'");
    $stmt->execute([$churchId]);
    $savedNotes = (string)$stmt->fetchColumn();
    ?>
    <form method="post" action="confession-manage.php" class="cm-notes" id="notes">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="save_notes">
        <input type="hidden" name="church" value="<?= (int)$churchId ?>">
        <?php if ($date): ?><input type="hidden" name="date" value="<?= e($date) ?>"><?php endif; ?>
        <input type="hidden" name="month" value="<?= e($month) ?>">
        <div class="cm-notes-head">
            <h2>Notes for parishioners</h2>
            <p>Shown on the Confession booking page of <?= e($church['church_name']) ?>, next to the booking summary.</p>
        </div>
        <textarea name="notes" rows="4" maxlength="1000" aria-label="Notes for parishioners" placeholder="e.g. Please arrive 10 minutes early. Confession is also heard after the Saturday evening Mass."><?= e($notesDraft ?? $savedNotes) ?></textarea>
        <div class="cm-notes-foot">
            <span class="cm-notes-hint">Up to 1,000 characters. Leave empty to show no notes.</span>
            <button type="submit" class="btn-save">Save notes</button>
        </div>
    </form>

    <?php if ($date): ?>
    <section class="cm-list" aria-label="Confession booking list">
        <h2 class="cm-list-title">Confession Booking List</h2>
        <div class="cm-list-head">
            <h3><?= e(date('j F Y', strtotime($date))) ?></h3>
            <?php if ($daySlots): ?>
            <div class="cm-list-tools">
                <a class="cm-edit" href="#slot-form"><?= sx_icon('pencil', 14) ?> Edit Slots</a>
                <form method="post" action="confession-manage.php" class="inline-form">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="delete_day">
                    <input type="hidden" name="church" value="<?= $churchId ?>">
                    <input type="hidden" name="month" value="<?= e($month) ?>">
                    <input type="hidden" name="date" value="<?= e($date) ?>">
                    <button type="submit" class="cm-trash" data-confirm="Remove all confession slots for <?= e(date('j F Y', strtotime($date))) ?>? This cannot be undone." aria-label="Remove all slots for this day"><?= sx_icon('trash', 15) ?></button>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!$daySlots): ?>
            <p class="ann-empty">No confession slots are set up for this day. Use <b>Add Slots</b> above to create some.</p>
        <?php else: ?>
            <div class="cm-avail">
                <h4>Available time</h4>
                <div class="cf-times">
                    <?php foreach ($daySlots as $t => $s): $full = $s['booked'] >= $s['max']; ?>
                        <span class="cf-time cm-pill<?= $full ? ' is-taken' : '' ?>" title="<?= $s['booked'] ?> of <?= $s['max'] ?> booked<?= $s['location'] ? ' · ' . e($s['location']) : '' ?>"><?= e(sx_time_label($t)) ?><?= $full ? ' (Full)' : '' ?></span>
                    <?php endforeach; ?>
                </div>
                <?php $loc = reset($daySlots)['location'] ?? ''; if ($loc): ?><p class="cm-loc"><?= sx_icon('church', 14) ?> <?= e($loc) ?></p><?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="cm-bookings">
            <h4>Bookings &mdash; <?= $activeCount ?> total</h4>
            <?php if (!$bookings): ?>
                <p class="cm-none">No bookings for this day yet.</p>
            <?php endif; ?>
            <?php foreach ($bookings as $b):
                $cancelled = in_array($b['status'], ['cancelled', 'rejected'], true);
                $parts = preg_split('/\s+/', trim($b['user_name']));
                $ini = strtoupper(mb_substr($parts[0] ?? '', 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
            ?>
                <div class="cm-row<?= $cancelled ? ' is-cancelled' : '' ?>">
                    <span class="cm-ini" aria-hidden="true"><?= e($ini) ?></span>
                    <div class="cm-who"><b><?= e($b['user_name']) ?></b><small><?= e(sx_time_label($b['time'])) ?><?= $b['user_phone'] ? ' &middot; ' . e($b['user_phone']) : '' ?></small></div>
                    <span class="sx-badge <?= $cancelled ? 'is-draft' : 'is-ok' ?>"><?= $cancelled ? 'cancelled' : 'confirmed' ?></span>
                    <form method="post" action="confession-manage.php" class="inline-form">
                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                        <input type="hidden" name="church" value="<?= $churchId ?>">
                        <input type="hidden" name="month" value="<?= e($month) ?>">
                        <input type="hidden" name="date" value="<?= e($date) ?>">
                        <input type="hidden" name="id" value="<?= (int)$b['application_id'] ?>">
                        <?php if (!$cancelled): ?><button type="submit" name="action" value="cancel_booking" class="cm-cancel-btn" data-confirm="Cancel <?= e($b['user_name']) ?>'s booking? This cannot be undone.">Cancel</button><?php endif; ?>
                        <button type="submit" name="action" value="delete_booking" class="cm-trash is-grey" data-confirm="Delete this booking record? This cannot be undone." aria-label="Delete booking"><?= sx_icon('trash', 14) ?></button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</main>
<script>
(function () {
    var chips = document.getElementById('cm-chips'), times = [];
    try { times = JSON.parse(chips.dataset.times || '[]'); } catch (e) {}
    function label(t) { var p = t.split(':'), h = +p[0]; return (h % 12 || 12) + ':' + p[1] + ' ' + (h < 12 ? 'am' : 'pm'); }
    function draw() {
        times.sort();
        chips.innerHTML = '';
        times.forEach(function (t) {
            var c = document.createElement('span');
            c.className = 'cm-chip';
            c.innerHTML = '<span></span><button type="button" aria-label="Remove ' + label(t) + '">&times;</button><input type="hidden" name="times[]" value="' + t + '">';
            c.firstChild.textContent = label(t);
            c.querySelector('button').addEventListener('click', function () { times = times.filter(function (x) { return x !== t; }); draw(); });
            chips.appendChild(c);
        });
        if (!times.length) chips.innerHTML = '<span class="cm-chip-none">No times yet &mdash; add one below.</span>';
    }
    document.getElementById('cm-add-time').addEventListener('click', function () {
        var v = document.getElementById('cm-time').value;
        if (v && times.indexOf(v) === -1) { times.push(v); draw(); }
    });
    draw();
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
