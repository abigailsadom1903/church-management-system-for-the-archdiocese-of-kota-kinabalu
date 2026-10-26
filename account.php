<?php
session_start();
require __DIR__ . '/config/db.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Church Admins and the Super Admin manage their own account here (public users use profile.php).
$role = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($role, ['church_admin', 'super_admin'], true)) {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$isSuper = $role === 'super_admin';
$dashboard = $isSuper ? 'superadmin/dashboard.php' : 'churchadmin/dashboard.php';
$adminId = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare('SELECT * FROM admin WHERE admin_id = ?');
$stmt->execute([$adminId]);
$me = $stmt->fetch();
if (!$me) {   // the account was removed while the session was still alive
    session_destroy();
    header('Location: login.php');
    exit;
}

$church = null;
if ($me['church_id']) {
    $stmt = $pdo->prepare('SELECT church_name, church_location FROM church_info WHERE church_info_id = ?');
    $stmt->execute([(int)$me['church_id']]);
    $church = $stmt->fetch();
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$errors = [];
$pwErrors = [];
$old = [
    'name'    => $me['admin_name'],
    'email'   => $me['admin_email'],
    'contact' => (string)$me['admin_contact'],
    'address' => (string)$me['admin_address'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['form'] = 'Your session expired. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'remove_photo') {
        if ($me['admin_photo'] && preg_match('/^[\w.-]+$/', $me['admin_photo'])) @unlink(__DIR__ . '/uploads/admin_photos/' . $me['admin_photo']);
        $pdo->prepare('UPDATE admin SET admin_photo = NULL WHERE admin_id = ?')->execute([$adminId]);
        $_SESSION['flash'] = 'Your photo was removed. ' . ($isSuper ? 'The Catholic Archdiocesan Centre photo is shown instead.' : 'Your church\'s photo is shown instead.');
        header('Location: account.php');
        exit;
    } elseif (($_POST['action'] ?? '') === 'photo') {
        // New profile photo: JPG, PNG or WebP up to 3 MB, checked by its content (same rules as parishioners' photos).
        $up = $_FILES['photo'] ?? null;
        $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
        $info = $up && $up['error'] === UPLOAD_ERR_OK ? @getimagesize($up['tmp_name']) : false;
        if (!$up || $up['error'] === UPLOAD_ERR_NO_FILE) $errors['photo'] = 'Please choose a photo.';
        elseif ($up['error'] !== UPLOAD_ERR_OK) $errors['photo'] = 'The photo could not be uploaded. Please try again.';
        elseif ($up['size'] > 3 * 1024 * 1024) $errors['photo'] = 'The photo must be 3 MB or smaller.';
        elseif (!$info || !isset($types[$info[2]])) $errors['photo'] = 'Please choose a JPG, PNG or WebP image.';
        else {
            $dir = __DIR__ . '/uploads/admin_photos';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $file = 'admin-' . $adminId . '-' . bin2hex(random_bytes(6)) . '.' . $types[$info[2]];
            if (!move_uploaded_file($up['tmp_name'], $dir . '/' . $file)) {
                $errors['photo'] = 'The photo could not be saved.';
            } else {
                $pdo->prepare('UPDATE admin SET admin_photo = ? WHERE admin_id = ?')->execute([$file, $adminId]);
                if ($me['admin_photo'] && preg_match('/^[\w.-]+$/', $me['admin_photo'])) @unlink($dir . '/' . $me['admin_photo']);   // the old one
                $_SESSION['flash'] = 'Your profile photo was updated.';
                header('Location: account.php');
                exit;
            }
        }
    } elseif (($_POST['action'] ?? '') === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if (!password_verify($current, $me['admin_password'])) $pwErrors['current_password'] = 'That is not your current password.';
        if (mb_strlen($new) < 8) $pwErrors['new_password'] = 'Please choose a password of at least 8 characters.';
        if ($new !== $confirm) $pwErrors['confirm_password'] = 'The passwords do not match.';

        if (!$pwErrors) {
            $pdo->prepare('UPDATE admin SET admin_password = ? WHERE admin_id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $adminId]);
            $_SESSION['flash'] = 'Your password has been changed.';
            header('Location: account.php');
            exit;
        }
    } else {
        $old['name']    = trim($_POST['name'] ?? '');
        $old['email']   = trim($_POST['email'] ?? '');
        $old['contact'] = trim($_POST['contact'] ?? '');
        $old['address'] = trim(str_replace("\r\n", "\n", $_POST['address'] ?? ''));

        if ($old['name'] === '' || mb_strlen($old['name']) > 100) {
            $errors['name'] = 'Please enter your full name.';
        }
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($old['email']) > 100) {
            $errors['email'] = 'Please enter a valid email address.';
        } else {
            $dup = $pdo->prepare('SELECT 1 FROM admin WHERE admin_email = ? AND admin_id <> ?');
            $dup->execute([$old['email'], $adminId]);
            if ($dup->fetch()) $errors['email'] = 'Another account already uses this email.';
        }
        if ($old['contact'] !== '' && (mb_strlen($old['contact']) > 50 || !preg_match('/^\+?[0-9][0-9\s\-()]{6,25}$/', $old['contact']))) {
            $errors['contact'] = 'Please enter a valid phone number.';
        }
        if (mb_strlen($old['address']) > 255) {
            $errors['address'] = 'Please keep your address under 255 characters.';
        }

        if (!$errors) {
            $pdo->prepare('UPDATE admin SET admin_name = ?, admin_email = ?, admin_contact = ?, admin_address = ? WHERE admin_id = ?')
                ->execute([$old['name'], $old['email'], $old['contact'] ?: null, $old['address'] ?: null, $adminId]);
            $_SESSION['user_name'] = $old['name'];
            $_SESSION['flash'] = 'Profile updated.';
            header('Location: account.php');
            exit;
        }
    }
}

$name = $old['name'] ?: $me['admin_name'];
$parts = preg_split('/\s+/', trim($name)) ?: [];
$initials = strtoupper(mb_substr($parts[0] ?? '', 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));

$pageTitle = 'Profile Information';
$activeNav = '';
include __DIR__ . '/includes/header.php';

function acc_class(array $errors, string $key): string { return isset($errors[$key]) ? 'pfield has-error' : 'pfield'; }
?>
<main class="profile-page">
    <?php back_button($dashboard, 'Back to dashboard'); ?>
    <header class="page-heading profile-heading">
        <h1>Profile Information</h1>
        <p><?= $isSuper ? 'Your Super Admin account details.' : 'Your account details for ' . e($church['church_name'] ?? 'your parish') . '.' ?></p>
    </header>

    <?php if ($flash): ?><div class="alert success profile-alert" role="status"><?= e($flash) ?></div><?php endif; ?>
    <?php if (isset($errors['form'])): ?><div class="alert profile-alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
    <?php if ($errors && !isset($errors['form'])): ?><div class="alert profile-alert" role="alert">Your changes were not saved. Please check the highlighted fields.</div><?php endif; ?>

    <form method="post" action="account.php" class="profile-card" novalidate>
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">

        <div class="profile-photo">
            <?php $ownPhoto = admin_own_photo($me['admin_photo'] ?? null); ?>
            <?php if ($shownPhoto = admin_profile_photo()): ?>
                <div class="avatar-lg" data-photo-view><img src="<?= e($shownPhoto) ?>" alt="Your profile photo" id="avatar-img"></div>
            <?php else: ?>
                <div class="avatar-lg is-initials" data-photo-view><span><?= e($initials) ?></span><img src="" alt="Your profile photo" id="avatar-img" hidden></div>
            <?php endif; ?>
            <span class="acc-role-badge"><?= $isSuper ? 'Super Admin' : 'Church Admin' ?></span>
            <label class="upload-btn">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/></svg>
                Upload New Photo
                <input type="file" name="photo" id="photo-input" form="photo-form" accept="image/jpeg,image/png,image/webp" data-adjust="1:1" data-adjust-shape="round" hidden>
            </label>
            <?php if ($ownPhoto): ?>
                <button type="submit" form="photo-form" name="action" value="remove_photo" class="remove-photo" formnovalidate data-confirm="Remove your profile photo? <?= $isSuper ? 'The Catholic Archdiocesan Centre photo' : 'Your church&rsquo;s photo' ?> will be shown instead.">Remove photo</button>
            <?php else: ?>
                <small class="acc-photo-note"><?= $isSuper ? 'Showing the Catholic Archdiocesan Centre until you upload your own photo.' : 'Showing your church&rsquo;s photo until you upload your own.' ?></small>
            <?php endif; ?>
            <?php if (isset($errors['photo'])): ?><span class="row-error"><?= e($errors['photo']) ?></span><?php endif; ?>
        </div>

        <div class="profile-grid">
            <div class="profile-col">
                <div class="<?= acc_class($errors, 'name') ?>">
                    <label for="name">Full Name</label>
                    <input type="text" id="name" name="name" maxlength="100" autocomplete="name" value="<?= e($old['name']) ?>" required>
                    <?php if (isset($errors['name'])): ?><span class="row-error"><?= e($errors['name']) ?></span><?php endif; ?>
                </div>
                <div class="<?= acc_class($errors, 'email') ?>">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" maxlength="100" autocomplete="email" value="<?= e($old['email']) ?>" required>
                    <?php if (isset($errors['email'])): ?><span class="row-error"><?= e($errors['email']) ?></span><?php endif; ?>
                </div>
                <?php if (!$isSuper): ?>
                    <div class="pfield">
                        <label>Assigned Church</label>
                        <input type="text" value="<?= e($church['church_name'] ?? 'Not assigned') ?>" disabled>
                    </div>
                <?php endif; ?>
            </div>

            <div class="profile-col">
                <div class="<?= acc_class($errors, 'contact') ?>">
                    <label for="contact">Contact Number</label>
                    <input type="tel" id="contact" name="contact" maxlength="50" autocomplete="tel" value="<?= e($old['contact']) ?>">
                    <?php if (isset($errors['contact'])): ?><span class="row-error"><?= e($errors['contact']) ?></span><?php endif; ?>
                </div>
                <div class="<?= acc_class($errors, 'address') ?>">
                    <label for="address">Address</label>
                    <textarea id="address" name="address" rows="3" maxlength="255" autocomplete="street-address"><?= e($old['address']) ?></textarea>
                    <?php if (isset($errors['address'])): ?><span class="row-error"><?= e($errors['address']) ?></span><?php endif; ?>
                </div>
            </div>
        </div>

        <div class="profile-actions">
            <a href="<?= e($dashboard) ?>" class="btn-cancel">Cancel</a>
            <button type="submit" class="btn-save">Save Changes</button>
        </div>
    </form>

    <section class="profile-card acc-password-card" aria-label="Change password">
        <h2 class="acc-subheading">Change Password</h2>
        <?php if ($pwErrors): ?><div class="alert profile-alert" role="alert">Your password was not changed. Please check the highlighted fields.</div><?php endif; ?>
        <form method="post" action="account.php" class="acc-pw-form" novalidate>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="password">
            <div class="<?= acc_class($pwErrors, 'current_password') ?>">
                <label for="current_password">Current Password</label>
                <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
                <?php if (isset($pwErrors['current_password'])): ?><span class="row-error"><?= e($pwErrors['current_password']) ?></span><?php endif; ?>
            </div>
            <div class="<?= acc_class($pwErrors, 'new_password') ?>">
                <label for="new_password">New Password</label>
                <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="8" required>
                <?php if (isset($pwErrors['new_password'])): ?><span class="row-error"><?= e($pwErrors['new_password']) ?></span><?php endif; ?>
            </div>
            <div class="<?= acc_class($pwErrors, 'confirm_password') ?>">
                <label for="confirm_password">Confirm New Password</label>
                <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" minlength="8" required>
                <?php if (isset($pwErrors['confirm_password'])): ?><span class="row-error"><?= e($pwErrors['confirm_password']) ?></span><?php endif; ?>
            </div>
            <div class="profile-actions">
                <button type="submit" class="btn-save">Change Password</button>
            </div>
        </form>
    </section>
</main>
<form method="post" action="account.php" enctype="multipart/form-data" id="photo-form" hidden>
    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
    <input type="hidden" name="action" value="photo">
</form>
<script>
// Choosing a photo shows it straight away and saves it (nothing else on the page is submitted).
(function () {
    var input = document.getElementById('photo-input');
    var img = document.getElementById('avatar-img');
    if (!input) return;
    input.addEventListener('change', function () {
        var file = input.files[0];
        if (!file) return;
        if (file.size > 3 * 1024 * 1024) { alert('The photo must be 3 MB or smaller.'); input.value = ''; return; }
        if (img) {
            img.src = URL.createObjectURL(file);
            img.hidden = false;
            var initials = img.parentNode.querySelector('span');
            if (initials) initials.remove();
            img.parentNode.classList.remove('is-initials');
        }
        document.getElementById('photo-form').submit();
    });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>

