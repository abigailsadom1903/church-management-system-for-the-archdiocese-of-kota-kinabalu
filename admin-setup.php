<?php
// An admin opens the one-time link the Super Admin sent them (superadmin/admins.php) and chooses their password.
// Used for new admin accounts and for resetting a forgotten admin password. The link works once.
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/admin_invite.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$admin = admin_invite_find($pdo, $token);
$errors = [];

if ($admin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm'] ?? '');
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['form'] = 'Your session expired. Please try again.';
    } elseif (strlen($password) < 8 || !preg_match('/\d/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors['password'] = 'Use at least 8 characters, with a number and a special symbol.';
    } elseif ($password !== $confirm) {
        $errors['confirm'] = 'The two passwords do not match.';
    } else {
        // Save it and use up the link, then sign them straight in.
        $pdo->prepare('UPDATE admin SET admin_password = ?, invite_token_hash = NULL, invite_expires = NULL WHERE admin_id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$admin['admin_id']]);
        session_regenerate_id(true);
        $_SESSION['csrf']      = bin2hex(random_bytes(32));
        $_SESSION['user_id']   = (int)$admin['admin_id'];
        $_SESSION['user_name'] = $admin['admin_name'];
        $_SESSION['role']      = $admin['admin_role'];
        if ($admin['admin_role'] === 'church_admin') $_SESSION['church_id'] = $admin['church_id'] !== null ? (int)$admin['church_id'] : null;
        header('Location: ' . ($admin['admin_role'] === 'super_admin' ? 'superadmin/dashboard.php' : 'churchadmin/dashboard.php'));
        exit;
    }
}

$church = null;
if ($admin && $admin['church_id']) {
    $stmt = $pdo->prepare('SELECT church_name FROM church_info WHERE church_info_id = ?');
    $stmt->execute([(int)$admin['church_id']]);
    $church = $stmt->fetchColumn() ?: null;
}

$pageTitle = 'Set Your Password';
$activeNav = 'auth';
include __DIR__ . '/includes/header.php';
?>
<main class="auth-page">
    <section class="auth-brand">
        <img src="assets/images/logo.png" alt="Archdiocese of Kota Kinabalu logo">
        <h2>Church Management System<br>The Archdiocese of Kota Kinabalu</h2>
        <p>United in Faith, Connected in Service</p>
    </section>

    <section class="auth-card serif">
        <?php if (!$admin): ?>
            <h1>Link not valid</h1>
            <p class="subtitle">This link has already been used, has expired, or is not complete. Links work once and for <?= ADMIN_INVITE_HOURS ?> hours.</p>
            <div class="alert" role="alert">Please ask the Super Admin for a new link.</div>
            <p class="switch-auth"><a href="login.php"><strong>Go to Sign In</strong></a></p>
        <?php else: ?>
            <h1>Set your password</h1>
            <p class="subtitle">Hello <?= e($admin['admin_name']) ?>. Choose the password for your <?= $admin['admin_role'] === 'super_admin' ? 'Super Admin' : 'Church Admin' ?> account<?= $church ? ' for ' . e($church) : '' ?>.</p>
            <?php if (isset($errors['form'])): ?><div class="alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>

            <form method="post" action="admin-setup.php" novalidate>
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div class="field">
                    <label for="as-email">Email Address</label>
                    <input type="email" id="as-email" value="<?= e($admin['admin_email']) ?>" readonly>
                </div>
                <div class="<?= isset($errors['password']) ? 'field has-error' : 'field' ?>">
                    <label for="password">New Password</label>
                    <input type="password" id="password" name="password" autocomplete="new-password" required autofocus>
                    <?php if (isset($errors['password'])): ?><span class="error-text"><?= e($errors['password']) ?></span><?php endif; ?>
                </div>
                <div class="<?= isset($errors['confirm']) ? 'field has-error' : 'field' ?>">
                    <label for="confirm">Confirm Password</label>
                    <input type="password" id="confirm" name="confirm" autocomplete="new-password" required>
                    <?php if (isset($errors['confirm'])): ?><span class="error-text"><?= e($errors['confirm']) ?></span><?php endif; ?>
                </div>
                <p class="subtitle">At least 8 characters, with a number and a special symbol.</p>
                <button type="submit" class="btn-primary">Save password &amp; sign in</button>
            </form>
        <?php endif; ?>
    </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
