<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/login_gate.php';
require_signed_in('My Event Registrations');   // visitors get "please login or register" instead of this page
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/event_helpers.php';
require __DIR__ . '/includes/event_reminders.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$isUser = ($_SESSION['role'] ?? '') === 'user';   // registrations are tied to public-user accounts
$uid = $isUser ? (int)$_SESSION['user_id'] : 0;
$today = date('Y-m-d');

// ---- Cancel one of my registrations ----------------------------------------------------------
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        // The registration must be mine (never trust the id alone) and its event must still be ahead.
        $stmt = $pdo->prepare(
            'SELECT r.registration_id, e.title, e.event_date FROM event_registrations r JOIN events e ON e.event_id = r.event_id
              WHERE r.registration_id = ? AND r.user_id = ?'
        );
        $stmt->execute([(int)($_POST['rid'] ?? 0), $uid]);
        $row = $uid ? $stmt->fetch() : false;
        if (!$row) {
            $errors['form'] = 'That registration could not be found.';
        } elseif ($row['event_date'] < $today) {
            $errors['form'] = 'That event has already taken place, so it cannot be cancelled.';
        } else {
            $pdo->prepare('DELETE FROM event_registrations WHERE registration_id = ? AND user_id = ?')->execute([(int)$row['registration_id'], $uid]);
            $_SESSION['event_flash'] = 'Your registration for ' . $row['title'] . ' has been cancelled.';
            header('Location: my-events.php');
            exit;
        }
    }
}

// ---- Email myself a reminder for one of my registrations --------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remind') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['form'] = 'Your session expired. Please try again.';
    } elseif (time() - (int)($_SESSION['remind_sent_at'] ?? 0) < 30) {
        $errors['form'] = 'A reminder was just sent. Please wait a moment before asking for another.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT r.people, u.user_name AS name, u.user_email AS email, e.title, e.event_date, e.start_time, e.end_time, e.is_all_day, e.location, c.church_name, c.church_email
               FROM event_registrations r JOIN events e ON e.event_id = r.event_id JOIN church_info c ON c.church_info_id = e.church_id
               JOIN user u ON u.user_id = r.user_id
              WHERE r.registration_id = ? AND r.user_id = ?'
        );
        $stmt->execute([(int)($_POST['rid'] ?? 0), $uid]);
        $row = $uid ? $stmt->fetch() : false;
        if (!$row) {
            $errors['form'] = 'That registration could not be found.';
        } elseif ($row['event_date'] < $today) {
            $errors['form'] = 'That event has already taken place.';
        } elseif (!filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['form'] = 'Your account has no valid email address on file.';
        } elseif (!send_event_reminder($row)) {
            $errors['form'] = 'The reminder could not be sent just now. Please try again in a moment.';
        } else {
            $_SESSION['remind_sent_at'] = time();
            $_SESSION['event_flash'] = 'A reminder for ' . $row['title'] . ' has been emailed to ' . $row['email'] . '.';
            header('Location: my-events.php');
            exit;
        }
    }
}

$flash = $_SESSION['event_flash'] ?? null;
unset($_SESSION['event_flash']);
$justRegistered = $_SESSION['event_registered'] ?? null;   // set by event-register.php, shown once
unset($_SESSION['event_registered']);

