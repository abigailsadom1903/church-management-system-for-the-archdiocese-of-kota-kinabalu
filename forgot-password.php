<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/password_reset.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        foreach (find_accounts_by_email($pdo, $email) as $account) {
            $code = create_reset_code($pdo, $account['type'], $account['id']);
            if ($code !== null) {
                send_reset_code_email($email, $account, $code);
            }
        }
        // Same result whether or not the email exists, so this page can't be used to discover accounts.
        $_SESSION['reset_email'] = $email;
        $_SESSION['reset_sent_at'] = time();
        header('Location: reset-password.php?sent=1');
        exit;
    }
}

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = 'Forgot Password';
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
        <h1>Forgot Password</h1>
        <p class="subtitle">Enter your email and we'll send you a 6-digit code to reset your password.</p>

        <?php if ($error): ?>
            <div class="alert" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="forgot-password.php" novalidate>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">

            <div class="field">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" placeholder="name@archkk.org" maxlength="100" value="<?= e($email) ?>" autocomplete="email" required autofocus>
            </div>

            <button type="submit" class="btn-primary">Send Reset Code</button>
        </form>
        <?php if (!empty($_SESSION['reset_email'])): ?><p class="switch-auth"><a href="reset-password.php">I already have a code</a></p><?php endif; ?>

        <p class="switch-auth divider"><a href="login.php"><strong>&larr; Back to Sign In</strong></a></p>
    </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
