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
    $stmt = $pdo->prepare(
        'SELECT r.registration_id, r.people, e.event_id, e.title, e.event_date, e.start_time, e.end_time, e.is_all_day, c.church_name
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

$pageTitle = 'My Event Registrations';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="reg-page">
    <?php back_button('events.php', 'Back to the calendar'); ?>

    <?php if ($justRegistered): ?>
        <section class="reg-done" role="status">
            <span class="reg-check" aria-hidden="true"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
            <h1>Event Registered</h1>
            <p>Check your email for event updates</p>
            <small><?= e($justRegistered) ?></small>
        </section>
    <?php endif; ?>

    <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>
    <?php if (isset($errors['form'])): ?><div class="alert info-alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>

    <h2 class="reg-list-title"><?= $justRegistered ? 'List of Event Registered' : 'My Registered Events' ?></h2>

    <?php if (!$mine): ?>
        <p class="ann-empty"><?= $isUser ? 'You have not registered for any event yet.' : 'Event registrations are kept for public user accounts.' ?> <a href="events.php">Browse the calendar</a>.</p>
    <?php else: ?>
        <div class="reg-table-wrap">
            <table class="reg-table">
                <thead><tr><th>Event</th><th>Date</th><th>Reminder</th></tr></thead>
                <tbody>
                <?php foreach ($mine as $m): $past = $m['event_date'] < $today; ?>
                    <tr class="<?= $past ? 'is-past' : '' ?>">
                        <td data-label="Event">
                            <a href="event.php?id=<?= (int)$m['event_id'] ?>"><?= e($m['title']) ?></a>
                            <small><?= e($m['church_name']) ?> &middot; <?= people_label((int)$m['people']) ?></small>
                        </td>
                        <td data-label="Date"><?= e(date('jS F Y', strtotime($m['event_date']))) ?><small><?= e(event_time_text($m)) ?></small><?= $past ? '' : countdown_badge($m, $m['event_date']) ?></td>
                        <td data-label="Reminder">
                            <?php if ($past): ?>
                                <span class="reg-past">Event has passed</span>
                            <?php else: ?>
                                <form method="post" action="my-events.php">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="action" value="remind">
                                    <input type="hidden" name="rid" value="<?= (int)$m['registration_id'] ?>">
                                    <button type="submit" class="reg-remind-btn" title="Email the details of this event to me now"><?= ev_icon('bell', 14) ?> Email me a reminder</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>
<?= countdown_script() ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