// ---- My registrations: coming up first (soonest at the top), then the ones already past ---------
$mine = [];
if ($uid) {
    ev_reg_ensure($pdo);
    // Registrations made before admission passes existed get their pass code now.
    $old = $pdo->prepare('SELECT registration_id FROM event_registrations WHERE user_id = ? AND pass_code IS NULL');
    $old->execute([$uid]);
    $give = $pdo->prepare('UPDATE event_registrations SET pass_code = ? WHERE registration_id = ? AND pass_code IS NULL');
    foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $rid) {
        try { $give->execute([ev_pass_code(), (int)$rid]); } catch (PDOException $ex) { /* a code clash: it gets one next time */ }
    }
    $stmt = $pdo->prepare(
        'SELECT r.registration_id, r.people, r.pass_code, r.checked_in_at, e.event_id, e.title, e.event_date, e.start_time, e.end_time, e.is_all_day, e.location, c.church_name
           FROM event_registrations r
           JOIN events e ON e.event_id = r.event_id
           JOIN church_info c ON c.church_info_id = e.church_id
          WHERE r.user_id = ?'
    );
    $stmt->execute([$uid]);
    $mine = $stmt->fetchAll();
    usort($mine, function ($a, $b) use ($today) {
        $pa = $a['event_date'] < $today; $pb = $b['event_date'] < $today;
        if ($pa !== $pb) return $pa <=> $pb;
        return $pa ? strcmp($b['event_date'], $a['event_date']) : strcmp($a['event_date'], $b['event_date']);
    });
}

$upcoming = array_values(array_filter($mine, fn($m) => $m['event_date'] >= $today));
$past     = array_values(array_filter($mine, fn($m) => $m['event_date'] < $today));

