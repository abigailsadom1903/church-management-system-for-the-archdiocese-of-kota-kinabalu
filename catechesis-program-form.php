<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/sacrament_helpers.php';
require __DIR__ . '/includes/catechesis_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Church Admins only: they add and change the programs of their own church.
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'church_admin') {
    header('Location: ' . (($_SESSION['role'] ?? '') === 'super_admin' ? 'catechesis-manage.php' : 'login.php'));
    exit;
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$stmt = $pdo->prepare('SELECT church_id FROM admin WHERE admin_id = ? AND admin_role = \'church_admin\'');
$stmt->execute([(int)$_SESSION['user_id']]);
$churchId = (int)$stmt->fetchColumn();   // their own church, never taken from the form
if (!$churchId) {
    header('Location: churchadmin/dashboard.php');
    exit;
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$existing = null;
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM catechesis_programs WHERE program_id = ? AND church_id = ?');
    $stmt->execute([$id, $churchId]);
    $existing = $stmt->fetch();
    if (!$existing) {
        header('Location: catechesis-manage.php');
        exit;
    }
}

$f = [
    'title' => $existing['title'] ?? '', 'category' => $existing['category'] ?? 'Growth', 'description' => $existing['description'] ?? '',
    'frequency' => $existing['frequency'] ?? 'weekly', 'weekday' => (string)($existing['weekday'] ?? '0'),
    'start_time' => $existing && $existing['start_time'] ? substr($existing['start_time'], 0, 5) : '', 'end_time' => $existing && $existing['end_time'] ? substr($existing['end_time'], 0, 5) : '',
    'location' => $existing['location'] ?? '', 'total_sessions' => (string)($existing['total_sessions'] ?? '20'), 'term_label' => $existing['term_label'] ?? date('Y'),
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['title' => 100, 'description' => 1000, 'location' => 120, 'term_label' => 30] as $k => $max) $f[$k] = mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $f['category'] = (string)($_POST['category'] ?? '');
    $f['frequency'] = (string)($_POST['frequency'] ?? '');
    $f['weekday'] = (string)($_POST['weekday'] ?? '');
    $f['start_time'] = trim((string)($_POST['start_time'] ?? ''));
    $f['end_time'] = trim((string)($_POST['end_time'] ?? ''));
    $f['total_sessions'] = trim((string)($_POST['total_sessions'] ?? ''));

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        $time = fn(string $t) => (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
        if ($f['title'] === '') $errors['title'] = 'Enter a title.';
        if (!isset(CAT_CATEGORIES[$f['category']])) $errors['category'] = 'Choose a category.';
        if (!in_array($f['frequency'], ['weekly', 'monthly_last'], true)) $errors['frequency'] = 'Choose how often it meets.';
        if (!ctype_digit($f['weekday']) || (int)$f['weekday'] > 6) $errors['weekday'] = 'Choose a day.';
        if ($f['start_time'] !== '' && !$time($f['start_time'])) $errors['start_time'] = 'Enter a valid time.';
        if ($f['end_time'] !== '' && !$time($f['end_time'])) $errors['end_time'] = 'Enter a valid time.';
        elseif ($f['end_time'] !== '' && $f['start_time'] !== '' && !isset($errors['start_time']) && $f['end_time'] <= $f['start_time']) $errors['end_time'] = 'The end time must be after the start time.';
        if ($f['end_time'] !== '' && $f['start_time'] === '') $errors['start_time'] = 'Enter a start time as well.';
        if (!ctype_digit($f['total_sessions']) || (int)$f['total_sessions'] < 1 || (int)$f['total_sessions'] > 200) $errors['total_sessions'] = 'Enter the number of lessons (1 to 200).';

        // Cover photo (optional): JPG, PNG or WebP up to 3 MB, checked by its content.
        $up = $_FILES['photo'] ?? null;
        $photoExt = null;
        if ($up && $up['error'] !== UPLOAD_ERR_NO_FILE) {
            $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
            $info = $up['error'] === UPLOAD_ERR_OK ? @getimagesize($up['tmp_name']) : false;
            if ($up['error'] !== UPLOAD_ERR_OK) $errors['photo'] = 'The photo could not be uploaded. Please try again.';
            elseif ($up['size'] > 3 * 1024 * 1024) $errors['photo'] = 'The photo must be 3 MB or smaller.';
            elseif (!$info || !isset($types[$info[2]])) $errors['photo'] = 'Please choose a JPG, PNG or WebP image.';
            else $photoExt = $types[$info[2]];
        }

        if (!$errors) {
            $vals = [$f['title'], $f['category'], $f['description'] ?: null, $f['frequency'], (int)$f['weekday'],
                     $f['start_time'] ? $f['start_time'] . ':00' : null, $f['end_time'] ? $f['end_time'] . ':00' : null,
                     $f['location'] ?: null, (int)$f['total_sessions'], $f['term_label'] ?: null];
            if ($existing) {
                $pdo->prepare('UPDATE catechesis_programs SET title=?, category=?, description=?, frequency=?, weekday=?, start_time=?, end_time=?, location=?, total_sessions=?, term_label=? WHERE program_id = ? AND church_id = ?')
                    ->execute([...$vals, $id, $churchId]);
            } else {
                $pdo->prepare('INSERT INTO catechesis_programs (title, category, description, frequency, weekday, start_time, end_time, location, total_sessions, term_label, church_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([...$vals, $churchId]);
                $id = (int)$pdo->lastInsertId();
            }
            $note = '';
            $oldPhoto = $existing['photo'] ?? null;
            if ($photoExt) {
                $dir = __DIR__ . '/uploads/catechesis_photos';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $file = 'program-' . $id . '-' . bin2hex(random_bytes(6)) . '.' . $photoExt;
                if (move_uploaded_file($up['tmp_name'], $dir . '/' . $file)) {
                    $pdo->prepare('UPDATE catechesis_programs SET photo = ? WHERE program_id = ? AND church_id = ?')->execute([$file, $id, $churchId]);
                    cat_delete_photo($oldPhoto);
                } else {
                    $note = ' The photo could not be saved.';
                }
            } elseif ($oldPhoto && !empty($_POST['remove_photo'])) {
                $pdo->prepare('UPDATE catechesis_programs SET photo = NULL WHERE program_id = ? AND church_id = ?')->execute([$id, $churchId]);
                cat_delete_photo($oldPhoto);
            }
            $_SESSION['catechesis_flash'] = ($existing ? 'Program updated.' : 'Program added.') . $note;
            header('Location: catechesis-manage.php');
            exit;
        }
    }
}

function fc2(array $errors, string $k): string { return isset($errors[$k]) ? 'pfield has-error' : 'pfield'; }
function fe2(array $errors, string $k): string { return isset($errors[$k]) ? '<span class="row-error">' . e($errors[$k]) . '</span>' : ''; }

$pageTitle = $existing ? 'Edit Program' : 'New Program';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="sx-page sx-form-page">
    <?php back_button('catechesis-manage.php', 'Back to programs'); ?>
    <header class="page-heading"><h1><?= $existing ? 'Edit Program' : 'New Program' ?></h1></header>

    <div class="sx-shell">
        <form method="post" action="catechesis-program-form.php<?= $existing ? '?id=' . $id : '' ?>" class="sx-form" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <?php if (isset($errors['form'])): ?><div class="alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>

            <section class="sx-section">
                <?php $photoUrl = cat_photo_url($existing['photo'] ?? null); ?>
                <div class="<?= fc2($errors, 'photo') ?> nc-photo-field">
                    <label class="nc-photo" for="cat-photo" id="cat-drop" title="Choose a photo for this program">
                        <img id="cat-preview" alt="" <?= $photoUrl ? 'src="' . e($photoUrl) . '"' : 'hidden' ?>>
                        <span class="nc-photo-empty cat-photo-empty" id="cat-empty" style="background: <?= e(cat_gradient($f['category'])) ?>"<?= $photoUrl ? ' hidden' : '' ?>><span>Add a photo</span></span>
                    </label>
                    <input type="file" id="cat-photo" name="photo" accept="image/jpeg,image/png,image/webp" data-adjust="2:1" class="sr-only-file">
                    <span class="file-note">Optional &middot; JPG, PNG or WebP, up to 3 MB. Click or drop a photo above. Without one, the card shows the category's colours.</span>
                    <?php if ($photoUrl): ?><label class="cat-photo-remove"><input type="checkbox" name="remove_photo" value="1"> Remove the current photo</label><?php endif; ?>
                    <?= fe2($errors, 'photo') ?>
                </div>
                <div class="sx-grid">
                    <div class="<?= fc2($errors, 'title') ?>"><label for="title">Title</label><input type="text" id="title" name="title" maxlength="100" value="<?= e($f['title']) ?>"><?= fe2($errors, 'title') ?></div>
                    <div class="<?= fc2($errors, 'category') ?>"><label for="category">Category</label>
                        <select id="category" name="category"><?php foreach (array_keys(CAT_CATEGORIES) as $c): ?><option value="<?= e($c) ?>" <?= $f['category'] === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select><?= fe2($errors, 'category') ?></div>
                    <div class="<?= fc2($errors, 'description') ?> is-full"><label for="description">Description</label><textarea id="description" name="description" rows="3" maxlength="1000"><?= e($f['description']) ?></textarea></div>
                    <div class="<?= fc2($errors, 'frequency') ?>"><label for="frequency">Meets</label>
                        <select id="frequency" name="frequency"><option value="weekly" <?= $f['frequency'] === 'weekly' ? 'selected' : '' ?>>Every week</option><option value="monthly_last" <?= $f['frequency'] === 'monthly_last' ? 'selected' : '' ?>>The last one of the month</option></select><?= fe2($errors, 'frequency') ?></div>
                    <div class="<?= fc2($errors, 'weekday') ?>"><label for="weekday">On</label>
                        <select id="weekday" name="weekday"><?php foreach (CAT_DAY_NAMES as $i => $d): ?><option value="<?= $i ?>" <?= (string)$i === $f['weekday'] ? 'selected' : '' ?>><?= e($d) ?></option><?php endforeach; ?></select><?= fe2($errors, 'weekday') ?></div>
                    <div class="<?= fc2($errors, 'start_time') ?>"><label for="start_time">Start time <span class="opt">(optional)</span></label><input type="time" id="start_time" name="start_time" value="<?= e($f['start_time']) ?>"><?= fe2($errors, 'start_time') ?></div>
                    <div class="<?= fc2($errors, 'end_time') ?>"><label for="end_time">End time <span class="opt">(optional)</span></label><input type="time" id="end_time" name="end_time" value="<?= e($f['end_time']) ?>"><?= fe2($errors, 'end_time') ?></div>
                    <div class="pfield"><label for="location">Location</label><input type="text" id="location" name="location" maxlength="120" value="<?= e($f['location']) ?>"></div>
                    <div class="<?= fc2($errors, 'total_sessions') ?>"><label for="total_sessions">Lessons in the whole program</label><input type="number" id="total_sessions" name="total_sessions" min="1" max="200" value="<?= e($f['total_sessions']) ?>"><?= fe2($errors, 'total_sessions') ?></div>
                    <div class="pfield"><label for="term_label">Term <span class="opt">(shown after the title, e.g. 2026)</span></label><input type="text" id="term_label" name="term_label" maxlength="30" value="<?= e($f['term_label']) ?>"></div>
                </div>
            </section>

            <div class="sx-actions">
                <a class="sx-draft" href="catechesis-manage.php">Cancel</a>
                <button type="submit" class="reg-submit"><?= $existing ? 'Save' : 'Add program' ?></button>
            </div>
        </form>
    </div>
</main>
<script>
// Photo: click the picture or drop a file on it, and see it straight away.
(function () {
    var input = document.getElementById('cat-photo'), drop = document.getElementById('cat-drop');
    var img = document.getElementById('cat-preview'), empty = document.getElementById('cat-empty');
    function show() {
        var file = input.files[0];
        if (!file || file.type.indexOf('image/') !== 0) return;
        img.src = URL.createObjectURL(file);
        img.hidden = false;
        empty.hidden = true;
    }
    input.addEventListener('change', show);
    ['dragenter', 'dragover'].forEach(function (t) { drop.addEventListener(t, function (ev) { ev.preventDefault(); drop.classList.add('is-drag'); }); });
    ['dragleave', 'drop'].forEach(function (t) { drop.addEventListener(t, function (ev) { ev.preventDefault(); drop.classList.remove('is-drag'); }); });
    drop.addEventListener('drop', function (ev) { if (ev.dataTransfer.files.length) { input.files = ev.dataTransfer.files; input.dispatchEvent(new Event('change', { bubbles: true })); } });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
