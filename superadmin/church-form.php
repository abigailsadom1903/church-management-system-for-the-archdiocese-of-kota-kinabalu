<?php
// Super Admin: add a new church (photo, name, town, priest, contact, address, operating hours, social media).
// Everything else (email, website, map, donation details...) is filled in afterwards on church-information.php.
require __DIR__ . '/../includes/auth.php';
require_role('super_admin');
require __DIR__ . '/../config/db.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

const CHURCH_PHOTO_MAX = 3 * 1024 * 1024;   // same limit as the Church Information page
$photoDir = dirname(__DIR__) . '/uploads/church_photos';

$errors = [];
$f = ['name' => '', 'town' => '', 'priest' => '', 'phone' => '', 'address' => '', 'hours' => '', 'social' => ''];

/** "facebook.com/page" -> "https://facebook.com/page"; null when it is not a web address. */
function social_url(string $v): ?string
{
    if (!preg_match('#^https?://#i', $v)) $v = 'https://' . $v;
    return filter_var($v, FILTER_VALIDATE_URL) ? $v : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($f as $k => $_) $f[$k] = trim(str_replace("\r\n", "\n", (string)($_POST[$k] ?? '')));
    // Operating hours: one line per day / time, no empty lines.
    $f['hours'] = implode("\n", array_filter(array_map('trim', explode("\n", $f['hours'])), fn($l) => $l !== ''));

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        // Same rules as church-information.php.
        if ($f['name'] === '' || mb_strlen($f['name']) > 100) $errors['name'] = 'Enter the church name (up to 100 characters).';
        if ($f['town'] === '' || mb_strlen($f['town']) > 255) $errors['town'] = 'Enter the town.';
        if (mb_strlen($f['priest']) > 100) $errors['priest'] = 'Keep the priest\'s name under 100 characters.';
        if ($f['phone'] !== '' && (mb_strlen($f['phone']) > 50 || !preg_match('/^\+?[0-9][0-9\s\-()\/,]{4,}$/', $f['phone']))) $errors['phone'] = 'Enter a valid phone number.';
        if (mb_strlen($f['address']) > 255) $errors['address'] = 'Keep the address under 255 characters.';
        if (mb_strlen($f['hours']) > 255) $errors['hours'] = 'Keep the operating hours under 255 characters.';
        if ($f['social'] !== '' && (mb_strlen($f['social']) > 150 || social_url($f['social']) === null)) $errors['social'] = 'Enter a web address, e.g. facebook.com/yourparish.';
        if (!isset($errors['name'])) {
            $stmt = $pdo->prepare('SELECT 1 FROM church_info WHERE church_name = ? AND church_location = ?');
            $stmt->execute([$f['name'], $f['town']]);
            if ($stmt->fetch()) $errors['name'] = 'A church with this name already exists in this town.';
        }

        // Photo (optional): checked by its content, not its name.
        $photo = $_FILES['photo'] ?? null;
        $photoExt = null;
        if ($photo && $photo['error'] !== UPLOAD_ERR_NO_FILE) {
            $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
            $info = $photo['error'] === UPLOAD_ERR_OK ? @getimagesize($photo['tmp_name']) : false;
            if ($photo['error'] !== UPLOAD_ERR_OK) $errors['photo'] = 'The photo could not be uploaded. Please try again.';
            elseif ($photo['size'] > CHURCH_PHOTO_MAX) $errors['photo'] = 'The photo must be 3 MB or smaller.';
            elseif (!$info || !isset($types[$info[2]])) $errors['photo'] = 'Please choose a JPG, PNG or WebP image.';
            else $photoExt = $types[$info[2]];
        }

        if (!$errors) {
            $pdo->prepare('INSERT INTO church_info (admin_id, church_name, church_location, church_contact_details, church_address, church_office_hours, church_facebook)
                           VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([(int)$_SESSION['user_id'], $f['name'], $f['town'], $f['phone'] ?: null, $f['address'] ?: null, $f['hours'] ?: null, $f['social'] ?: null]);
            $id = (int)$pdo->lastInsertId();
            // More priests, their titles and photos are added afterwards on the Church Information page.
            if ($f['priest'] !== '') {
                $pdo->prepare('INSERT INTO church_priests (church_id, priest_name, priest_title, sort_order) VALUES (?, ?, ?, 1)')->execute([$id, $f['priest'], 'Parish Priest']);
            }

            $note = '';
            if ($photoExt) {
                // Named like the photos saved on church-information.php (church-<id>-<random>.<ext>).
                if (!is_dir($photoDir)) mkdir($photoDir, 0755, true);
                $file = 'church-' . $id . '-' . bin2hex(random_bytes(6)) . '.' . $photoExt;
                if (move_uploaded_file($photo['tmp_name'], $photoDir . '/' . $file)) {
                    $pdo->prepare('UPDATE church_info SET church_photo = ? WHERE church_info_id = ?')->execute([$file, $id]);
                } else {
                    $note = ' The photo could not be saved; you can add it below.';
                }
            }
            $_SESSION['flash'] = $f['name'] . ' was added.' . $note . ' You can fill in the rest of its information here.';
            header('Location: ../church-information.php?church=' . $id);
            exit;
        }
    }
}

