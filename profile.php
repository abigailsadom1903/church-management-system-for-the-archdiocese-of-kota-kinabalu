<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/user_mail.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Only signed-in public users have a profile.
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'user') {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$userId = (int)$_SESSION['user_id'];
$stmt = $pdo->prepare('SELECT * FROM user WHERE user_id = ?');
$stmt->execute([$userId]);
$me = $stmt->fetch();
if (!$me) {   // account was deleted while the session was still alive
    session_destroy();
    header('Location: login.php');
    exit;
}

$parishes = $pdo->query('SELECT church_info_id, church_name, church_location FROM church_info ORDER BY church_name')->fetchAll();

function delete_profile_photo(?string $file): void
{
    if ($file && preg_match('/^[\w.-]+$/', $file)) {
        $path = __DIR__ . '/uploads/profile_photos/' . $file;   // only ever removes files we stored
        if (is_file($path)) @unlink($path);
    }
}

$flash  = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$errors = [];
$old = [
    'name'    => $me['user_name'],
    'email'   => $me['user_email'],
    'phone'   => (string)$me['user_phone'],
    'dob'     => (string)$me['date_of_birth'],
    'parish'  => (string)$me['church_id'],
    'address' => (string)$me['user_address'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['form'] = 'Your session expired. Please try again.';
    } elseif (isset($_POST['remove_photo'])) {
        delete_profile_photo($me['user_photo']);
        $pdo->prepare('UPDATE user SET user_photo = NULL WHERE user_id = ?')->execute([$userId]);
        $_SESSION['user_photo'] = null;
        $_SESSION['flash'] = 'Photo removed.';
        header('Location: profile.php');
        exit;
    } elseif (isset($_POST['resend_verification'])) {
        // At most one verification email a minute.
        if (!$me['user_verified'] && time() - (int)($_SESSION['verify_sent_at'] ?? 0) >= 60) {
            send_welcome_email($pdo, $userId, $me['user_email'], $me['user_name']);
            $_SESSION['verify_sent_at'] = time();
        }
        $_SESSION['flash'] = 'We have sent a verification link to ' . $me['user_email'] . '.';
        header('Location: profile.php');
        exit;
    } else {
        $old['name']    = trim($_POST['name'] ?? '');
        $old['email']   = trim($_POST['email'] ?? '');
        $old['phone']   = trim($_POST['phone'] ?? '');
        $old['dob']     = trim($_POST['dob'] ?? '');
        $old['parish']  = (string)($_POST['parish'] ?? '');
        $old['address'] = trim(str_replace("\r\n", "\n", $_POST['address'] ?? ''));

        if ($old['name'] === '' || mb_strlen($old['name']) > 100) {
            $errors['name'] = 'Please enter your full name.';
        }
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($old['email']) > 100) {
            $errors['email'] = 'Please enter a valid email address.';
        } else {
            $dup = $pdo->prepare('SELECT 1 FROM user WHERE user_email = ? AND user_id <> ?');
            $dup->execute([$old['email'], $userId]);
            if ($dup->fetch()) $errors['email'] = 'Another account already uses this email.';
        }
        if (mb_strlen($old['phone']) > 20 || !preg_match('/^\+?[0-9][0-9\s\-]{6,18}$/', $old['phone'])) {
            $errors['phone'] = 'Please enter a valid phone number.';
        }
        if ($old['dob'] !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $old['dob']);
            if (!$d || $d->format('Y-m-d') !== $old['dob'] || $old['dob'] > date('Y-m-d') || $old['dob'] < '1900-01-01') {
                $errors['dob'] = 'Please enter a valid date of birth.';
            }
        }
        if (!in_array((int)$old['parish'], array_column($parishes, 'church_info_id'), true)) {
            $errors['parish'] = 'Please select your home parish.';
        }
        if (mb_strlen($old['address']) > 255) {
            $errors['address'] = 'Please keep your address under 255 characters.';
        }

        // Photo upload ("Upload New Photo")
        $newPhoto = null;
        if (!empty($_FILES['photo']['name'])) {
            $f = $_FILES['photo'];
            $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
            $info = $f['error'] === UPLOAD_ERR_OK ? @getimagesize($f['tmp_name']) : false;
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $errors['photo'] = 'The photo could not be uploaded. Please try again.';
            } elseif ($f['size'] > 3 * 1024 * 1024) {
                $errors['photo'] = 'The photo must be 3 MB or smaller.';
            } elseif (!$info || !isset($types[$info[2]])) {
                $errors['photo'] = 'Please choose a JPG, PNG or WebP image.';
            } elseif (!$errors) {
                $dir = __DIR__ . '/uploads/profile_photos';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $newPhoto = 'user-' . $userId . '-' . bin2hex(random_bytes(6)) . '.' . $types[$info[2]];
                if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $newPhoto)) {
                    $newPhoto = null;
                    $errors['photo'] = 'The photo could not be saved.';
                }
            }
        }

        if (!$errors) {
            $pdo->prepare(
                'UPDATE user SET user_name = ?, user_email = ?, user_phone = ?, date_of_birth = ?, church_id = ?, user_address = ?'
                . ($newPhoto ? ', user_photo = ?' : '') . ' WHERE user_id = ?'
            )->execute(array_merge(
                [$old['name'], $old['email'], $old['phone'], $old['dob'] ?: null, (int)$old['parish'], $old['address'] ?: null],
                $newPhoto ? [$newPhoto] : [],
                [$userId]
            ));
            if (strcasecmp($old['email'], (string)$me['user_email']) !== 0) {
                // A new address has to be verified again.
                $pdo->prepare('UPDATE user SET user_verified = 0 WHERE user_id = ?')->execute([$userId]);
                send_welcome_email($pdo, $userId, $old['email'], $old['name']);
            }
            // Moving home parish: carry this parishioner's still-open requests to the new church, so its admin
            // (not the old one) can see and continue them. Confession bookings stay put — they're tied to an
            // actual slot at a specific church, not to the parishioner's home parish.
            if ((int)$old['parish'] !== (int)$me['church_id']) {
                $newChurch = (int)$old['parish'];
                $pdo->prepare("UPDATE sacrament_applications SET church_id = ? WHERE user_id = ? AND sacrament <> 'confession' AND status IN ('draft','submitted','verifying','review')")
                    ->execute([$newChurch, $userId]);
                $pdo->prepare("UPDATE pastoral_requests SET church_id = ? WHERE user_id = ? AND status IN ('draft','submitted','review')")
                    ->execute([$newChurch, $userId]);
            }
            if ($newPhoto) {
                delete_profile_photo($me['user_photo']);
                $_SESSION['user_photo'] = $newPhoto;
            }
            $_SESSION['user_name'] = $old['name'];
            $_SESSION['flash'] = 'Profile updated.';
            header('Location: profile.php');
            exit;
        }
        if ($newPhoto) @unlink(__DIR__ . '/uploads/profile_photos/' . $newPhoto);   // don't keep a photo from a failed save
    }
}

