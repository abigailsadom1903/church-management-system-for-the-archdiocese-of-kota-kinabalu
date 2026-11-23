<?php
// Last step of registering with Google: Google gave us the name and a verified email, the person adds
// their phone number and home parish (the site needs both), agrees to the terms, and is signed in.
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/google_auth.php';
require __DIR__ . '/includes/user_mail.php';

$pending = $_SESSION['google_pending'] ?? null;
// Only a fresh Google sign-in may use this page (30 minutes to finish).
if (!is_array($pending) || empty($pending['sub']) || time() - (int)($pending['at'] ?? 0) > 1800) {
    unset($_SESSION['google_pending']);
    $_SESSION['login_error'] = 'Please continue with Google again to finish creating your account.';
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$parishes = $pdo->query('SELECT church_info_id, church_name, church_location FROM church_info ORDER BY church_name')->fetchAll();

$errors = [];
$old = ['name' => $pending['name'], 'phone' => '', 'parish' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'cancel') {
        unset($_SESSION['google_pending']);
        header('Location: register.php');
        exit;
    }
    $old['name']   = trim($_POST['name'] ?? '');
    $old['phone']  = trim($_POST['phone'] ?? '');
    $old['parish'] = (string)($_POST['parish'] ?? '');

    // Same rules as register.php.
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) $errors['form'] = 'Your session expired. Please try again.';
    if ($old['name'] === '' || mb_strlen($old['name']) > 100) $errors['name'] = 'Please enter your full name.';
    if (!preg_match('/^\+?[0-9][0-9\s\-]{6,18}$/', $old['phone'])) $errors['phone'] = 'Please enter a valid phone number.';
    if (!in_array((int)$old['parish'], array_column($parishes, 'church_info_id'), true)) $errors['parish'] = 'Please select your home parish.';
    if (empty($_POST['terms'])) $errors['terms'] = 'You must agree to the Terms of Service and Privacy Policy.';

    if (!$errors) {
        // Someone may have registered this email (or this Google account) since they started.
        $stmt = $pdo->prepare('SELECT 1 FROM user WHERE user_email = ? OR google_sub = ?');
        $stmt->execute([$pending['email'], $pending['sub']]);
        if ($stmt->fetch()) {
            unset($_SESSION['google_pending']);
            $_SESSION['login_error'] = 'An account with this email already exists. Please sign in.';
            header('Location: login.php');
            exit;
        }

        // No password of their own yet: a random one nobody knows. They sign in with Google,
        // or set a password any time with "Forgot password".
        $pdo->prepare('INSERT INTO user (church_id, user_name, user_email, user_password, google_sub, user_phone, user_verified) VALUES (?, ?, ?, ?, ?, ?, 1)')
            ->execute([(int)$old['parish'], $old['name'], $pending['email'], password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $pending['sub'], $old['phone']]);

        $newUserId = (int)$pdo->lastInsertId();
        send_google_welcome_email($pending['email'], $old['name']);   // Google has already verified the address
        google_sign_in(['user_id' => $newUserId, 'user_name' => $old['name'], 'user_photo' => null]);
    }
}

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function fieldClass(array $errors, string $key): string { return isset($errors[$key]) ? 'field has-error' : 'field'; }

$pageTitle = 'Finish Registration';
$activeNav = 'auth';
include __DIR__ . '/includes/header.php';
?>
<main class="auth-page">
    <section class="auth-brand">
        <img src="assets/images/logo.png" alt="Archdiocese of Kota Kinabalu logo">
        <h2>Church Management System<br>The Archdiocese of Kota Kinabalu</h2>
        <p>United in Faith, Connected in Service</p>
    </section>

    <section class="auth-card">
        <h1>Almost there</h1>
        <p class="subtitle">You are registering with your Google account. Tell us your home parish to finish.</p>

        <?php if (isset($errors['form'])): ?><div class="alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>

        <form method="post" action="google-register.php" novalidate>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">

            <div class="field">
                <label for="g-email">Email Address</label>
                <input type="email" id="g-email" value="<?= e($pending['email']) ?>" readonly aria-describedby="g-email-note">
                <span class="google-note" id="g-email-note">Verified by Google</span>
            </div>

            <div class="field-row">
                <div class="<?= fieldClass($errors, 'name') ?>">
                    <label for="name">Full Name</label>
                    <input type="text" id="name" name="name" maxlength="100" value="<?= e($old['name']) ?>" required>
                    <?php if (isset($errors['name'])): ?><span class="error-text"><?= e($errors['name']) ?></span><?php endif; ?>
                </div>
                <div class="<?= fieldClass($errors, 'phone') ?>">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" placeholder="+60 12-345 6789" maxlength="20" value="<?= e($old['phone']) ?>" required autofocus>
                    <?php if (isset($errors['phone'])): ?><span class="error-text"><?= e($errors['phone']) ?></span><?php endif; ?>
                </div>
            </div>

            <div class="<?= fieldClass($errors, 'parish') ?>">
                <label for="parish">Home Parish</label>
                <select id="parish" name="parish" required>
                    <option value="">Select your primary parish</option>
                    <?php foreach ($parishes as $p): ?>
                        <option value="<?= (int)$p['church_info_id'] ?>" <?= $old['parish'] === (string)$p['church_info_id'] ? 'selected' : '' ?>>
                            <?= e($p['church_name']) ?> &ndash; <?= e($p['church_location']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['parish'])): ?><span class="error-text"><?= e($errors['parish']) ?></span><?php endif; ?>
            </div>

            <label class="terms <?= isset($errors['terms']) ? 'has-error' : '' ?>">
                <input type="checkbox" name="terms" value="1" <?= !empty($_POST['terms']) ? 'checked' : '' ?>>
                <span>I agree to the Archdiocese <a href="terms.php">Terms of Service</a> and <a href="privacy.php">Privacy Policy</a>.</span>
            </label>
            <?php if (isset($errors['terms'])): ?><p class="error-text terms-error"><?= e($errors['terms']) ?></p><?php endif; ?>

            <button type="submit" class="btn-primary">Create Account</button>
            <p class="switch-auth"><button type="submit" name="action" value="cancel" class="link-btn" formnovalidate>Cancel and use a different method</button></p>
        </form>
    </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
