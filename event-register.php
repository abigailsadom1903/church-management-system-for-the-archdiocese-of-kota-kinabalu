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
ev_reg_ensure($pdo);
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

// Already registered? Show their pass instead of the form again.
if ($isUser) {
    $stmt = $pdo->prepare('SELECT pass_code FROM event_registrations WHERE event_id = ? AND user_id = ?');
    $stmt->execute([$id, (int)$_SESSION['user_id']]);
    $mine = $stmt->fetch();
    if ($mine) {
        $_SESSION['event_flash'] = 'You are already registered for ' . $ev['title'] . '.';
        header('Location: ' . ($mine['pass_code'] ? 'event-pass.php?code=' . $mine['pass_code'] : 'my-events.php'));
        exit;
    }
}

$maxPeople = min(10, $left ?? 10);   // a group registration covers 2 to this many people
$parishes = $pdo->query('SELECT church_info_id, church_name FROM church_info ORDER BY church_name')->fetchAll(PDO::FETCH_KEY_PAIR);

// The answers: from the session while moving between Registration and Review, otherwise fresh.
$blank = ['type' => 'individual', 'name' => '', 'email' => '', 'phone' => '', 'parish_id' => 0, 'people' => 1,
          'participants' => [], 'first_time' => '', 'questions' => '', 'access' => '', 'consent' => false];
$f = $_SESSION['ev_pending'][$id] ?? null;
if (!$f) {
    $f = $blank;
    $f['parish_id'] = (int)$ev['church_id'];
    if ($isUser) {
        $u = $pdo->prepare('SELECT user_name, user_email, user_phone, church_id FROM user WHERE user_id = ?');
        $u->execute([(int)$_SESSION['user_id']]);
        if ($row = $u->fetch()) {
            $f['name'] = (string)$row['user_name']; $f['email'] = (string)$row['user_email']; $f['phone'] = (string)$row['user_phone'];
            if ($row['church_id'] && isset($parishes[(int)$row['church_id']])) $f['parish_id'] = (int)$row['church_id'];
        }
    }
}

/** Clean posted answers. */
function er_collect(array $p, int $maxPeople): array
{
    $type = ($p['type'] ?? '') === 'group' ? 'group' : 'individual';
    $people = $type === 'group' ? (int)($p['people'] ?? 2) : 1;
    $names = array_map(fn($n) => mb_substr(trim((string)$n), 0, 100), array_slice((array)($p['participants'] ?? []), 0, max(0, $people - 1)));
    return [
        'type' => $type,
        'name' => mb_substr(trim((string)($p['name'] ?? '')), 0, 100),
        'email' => mb_substr(trim((string)($p['email'] ?? '')), 0, 100),
        'phone' => mb_substr(trim((string)($p['phone'] ?? '')), 0, 30),
        'parish_id' => (int)($p['parish_id'] ?? 0),
        'people' => $people,
        'participants' => $names,
        'first_time' => in_array($p['first_time'] ?? '', ['yes', 'no'], true) ? $p['first_time'] : '',
        'questions' => mb_substr(trim((string)($p['questions'] ?? '')), 0, 500),
        'access' => mb_substr(trim((string)($p['access'] ?? '')), 0, 300),
        'consent' => !empty($p['consent']),
    ];
}

/** Problems with the answers, key => message. */
function er_validate(array $f, int $maxPeople, array $parishes): array
{
    $err = [];
    if ($f['name'] === '') $err['name'] = 'Please enter your full name.';
    if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $err['email'] = 'Please enter a valid email address.';
    if (!preg_match('/^\+?[0-9][0-9\s\-()]{6,25}$/', $f['phone'])) $err['phone'] = 'Please enter a valid phone number.';
    if ($f['parish_id'] && !isset($parishes[$f['parish_id']])) $err['parish_id'] = 'Please choose a parish from the list.';
    if ($f['type'] === 'group') {
        if ($maxPeople < 2) $err['type'] = 'Only one place is left, so please register on your own.';
        elseif ($f['people'] < 2 || $f['people'] > $maxPeople) $err['people'] = 'Please choose between 2 and ' . $maxPeople . ' participants.';
        else {
            for ($i = 0; $i < $f['people'] - 1; $i++) {
                if (($f['participants'][$i] ?? '') === '') $err['participants'] = 'Please enter the name of every other participant.';
            }
        }
    }
    if (!$f['consent']) $err['consent'] = 'Please tick the box to continue.';
    return $err;
}

