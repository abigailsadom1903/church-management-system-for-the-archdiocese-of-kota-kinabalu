<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/login_gate.php';
require_signed_in('Church Calendar');   // visitors get "please login or register" instead of this page
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/event_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT e.*, c.church_name, c.church_location, (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.event_id) AS regs,
            (SELECT COALESCE(SUM(r.people), 0) FROM event_registrations r WHERE r.event_id = e.event_id) AS people
       FROM events e JOIN church_info c ON c.church_info_id = e.church_id WHERE e.event_id = ?'
);
$stmt->execute([$id]);
$ev = $stmt->fetch();
if (!$ev) {
    header('Location: events.php');
    exit;
}

// Which day of a repeating event is being looked at.
$today = date('Y-m-d');
$date = (string)($_GET['date'] ?? $_POST['date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !event_occurrences($ev, $date, $date)) {
    $date = event_next_date($ev, $today) ?? $ev['event_date'];
}
$isPast = $date < $today;
$oneOff = !$ev['repeat_weekly'];
$canRegister = $ev['registration_required'] && $oneOff && !$isPast;
$isFull = $ev['capacity'] !== null && (int)$ev['people'] >= (int)$ev['capacity'];   // capacity counts PEOPLE, not sign-ups
$canManage = can_manage_church($pdo, (int)$ev['church_id']);

$flash = $_SESSION['event_flash'] ?? null;
unset($_SESSION['event_flash']);
// Has this public user already registered? (Registrations are made on event-register.php.)
$myReg = null;
if (($_SESSION['role'] ?? '') === 'user' && !empty($_SESSION['user_id'])) {
    $r = $pdo->prepare('SELECT people FROM event_registrations WHERE event_id = ? AND user_id = ?');
    $r->execute([$id, (int)$_SESSION['user_id']]);
    $myReg = $r->fetch() ?: null;
}
$backUrl = 'events.php?church=' . (int)$ev['church_id'] . '&month=' . substr($date, 0, 7);
$left = $ev['capacity'] !== null ? max(0, (int)$ev['capacity'] - (int)$ev['people']) : null;
// Countdown to the start of the day being looked at (kept ticking by the script at the bottom).
[$startTs, $endTs] = event_window($ev, $date);
$secs = max(0, $startTs - time());
function fc(array $errors, string $key): string { return isset($errors[$key]) ? 'pfield has-error' : 'pfield'; }

$pageTitle = $ev['title'];
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="event-page">
    <?php back_button($backUrl, 'Back to the calendar'); ?>

    <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>

    <article class="event-card <?= event_type_class($ev['event_type']) ?>">
        <div class="ev-badge lg" aria-hidden="true"><span><?= e(strtoupper(date('M', strtotime($date)))) ?></span><b><?= (int)date('j', strtotime($date)) ?></b></div>
        <div class="event-body">
            <span class="ev-tag <?= event_type_class($ev['event_type']) ?>"><?= e($ev['event_type']) ?></span>
            <h1><?= e($ev['title']) ?></h1>
            <ul class="event-facts">
                <li><?= ev_icon('calendar', 17) ?> <span><?= e(date('l, j F Y', strtotime($date))) ?><?= $isPast ? ' <em>(past)</em>' : '' ?></span></li>
                <li><?= ev_icon('clock', 17) ?> <span><?= e(event_time_text($ev)) ?></span></li>
                <?php if ($ev['location']): ?><li><?= ev_icon('pin', 17) ?> <span><?= e($ev['location']) ?></span></li><?php endif; ?>
                <li><?= ev_icon('church', 17) ?> <span><a href="church-information.php?church=<?= (int)$ev['church_id'] ?>"><?= e($ev['church_name']) ?></a> &middot; <?= e($ev['church_location']) ?></span></li>
                <?php if ($ev['repeat_weekly']): ?><li><?= ev_icon('repeat', 17) ?> <span>Every <?= e(date('l', strtotime($ev['event_date']))) ?><?= $ev['repeat_until'] ? ' until ' . e(date('j F Y', strtotime($ev['repeat_until']))) : '' ?></span></li><?php endif; ?>
            </ul>
            <?php if (time() < $endTs): ?>
                <div class="ev-countdown<?= $secs > 0 ? '' : ' is-live' ?>" data-start="<?= $startTs ?>" data-end="<?= $endTs ?>" data-now="<?= time() ?>" role="timer" aria-live="off">
                    <p class="ev-cd-label"><?= $secs > 0 ? 'Starts in' : 'Happening now' ?></p>
                    <div class="ev-cd-units"<?= $secs > 0 ? '' : ' hidden' ?>>
                        <div><b data-u="d"><?= intdiv($secs, 86400) ?></b><span>Days</span></div>
                        <div><b data-u="h"><?= sprintf('%02d', intdiv($secs % 86400, 3600)) ?></b><span>Hours</span></div>
                        <div><b data-u="m"><?= sprintf('%02d', intdiv($secs % 3600, 60)) ?></b><span>Minutes</span></div>
                        <div><b data-u="s"><?= sprintf('%02d', $secs % 60) ?></b><span>Seconds</span></div>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($ev['description']): ?><div class="event-desc"><?= nl2br(e($ev['description'])) ?></div><?php endif; ?>

            <?php if ($canManage): ?>
                <div class="event-admin">
                    <a class="btn-new" href="event-form.php?id=<?= $id ?>"><?= ev_icon('pencil', 14) ?> Edit event</a>
                    <?php if ($ev['registration_required'] && $oneOff): ?><a class="btn-new" href="event-participants.php?id=<?= $id ?>"><?= ev_icon('users', 14) ?> Participants (<?= (int)$ev['regs'] ?>)</a><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </article>

    <?php if ($ev['registration_required'] && $oneOff): ?>
        <section class="event-register" id="register">
            <h2>Register</h2>
            <p class="event-count"><?= ev_icon('users', 15) ?> <?= people_label((int)$ev['people']) ?> registered<?= $left !== null ? ' &middot; ' . ($left > 0 ? "$left " . ($left === 1 ? 'place' : 'places') . ' left' : '<strong>full</strong>') : '' ?></p>

            <?php if ($myReg): ?>
                <p class="event-mine"><?= ev_icon('check', 16) ?> You are registered<?= (int)$myReg['people'] > 1 ? ' (' . people_label((int)$myReg['people']) . ')' : '' ?>.</p>
                <div class="profile-actions"><a class="btn-save" href="my-events.php">View my registrations</a></div>
            <?php elseif ($isPast): ?>
                <p class="ann-empty">Registration has closed because this event has already taken place.</p>
            <?php elseif ($isFull): ?>
                <p class="ann-empty">This event is full. Please contact the parish office if you would still like to attend.</p>
            <?php else: ?>
                <div class="profile-actions"><a class="btn-save" href="event-register.php?id=<?= $id ?>">Register for this event</a></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<script>
// Event countdown. Times come from the server, so the visitor's own clock/time zone doesn't matter.
(function () {
    var box = document.querySelector('.ev-countdown');
    if (!box) return;
    var start = +box.dataset.start, end = +box.dataset.end, skew = +box.dataset.now - Date.now() / 1000;
    var label = box.querySelector('.ev-cd-label'), units = box.querySelector('.ev-cd-units');
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    function tick() {
        var now = Date.now() / 1000 + skew, s = Math.max(0, Math.floor(start - now));
        if (now >= end) { label.textContent = 'This event has ended'; units.hidden = true; box.classList.add('is-over'); return clearInterval(timer); }
        if (s === 0) { label.textContent = 'Happening now'; units.hidden = true; box.classList.add('is-live'); return; }
        box.querySelector('[data-u=d]').textContent = Math.floor(s / 86400);
        box.querySelector('[data-u=h]').textContent = pad(Math.floor(s % 86400 / 3600));
        box.querySelector('[data-u=m]').textContent = pad(Math.floor(s % 3600 / 60));
        box.querySelector('[data-u=s]').textContent = pad(s % 60);
    }
    var timer = setInterval(tick, 1000);
    tick();
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
