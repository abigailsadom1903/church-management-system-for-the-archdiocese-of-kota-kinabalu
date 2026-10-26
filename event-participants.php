<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/event_helpers.php';
require __DIR__ . '/includes/report_tools.php';
require_once __DIR__ . '/includes/mailer.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT e.*, c.church_name, c.church_email FROM events e JOIN church_info c ON c.church_info_id = e.church_id WHERE e.event_id = ?');
$stmt->execute([$id]);
$ev = $stmt->fetch();

// Super Admin: any church. Church Admin: only their own church's events.
if (!$ev || !can_manage_church($pdo, (int)$ev['church_id'])) {
    header('Location: events.php');
    exit;
}
$backUrl = 'events.php?church=' . (int)$ev['church_id'] . '&view=manage';
$flash = $_SESSION['event_flash'] ?? null;
unset($_SESSION['event_flash']);
$error = '';
$addErrors = [];
$updErrors = [];
$add = ['name' => '', 'people' => 1, 'email' => '', 'phone' => ''];
$mailForm = null;   // subject + message of "Email All Participants" after a failed send
$mailError = '';
$mailRid = 0;       // registrant being emailed on their own; 0 = everyone
$editing = 0;   // registrant whose editor is open after a failed save

/** People already registered, optionally without one registration (when editing it). */
function places_used(PDO $pdo, int $eventId, int $exceptRid = 0): int
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(people), 0) FROM event_registrations WHERE event_id = ? AND registration_id <> ?');
    $stmt->execute([$eventId, $exceptRid]);
    return (int)$stmt->fetchColumn();
}

