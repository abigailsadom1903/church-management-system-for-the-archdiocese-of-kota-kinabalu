<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/login_gate.php';
require_signed_in('Event Registration');   // visitors get "please login or register" instead of this page
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/event_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$isUser = ($_SESSION['role'] ?? '') === 'user';   // only public users have "my registrations"

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT e.*, c.church_name, (SELECT COALESCE(SUM(r.people), 0) FROM event_registrations r WHERE r.event_id = e.event_id) AS people
       FROM events e JOIN church_info c ON c.church_info_id = e.church_id WHERE e.event_id = ?'
);
$stmt->execute([$id]);
$ev = $stmt->fetch();
if (!$ev) {
    header('Location: events.php');
    exit;
}
$eventUrl = 'event.php?id=' . $id;

// Only one-off events that have not happened yet, have registration switched on and still have room.
$today = date('Y-m-d');
$isPast = $ev['event_date'] < $today;
$left = $ev['capacity'] !== null ? max(0, (int)$ev['capacity'] - (int)$ev['people']) : null;   // capacity counts PEOPLE
if (!$ev['registration_required'] || $ev['repeat_weekly'] || $isPast || $left === 0) {
    $_SESSION['event_flash'] = $left === 0 && !$isPast ? 'Sorry, this event is full.' : 'Registration is not open for this event.';
    header('Location: ' . $eventUrl);
    exit;
}

// Already registered? Send them to their list instead of showing the form again.
if ($isUser) {
    $stmt = $pdo->prepare('SELECT 1 FROM event_registrations WHERE event_id = ? AND user_id = ?');
    $stmt->execute([$id, (int)$_SESSION['user_id']]);
    if ($stmt->fetchColumn()) {
        $_SESSION['event_flash'] = 'You are already registered for ' . $ev['title'] . '.';
        header('Location: my-events.php');
        exit;
    }
}

$maxPeople = min(10, $left ?? 10);
$errors = [];
$f = ['name' => '', 'email' => '', 'phone' => '', 'people' => 1];
if ($isUser) {
    $u = $pdo->prepare('SELECT user_name, user_email, user_phone FROM user WHERE user_id = ?');
    $u->execute([(int)$_SESSION['user_id']]);
    if ($row = $u->fetch()) $f = ['name' => $row['user_name'], 'email' => $row['user_email'], 'phone' => (string)$row['user_phone'], 'people' => 1];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = ['name' => trim($_POST['name'] ?? ''), 'email' => trim($_POST['email'] ?? ''), 'phone' => trim($_POST['phone'] ?? ''), 'people' => (int)($_POST['people'] ?? 0)];
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['form'] = 'Your session expired. Please try again.';
    } elseif (!empty($_POST['website'])) {
        header('Location: ' . $eventUrl);   // hidden field only bots fill in: pretend it worked
        exit;
    } else {
        if ($f['name'] === '' || mb_strlen($f['name']) > 100) $errors['name'] = 'Please enter your full name.';
        if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($f['email']) > 100) $errors['email'] = 'Please enter a valid email address.';
        if (!preg_match('/^\+?[0-9][0-9\s\-()]{6,25}$/', $f['phone'])) $errors['phone'] = 'Please enter a valid phone number.';
        if ($f['people'] < 1 || $f['people'] > 10) $errors['people'] = 'Please choose the number of participants.';

        if (!$errors) {
            // Re-check the room and the duplicates inside a transaction so two people can't take the last place together.
            $pdo->beginTransaction();
            $pdo->prepare('SELECT event_id FROM events WHERE event_id = ? FOR UPDATE')->execute([$id]);
            $n = $pdo->prepare('SELECT COALESCE(SUM(people), 0) FROM event_registrations WHERE event_id = ?');
            $n->execute([$id]);
            $used = (int)$n->fetchColumn();
            $dup = $pdo->prepare('SELECT 1 FROM event_registrations WHERE event_id = ? AND (email = ? OR (? IS NOT NULL AND user_id = ?))');
            $uid = $isUser ? (int)$_SESSION['user_id'] : null;
            $dup->execute([$id, $f['email'], $uid, $uid]);
            if ($ev['capacity'] !== null && $used + $f['people'] > (int)$ev['capacity']) {
                $pdo->rollBack();
                $room = max(0, (int)$ev['capacity'] - $used);
                $errors['form'] = $room === 0 ? 'Sorry, this event is now full.' : 'Sorry, only ' . $room . ($room === 1 ? ' place is' : ' places are') . ' left. Please choose fewer participants.';
            } elseif ($dup->fetchColumn()) {
                $pdo->rollBack();
                $errors['email'] = 'This email address is already registered for the event.';
            } else {
                $pdo->prepare('INSERT INTO event_registrations (event_id, user_id, name, email, phone, people) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$id, $uid, $f['name'], $f['email'], $f['phone'], $f['people']]);
                $pdo->commit();
                $_SESSION['event_registered'] = $ev['title'];   // makes my-events.php show the "Event Registered" banner once
                header('Location: my-events.php');
                exit;
            }
        }
    }
}