$step = 'form';
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'review');
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['form'] = 'Your session expired. Please try again.';
    } elseif (!empty($_POST['website'])) {
        header('Location: ' . $eventUrl);   // hidden field only bots fill in: pretend it worked
        exit;
    } elseif ($action === 'edit') {
        $step = 'form';                     // "Back" from the review: the answers are still in the session
    } elseif ($action === 'review') {
        $f = er_collect($_POST, $maxPeople);
        $_SESSION['ev_pending'][$id] = $f;
        $errors = er_validate($f, $maxPeople, $parishes);
        $step = $errors ? 'form' : 'review';
    } elseif ($action === 'confirm') {
        $errors = er_validate($f, $maxPeople, $parishes);
        if ($errors) {
            $step = 'form';
        } else {
            // Re-check the room and the duplicates inside a transaction so two people can't take the last place together.
            $pdo->beginTransaction();
            $pdo->prepare('SELECT event_id FROM events WHERE event_id = ? FOR UPDATE')->execute([$id]);
            $n = $pdo->prepare('SELECT COALESCE(SUM(people), 0) FROM event_registrations WHERE event_id = ?');
            $n->execute([$id]);
            $used = (int)$n->fetchColumn();
            $uid = $isUser ? (int)$_SESSION['user_id'] : null;
            $dup = $pdo->prepare('SELECT 1 FROM event_registrations WHERE event_id = ? AND (email = ? OR (? IS NOT NULL AND user_id = ?))');
            $dup->execute([$id, $f['email'], $uid, $uid]);
            if ($ev['capacity'] !== null && $used + $f['people'] > (int)$ev['capacity']) {
                $pdo->rollBack();
                $room = max(0, (int)$ev['capacity'] - $used);
                $errors['form'] = $room === 0 ? 'Sorry, this event is now full.' : 'Sorry, only ' . $room . ($room === 1 ? ' place is' : ' places are') . ' left. Please choose fewer participants.';
                $step = 'form';
            } elseif ($dup->fetchColumn()) {
                $pdo->rollBack();
                $errors['email'] = 'This email address is already registered for the event.';
                $step = 'form';
            } else {
                $details = json_encode([
                    'type' => $f['type'], 'parish_id' => $f['parish_id'], 'participants' => $f['participants'],
                    'first_time' => $f['first_time'], 'questions' => $f['questions'], 'access' => $f['access'],
                ], JSON_UNESCAPED_UNICODE);
                $ins = $pdo->prepare('INSERT INTO event_registrations (event_id, user_id, name, email, phone, people, details, pass_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                for ($try = 0; ; $try++) {   // a new pass code is practically always unique; try again in the rare case it is not
                    $code = ev_pass_code();
                    try { $ins->execute([$id, $uid, $f['name'], $f['email'], $f['phone'], $f['people'], $details, $code]); break; }
                    catch (PDOException $ex) { if ($try >= 3 || !str_contains($ex->getMessage(), 'uq_reg_pass')) throw $ex; }
                }
                $pdo->commit();
                unset($_SESSION['ev_pending'][$id]);
                header('Location: event-pass.php?code=' . $code . '&new=1');
                exit;
            }
        }
    }
}

function fc(array $errors, string $key, string $extra = ''): string { return trim('pfield ' . $extra . (isset($errors[$key]) ? ' has-error' : '')); }
function ferr(array $errors, string $key): string { return isset($errors[$key]) ? '<span class="row-error">' . e($errors[$key]) . '</span>' : ''; }

$steps = ['Registration', 'Review', 'Confirmation'];
$current = $step === 'review' ? 2 : 1;

