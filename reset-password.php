<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/password_reset.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// The token arrives in the link (GET) and is carried through the form (POST).
$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$reset = find_valid_reset($pdo, $token);

$error = '';

if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif ($msg = password_rule_error($password)) {
        $error = $msg;
    } elseif ($password !== $confirm) {
        $error = 'The two passwords do not match.';
    } else {
        $table = $reset['account_type'] === 'admin'
            ? ['admin', 'admin_password', 'admin_id']
            : ['user', 'user_password', 'user_id'];

        $pdo->beginTransaction();
        $pdo->prepare("UPDATE {$table[0]} SET {$table[1]} = ? WHERE {$table[2]} = ?")
            ->execute([password_hash($password, PASSWORD_DEFAULT), $reset['account_id']]);
        // Burn every outstanding link for this account.
        $pdo->prepare('DELETE FROM password_resets WHERE account_type = ? AND account_id = ?')
            ->execute([$reset['account_type'], $reset['account_id']]);
        $pdo->commit();

        unset($_SESSION['csrf']);
        header('Location: login.php?reset=1');
        exit;
    }
}

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = 'Reset Password';
$activeNav = 'auth';   // the menu underlines "Login/ Register" here, not Home
include __DIR__ . '/includes/header.php';
?>
<main class="auth-page">
    <section class="auth-brand">
        <img src="assets/images/logo.png" alt="Archdiocese of Kota Kinabalu logo">
        <h2>Church Management System<br>The Archdiocese of Kota Kinabalu</h2>
        <p>United in Faith, Connected in Service</p>
    </section>

    <section class="auth-card serif">
        <?php if (!$reset): ?>
            <h1>Link Expired</h1>
            <p class="subtitle">This reset link is invalid or has expired. Reset links can only be used once.</p>
            <a href="forgot-password.php" class="btn-primary btn-link">Request a New Link</a>
        <?php else: ?>
            <h1>Reset Password</h1>
            <p class="subtitle">Choose a new password for your account.</p>

            <?php if ($error): ?>
                <div class="alert" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" action="reset-password.php" novalidate>
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="token" value="<?= e($token) ?>">

                <div class="field">
                    <label for="password">New Password</label>
                    <input type="password" id="password" name="password" autocomplete="new-password" required autofocus>
                    <span class="hint">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                        Must be at least 8 characters long, contain a number and a special symbol.
                    </span>
                </div>

                <div class="field">
                    <label for="confirm">Confirm New Password</label>
                    <input type="password" id="confirm" name="confirm" autocomplete="new-password" required>
                </div>

                <button type="submit" class="btn-primary">Reset Password</button>
            </form>
        <?php endif; ?>

        <p class="switch-auth divider"><a href="login.php"><strong>&larr; Back to Sign In</strong></a></p>
    </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