/** Checks one registrant's fields; returns [clean values, errors]. */
function check_registrant(PDO $pdo, array $ev, array $in, int $exceptRid = 0): array
{
    $v = ['name' => trim((string)($in['name'] ?? '')), 'people' => (int)($in['people'] ?? 1), 'email' => trim((string)($in['email'] ?? '')), 'phone' => trim((string)($in['phone'] ?? ''))];
    $err = [];
    if ($v['name'] === '' || mb_strlen($v['name']) > 100) $err['name'] = 'Enter a name.';
    if ($v['people'] < 1 || $v['people'] > 20) $err['people'] = 'Between 1 and 20 people.';
    if ($v['email'] !== '' && (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($v['email']) > 100)) $err['email'] = 'Enter a valid email.';
    if ($v['phone'] !== '' && !preg_match('/^\+?[0-9][0-9\s\-()]{6,25}$/', $v['phone'])) $err['phone'] = 'Enter a valid phone number.';
    if (!$err && $v['email'] !== '') {
        $dup = $pdo->prepare('SELECT 1 FROM event_registrations WHERE event_id = ? AND email = ? AND registration_id <> ?');
        $dup->execute([$ev['event_id'], $v['email'], $exceptRid]);
        if ($dup->fetchColumn()) $err['email'] = 'This email is already registered.';
    }
    if (!$err && $ev['capacity'] !== null) {
        $left = (int)$ev['capacity'] - places_used($pdo, (int)$ev['event_id'], $exceptRid);
        if ($v['people'] > $left) $err['people'] = $left > 0 ? "Only $left " . ($left === 1 ? 'place is' : 'places are') . ' left.' : 'The event is full.';
    }
    return [$v, $err];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif ($action === 'remove') {
        // Only ever removes a registration that belongs to THIS event.
        $pdo->prepare('DELETE FROM event_registrations WHERE registration_id = ? AND event_id = ?')->execute([(int)($_POST['rid'] ?? 0), $id]);
        $_SESSION['event_flash'] = 'Registrant removed.';
        header('Location: event-participants.php?id=' . $id);
        exit;
    } elseif ($action === 'add') {
        [$add, $addErrors] = check_registrant($pdo, $ev, $_POST);
        if (!$addErrors) {
            $pdo->prepare('INSERT INTO event_registrations (event_id, user_id, name, email, phone, people) VALUES (?, NULL, ?, ?, ?, ?)')
                ->execute([$id, $add['name'], $add['email'] ?: null, $add['phone'] ?: null, $add['people']]);
            $_SESSION['event_flash'] = $add['name'] . ' was added.';
            header('Location: event-participants.php?id=' . $id);
            exit;
        }
    } elseif ($action === 'update') {
        $rid = (int)($_POST['rid'] ?? 0);
        $own = $pdo->prepare('SELECT 1 FROM event_registrations WHERE registration_id = ? AND event_id = ?');
        $own->execute([$rid, $id]);
        if (!$own->fetchColumn()) {
            $error = 'That registrant no longer exists.';
        } else {
            [$v, $errs] = check_registrant($pdo, $ev, $_POST, $rid);
            if (!$errs) {
                $pdo->prepare('UPDATE event_registrations SET name = ?, email = ?, phone = ?, people = ? WHERE registration_id = ? AND event_id = ?')
                    ->execute([$v['name'], $v['email'] ?: null, $v['phone'] ?: null, $v['people'], $rid, $id]);
                $_SESSION['event_flash'] = 'Registrant updated.';
                header('Location: event-participants.php?id=' . $id);
                exit;
            }
            $updErrors[$rid] = ['errors' => $errs, 'values' => $v];
            $editing = $rid;
        }
    } elseif ($action === 'email') {
        // Sent by the site itself, one email per participant, so nobody sees the other addresses.
        // With a rid it goes to that one registrant only (the "Email" button on their row).
        $mailRid = (int)($_POST['rid'] ?? 0);
        $mailForm = [
            'subject' => mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 150),
            'message' => mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST['message'] ?? ''))), 0, 3000),
        ];
        if ($mailForm['subject'] === '' || $mailForm['message'] === '') {
            $mailError = 'Please write a subject and a message.';
        } else {
            $one = null;
            if ($mailRid) {
                $stmt = $pdo->prepare('SELECT name, email FROM event_registrations WHERE registration_id = ? AND event_id = ?');
                $stmt->execute([$mailRid, $id]);
                $one = $stmt->fetch() ?: ['name' => '', 'email' => null];
                $found = [(string)$one['email']];
            } else {
                $stmt = $pdo->prepare('SELECT DISTINCT email FROM event_registrations WHERE event_id = ? AND email IS NOT NULL');
                $stmt->execute([$id]);
                $found = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
            $to = array_values(array_unique(array_filter(array_map('trim', $found), fn($m) => filter_var($m, FILTER_VALIDATE_EMAIL))));
            $sent = 0;
            foreach ($to as $i => $address) {
                if (send_plain_email($address, $mailForm['subject'], $mailForm['message'] . "\n", $ev)) $sent++;
                elseif ($i === 0) break;   // the very first one failed: the mail account is not working, do not try the rest
            }
            if (!$to) $mailError = $one ? 'This registrant has no email address.' : 'Nobody has given an email address yet.';
            elseif ($sent === 0) $mailError = 'The message could not be sent just now. Please try again in a moment.';
            else {
                $_SESSION['event_flash'] = $one ? 'Your message was emailed to ' . $one['name'] . '.'
                    : 'Your message was emailed to ' . $sent . ($sent === 1 ? ' participant.' : ' participants.')
                    . ($sent < count($to) ? ' ' . (count($to) - $sent) . ' could not be reached.' : '');
                header('Location: event-participants.php?id=' . $id);
                exit;
            }
        }
    }
}

$stmt = $pdo->prepare('SELECT * FROM event_registrations WHERE event_id = ? ORDER BY created_at, registration_id');
$stmt->execute([$id]);
$people = $stmt->fetchAll();
$participants = array_sum(array_column($people, 'people'));
$withEmail = count(array_filter($people, fn($p) => $p['email']));

// "Email All Participants" opens a compose form on this page, already filled in; the site sends the message.
$allEmails = array_values(array_unique(array_filter(array_map(
    fn($p) => filter_var(trim((string)$p['email']), FILTER_VALIDATE_EMAIL) ?: null, $people))));
