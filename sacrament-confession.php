<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/login_gate.php';
require_signed_in('Confession');   // visitors get "please login or register" instead of this page
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/sacrament_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
if (($_SESSION['role'] ?? '') !== 'user') {
    $_SESSION['sacrament_flash'] = 'Sacrament bookings are made from a parishioner account.';
    header('Location: sacraments.php');
    exit;
}
$userId = (int)$_SESSION['user_id'];
$stmt = $pdo->prepare('SELECT u.church_id, c.church_name FROM user u LEFT JOIN church_info c ON c.church_info_id = u.church_id WHERE u.user_id = ?');
$stmt->execute([$userId]);
$me = $stmt->fetch();
$churchId = (int)($me['church_id'] ?? 0);   // the home parish: never taken from the form
if (!$churchId) {
    $_SESSION['sacrament_flash'] = 'Please choose your home parish on your profile before booking.';
    header('Location: profile.php');
    exit;
}

$today = date('Y-m-d');
$first = date('Y-m-d', strtotime('+1 day'));                          // bookings start tomorrow
$last = date('Y-m-d', strtotime('+' . CONFESSION_DAYS_AHEAD . ' days'));
$validDate = function (string $d) use ($first, $last): bool {
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d && $d >= $first && $d <= $last;
};

$date = (string)($_GET['date'] ?? $_POST['date'] ?? '');
$time = (string)($_GET['time'] ?? $_POST['time'] ?? '');
if (!$validDate($date)) $date = '';
if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) $time = '';
$error = '';

// ---- Confirm the booking ------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif ($date === '' || $time === '') {
        $error = 'Please choose a day and a time first.';
    } else {
        // Re-check inside a transaction so two people cannot take the last place at the same moment.
        $pdo->beginTransaction();
        $pdo->prepare('SELECT church_info_id FROM church_info WHERE church_info_id = ? FOR UPDATE')->execute([$churchId]);
        $slot = sx_confession_slots($pdo, $churchId, $date, $date)[$date][$time] ?? null;
        $dup = $pdo->prepare(
            "SELECT COUNT(*) FROM sacrament_applications WHERE user_id = ? AND sacrament = 'confession' AND status = 'submitted'
                AND JSON_UNQUOTE(JSON_EXTRACT(details, '$.date')) = ? AND JSON_UNQUOTE(JSON_EXTRACT(details, '$.time')) = ?"
        );
        $dup->execute([$userId, $date, $time]);
        $mine = $pdo->prepare(
            "SELECT COUNT(*) FROM sacrament_applications WHERE user_id = ? AND sacrament = 'confession' AND status = 'submitted'
                AND JSON_UNQUOTE(JSON_EXTRACT(details, '$.date')) >= ?"
        );
        $mine->execute([$userId, $today]);
        if (!$slot) {
            $pdo->rollBack();
            $error = 'That time is not available. Please choose another.';
            $time = '';
        } elseif ($slot['booked'] >= $slot['max']) {
            $pdo->rollBack();
            $error = 'Sorry, that time has just been filled. Please choose another.';
            $time = '';
        } elseif ((int)$dup->fetchColumn() > 0) {
            $pdo->rollBack();
            $error = 'You have already booked that time.';
        } elseif ((int)$mine->fetchColumn() >= 3) {
            $pdo->rollBack();
            $error = 'You already have 3 upcoming confession bookings. Please contact the parish office if you need to change one.';
        } else {
            $pdo->prepare("INSERT INTO sacrament_applications (user_id, church_id, sacrament, status, details, submitted_at) VALUES (?, ?, 'confession', 'submitted', ?, NOW())")
                ->execute([$userId, $churchId, json_encode(['date' => $date, 'time' => $time, 'location' => $slot['location']])]);
            $newId = (int)$pdo->lastInsertId();
            $pdo->commit();
            $_SESSION['sacrament_flash'] = 'Your confession is booked for ' . date('l, j F Y', strtotime($date)) . ' at ' . sx_time_label($time) . '.';
            header('Location: sacrament-status.php?id=' . $newId);
            exit;
        }
    }
}

// ---- The month being shown ----------------------------------------------------------------------
$month = (string)($_GET['month'] ?? ($date ? substr($date, 0, 7) : date('Y-m', strtotime($first))));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m', strtotime($first));
$minMonth = substr($first, 0, 7);
$maxMonth = substr($last, 0, 7);
$month = max($minMonth, min($maxMonth, $month));
$monthStart = $month . '-01';
$prevMonth = date('Y-m', strtotime($monthStart . ' -1 month'));
$nextMonth = date('Y-m', strtotime($monthStart . ' +1 month'));
$daysInMonth = (int)date('t', strtotime($monthStart));
$startDow = (int)date('w', strtotime($monthStart));   // 0 = Sunday

$monthSlots = sx_confession_slots($pdo, $churchId, $monthStart, date('Y-m-t', strtotime($monthStart)));
$daySlots = $date ? ($monthSlots[$date] ?? sx_confession_slots($pdo, $churchId, $date, $date)[$date] ?? []) : [];
$chosen = $date && $time ? ($daySlots[$time] ?? null) : null;
if ($time && !$chosen) $time = '';
$anySlots = (bool)$pdo->query('SELECT 1 FROM confession_slots WHERE church_id = ' . $churchId . " AND slot_date >= '" . $first . "' LIMIT 1")->fetchColumn();

function cf_url(array $q): string { return 'sacrament-confession.php?' . http_build_query(array_filter($q, fn($v) => $v !== '')); }

