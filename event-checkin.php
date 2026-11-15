<?php
// Check-in at the door: the address inside every admission pass QR code (event-pass.php).
// The event's church admins see who it is and tap "Check in"; anyone else is told to show the pass at the entrance.
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/login_gate.php';
require_signed_in('Event Check-in');
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/event_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
ev_reg_ensure($pdo);
$code = strtoupper(trim((string)($_GET['code'] ?? $_POST['code'] ?? '')));
$reg = null;
if (ev_pass_valid($code)) {
    $stmt = $pdo->prepare(
        'SELECT r.*, e.event_id, e.title, e.event_date, e.start_time, e.end_time, e.is_all_day, e.location, e.church_id, c.church_name
           FROM event_registrations r JOIN events e ON e.event_id = r.event_id JOIN church_info c ON c.church_info_id = e.church_id
          WHERE r.pass_code = ?'
    );
    $stmt->execute([$code]);
    $reg = $stmt->fetch();
}
$canCheck = $reg && can_manage_church($pdo, (int)$reg['church_id']);
$isOwner = $reg && ($_SESSION['role'] ?? '') === 'user' && (int)$reg['user_id'] === (int)($_SESSION['user_id'] ?? 0);
if ($isOwner && !$canCheck) {   // the person scanned their own pass: just show it
    header('Location: event-pass.php?code=' . $code);
    exit;
}

$msg = null;
if ($canCheck && $_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
    if (($_POST['action'] ?? '') === 'undo') {
        $pdo->prepare('UPDATE event_registrations SET checked_in_at = NULL WHERE registration_id = ?')->execute([(int)$reg['registration_id']]);
        $_SESSION['checkin_flash'] = 'Check-in undone for ' . $reg['name'] . '.';
    } else {
        // Only the first scan counts, so the time shows when they really arrived.
        $pdo->prepare('UPDATE event_registrations SET checked_in_at = NOW() WHERE registration_id = ? AND checked_in_at IS NULL')->execute([(int)$reg['registration_id']]);
        $_SESSION['checkin_flash'] = $reg['name'] . ' is checked in.';
    }
    header('Location: event-checkin.php?code=' . $code);
    exit;
}
$msg = $_SESSION['checkin_flash'] ?? null;
unset($_SESSION['checkin_flash']);

$d = $reg ? ev_reg_details($reg['details']) : null;
$today = date('Y-m-d');

$pageTitle = 'Event Check-in';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="reg-page er-page ec-page">
    <?php if (!$reg): ?>
        <div class="ec-card is-bad">
            <span class="ec-ico"><?= ev_icon('scan', 26) ?></span>
            <h1>Pass not found</h1>
            <p>This QR code is not a valid admission pass. Please ask the person to open the pass again from <b>My registrations</b>.</p>
        </div>
    <?php elseif (!$canCheck): ?>
        <div class="ec-card">
            <span class="ec-ico"><?= ev_icon('ticket', 26) ?></span>
            <h1>Admission pass</h1>
            <p>This is a pass for <b><?= e($reg['title']) ?></b> at <?= e($reg['church_name']) ?>. Please show it at the entrance; the church team will check you in.</p>
        </div>
    <?php else: $in = (bool)$reg['checked_in_at']; ?>
        <?php back_button('event-participants.php?id=' . (int)$reg['event_id'], 'Participants'); ?>
        <?php if ($msg): ?><div class="alert success info-alert" role="status"><?= e($msg) ?></div><?php endif; ?>
        <div class="ec-card <?= $in ? 'is-in' : 'is-ready' ?>">
            <span class="ec-ico"><?= ev_icon($in ? 'check' : 'ticket', 26) ?></span>
            <p class="ec-kicker"><?= $in ? 'Already checked in' : 'Valid pass' ?></p>
            <h1><?= e($reg['name']) ?></h1>
            <p class="ec-sub"><?= e(people_label((int)$reg['people'])) ?> &middot; <?= e(ev_reg_ref((int)$reg['registration_id'], $reg['event_date'])) ?></p>
            <?php if ($d['participants']): ?><p class="ec-with">With <?= e(implode(', ', $d['participants'])) ?></p><?php endif; ?>

            <?php if ($reg['event_date'] !== $today): ?>
                <p class="ec-warn"><?= ev_icon('calendar', 14) ?> This pass is for <b><?= e(date('l, j F Y', strtotime($reg['event_date']))) ?></b>, not today.</p>
            <?php endif; ?>
            <?php if ($d['access'] !== ''): ?><p class="ec-note"><b>Assistance needed:</b> <?= e($d['access']) ?></p><?php endif; ?>

            <dl class="ec-facts">
                <div><dt>Event</dt><dd><?= e($reg['title']) ?></dd></div>
                <div><dt>When</dt><dd><?= e(date('D, j M', strtotime($reg['event_date']))) ?> &middot; <?= e(event_time_text($reg)) ?></dd></div>
                <?php if ($in): ?><div><dt>Arrived</dt><dd><?= e(date('j M, g:i A', strtotime($reg['checked_in_at']))) ?></dd></div><?php endif; ?>
            </dl>

            <form method="post" action="event-checkin.php" class="ec-actions">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="code" value="<?= e($code) ?>">
                <?php if ($in): ?>
                    <button type="submit" name="action" value="undo" class="er-btn is-ghost" data-confirm="Undo the check-in of <?= e($reg['name']) ?>?">Undo check-in</button>
                <?php else: ?>
                    <button type="submit" name="action" value="in" class="er-btn is-solid is-big"><?= ev_icon('check', 18) ?> Check in <?= (int)$reg['people'] > 1 ? e(people_label((int)$reg['people'])) : '' ?></button>
                <?php endif; ?>
            </form>
        </div>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