function fc(array $errors, string $key): string { return isset($errors[$key]) ? 'pfield has-error' : 'pfield'; }
function err(array $errors, string $key): void { if (isset($errors[$key])) echo '<span class="row-error">' . e($errors[$key]) . '</span>'; }

$pageTitle = 'New Church';
$roleLabel = 'Super Admin';
include __DIR__ . '/../includes/dashboard_header.php';
?>
<div class="nc-page">
    <a class="back-btn" href="dashboard.php#churches">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
        Back to dashboard
    </a>
    <h1 class="nc-title">New Church</h1>

    <?php if (isset($errors['form'])): ?><div class="alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
    <?php if ($errors && !isset($errors['form'])): ?><div class="alert" role="alert">The church was not added. Please check the highlighted fields.</div><?php endif; ?>

    <form method="post" action="church-form.php" enctype="multipart/form-data" class="panel nc-form" novalidate>
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">

        <div class="<?= fc($errors, 'photo') ?> nc-photo-field">
            <label class="nc-photo" for="nc-photo" id="nc-drop" title="Choose a photo of the church">
                <img id="nc-preview" alt="" hidden>
                <span class="nc-photo-empty" id="nc-empty">
                    <svg viewBox="0 0 200 90" preserveAspectRatio="none" aria-hidden="true"><rect width="200" height="90" fill="#e6f1fb"/><ellipse cx="40" cy="18" rx="12" ry="5" fill="#fff"/><ellipse cx="100" cy="16" rx="17" ry="7" fill="#fff"/><ellipse cx="175" cy="18" rx="8" ry="3" fill="#fff"/><path d="M0 70 Q30 52 60 62 T120 58 T200 62 V90 H0Z" fill="#c4d98a"/><path d="M0 78 Q40 60 80 72 T160 66 T200 70 V90 H0Z" fill="#8aa50f"/></svg>
                    <span>Add a photo</span>
                </span>
            </label>
            <input type="file" id="nc-photo" name="photo" accept="image/jpeg,image/png,image/webp" data-adjust="16:10" class="sr-only-file">
            <span class="file-note">Optional &middot; JPG, PNG or WebP, up to 3 MB. Click or drop a photo above.</span>
            <?php err($errors, 'photo'); ?>
        </div>

        <div class="form-stack">
            <div class="pf-row">
                <div class="<?= fc($errors, 'name') ?>">
                    <label for="name">Church Name</label>
                    <input type="text" id="name" name="name" maxlength="100" value="<?= e($f['name']) ?>" required autofocus>
                    <?php err($errors, 'name'); ?>
                </div>
                <div class="<?= fc($errors, 'town') ?>">
                    <label for="town">Town</label>
                    <input type="text" id="town" name="town" maxlength="255" placeholder="e.g. Kota Kinabalu" value="<?= e($f['town']) ?>" required>
                    <?php err($errors, 'town'); ?>
                </div>
            </div>
            <div class="<?= fc($errors, 'priest') ?>">
                <label for="priest">Church Priest <span class="opt">(optional)</span></label>
                <input type="text" id="priest" name="priest" maxlength="100" placeholder="e.g. Rev. Fr. John Tan" value="<?= e($f['priest']) ?>">
                <?php err($errors, 'priest'); ?>
            </div>
            <div class="<?= fc($errors, 'phone') ?>">
                <label for="phone">Contact <span class="opt">(optional)</span></label>
                <input type="tel" id="phone" name="phone" maxlength="50" placeholder="+60 88-123 456" value="<?= e($f['phone']) ?>">
                <?php err($errors, 'phone'); ?>
            </div>
            <div class="<?= fc($errors, 'address') ?>">
                <label for="address">Address <span class="opt">(optional)</span></label>
                <input type="text" id="address" name="address" maxlength="255" value="<?= e($f['address']) ?>">
                <?php err($errors, 'address'); ?>
            </div>
            <div class="<?= fc($errors, 'hours') ?>">
                <label for="hours">Operating Hours <span class="opt">(optional, one line each)</span></label>
                <textarea id="hours" name="hours" rows="3" maxlength="255" placeholder="Mon - Fri: 8:30 AM - 4:30 PM&#10;Sat: 8:30 AM - 12:30 PM"><?= e($f['hours']) ?></textarea>
                <?php err($errors, 'hours'); ?>
            </div>
            <div class="<?= fc($errors, 'social') ?>">
                <label for="social">Social Media <span class="opt">(optional, e.g. the Facebook page)</span></label>
                <input type="text" id="social" name="social" maxlength="150" placeholder="facebook.com/yourparish" value="<?= e($f['social']) ?>">
                <?php err($errors, 'social'); ?>
            </div>
        </div>

        <div class="profile-actions">
            <a href="dashboard.php#churches" class="btn-cancel">Cancel</a>
            <button type="submit" class="btn-save">Save</button>
        </div>
    </form>
</div>
<script>
// Photo: click the picture or drop a file on it, and see it straight away.
(function () {
    var input = document.getElementById('nc-photo'), drop = document.getElementById('nc-drop');
    var img = document.getElementById('nc-preview'), empty = document.getElementById('nc-empty');
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
<?php include __DIR__ . '/../includes/dashboard_footer.php'; ?>
