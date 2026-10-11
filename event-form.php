<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/event_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Editing (?id=) or creating (?church=). When editing, the church always comes from the record.
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$existing = null;
if ($id) {
    $stmt = $pdo->prepare(
        'SELECT e.*, (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.event_id) AS regs,
                (SELECT COALESCE(SUM(r.people), 0) FROM event_registrations r WHERE r.event_id = e.event_id) AS people
           FROM events e WHERE e.event_id = ?'
    );
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        header('Location: events.php');
        exit;
    }
    $churchId = (int)$existing['church_id'];
} else {
    $churchId = (int)($_GET['church'] ?? $_POST['church'] ?? 0);
}

// Super Admin: any church. Church Admin: only their own. Anyone else is sent back.
if (!can_manage_church($pdo, $churchId)) {
    header('Location: events.php' . ($churchId ? '?church=' . $churchId : ''));
    exit;
}
$stmt = $pdo->prepare('SELECT church_name, church_location FROM church_info WHERE church_info_id = ?');
$stmt->execute([$churchId]);
$church = $stmt->fetch();

$errors = [];
$f = [
    'title'    => $existing['title'] ?? '',
    'category' => $existing['category'] ?? 'event',
    'type'     => $existing['event_type'] ?? '',
    'date'     => $existing['event_date'] ?? (($_GET['date'] ?? '') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : date('Y-m-d')),
    'all_day'  => !empty($existing['is_all_day']),
    'start'    => isset($existing['start_time']) && $existing['start_time'] ? substr($existing['start_time'], 0, 5) : '',
    'end'      => isset($existing['end_time']) && $existing['end_time'] ? substr($existing['end_time'], 0, 5) : '',
    'location' => $existing['location'] ?? '',
    'desc'     => $existing['description'] ?? '',
    'repeat'   => !empty($existing['repeat_weekly']),
    'until'    => $existing['repeat_until'] ?? '',
    'register' => !empty($existing['registration_required']),
    'capacity' => $existing['capacity'] ?? '',
];

function valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d && $d >= '2000-01-01' && $d <= '2100-12-31';
}
function valid_time(string $t): bool
{
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f['title']    = trim($_POST['title'] ?? '');
    $f['category'] = ($_POST['category'] ?? '') === 'mass' ? 'mass' : 'event';
    $f['type']     = trim($_POST['type'] ?? '');
    $f['date']     = trim($_POST['date'] ?? '');
    $f['all_day']  = !empty($_POST['all_day']);
    $f['start']    = trim($_POST['start'] ?? '');
    $f['end']      = trim($_POST['end'] ?? '');
    $f['location'] = trim($_POST['location'] ?? '');
    $f['desc']     = trim(str_replace("\r\n", "\n", $_POST['desc'] ?? ''));
    $f['repeat']   = ($_POST['repeat'] ?? '') === 'weekly';
    $f['until']    = trim($_POST['until'] ?? '');
    $f['register'] = !empty($_POST['register']);
    $f['capacity'] = trim($_POST['capacity'] ?? '');

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        if ($f['title'] === '' || mb_strlen($f['title']) > 150) $errors['title'] = 'Enter a title (up to 150 characters).';
        if ($f['type'] === '') $f['type'] = $f['category'] === 'mass' ? 'Mass' : 'Event';
        if (mb_strlen($f['type']) > 50) $errors['type'] = 'Keep the label under 50 characters.';
        if (!valid_date($f['date'])) $errors['date'] = 'Enter a valid date.';

        if (!$f['all_day']) {
            if (!valid_time($f['start'])) $errors['start'] = 'Enter a start time, or tick "All day event".';
            if ($f['end'] !== '' && !valid_time($f['end'])) $errors['end'] = 'Enter a valid end time.';
            elseif ($f['end'] !== '' && !isset($errors['start']) && $f['end'] <= $f['start']) $errors['end'] = 'The end time must be after the start time.';
        }
        if (mb_strlen($f['location']) > 150) $errors['location'] = 'Keep the location under 150 characters.';
        if (mb_strlen($f['desc']) > 3000) $errors['desc'] = 'Keep the description under 3,000 characters.';

        if ($f['repeat']) {
            if ($f['until'] !== '' && !valid_date($f['until'])) $errors['until'] = 'Enter a valid date.';
            elseif ($f['until'] !== '' && !isset($errors['date']) && $f['until'] < $f['date']) $errors['until'] = 'The repeat cannot end before the first date.';
            $f['register'] = false;   // registration is for one-off events only
            if ($existing && (int)$existing['regs'] > 0) $errors['repeat'] = 'This event already has ' . (int)$existing['regs'] . ' registrations, so it cannot be turned into a repeating event.';
        } else {
            $f['until'] = '';
        }

        $capacity = null;
        if ($f['register'] && $f['capacity'] !== '') {
            $capacity = filter_var($f['capacity'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
            if ($capacity === false) { $errors['capacity'] = 'Enter a number of places between 1 and 100,000, or leave it empty.'; $capacity = null; }
            elseif ($existing && $capacity < (int)$existing['people']) $errors['capacity'] = 'There are already ' . (int)$existing['people'] . ' people registered, so the capacity cannot be lower.';
        }

        if (!$errors) {
            $values = [
                $f['title'], $f['category'], $f['type'], $f['date'],
                $f['all_day'] ? null : $f['start'], $f['all_day'] || $f['end'] === '' ? null : $f['end'], $f['all_day'] ? 1 : 0,
                $f['location'] ?: null, $f['desc'] ?: null,
                $f['repeat'] ? 1 : 0, $f['repeat'] ? ($f['until'] ?: null) : null,
                $f['register'] ? 1 : 0, $f['register'] ? $capacity : null,
            ];
            if ($existing) {
                $pdo->prepare('UPDATE events SET title=?, category=?, event_type=?, event_date=?, start_time=?, end_time=?, is_all_day=?, location=?, description=?, repeat_weekly=?, repeat_until=?, registration_required=?, capacity=? WHERE event_id = ?')
                    ->execute([...$values, $id]);
            } else {
                $pdo->prepare('INSERT INTO events (title, category, event_type, event_date, start_time, end_time, is_all_day, location, description, repeat_weekly, repeat_until, registration_required, capacity, church_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([...$values, $churchId, (int)$_SESSION['user_id']]);
            }
            $_SESSION['event_flash'] = $existing ? 'Event updated.' : 'Event created.';
            header('Location: events.php?church=' . $churchId . '&month=' . substr($f['date'], 0, 7));
            exit;
        }
    }
}

function fc(array $errors, string $key): string { return isset($errors[$key]) ? 'pfield has-error' : 'pfield'; }
$moreOpen = isset($errors['type']) || $f['until'] !== '' || isset($errors['until']) || $f['capacity'] !== '' || isset($errors['capacity']);

$pageTitle = $existing ? 'Edit Mass Schedule or Event' : 'Create Mass Schedule or Event';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="form-page">
    <?php back_button('events.php?church=' . $churchId, 'Back to calendar'); ?>
    <header class="page-heading">
        <h1><?= $existing ? 'Edit Mass Schedule or Event' : 'Create Mass Schedule or Event' ?></h1>
        <p><?= e($church['church_name']) ?> &middot; <?= e($church['church_location']) ?></p>
    </header>

    <?php if (isset($errors['form'])): ?><div class="alert info-alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
    <?php if ($errors && !isset($errors['form'])): ?><div class="alert info-alert" role="alert">Please check the highlighted fields.</div><?php endif; ?>

    <form method="post" action="event-form.php" class="profile-card form-card" id="event-form" novalidate>
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <?php if ($existing): ?><input type="hidden" name="id" value="<?= $id ?>"><?php else: ?><input type="hidden" name="church" value="<?= $churchId ?>"><?php endif; ?>

        <div class="form-stack">
            <div class="<?= fc($errors, 'title') ?>">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" maxlength="150" value="<?= e($f['title']) ?>" required autofocus>
                <?php if (isset($errors['title'])): ?><span class="row-error"><?= e($errors['title']) ?></span><?php endif; ?>
            </div>

            <div class="pf-row">
                <div class="pfield">
                    <label for="category">Type</label>
                    <select id="category" name="category">
                        <option value="event" <?= $f['category'] === 'event' ? 'selected' : '' ?>>Event</option>
                        <option value="mass" <?= $f['category'] === 'mass' ? 'selected' : '' ?>>Mass Schedule</option>
                    </select>
                </div>
                <span></span>
            </div>

            <div class="pf-row">
                <div class="<?= fc($errors, 'date') ?>">
                    <label for="date">Date</label>
                    <input type="date" id="date" name="date" value="<?= e($f['date']) ?>" required>
                    <?php if (isset($errors['date'])): ?><span class="row-error"><?= e($errors['date']) ?></span><?php endif; ?>
                </div>
                <div class="<?= fc($errors, 'location') ?>">
                    <label for="location">Location</label>
                    <input type="text" id="location" name="location" maxlength="150" placeholder="e.g. Main Cathedral, Parish Hall" value="<?= e($f['location']) ?>">
                    <?php if (isset($errors['location'])): ?><span class="row-error"><?= e($errors['location']) ?></span><?php endif; ?>
                </div>
            </div>

            <label class="check-row"><input type="checkbox" name="all_day" id="all_day" value="1" <?= $f['all_day'] ? 'checked' : '' ?>><span>All day event</span></label>

            <div class="pf-row" id="time-row">
                <div class="<?= fc($errors, 'start') ?>">
                    <label for="start">Start Time</label>
                    <input type="time" id="start" name="start" value="<?= e($f['start']) ?>">
                    <?php if (isset($errors['start'])): ?><span class="row-error"><?= e($errors['start']) ?></span><?php endif; ?>
                </div>
                <div class="<?= fc($errors, 'end') ?>">
                    <label for="end">End Time</label>
                    <input type="time" id="end" name="end" value="<?= e($f['end']) ?>">
                    <?php if (isset($errors['end'])): ?><span class="row-error"><?= e($errors['end']) ?></span><?php endif; ?>
                </div>
            </div>

            <div class="ef-box <?= isset($errors['repeat']) ? 'has-error' : '' ?>">
                <label class="check-row"><input type="checkbox" name="repeat" id="repeat" value="weekly" <?= $f['repeat'] ? 'checked' : '' ?>><span>Repeats every week <small>(on the same day, e.g. a Sunday Mass)</small></span></label>
                <?php if (isset($errors['repeat'])): ?><span class="row-error"><?= e($errors['repeat']) ?></span><?php endif; ?>
            </div>

            <div class="ef-box" id="register-wrap">
                <label class="check-row"><input type="checkbox" name="register" id="register" value="1" <?= $f['register'] ? 'checked' : '' ?>><span>Requires Registration <small>(shows a Register link)</small></span></label>
            </div>

            <div class="<?= fc($errors, 'desc') ?>">
                <label for="desc">Description</label>
                <textarea id="desc" name="desc" rows="4" maxlength="3000"><?= e($f['desc']) ?></textarea>
                <?php if (isset($errors['desc'])): ?><span class="row-error"><?= e($errors['desc']) ?></span><?php endif; ?>
            </div>

            <details class="ef-more" <?= $moreOpen ? 'open' : '' ?>>
                <summary>More options</summary>
                <div class="ef-more-body">
                    <div class="<?= fc($errors, 'type') ?>">
                        <label for="type">Calendar label &amp; colour <span class="opt">(optional)</span></label>
                        <input type="text" id="type" name="type" maxlength="50" list="type-list" placeholder="e.g. Mass, Meeting, Youth, Adoration" value="<?= e($f['type']) ?>">
                        <datalist id="type-list"><?php foreach (EVENT_TYPES as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?></datalist>
                        <?php if (isset($errors['type'])): ?><span class="row-error"><?= e($errors['type']) ?></span><?php endif; ?>
                    </div>
                    <div class="<?= fc($errors, 'until') ?>" id="until-wrap">
                        <label for="until">Repeat until <span class="opt">(optional, empty = no end)</span></label>
                        <input type="date" id="until" name="until" value="<?= e($f['until']) ?>">
                        <?php if (isset($errors['until'])): ?><span class="row-error"><?= e($errors['until']) ?></span><?php endif; ?>
                    </div>
                    <div class="<?= fc($errors, 'capacity') ?>" id="capacity-wrap">
                        <label for="capacity">Places available <span class="opt">(people; empty = unlimited)</span></label>
                        <input type="number" id="capacity" name="capacity" min="1" max="100000" value="<?= e((string)$f['capacity']) ?>">
                        <?php if (isset($errors['capacity'])): ?><span class="row-error"><?= e($errors['capacity']) ?></span><?php endif; ?>
                    </div>
                </div>
            </details>
        </div>

        <div class="profile-actions">
            <a href="events.php?church=<?= $churchId ?>" class="btn-cancel">Cancel</a>
            <button type="submit" class="btn-save"><?= $existing ? 'Save' : 'Create' ?></button>
        </div>
    </form>
</main>
<script>
// Show only the fields that apply.
(function () {
    var allDay = document.getElementById('all_day'), timeRow = document.getElementById('time-row');
    var repeat = document.getElementById('repeat'), untilWrap = document.getElementById('until-wrap'), registerWrap = document.getElementById('register-wrap');
    var register = document.getElementById('register'), capWrap = document.getElementById('capacity-wrap');
    var category = document.getElementById('category');
    var creating = <?= $existing ? 'false' : 'true' ?>;
    function sync() {
        timeRow.hidden = allDay.checked;
        untilWrap.hidden = !repeat.checked;
        registerWrap.hidden = repeat.checked;     // registration is for one-off events only
        capWrap.hidden = repeat.checked || !register.checked;
    }
    // A new Mass Schedule almost always repeats weekly, so tick that for the admin (they can untick it).
    category.addEventListener('change', function () { if (creating && category.value === 'mass') { repeat.checked = true; sync(); } });
    [allDay, repeat, register].forEach(function (el) { el.addEventListener('change', sync); });
    sync();
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