$pageTitle = 'Event Registration';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="reg-page er-page">
    <?php back_button($eventUrl, 'Back to the event'); ?>

    <div class="er-card">
        <header class="er-head">
            <h1>Event Registration</h1>
            <p><?= e($ev['title']) ?></p>
            <ol class="er-steps" aria-label="Progress">
                <?php foreach ($steps as $i => $label): $n = $i + 1; ?>
                    <li class="<?= $n < $current ? 'is-done' : ($n === $current ? 'is-now' : '') ?>"<?= $n === $current ? ' aria-current="step"' : '' ?>><span><?= e($label) ?></span></li>
                <?php endforeach; ?>
            </ol>
        </header>

        <?php if ($step === 'form'): ?>
        <form method="post" action="event-register.php?id=<?= $id ?>" class="er-body" novalidate>
            <?php if (isset($errors['form'])): ?><div class="alert" role="alert"><?= e($errors['form']) ?></div>
            <?php elseif ($errors): ?><div class="alert info-alert" role="alert">Please check the highlighted items.</div><?php endif; ?>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="action" value="review">
            <input type="text" name="website" class="hp-field" tabindex="-1" autocomplete="off" aria-hidden="true">

            <section class="er-sec">
                <h2>Registration Type</h2>
                <div class="er-types" role="radiogroup" aria-label="Registration type">
                    <label class="er-type"><input type="radio" name="type" value="individual" <?= $f['type'] === 'individual' ? 'checked' : '' ?>>
                        <span><b><?= ev_icon('user', 16) ?> Individual</b><small>Just me</small></span></label>
                    <label class="er-type<?= $maxPeople < 2 ? ' is-off' : '' ?>"><input type="radio" name="type" value="group" <?= $f['type'] === 'group' ? 'checked' : '' ?><?= $maxPeople < 2 ? ' disabled' : '' ?>>
                        <span><b><?= ev_icon('users', 16) ?> Family / Group</b><small><?= $maxPeople < 2 ? 'Only one place left' : 'Me and up to ' . ($maxPeople - 1) . ' more' ?></small></span></label>
                </div>
                <?= ferr($errors, 'type') ?>
            </section>

            <section class="er-sec">
                <h2>Contact Information</h2>
                <div class="er-grid">
                    <div class="<?= fc($errors, 'name') ?>">
                        <label for="r-name">Full Name</label>
                        <input type="text" id="r-name" name="name" maxlength="100" autocomplete="name" value="<?= e($f['name']) ?>" required>
                        <?= ferr($errors, 'name') ?>
                    </div>
                    <div class="<?= fc($errors, 'email') ?>">
                        <label for="r-email">Email</label>
                        <input type="email" id="r-email" name="email" maxlength="100" autocomplete="email" value="<?= e($f['email']) ?>" required>
                        <?= ferr($errors, 'email') ?>
                    </div>
                    <div class="<?= fc($errors, 'phone') ?>">
                        <label for="r-phone">Phone Number</label>
                        <input type="tel" id="r-phone" name="phone" maxlength="30" autocomplete="tel" placeholder="+60" value="<?= e($f['phone']) ?>" required>
                        <?= ferr($errors, 'phone') ?>
                    </div>
                    <div class="<?= fc($errors, 'parish_id') ?>">
                        <label for="r-parish">Parish <span class="opt">(optional)</span></label>
                        <select id="r-parish" name="parish_id">
                            <option value="0">Select parish</option>
                            <?php foreach ($parishes as $pid => $pname): ?>
                                <option value="<?= (int)$pid ?>" <?= (int)$f['parish_id'] === (int)$pid ? 'selected' : '' ?>><?= e($pname) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= ferr($errors, 'parish_id') ?>
                    </div>
                </div>

                <div class="er-group" data-er-group<?= $f['type'] === 'group' ? '' : ' hidden' ?>>
                    <div class="<?= fc($errors, 'people') ?>">
                        <label for="r-people">Number of Participants <span class="opt">(including yourself)</span></label>
                        <select id="r-people" name="people">
                            <?php for ($n = 2; $n <= max(2, $maxPeople); $n++): ?>
                                <option value="<?= $n ?>" <?= (int)$f['people'] === $n ? 'selected' : '' ?>><?= $n ?></option>
                            <?php endfor; ?>
                        </select>
                        <?php if ($left !== null): ?><span class="reg-hint"><?= $left ?> <?= $left === 1 ? 'place' : 'places' ?> left</span><?php endif; ?>
                        <?= ferr($errors, 'people') ?>
                    </div>
                    <div class="er-names<?= isset($errors['participants']) ? ' has-error' : '' ?>">
                        <?php for ($i = 0; $i < max(1, $maxPeople - 1); $i++): ?>
                            <div class="pfield" data-er-name="<?= $i + 2 ?>"<?= $f['type'] === 'group' && $i < $f['people'] - 1 ? '' : ' hidden' ?>>
                                <label for="r-p<?= $i ?>">Additional Participant <?= $i + 1 ?> &mdash; Full Name</label>
                                <input type="text" id="r-p<?= $i ?>" name="participants[]" maxlength="100" placeholder="Enter participant name" value="<?= e($f['participants'][$i] ?? '') ?>">
                            </div>
                        <?php endfor; ?>
                        <?= ferr($errors, 'participants') ?>
                    </div>
                </div>
            </section>

            <section class="er-sec">
                <h2>Event Preferences</h2>
                <div class="pfield">
                    <span class="er-q">Is this your first time attending this kind of event? <span class="opt">(optional)</span></span>
                    <div class="er-yn">
                        <label><input type="radio" name="first_time" value="yes" <?= $f['first_time'] === 'yes' ? 'checked' : '' ?>> Yes</label>
                        <label><input type="radio" name="first_time" value="no" <?= $f['first_time'] === 'no' ? 'checked' : '' ?>> No</label>
                    </div>
                </div>
                <div class="pfield">
                    <label for="r-questions">Questions you hope the speaker will address <span class="opt">(optional)</span></label>
                    <textarea id="r-questions" name="questions" rows="3" maxlength="500" placeholder="What would you like to learn?"><?= e($f['questions']) ?></textarea>
                </div>
                <div class="pfield">
                    <label for="r-access">Accessibility or other assistance <span class="opt">(optional)</span></label>
                    <textarea id="r-access" name="access" rows="2" maxlength="300" placeholder="e.g. wheelchair access, a seat near the front, sign language"><?= e($f['access']) ?></textarea>
                </div>
            </section>

            <div class="<?= fc($errors, 'consent', 'er-consent') ?>">
                <label><input type="checkbox" name="consent" value="1" <?= $f['consent'] ? 'checked' : '' ?>> I agree that <?= e($ev['church_name']) ?> may use my information to manage this event registration.</label>
                <?= ferr($errors, 'consent') ?>
            </div>

            <div class="er-actions">
                <a class="er-btn is-ghost" href="<?= e($eventUrl) ?>">Cancel</a>
                <button type="submit" class="er-btn is-solid" data-er-next>Review Registration <?= ev_icon('arrow', 15) ?></button>
            </div>
        </form>

        <?php else: /* review */ ?>
        <div class="er-body">
            <h2 class="er-review-h">Review Registration</h2>
            <div class="er-review">
                <div class="er-rv-event">
                    <h3><?= e($ev['title']) ?></h3>
                    <ul>
                        <li><?= ev_icon('calendar', 15) ?> <?= e(date('l, j F Y', strtotime($ev['event_date']))) ?></li>
                        <li><?= ev_icon('clock', 15) ?> <?= e(event_time_text($ev)) ?></li>
                        <?php if ($ev['location']): ?><li><?= ev_icon('pin', 15) ?> <?= e($ev['location']) ?></li><?php endif; ?>
                        <li><?= ev_icon('church', 15) ?> <?= e($ev['church_name']) ?></li>
                    </ul>
                </div>
                <dl class="er-rv-list">
                    <div><dt>Registration</dt><dd><?= $f['type'] === 'group' ? 'Family / Group' : 'Individual' ?></dd></div>
                    <div><dt>Name</dt><dd><?= e($f['name']) ?></dd></div>
                    <div><dt>Email</dt><dd><?= e($f['email']) ?></dd></div>
                    <div><dt>Phone</dt><dd><?= e($f['phone']) ?></dd></div>
                    <div><dt>Parish</dt><dd><?= $f['parish_id'] ? e($parishes[$f['parish_id']] ?? '') : '<span class="muted">&mdash;</span>' ?></dd></div>
                    <div><dt>Participants</dt><dd><?= e(people_label((int)$f['people'])) ?><?php if ($f['participants']): ?><small><?= e(implode(', ', $f['participants'])) ?></small><?php endif; ?></dd></div>
                    <?php if ($f['first_time'] !== ''): ?><div><dt>First time</dt><dd><?= $f['first_time'] === 'yes' ? 'Yes' : 'No' ?></dd></div><?php endif; ?>
                    <?php if ($f['questions'] !== ''): ?><div><dt>Questions</dt><dd><?= nl2br(e($f['questions'])) ?></dd></div><?php endif; ?>
                    <?php if ($f['access'] !== ''): ?><div><dt>Assistance</dt><dd><?= nl2br(e($f['access'])) ?></dd></div><?php endif; ?>
                    <div><dt>Registration fee</dt><dd>Free</dd></div>
                </dl>
            </div>
            <form method="post" action="event-register.php?id=<?= $id ?>" class="er-actions">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <button type="submit" name="action" value="edit" class="er-btn is-ghost"><?= ev_icon('left', 15) ?> Back</button>
                <button type="submit" name="action" value="confirm" class="er-btn is-solid"><?= ev_icon('check', 15) ?> Confirm Registration</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</main>
<?php if ($step === 'form'): ?>
<script>
// Individual / Family-Group: show the group part, and one name box per extra participant.
(function () {
    var form = document.querySelector('.er-body');
    if (!form) return;
    var group = form.querySelector('[data-er-group]'), people = document.getElementById('r-people');
    var consent = form.querySelector('input[name=consent]'), next = form.querySelector('[data-er-next]');
    function sync() {
        var isGroup = form.querySelector('input[name=type]:checked') && form.querySelector('input[name=type]:checked').value === 'group';
        group.hidden = !isGroup;
        var n = isGroup ? parseInt(people.value, 10) : 1;
        form.querySelectorAll('[data-er-name]').forEach(function (box) {
            var show = +box.dataset.erName <= n;
            box.hidden = !show;
            box.querySelector('input').disabled = !show;
        });
        next.classList.toggle('is-waiting', !consent.checked);
    }
    form.querySelectorAll('input[name=type]').forEach(function (r) { r.addEventListener('change', sync); });
    people.addEventListener('change', sync);
    consent.addEventListener('change', sync);
    sync();
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
