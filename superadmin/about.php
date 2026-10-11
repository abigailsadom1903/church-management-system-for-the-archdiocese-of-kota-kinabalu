<?php
// Super Admin: edit the public "About Us" section (photo, heading, text, contact details, map)
// and, with the same contact details, the footer.
require __DIR__ . '/../includes/auth.php';
require_role('super_admin');
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/site_content.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

const ABOUT_PHOTO_MAX = 5 * 1024 * 1024;
$uploadDir = dirname(__DIR__) . '/uploads/site';

$saved = site_content();
$f = $saved;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['about_eyebrow', 'about_title', 'about_body', 'contact_address', 'contact_phone', 'contact_fax', 'contact_email', 'map_query'] as $k) {
        $f[$k] = trim(str_replace("\r\n", "\n", (string)($_POST[$k] ?? '')));
    }

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        if (mb_strlen($f['about_eyebrow']) > 60) $errors['about_eyebrow'] = 'Keep the small heading under 60 characters.';
        if ($f['about_title'] === '' || mb_strlen($f['about_title']) > 150) $errors['about_title'] = 'Enter a heading (up to 150 characters).';
        if ($f['about_body'] === '' || mb_strlen($f['about_body']) > 3000) $errors['about_body'] = 'Enter the text (up to 3,000 characters).';
        if ($f['contact_address'] === '' || mb_strlen($f['contact_address']) > 300) $errors['contact_address'] = 'Enter the address (up to 300 characters).';
        if (mb_strlen($f['contact_phone']) > 100) $errors['contact_phone'] = 'Keep the telephone numbers under 100 characters.';
        if (mb_strlen($f['contact_fax']) > 60) $errors['contact_fax'] = 'Keep the fax number under 60 characters.';
        if ($f['contact_email'] !== '' && (!filter_var($f['contact_email'], FILTER_VALIDATE_EMAIL) || mb_strlen($f['contact_email']) > 100)) $errors['contact_email'] = 'Enter a valid email address, or leave it empty.';
        if (mb_strlen($f['map_query']) > 300) $errors['map_query'] = 'Keep the map location under 300 characters.';

        // New photo (optional): JPG, PNG or WebP, checked by its content, not its name.
        $newPhoto = null;
        $up = $_FILES['photo'] ?? null;
        if (!$errors && $up && $up['error'] !== UPLOAD_ERR_NO_FILE) {
            $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
            $info = $up['error'] === UPLOAD_ERR_OK ? @getimagesize($up['tmp_name']) : false;
            if ($up['error'] !== UPLOAD_ERR_OK) $errors['photo'] = 'The photo could not be uploaded. Please try again.';
            elseif ($up['size'] > ABOUT_PHOTO_MAX) $errors['photo'] = 'The photo must be 5 MB or smaller.';
            elseif (!$info || !isset($types[$info[2]])) $errors['photo'] = 'Please choose a JPG, PNG or WebP image.';
            else {
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $newPhoto = 'about-' . bin2hex(random_bytes(6)) . '.' . $types[$info[2]];
                if (!move_uploaded_file($up['tmp_name'], $uploadDir . '/' . $newPhoto)) {
                    $newPhoto = null;
                    $errors['photo'] = 'The photo could not be saved.';
                }
            }
        }

        if (!$errors) {
            $f['about_photo'] = $newPhoto ?? (!empty($_POST['reset_photo']) ? '' : $saved['about_photo']);
            $stmt = $pdo->prepare('INSERT INTO site_content (content_key, content_value, updated_by) VALUES (?, ?, ?)
                                   ON DUPLICATE KEY UPDATE content_value = VALUES(content_value), updated_by = VALUES(updated_by)');
            $pdo->beginTransaction();
            foreach (SITE_CONTENT_DEFAULTS as $k => $_) {
                $stmt->execute([$k, $f[$k], (int)$_SESSION['user_id']]);
            }
            $pdo->commit();
            // The replaced upload is no longer used by anything.
            $old = $saved['about_photo'];
            if ($old !== '' && $old !== $f['about_photo'] && preg_match('/^[\w.-]+$/', $old)) @unlink($uploadDir . '/' . $old);
            header('Location: ../index.php?about_saved=1#about');
            exit;
        }
        if ($newPhoto) @unlink($uploadDir . '/' . $newPhoto);   // don't keep an upload from a failed save
    }
}

function fc(array $errors, string $key): string { return isset($errors[$key]) ? 'pfield has-error' : 'pfield'; }
function err(array $errors, string $key): void { if (isset($errors[$key])) echo '<span class="row-error">' . e($errors[$key]) . '</span>'; }

