<?php
// The admission pass of one event registration: a QR code the church scans at the door (event-checkin.php).
// Only the person who registered, and the admins of the event's church, can open it.
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/login_gate.php';
require_signed_in('Event Admission Pass');
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/event_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

ev_reg_ensure($pdo);
$code = strtoupper(trim((string)($_GET['code'] ?? '')));
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
$isOwner = $reg && ($_SESSION['role'] ?? '') === 'user' && (int)$reg['user_id'] === (int)($_SESSION['user_id'] ?? 0);
if (!$reg || !($isOwner || can_manage_church($pdo, (int)$reg['church_id']))) {
    header('Location: ' . (($_SESSION['role'] ?? '') === 'user' ? 'my-events.php' : 'events.php'));
    exit;
}

$d = ev_reg_details($reg['details']);
$isNew = !empty($_GET['new']);
$flash = $_SESSION['event_flash'] ?? null;
unset($_SESSION['event_flash']);
$checkinUrl = ev_site_root() . 'event-checkin.php?code=' . $code;   // what the QR code holds
$ts = strtotime($reg['event_date']);
$isPast = $reg['event_date'] < date('Y-m-d');

$pageTitle = 'Admission Pass';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="reg-page er-page ep-page">
    <?php if ($isOwner) back_button('my-events.php', 'My registrations'); else back_button('event-participants.php?id=' . (int)$reg['event_id'], 'Back to participants'); ?>
    <?php if ($flash): ?><div class="alert info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>

    <?php if ($isNew): ?>
        <div class="er-card ep-done">
            <header class="er-head">
                <h1>Event Registration</h1>
                <p><?= e($reg['title']) ?></p>
                <ol class="er-steps" aria-label="Progress">
                    <li class="is-done"><span>Registration</span></li>
                    <li class="is-done"><span>Review</span></li>
                    <li class="is-now" aria-current="step"><span>Confirmation</span></li>
                </ol>
            </header>
            <div class="ep-done-msg">
                <span class="ep-done-ico"><?= ev_icon('check', 22) ?></span>
                <div><b>You&rsquo;re registered!</b><small>Show this pass at the entrance. We&rsquo;ve also kept it in <a href="my-events.php">My registrations</a>.</small></div>
            </div>
        </div>
    <?php endif; ?>

    <article class="ep-pass" aria-label="Event admission pass">
        <header class="ep-head">
            <span class="ep-kicker">Event Admission Pass</span>
            <h2><?= e($reg['title']) ?></h2>
            <p><?= ev_icon('church', 13) ?> <?= e($reg['church_name']) ?></p>
        </header>

        <div class="ep-qr-wrap">
            <div class="ep-qr" data-qr="<?= e($checkinUrl) ?>" role="img" aria-label="QR code of this admission pass">
                <noscript><span class="ep-code-big"><?= e(chunk_split($code, 4, ' ')) ?></span></noscript>
            </div>
            <p class="ep-code">Pass code <b><?= e(trim(chunk_split($code, 4, ' '))) ?></b></p>
        </div>

        <div class="ep-who">
            <h3><?= e($reg['name']) ?></h3>
            <p>Registration ID: <b><?= e(ev_reg_ref((int)$reg['registration_id'], $reg['event_date'])) ?></b></p>
            <?php if ($d['participants']): ?><p class="ep-with">With <?= e(implode(', ', $d['participants'])) ?></p><?php endif; ?>
        </div>

        <div class="ep-tear" aria-hidden="true"></div>

        <dl class="ep-facts">
            <div><dt>Date</dt><dd><?= e(date('D, j M Y', $ts)) ?></dd></div>
            <div><dt>Time</dt><dd><?= e(event_time_text($reg)) ?></dd></div>
            <?php if ($reg['location']): ?><div><dt>Venue</dt><dd><?= e($reg['location']) ?></dd></div><?php endif; ?>
            <div><dt>Admits</dt><dd><?= e(people_label((int)$reg['people'])) ?></dd></div>
        </dl>

        <p class="ep-status <?= $reg['checked_in_at'] ? 'is-in' : ($isPast ? 'is-past' : 'is-ok') ?>">
            <?php if ($reg['checked_in_at']): ?><?= ev_icon('check', 14) ?> Checked in &middot; <?= e(date('j M, g:i A', strtotime($reg['checked_in_at']))) ?>
            <?php elseif ($isPast): ?><?= ev_icon('clock', 14) ?> Event has passed
            <?php else: ?><?= ev_icon('check', 14) ?> Registration Confirmed<?php endif; ?>
        </p>
    </article>

    <div class="ep-actions no-print">
        <button type="button" class="er-btn is-ghost" onclick="window.print()"><?= ev_icon('download', 15) ?> Save / Print pass</button>
        <a class="er-btn is-solid" href="event.php?id=<?= (int)$reg['event_id'] ?>">View event <?= ev_icon('arrow', 15) ?></a>
    </div>
</main>
<script src="assets/js/vendor/qrcode.min.js?v=1.4.4"></script>
<script>
// Draw the QR code (the check-in address) as a sharp SVG.
(function () {
    var box = document.querySelector('.ep-qr[data-qr]');
    if (!box || typeof qrcode !== 'function') return;
    var qr = qrcode(0, 'M');
    qr.addData(box.dataset.qr);
    qr.make();
    box.innerHTML = qr.createSvgTag({ cellSize: 6, margin: 2, scalable: true });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
