<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/password_reset.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// The email address comes from forgot-password.php (kept in the session); the code is typed here.
$email = (string)($_SESSION['reset_email'] ?? '');
if ($email === '') {
    header('Location: forgot-password.php');
    exit;
}

$error = '';
$notice = !empty($_GET['sent']) ? 'If an account exists for ' . $email . ', we have emailed it a 6-digit code.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif (isset($_POST['resend'])) {
        // The wait is counted in this browser, so the answer is the same whether or not the email has an account.
        $wait = RESET_REQUEST_COOLDOWN_SECONDS - (time() - (int)($_SESSION['reset_sent_at'] ?? 0));
        if ($wait > 0) {
            $error = 'Please wait ' . $wait . ' seconds before asking for another code.';
        } else {
            foreach (find_accounts_by_email($pdo, $email) as $account) {
                if (($code = create_reset_code($pdo, $account['type'], $account['id'])) !== null) {
                    send_reset_code_email($email, $account, $code);
                }
            }
            $_SESSION['reset_sent_at'] = time();
            $notice = 'If an account exists for ' . $email . ', a new code is on its way. Codes from earlier emails no longer work.';
        }
    } else {
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm'] ?? '';
        $code     = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));

        if (strlen($code) !== 6) {
            $error = 'Enter the 6-digit code from the email.';
        } elseif ($msg = password_rule_error($password)) {
            $error = $msg;
        } elseif ($password !== $confirm) {
            $error = 'The two passwords do not match.';
        } elseif (!($reset = find_reset_by_code($pdo, $email, $code))) {
            // one message for a wrong, used-up or expired code, so it never tells whether the email has an account
            $error = 'That code is not right, or it has run out. Check the newest email, or ask for a new code.';
        } else {
            $table = $reset['account_type'] === 'admin'
                ? ['admin', 'admin_password', 'admin_id']
                : ['user', 'user_password', 'user_id'];

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE {$table[0]} SET {$table[1]} = ? WHERE {$table[2]} = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $reset['account_id']]);
            // Burn every outstanding code for this account.
            $pdo->prepare('DELETE FROM password_resets WHERE account_type = ? AND account_id = ?')
                ->execute([$reset['account_type'], $reset['account_id']]);
            $pdo->commit();

            unset($_SESSION['csrf'], $_SESSION['reset_email']);
            header('Location: login.php?reset=1');
            exit;
        }
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
        <h1>Reset Password</h1>
        <p class="subtitle">Enter the 6-digit code we emailed to <strong><?= e($email) ?></strong>, then choose a new password. The code works for <?= RESET_CODE_MINUTES ?> minutes. Check your spam folder if it is not in your inbox.</p>

        <?php if ($notice && !$error): ?><div class="alert success" role="status"><?= e($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endif; ?>

        <form method="post" action="reset-password.php" novalidate>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">

            <div class="field">
                <label for="code">Reset code</label>
                <input type="text" id="code" name="code" class="otp-input" inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" maxlength="6" placeholder="••••••" required autofocus>
            </div>

            <div class="field">
                <label for="password">New Password</label>
                <input type="password" id="password" name="password" autocomplete="new-password" required>
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

        <form method="post" action="reset-password.php" class="otp-resend">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <span>Didn't get it?</span>
            <button type="submit" name="resend" value="1">Send a new code</button>
        </form>
        <p class="switch-auth divider"><a href="forgot-password.php">Use a different email</a> &middot; <a href="login.php"><strong>Back to Sign In</strong></a></p>
    </section>
</main>
<script>
// Digits only in the code box, then on to the password.
(function () {
    var input = document.getElementById('code');
    input.addEventListener('input', function () {
        input.value = input.value.replace(/\D+/g, '').slice(0, 6);
        if (input.value.length === 6) document.getElementById('password').focus();
    });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