$date = $ev['repeat_weekly'] ? (event_next_date($ev, date('Y-m-d')) ?? $ev['event_date']) : $ev['event_date'];
// The "Email" button on a row opens the same form for that one registrant ({name} becomes their name).
$mailDefault = [
    'subject' => 'Update: ' . $ev['title'] . ' - ' . date('l, j F Y', strtotime($date)),
    'message' => "Dear {name},\n\nWe have an update regarding the event.\n[Write your message here]\n\nThank you for registering. We look forward to seeing you.\n\n" . $ev['church_name'],
];
$mailOthers = count($allEmails) - 2;
$mailAllTo = implode(', ', array_slice($allEmails, 0, 2)) . ($mailOthers > 0 ? ', and ' . $mailOthers . ' other' . ($mailOthers === 1 ? '' : 's') : '');
// After a failed send the form comes back as it was, for the same recipient.
$mailOne = null;
foreach ($people as $p) {
    if ($mailRid && (int)$p['registration_id'] === $mailRid) $mailOne = $p;
}
if (!$mailOne) $mailRid = 0;
$mailForm = $mailForm ?? ['subject' => $mailDefault['subject'], 'message' => str_replace('{name}', 'Participants', $mailDefault['message'])];
$left = $ev['capacity'] !== null ? max(0, (int)$ev['capacity'] - $participants) : null;

// ---- Generate Report: everyone registered for this event, as a CSV ----
if (($_GET['export'] ?? '') === 'csv') {
    send_report('registrants-' . $ev['title'],
        ['Event', 'Date', 'Church', 'Name', 'Email', 'Phone', 'People', 'Registered'],
        array_map(fn($p) => [$ev['title'], $date, $ev['church_name'], $p['name'], $p['email'], $p['phone'], $p['people'], date('Y-m-d H:i', strtotime($p['created_at']))], $people));
}