$pageTitle = 'My Event Registrations';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="sx-page sx-sacr me-page">
    <section class="me-hero">
        <?php back_button('events.php', 'Back to the calendar'); ?>
        <div class="me-hero-row">
            <div>
                <span class="me-eyebrow">Parish life &middot; My registrations</span>
                <h1>My Registered Events</h1>
                <p>Everything you&rsquo;ve signed up for, with reminders in one place.</p>
            </div>
            <?php if ($uid): ?>
                <div class="me-stats">
                    <div class="is-up"><b><?= count($upcoming) ?></b><span>Upcoming</span></div>
                    <div><b><?= count($past) ?></b><span>Past</span></div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <div class="sx-band me-band">
    <?php if ($justRegistered): ?>
        <section class="reg-done" role="status">
            <span class="reg-check" aria-hidden="true"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
            <h2>Event Registered</h2>
            <p>Check your email for event updates</p>
            <small><?= e($justRegistered) ?></small>
        </section>
    <?php endif; ?>

    <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>
    <?php if (isset($errors['form'])): ?><div class="alert info-alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>

    <?php if (!$mine): ?>
        <div class="me-empty">
            <span class="me-empty-ico"><?= ev_icon('calendar', 22) ?></span>
            <h2>No registrations yet</h2>
            <p><?= $isUser ? 'You have not registered for any event yet. Find a gathering to join in the church calendar.' : 'Event registrations are kept for public user accounts.' ?></p>
        </div>
    <?php endif; ?>

    <?php if ($upcoming): ?>
        <section class="me-sec" aria-labelledby="me-up-title">
            <h2 id="me-up-title" class="me-sec-title">Upcoming</h2>
            <div class="me-list">
                <?php foreach ($upcoming as $m):
                    $ts = strtotime($m['event_date']);
                    [$start, $end] = event_window($m, $m['event_date']);
                    $place = $m['location'] ?: $m['church_name'];
                ?>
                    <article class="me-card">
                        <div class="me-date">
                            <span class="me-mon"><?= e(strtoupper(date('M', $ts))) ?></span>
                            <span class="me-day"><?= e(date('j', $ts)) ?></span>
                            <span class="me-dow"><?= e(date('l', $ts)) ?> &middot; <?= e(date('Y', $ts)) ?></span>
                            <?= countdown_badge($m, $m['event_date']) ?>
                        </div>
                        <div class="me-body">
                            <span class="me-tag"><?= ev_icon('check', 12) ?> Registered</span>
                            <h3><a href="event.php?id=<?= (int)$m['event_id'] ?>"><?= e($m['title']) ?></a></h3>
                            <ul class="me-facts">
                                <li><span><?= ev_icon('clock', 14) ?></span> <?= e(event_time_text($m)) ?></li>
                                <li><span><?= ev_icon('pin', 14) ?></span> <?= e($place) ?></li>
                                <li><span><?= ev_icon('users', 14) ?></span> <?= people_label((int)$m['people']) ?></li>
                            </ul>
                            <div class="me-acts">
                                <?php if ($m['pass_code']): ?><a class="me-btn is-solid" href="event-pass.php?code=<?= e($m['pass_code']) ?>"><?= ev_icon('ticket', 14) ?> View pass</a><?php endif; ?>
                                <form method="post" action="my-events.php">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="action" value="remind">
                                    <input type="hidden" name="rid" value="<?= (int)$m['registration_id'] ?>">
                                    <button type="submit" class="me-btn is-outline" title="Email the details of this event to me now"><?= ev_icon('bell', 14) ?> Email me a reminder</button>
                                </form>
                                <button type="button" class="me-btn is-link" data-addcal data-start="<?= $start ?>" data-end="<?= $end ?>" data-title="<?= e($m['title']) ?>" data-place="<?= e($place . ($m['location'] ? ', ' . $m['church_name'] : '')) ?>"><?= ev_icon('calendar', 14) ?> Add to my calendar</button>
                                <a class="me-view" href="event.php?id=<?= (int)$m['event_id'] ?>">View event <?= ev_icon('right', 14) ?></a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($past): ?>
        <section class="me-sec" aria-labelledby="me-past-title">
            <h2 id="me-past-title" class="me-sec-title">Past</h2>
            <div class="me-list">
                <?php foreach ($past as $m): $ts = strtotime($m['event_date']); ?>
                    <a class="me-past" href="event.php?id=<?= (int)$m['event_id'] ?>">
                        <span class="me-past-date"><small><?= e(strtoupper(date('M', $ts))) ?></small><b><?= e(date('j', $ts)) ?></b></span>
                        <span class="me-past-t">
                            <b><?= e($m['title']) ?></b>
                            <small><?= e(date('l j M Y', $ts)) ?> &middot; <?= e(event_time_text($m)) ?> &middot; <?= e($m['church_name']) ?> &middot; <?= people_label((int)$m['people']) ?></small>
                        </span>
                        <span class="me-passed"><?= ev_icon('clock', 12) ?> Event has passed</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <aside class="me-more">
        <span class="me-more-ico"><?= ev_icon('calendar', 18) ?></span>
        <span class="me-more-t"><b>Looking for more to join?</b><small>See every Mass, event and gathering in the church calendar.</small></span>
        <a class="me-btn is-solid" href="events.php">Browse the calendar <?= ev_icon('right', 14) ?></a>
    </aside>
    </div>
</main>
<?= countdown_script() ?>
<script>
// "Add to my calendar" downloads an .ics file (works with Google, Outlook, Apple).
document.querySelectorAll('[data-addcal]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var stamp = function (ms) { return new Date(ms).toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, ''); };
        var esc = function (s) { return String(s).replace(/([,;\\])/g, '\\$1'); };
        var start = +btn.dataset.start * 1000, end = +btn.dataset.end * 1000;
        var ics = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Archdiocese of Kota Kinabalu//Events//EN', 'BEGIN:VEVENT',
            'UID:' + start + '-' + Math.random().toString(36).slice(2) + '@events', 'DTSTAMP:' + stamp(Date.now()),
            'DTSTART:' + stamp(start), 'DTEND:' + stamp(end), 'SUMMARY:' + esc(btn.dataset.title), 'LOCATION:' + esc(btn.dataset.place),
            'URL:' + location.href.split('#')[0], 'END:VEVENT', 'END:VCALENDAR'].join('\r\n');
        var a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([ics], { type: 'text/calendar' }));
        a.download = btn.dataset.title.replace(/[^\w\s-]/g, '').trim().replace(/\s+/g, '-') + '.ics';
        document.body.appendChild(a); a.click(); a.remove();
    });
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