function fc(array $errors, string $key): string { return isset($errors[$key]) ? 'pfield has-error' : 'pfield'; }

$pageTitle = 'Event Registration';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="reg-page">
    <?php back_button($eventUrl, 'Back to the event'); ?>

    <form method="post" action="event-register.php?id=<?= $id ?>" class="reg-card" novalidate>
        <header class="reg-head">
            <h1>Event Registration</h1>
            <p>Registering for: <?= e($ev['title']) ?></p>
        </header>

        <div class="reg-body">
            <?php if (isset($errors['form'])): ?><div class="alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="text" name="website" class="hp-field" tabindex="-1" autocomplete="off" aria-hidden="true">

            <div class="reg-grid">
                <div class="reg-left">
                    <div class="<?= fc($errors, 'name') ?>">
                        <label for="r-name">Full Name</label>
                        <input type="text" id="r-name" name="name" maxlength="100" autocomplete="name" value="<?= e($f['name']) ?>" required>
                        <?php if (isset($errors['name'])): ?><span class="row-error"><?= e($errors['name']) ?></span><?php endif; ?>
                    </div>
                    <div class="<?= fc($errors, 'email') ?>">
                        <label for="r-email">Email</label>
                        <input type="email" id="r-email" name="email" maxlength="100" autocomplete="email" value="<?= e($f['email']) ?>" required>
                        <?php if (isset($errors['email'])): ?><span class="row-error"><?= e($errors['email']) ?></span><?php endif; ?>
                    </div>
                    <div class="<?= fc($errors, 'phone') ?>">
                        <label for="r-phone">Phone Number</label>
                        <input type="tel" id="r-phone" name="phone" maxlength="30" autocomplete="tel" value="<?= e($f['phone']) ?>" required>
                        <?php if (isset($errors['phone'])): ?><span class="row-error"><?= e($errors['phone']) ?></span><?php endif; ?>
                    </div>
                </div>

                <div class="reg-right">
                    <div class="<?= fc($errors, 'people') ?>">
                        <label for="r-people">Number of Participants</label>
                        <select id="r-people" name="people" required>
                            <?php for ($n = 1; $n <= $maxPeople; $n++): ?>
                                <option value="<?= $n ?>" <?= $f['people'] === $n ? 'selected' : '' ?>><?= $n ?></option>
                            <?php endfor; ?>
                        </select>
                        <?php if (isset($errors['people'])): ?><span class="row-error"><?= e($errors['people']) ?></span><?php endif; ?>
                        <span class="reg-hint">Including yourself<?= $left !== null ? ' &middot; ' . $left . ($left === 1 ? ' place' : ' places') . ' left' : '' ?></span>
                    </div>
                    <ul class="reg-facts">
                        <li><?= ev_icon('calendar', 15) ?> <?= e(date('l, j F Y', strtotime($ev['event_date']))) ?></li>
                        <li><?= ev_icon('clock', 15) ?> <?= e(event_time_text($ev)) ?></li>
                        <?php if ($ev['location']): ?><li><?= ev_icon('pin', 15) ?> <?= e($ev['location']) ?></li><?php endif; ?>
                    </ul>
                </div>
            </div>

            <div class="reg-actions">
                <a class="reg-cancel" href="<?= e($eventUrl) ?>">Cancel</a>
                <button type="submit" class="reg-submit">Submit</button>
            </div>
        </div>
    </form>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