$pageTitle = 'Registrants';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="rg-page">
    <header class="rg-head">
        <h1>Registrants - <?= e($ev['title']) ?></h1>
        <p class="rg-back"><a href="<?= e($backUrl) ?>"><?= ev_icon('left', 14) ?> Back to events</a> <a href="event.php?id=<?= $id ?>">View event page</a></p>
    </header>

    <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert info-alert" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php print_heading('Registrants: ' . $ev['title'], $ev['church_name'], ['Registrants' => (string)count($people), 'Participants' => (string)$participants]); ?>

    <section class="rg-summary">
        <p class="rg-facts">
            <span><?= ev_icon('calendar', 15) ?> <?= e(date('D, M j, Y', strtotime($date))) ?></span>
            <span><?= ev_icon('clock', 15) ?> <?= e(event_time_text($ev)) ?></span>
            <?php if ($ev['location']): ?><span><?= ev_icon('pin', 15) ?> <?= e($ev['location']) ?></span><?php endif; ?>
            <?= countdown_badge($ev, $date) ?>
        </p>
        <div class="rg-stats">
            <div><b><?= count($people) ?></b><span>Registrants</span></div>
            <div><b><?= $participants ?></b><span>Participants</span></div>
            <div><b><?= $withEmail ?></b><span>With Email</span></div>
            <?php if ($ev['capacity'] !== null): ?><div><b><?= $left ?></b><span>Places left</span></div><?php endif; ?>
        </div>
    </section>

    <div class="rg-toolbar">
        <button type="button" class="btn-add" id="add-toggle" aria-expanded="<?= $addErrors ? 'true' : 'false' ?>" aria-controls="add-panel"><?= ev_icon('plus', 16) ?> Add Registrant</button>
        <?php if ($allEmails): ?>
            <button type="button" class="btn-email-all" id="mail-toggle" aria-expanded="<?= $mailError ? 'true' : 'false' ?>" aria-controls="mail-panel"><?= ev_icon('mail', 16) ?> Email All Participants</button>
            <button type="button" class="btn-copy-emails" data-emails="<?= e(implode(', ', $allEmails)) ?>" title="Copy the addresses to paste into any email"><?= ev_icon('copy', 15) ?> <span>Copy email addresses</span></button>
        <?php else: ?>
            <span class="btn-email-all is-off" title="Nobody has given an email address yet"><?= ev_icon('mail', 16) ?> Email All Participants</span>
        <?php endif; ?>
        <span class="sr-spacer"></span>
        <?php report_tools('event-participants.php?id=' . $id . '&export=csv', 'Print the registrant list'); ?>
    </div>

    <form method="post" action="event-participants.php?id=<?= $id ?>" class="rg-add" id="add-panel" <?= $addErrors ? '' : 'hidden' ?> novalidate>
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="add">
        <h2>Add Registrant</h2>
        <div class="rg-fields">
            <div class="<?= isset($addErrors['name']) ? 'pfield has-error' : 'pfield' ?>"><label for="a-name">Name</label><input type="text" id="a-name" name="name" maxlength="100" value="<?= e($add['name']) ?>" required><?php if (isset($addErrors['name'])): ?><span class="row-error"><?= e($addErrors['name']) ?></span><?php endif; ?></div>
            <div class="<?= isset($addErrors['people']) ? 'pfield has-error' : 'pfield' ?> rg-num"><label for="a-people">People</label><input type="number" id="a-people" name="people" min="1" max="20" value="<?= (int)$add['people'] ?>" required><?php if (isset($addErrors['people'])): ?><span class="row-error"><?= e($addErrors['people']) ?></span><?php endif; ?></div>
            <div class="<?= isset($addErrors['email']) ? 'pfield has-error' : 'pfield' ?>"><label for="a-email">Email <span class="opt">(optional)</span></label><input type="email" id="a-email" name="email" maxlength="100" value="<?= e($add['email']) ?>"><?php if (isset($addErrors['email'])): ?><span class="row-error"><?= e($addErrors['email']) ?></span><?php endif; ?></div>
            <div class="<?= isset($addErrors['phone']) ? 'pfield has-error' : 'pfield' ?>"><label for="a-phone">Phone <span class="opt">(optional)</span></label><input type="tel" id="a-phone" name="phone" maxlength="30" value="<?= e($add['phone']) ?>"><?php if (isset($addErrors['phone'])): ?><span class="row-error"><?= e($addErrors['phone']) ?></span><?php endif; ?></div>
        </div>
        <div class="rg-add-actions"><button type="button" class="btn-cancel" id="add-cancel">Cancel</button><button type="submit" class="btn-save">Add</button></div>
    </form>

    <?php if ($allEmails): ?>
    <form method="post" action="event-participants.php?id=<?= $id ?>#mail-panel" class="rg-add rg-mail" id="mail-panel" <?= $mailError ? '' : 'hidden' ?>>
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="email">
        <input type="hidden" name="rid" id="mail-rid" value="<?= $mailRid ?>">
        <h2 id="mail-title"><?= $mailOne ? 'Email ' . e($mailOne['name']) : 'Email All Participants' ?></h2>
        <p class="rg-mail-to">To: <span id="mail-to" data-all="<?= e($mailAllTo) ?>"><?= e($mailOne ? $mailOne['email'] : $mailAllTo) ?></span></p>
        <?php if ($mailError): ?><div class="alert info-alert" role="alert"><?= e($mailError) ?></div><?php endif; ?>
        <div class="pfield"><label for="m-subject">Subject</label><input type="text" id="m-subject" name="subject" maxlength="150" value="<?= e($mailForm['subject']) ?>" required></div>
        <div class="pfield"><label for="m-message">Message</label><textarea id="m-message" name="message" rows="8" maxlength="3000" required><?= e($mailForm['message']) ?></textarea></div>
        <p class="rg-mail-note" id="mail-note" data-all="Each participant gets their own email from <?= e($ev['church_name']) ?>; they cannot see each other's addresses." data-one="Only this person receives the email, sent from <?= e($ev['church_name']) ?>."></p>
        <div class="rg-add-actions"><button type="button" class="btn-cancel" id="mail-cancel">Cancel</button><button type="submit" class="btn-save" id="mail-send">Send</button></div>
    </form>
    <?php endif; ?>

    <div class="rg-list">
        <?php foreach ($people as $p): $rid = (int)$p['registration_id'];
            $open = $editing === $rid; $u = $updErrors[$rid] ?? null; $val = $u['values'] ?? $p; $er = $u['errors'] ?? []; ?>
            <article class="rg-row" id="r<?= $rid ?>">
                <span class="rg-avatar <?= avatar_class($p['name']) ?>" aria-hidden="true"><?= e(person_initials($p['name'])) ?></span>
                <div class="rg-info">
                    <h3><?= e($p['name']) ?> <span class="people-badge pb-<?= min(3, max(1, (int)$p['people'])) ?>"><?= e(people_label((int)$p['people'])) ?></span></h3>
                    <p><?= $p['email'] ? e($p['email']) : '<span class="muted">No email</span>' ?><?= $p['phone'] ? ' &middot; ' . e($p['phone']) : '' ?></p>
                </div>
                <div class="rg-actions">
                    <?php if ($p['email']): ?><button type="button" class="btn-mail" data-rid="<?= $rid ?>" data-name="<?= e($p['name']) ?>" data-email="<?= e($p['email']) ?>" aria-controls="mail-panel" title="Email <?= e($p['name']) ?> only"><?= ev_icon('mail', 14) ?> Email</button>
                    <?php else: ?><span class="btn-mail is-off"><?= ev_icon('mail', 14) ?> Email</span><?php endif; ?>
                    <button type="button" class="ico-btn sm rg-toggle" aria-expanded="<?= $open ? 'true' : 'false' ?>" aria-controls="e<?= $rid ?>" title="Edit details" aria-label="Edit <?= e($p['name']) ?>"><?= ev_icon('down', 15) ?></button>
                    <form method="post" action="event-participants.php?id=<?= $id ?>" class="rg-del">
                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="rid" value="<?= $rid ?>">
                        <button type="submit" class="ico-btn sm danger" data-confirm="Remove <?= e($p['name']) ?> from this event? This cannot be undone." title="Remove" aria-label="Remove <?= e($p['name']) ?>"><?= ev_icon('trash', 14) ?></button>
                    </form>
                </div>
                <form method="post" action="event-participants.php?id=<?= $id ?>#r<?= $rid ?>" class="rg-edit" id="e<?= $rid ?>" <?= $open ? '' : 'hidden' ?> novalidate>
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="rid" value="<?= $rid ?>">
                    <div class="rg-fields">
                        <div class="<?= isset($er['name']) ? 'pfield has-error' : 'pfield' ?>"><label>Name</label><input type="text" name="name" maxlength="100" value="<?= e($val['name']) ?>"><?php if (isset($er['name'])): ?><span class="row-error"><?= e($er['name']) ?></span><?php endif; ?></div>
                        <div class="<?= isset($er['people']) ? 'pfield has-error' : 'pfield' ?> rg-num"><label>People</label><input type="number" name="people" min="1" max="20" value="<?= (int)$val['people'] ?>"><?php if (isset($er['people'])): ?><span class="row-error"><?= e($er['people']) ?></span><?php endif; ?></div>
                        <div class="<?= isset($er['email']) ? 'pfield has-error' : 'pfield' ?>"><label>Email</label><input type="email" name="email" maxlength="100" value="<?= e((string)$val['email']) ?>"><?php if (isset($er['email'])): ?><span class="row-error"><?= e($er['email']) ?></span><?php endif; ?></div>
                        <div class="<?= isset($er['phone']) ? 'pfield has-error' : 'pfield' ?>"><label>Phone</label><input type="tel" name="phone" maxlength="30" value="<?= e((string)$val['phone']) ?>"><?php if (isset($er['phone'])): ?><span class="row-error"><?= e($er['phone']) ?></span><?php endif; ?></div>
                    </div>
                    <p class="rg-meta">Registered <?= e(date('j M Y, g:i A', strtotime($p['created_at']))) ?><?= $p['user_id'] ? ' &middot; has an account' : '' ?></p>
                    <div class="rg-add-actions"><button type="submit" class="btn-save">Save changes</button></div>
                </form>
            </article>
        <?php endforeach; ?>
        <?php if (!$people): ?><p class="ann-empty">Nobody has registered yet. Use &ldquo;Add Registrant&rdquo; to add someone who signed up by phone or in person.</p><?php endif; ?>
    </div>