// Notes from the parish office (confession-manage.php).
$confNotes = '';
try {
    $stmt = $pdo->prepare("SELECT notes FROM sacrament_schedules WHERE church_id = ? AND sacrament = 'confession'");
    $stmt->execute([$churchId]);
    $confNotes = trim((string)$stmt->fetchColumn());
} catch (PDOException $ex) { /* column not added yet: database/confession_notes.sql */ }

$pageTitle = 'Confession';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="sx-page sx-form-page">
    <?php back_button('sacraments.php', 'Back to Sacraments'); ?>
    <header class="page-heading">
        <h1>Confession</h1>
        <p>Choose a day and a time, then confirm your booking.</p>
    </header>

    <div class="sx-shell">
        <?php if ($error): ?><div class="alert info-alert" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?php if (!$anySlots): ?><div class="alert info-alert" role="status"><?= e($me['church_name']) ?> has not opened any confession times yet. Please check again later or contact the parish office.</div><?php endif; ?>

        <div class="cf-layout">
            <section class="cf-pick" aria-label="Choose a day and time">
                <div class="cf-month">
                    <?php if ($month > $minMonth): ?><a class="cf-arrow" href="<?= e(cf_url(['month' => $prevMonth])) ?>" aria-label="Previous month"><?= sx_icon('left', 18) ?></a><?php else: ?><span class="cf-arrow is-off"><?= sx_icon('left', 18) ?></span><?php endif; ?>
                    <h2><?= e(date('F, Y', strtotime($monthStart))) ?></h2>
                    <?php if ($month < $maxMonth): ?><a class="cf-arrow" href="<?= e(cf_url(['month' => $nextMonth])) ?>" aria-label="Next month"><?= sx_icon('right', 18) ?></a><?php else: ?><span class="cf-arrow is-off"><?= sx_icon('right', 18) ?></span><?php endif; ?>
                </div>

                <div class="cf-cal" role="grid" aria-label="<?= e(date('F Y', strtotime($monthStart))) ?>">
                    <?php foreach (['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'] as $i => $d): ?><span class="cf-dow<?= $i === 0 ? ' is-sun' : '' ?>" role="columnheader"><?= $d ?></span><?php endforeach; ?>
                    <?php for ($i = 0; $i < $startDow; $i++): ?><span></span><?php endfor; ?>
                    <?php for ($d = 1; $d <= $daysInMonth; $d++):
                        $iso = sprintf('%s-%02d', $month, $d);
                        $free = false;   // a day can be picked when it has a time with room left
                        foreach ($monthSlots[$iso] ?? [] as $s) if ($s['booked'] < $s['max']) { $free = true; break; }
                        $ok = $iso >= $first && $iso <= $last && $free;
                        $sun = ($startDow + $d - 1) % 7 === 0;
                        $cls = 'cf-day' . ($sun ? ' is-sun' : '') . ($iso === $date ? ' is-selected' : '') . ($ok ? '' : ' is-off') . ($ok ? ' has-slots' : '');
                    ?>
                        <?php if ($ok): ?><a class="<?= $cls ?>" href="<?= e(cf_url(['month' => $month, 'date' => $iso])) ?>"<?= $iso === $date ? ' aria-current="date"' : '' ?>><?= $d ?></a>
                        <?php else: ?><span class="<?= $cls ?>"><?= $d ?></span><?php endif; ?>
                    <?php endfor; ?>
                </div>

                <h3 class="cf-times-title">Available time</h3>
                <?php if (!$date): ?>
                    <p class="cf-hint">Choose a highlighted day above to see the times.</p>
                <?php else: ?>
                    <div class="cf-times">
                        <?php foreach ($daySlots as $slotTime => $s):
                            $full = $s['booked'] >= $s['max'];
                            $left = $s['max'] - $s['booked'];
                        ?>
                            <?php if ($full): ?><span class="cf-time is-taken" title="Fully booked"><?= e(sx_time_label($slotTime)) ?> (Full)</span>
                            <?php else: ?><a class="cf-time<?= $slotTime === $time ? ' is-selected' : '' ?>" href="<?= e(cf_url(['month' => $month, 'date' => $date, 'time' => $slotTime])) ?>" title="<?= $left ?> <?= $left === 1 ? 'place' : 'places' ?> left"><?= e(sx_time_label($slotTime)) ?></a><?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <form method="post" action="sacrament-confession.php" class="cf-summary" aria-label="Booking summary">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="date" value="<?= e($date) ?>">
                <input type="hidden" name="time" value="<?= e($time) ?>">
                <div class="cf-summary-head"><h2>Booking Summary</h2></div>
                <div class="cf-rows">
                    <div class="cf-row"><dt>Date</dt><dd><?= $date ? e(date('jS F Y', strtotime($date))) : '&mdash;' ?></dd></div>
                    <div class="cf-row"><dt>Time</dt><dd><?= $time ? e(date('h:i a', strtotime($time))) : '&mdash;' ?></dd></div>
                    <div class="cf-row"><dt>Church</dt><dd><?= e($me['church_name']) ?></dd></div>
                    <?php if ($chosen && $chosen['location']): ?><div class="cf-row"><dt>Where</dt><dd><?= e($chosen['location']) ?></dd></div><?php endif; ?>
                </div>
                <?php if ($confNotes !== ''): ?>
                    <div class="cf-notes" role="note">
                        <h3><?= sx_icon('info', 16) ?> Notes from the parish</h3>
                        <p><?= nl2br(e($confNotes)) ?></p>
                    </div>
                <?php endif; ?>
                <div class="cf-actions">
                    <a class="cf-cancel" href="sacrament-confession.php">Cancel</a>
                    <button type="submit" class="cf-confirm" <?= $date && $time ? '' : 'disabled' ?>>Confirm</button>
                </div>
            </form>
        </div>
    </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