$pageTitle = 'Edit About Us';
$roleLabel = 'Super Admin';
include __DIR__ . '/../includes/dashboard_header.php';
?>
<h1 class="dash-title">Edit About Us</h1>
<p class="dash-sub">The &ldquo;About Us&rdquo; section of the public site. The telephone and email also appear in the footer of every page.
    <a href="../index.php#about">View it on the site</a></p>

<?php if (isset($errors['form'])): ?><div class="alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
<?php if ($errors && !isset($errors['form'])): ?><div class="alert" role="alert">Nothing was saved. Please check the highlighted fields.</div><?php endif; ?>

<form method="post" action="about.php" enctype="multipart/form-data" class="about-editor" novalidate>
    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">

    <section class="panel">
        <h2>Who we are</h2>
        <div class="about-editor-top">
            <div class="<?= fc($errors, 'photo') ?> about-editor-photo">
                <label for="photo">Photo</label>
                <img src="../<?= e(site_about_photo()) ?>" alt="Current photo" id="photo-preview">
                <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp" data-adjust="4:3">
                <span class="file-note">JPG, PNG or WebP, up to 5 MB. A wide photo looks best.</span>
                <?php if ($saved['about_photo'] !== ''): ?>
                    <label class="check-row"><input type="checkbox" name="reset_photo" value="1"><span>Go back to the original photo</span></label>
                <?php endif; ?>
                <?php err($errors, 'photo'); ?>
            </div>
            <div class="form-stack">
                <div class="<?= fc($errors, 'about_eyebrow') ?>">
                    <label for="about_eyebrow">Small heading <span class="opt">(optional)</span></label>
                    <input type="text" id="about_eyebrow" name="about_eyebrow" maxlength="60" value="<?= e($f['about_eyebrow']) ?>">
                    <?php err($errors, 'about_eyebrow'); ?>
                </div>
                <div class="<?= fc($errors, 'about_title') ?>">
                    <label for="about_title">Heading</label>
                    <input type="text" id="about_title" name="about_title" maxlength="150" value="<?= e($f['about_title']) ?>" required>
                    <?php err($errors, 'about_title'); ?>
                </div>
                <div class="<?= fc($errors, 'about_body') ?>">
                    <label for="about_body">Text</label>
                    <textarea id="about_body" name="about_body" rows="6" maxlength="3000" required><?= e($f['about_body']) ?></textarea>
                    <span class="file-note">Leave an empty line between paragraphs.</span>
                    <?php err($errors, 'about_body'); ?>
                </div>
            </div>
        </div>
    </section>

    <section class="panel">
        <h2>Contact</h2>
        <div class="form-stack">
            <div class="<?= fc($errors, 'contact_address') ?>">
                <label for="contact_address">Address</label>
                <textarea id="contact_address" name="contact_address" rows="3" maxlength="300" required><?= e($f['contact_address']) ?></textarea>
                <?php err($errors, 'contact_address'); ?>
            </div>
            <div class="pf-row">
                <div class="<?= fc($errors, 'contact_phone') ?>">
                    <label for="contact_phone">Telephone <span class="opt">(optional)</span></label>
                    <input type="text" id="contact_phone" name="contact_phone" maxlength="100" value="<?= e($f['contact_phone']) ?>">
                    <?php err($errors, 'contact_phone'); ?>
                </div>
                <div class="<?= fc($errors, 'contact_fax') ?>">
                    <label for="contact_fax">Fax <span class="opt">(optional)</span></label>
                    <input type="text" id="contact_fax" name="contact_fax" maxlength="60" value="<?= e($f['contact_fax']) ?>">
                    <?php err($errors, 'contact_fax'); ?>
                </div>
            </div>
            <div class="<?= fc($errors, 'contact_email') ?>">
                <label for="contact_email">Email <span class="opt">(optional)</span></label>
                <input type="email" id="contact_email" name="contact_email" maxlength="100" value="<?= e($f['contact_email']) ?>">
                <?php err($errors, 'contact_email'); ?>
            </div>
            <div class="<?= fc($errors, 'map_query') ?>">
                <label for="map_query">Map location <span class="opt">(what Google Maps searches for)</span></label>
                <input type="text" id="map_query" name="map_query" maxlength="300" value="<?= e($f['map_query']) ?>">
                <span class="file-note">A place name or full address. Leave it empty to use the address above.</span>
                <?php err($errors, 'map_query'); ?>
            </div>
        </div>
    </section>

    <div class="profile-actions">
        <a href="../index.php#about" class="btn-cancel">Cancel</a>
        <button type="submit" class="btn-save">Save changes</button>
    </div>
</form>
<script>
// Show the chosen photo straight away.
document.getElementById('photo').addEventListener('change', function () {
    var file = this.files[0];
    if (file && file.type.indexOf('image/') === 0) document.getElementById('photo-preview').src = URL.createObjectURL(file);
});
</script>
<?php include __DIR__ . '/../includes/dashboard_footer.php'; ?>