$photo = $me['user_photo'] && preg_match('/^[\w.-]+$/', $me['user_photo']) && is_file(__DIR__ . '/uploads/profile_photos/' . $me['user_photo'])
    ? 'uploads/profile_photos/' . $me['user_photo'] : null;
$_SESSION['user_photo'] = $photo ? $me['user_photo'] : null;   // keep the header avatar in step with what is on disk

$pageTitle = 'Profile Information';
$activeNav = '';
include __DIR__ . '/includes/header.php';

function pf_class(array $errors, string $key): string { return isset($errors[$key]) ? 'pfield has-error' : 'pfield'; }
?>
<main class="profile-page">
    <div class="profile-topbar">
        <?php back_button('index.php', 'Back to Home'); ?>
        <?php signout_button('signout.php?next=profile.php'); ?>
    </div>
    <header class="page-heading profile-heading">
        <h1>Profile Information</h1>
        <p>Keep your details up to date so your parish can reach you.</p>
    </header>

    <?php if ($flash): ?><div class="alert success profile-alert" role="status"><?= e($flash) ?></div><?php endif; ?>
    <?php if (isset($errors['form'])): ?><div class="alert profile-alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
    <?php if ($errors && !isset($errors['form'])): ?><div class="alert profile-alert" role="alert">Your changes were not saved. Please check the highlighted fields.</div><?php endif; ?>
    <?php if (!$me['user_verified']): ?>
        <form method="post" action="profile.php" class="verify-note profile-alert" role="status">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <span><strong>Your email is not verified yet.</strong> Open the link we sent to <?= e($me['user_email']) ?>.</span>
            <button type="submit" name="resend_verification" value="1">Send the link again</button>
        </form>
    <?php endif; ?>

    <form method="post" action="profile.php" enctype="multipart/form-data" class="profile-card" id="profile-form" novalidate>
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">

        <div class="profile-photo">
            <div class="avatar-lg" data-photo-view>
                <?php if ($photo): ?>
                    <img src="<?= e($photo) ?>" alt="Your profile photo" id="avatar-img">
                <?php else: ?>
                    <svg viewBox="0 0 40 40" aria-hidden="true" id="avatar-default"><circle cx="20" cy="15" r="7.5" fill="#6b6b76"/><path d="M4.5 35c2.6-7 8.4-10 15.500-10s12.900 3 15.500 10A19 19 0 0 1 20 40 19 19 0 0 1 4.500 35z" fill="#6b6b76"/></svg>
                    <img src="" alt="Your profile photo" id="avatar-img" hidden>
                <?php endif; ?>
            </div>
            <label class="upload-btn">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/></svg>
                Upload New Photo
                <input type="file" name="photo" id="photo-input" accept="image/jpeg,image/png,image/webp" data-adjust="1:1" data-adjust-shape="round" hidden>
            </label>
            <?php if ($photo): ?>
                <button type="submit" name="remove_photo" value="1" class="remove-photo" formnovalidate data-confirm="Remove your profile photo? This cannot be undone.">Remove photo</button>
            <?php endif; ?>
            <?php if (isset($errors['photo'])): ?><span class="row-error"><?= e($errors['photo']) ?></span><?php endif; ?>
        </div>

        <div class="profile-grid">
            <div class="profile-col">
                <div class="<?= pf_class($errors, 'name') ?>">
                    <label for="name">Full Name</label>
                    <input type="text" id="name" name="name" maxlength="100" autocomplete="name" value="<?= e($old['name']) ?>" required>
                    <?php if (isset($errors['name'])): ?><span class="row-error"><?= e($errors['name']) ?></span><?php endif; ?>
                </div>
                <div class="<?= pf_class($errors, 'email') ?>">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" maxlength="100" autocomplete="email" value="<?= e($old['email']) ?>" required>
                    <?php if (isset($errors['email'])): ?><span class="row-error"><?= e($errors['email']) ?></span><?php endif; ?>
                </div>
                <div class="<?= pf_class($errors, 'parish') ?>">
                    <label for="parish">Home Parish</label>
                    <select id="parish" name="parish" required>
                        <option value="">Select your primary parish</option>
                        <?php foreach ($parishes as $p): ?>
                            <option value="<?= (int)$p['church_info_id'] ?>" <?= $old['parish'] === (string)$p['church_info_id'] ? 'selected' : '' ?>>
                                <?= e($p['church_name']) ?> &ndash; <?= e($p['church_location']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['parish'])): ?><span class="row-error"><?= e($errors['parish']) ?></span><?php endif; ?>
                </div>
                <div class="<?= pf_class($errors, 'address') ?>">
                    <label for="address">Home Address</label>
                    <textarea id="address" name="address" rows="3" maxlength="255" autocomplete="street-address"><?= e($old['address']) ?></textarea>
                    <?php if (isset($errors['address'])): ?><span class="row-error"><?= e($errors['address']) ?></span><?php endif; ?>
                </div>
            </div>

            <div class="profile-col">
                <div class="<?= pf_class($errors, 'phone') ?>">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" maxlength="20" autocomplete="tel" value="<?= e($old['phone']) ?>" required>
                    <?php if (isset($errors['phone'])): ?><span class="row-error"><?= e($errors['phone']) ?></span><?php endif; ?>
                </div>
                <div class="<?= pf_class($errors, 'dob') ?>">
                    <label for="dob">Date of Birth</label>
                    <input type="date" id="dob" name="dob" min="1900-01-01" max="<?= date('Y-m-d') ?>" autocomplete="bday" value="<?= e($old['dob']) ?>">
                    <?php if (isset($errors['dob'])): ?><span class="row-error"><?= e($errors['dob']) ?></span><?php endif; ?>
                </div>
            </div>
        </div>

        <div class="profile-actions">
            <a href="profile.php" class="btn-cancel">Cancel</a>
            <button type="submit" class="btn-save">Save Changes</button>
        </div>
    </form>

</main>
<script>
(function () {
    var input = document.getElementById('photo-input');
    var img = document.getElementById('avatar-img');
    var fallback = document.getElementById('avatar-default');

    // Preview the chosen photo before saving.
    input.addEventListener('change', function () {
        var file = input.files[0];
        if (!file) return;
        if (file.size > 3 * 1024 * 1024) { alert('The photo must be 3 MB or smaller.'); input.value = ''; return; }
        var reader = new FileReader();
        reader.onload = function (ev) {
            img.src = ev.target.result;
            img.hidden = false;
            if (fallback) fallback.style.display = 'none';
        };
        reader.readAsDataURL(file);
    });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