</main>
<script>
(function () {
    var panel = document.getElementById('add-panel'), toggle = document.getElementById('add-toggle');
    function setAdd(open) { panel.hidden = !open; toggle.setAttribute('aria-expanded', open); if (open) panel.querySelector('input[name=name]').focus(); }
    toggle.addEventListener('click', function () { setAdd(panel.hidden); });
    document.getElementById('add-cancel').addEventListener('click', function () { setAdd(false); });

    var mailPanel = document.getElementById('mail-panel'), mailToggle = document.getElementById('mail-toggle');
    if (mailPanel && mailToggle) {
        var mailDefault = <?= json_encode($mailDefault, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var mailRid = document.getElementById('mail-rid'), mailTo = document.getElementById('mail-to'), mailNote = document.getElementById('mail-note');
        var mailBox = mailPanel.querySelector('textarea');
        // who: null = every participant, or the row's "Email" button = that one registrant only.
        var mailHome = document.createComment('mail-panel');
        mailPanel.before(mailHome);
        var mailFor = function (who, fresh) {
            // For one registrant the form pops open right under their row; for everyone, under the toolbar.
            if (who) who.closest('.rg-row').after(mailPanel); else mailHome.after(mailPanel);
            mailRid.value = who ? who.dataset.rid : 0;
            document.getElementById('mail-title').textContent = who ? 'Email ' + who.dataset.name : 'Email All Participants';
            mailTo.textContent = who ? who.dataset.email : mailTo.dataset.all;
            mailNote.textContent = who ? mailNote.dataset.one : mailNote.dataset.all;
            if (!fresh) return;
            document.getElementById('m-subject').value = mailDefault.subject;
            mailBox.value = mailDefault.message.replace('{name}', who ? who.dataset.name : 'Participants');
        };
        var setMail = function (open) {
            mailPanel.hidden = !open;
            mailToggle.setAttribute('aria-expanded', open && mailRid.value === '0');
            if (!open) return;
            // Start with the "[Write your message here]" line selected, ready to be typed over.
            var hint = '[Write your message here]', at = mailBox.value.indexOf(hint);
            mailBox.focus();
            if (at > -1) mailBox.setSelectionRange(at, at + hint.length);
            mailPanel.scrollIntoView({ block: 'center' });
        };
        // Clicking the button of the form that is already open closes it; another one switches the recipient.
        var openMail = function (who) {
            var rid = who ? who.dataset.rid : '0';
            if (!mailPanel.hidden && mailRid.value === rid) return setMail(false);
            mailFor(who, mailRid.value !== rid);
            setMail(true);
        };
        mailFor(document.querySelector('button.btn-mail[data-rid="' + mailRid.value + '"]'), false);
        mailToggle.addEventListener('click', function () { openMail(null); });
        document.querySelectorAll('button.btn-mail').forEach(function (btn) {
            btn.addEventListener('click', function () { openMail(btn); });
        });
        document.getElementById('mail-cancel').addEventListener('click', function () { setMail(false); });
        mailPanel.addEventListener('submit', function () { var b = document.getElementById('mail-send'); b.disabled = true; b.textContent = 'Sending...'; });
    }

    document.querySelectorAll('.rg-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var box = document.getElementById(btn.getAttribute('aria-controls'));
            var open = box.hidden;
            box.hidden = !open;
            btn.setAttribute('aria-expanded', open);
            btn.classList.toggle('is-open', open);
        });
        if (btn.getAttribute('aria-expanded') === 'true') btn.classList.add('is-open');
    });
})();
</script>
<script>
// Copy every participant's address, e.g. to paste into another email app.
document.querySelector('.btn-copy-emails')?.addEventListener('click', function () {
    var btn = this, label = btn.querySelector('span'), text = btn.dataset.emails;
    var done = function () { label.textContent = 'Copied!'; setTimeout(function () { label.textContent = 'Copy email addresses'; }, 1800); };
    if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, function () { prompt('Copy these addresses:', text); });
    else prompt('Copy these addresses:', text);
});
</script>
<?= countdown_script() ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
