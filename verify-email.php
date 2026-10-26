<?php
// Email verification: the parishioner types the 6-digit code from their email (see includes/user_mail.php).
// It works for the account just registered in this browser (register.php sends them here) or for the
// signed-in parishioner (from their profile).
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/user_mail.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$signedIn = !empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'user';
$userId = $signedIn ? (int)$_SESSION['user_id'] : (int)($_SESSION['verify_user_id'] ?? 0);

$me = null;
if ($userId) {
    $stmt = $pdo->prepare('SELECT user_id, user_name, user_email, user_verified FROM user WHERE user_id = ?');
    $stmt->execute([$userId]);
    $me = $stmt->fetch() ?: null;
}

/** a***l@gmail.com: enough to recognise the address, without showing all of it */
function mask_email(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    $shown = mb_strlen($local) <= 2 ? mb_substr($local, 0, 1) . '*' : mb_substr($local, 0, 1) . str_repeat('*', min(6, mb_strlen($local) - 2)) . mb_substr($local, -1);
    return $shown . '@' . $domain;
}

$error = '';
$notice = '';
$done = false;
if (!empty($_GET['sent'])) $notice = 'We have emailed you a 6-digit code.';

if ($me && !$me['user_verified'] && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif (isset($_POST['resend'])) {
        // At most one code a minute (shared with the button on the profile page).
        $wait = 60 - (time() - (int)($_SESSION['verify_sent_at'] ?? 0));
        if ($wait > 0) {
            $error = 'Please wait ' . $wait . ' seconds before asking for another code.';
        } elseif (send_verification_code($pdo, (int)$me['user_id'], $me['user_email'], $me['user_name'])) {
            $_SESSION['verify_sent_at'] = time();
            $notice = 'A new code is on its way. Codes from earlier emails no longer work.';
        } else {
            $error = 'The email could not be sent just now. Please try again in a moment.';
        }
    } else {
        $code = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));
        $result = verify_email_code($pdo, (int)$me['user_id'], $code);
        if ($result === 'ok') {
            unset($_SESSION['verify_user_id']);
            $done = true;
        } else {
            $error = [
                'wrong'   => 'That code is not right. Please check the email and try again.',
                'locked'  => 'Too many wrong tries. Please ask for a new code.',
                'expired' => 'That code has run out. Please ask for a new code.',
                'none'    => 'Please ask for a code first.',
            ][$result];
        }
    }
}

$pageTitle = 'Verify Email';
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
        <h1>Verify Email</h1>

        <?php if (!$me): ?>
            <p class="subtitle">Sign in to verify your email address. You can ask for a code from your profile page.</p>
            <a class="btn-primary otp-btn-link" href="login.php">Sign in</a>
        <?php elseif ($done || $me['user_verified']): ?>
            <div class="alert success" role="status"><?= $done ? 'Thank you, ' . e($me['user_name']) . '. ' : '' ?><strong><?= e($me['user_email']) ?></strong> is verified.</div>
            <a class="btn-primary otp-btn-link" href="<?= $signedIn ? 'profile.php' : 'login.php' ?>"><?= $signedIn ? 'Go to my profile' : 'Sign in' ?></a>
        <?php else: ?>
            <p class="subtitle">Enter the 6-digit code we sent to <strong><?= e(mask_email($me['user_email'])) ?></strong>. It works for <?= VERIFY_CODE_MINUTES ?> minutes. Check your spam folder if it is not in your inbox.</p>
            <?php if ($notice && !$error): ?><div class="alert success" role="status"><?= e($notice) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endif; ?>

            <form method="post" action="verify-email.php" novalidate>
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <div class="field<?= $error && !isset($_POST['resend']) ? ' has-error' : '' ?>">
                    <label for="code">Verification code</label>
                    <input type="text" id="code" name="code" class="otp-input" inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" maxlength="6" placeholder="••••••" required autofocus>
                </div>
                <button type="submit" class="btn-primary">Verify</button>
            </form>

            <form method="post" action="verify-email.php" class="otp-resend">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <span>Didn't get it?</span>
                <button type="submit" name="resend" value="1">Send a new code</button>
            </form>
            <p class="switch-auth divider"><?= $signedIn ? '<a href="profile.php">Back to my profile</a>' : 'You can already <a href="login.php"><strong>sign in</strong></a> and verify later from your profile.' ?></p>
        <?php endif; ?>
    </section>
</main>
<script>
// Digits only, and send the form as soon as all six are in.
(function () {
    var input = document.getElementById('code');
    if (!input) return;
    input.addEventListener('input', function () {
        input.value = input.value.replace(/\D+/g, '').slice(0, 6);
        if (input.value.length === 6) input.form.requestSubmit ? input.form.requestSubmit() : input.form.submit();
    });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
