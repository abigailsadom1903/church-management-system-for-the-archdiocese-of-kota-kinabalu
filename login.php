<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/login_gate.php';

// "?next=events.php" (set by the login-or-register notice): remember it, and go there after signing in.
if (isset($_GET['next']) && ($n = safe_next($_GET['next']))) {
    $_SESSION['login_next'] = $n;
}

// Each tab maps to a table, the role it must match, and where to send the user afterwards.
$roles = [
    'super_admin'  => ['label' => 'Super Admin',  'redirect' => 'superadmin/dashboard.php'],
    'church_admin' => ['label' => 'Church Admin', 'redirect' => 'churchadmin/dashboard.php'],
    'user'         => ['label' => 'Public User',  'redirect' => 'index.php'],
];

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$role  = 'super_admin';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role     = isset($roles[$_POST['role'] ?? '']) ? $_POST['role'] : 'super_admin';
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } else {
        if ($role === 'user') {
            $stmt = $pdo->prepare('SELECT user_id AS id, user_name AS name, user_password AS pw, user_photo FROM user WHERE user_email = ?');
            $stmt->execute([$email]);
        } else {
            $stmt = $pdo->prepare('SELECT admin_id AS id, admin_name AS name, admin_password AS pw, church_id FROM admin WHERE admin_email = ? AND admin_role = ?');
            $stmt->execute([$email, $role]);
        }
        $account = $stmt->fetch();

        // Same generic message whether the email or the password is wrong.
        if (!$account || !password_verify($password, $account['pw'])) {
            $error = 'Invalid email or password for the selected role.';
        } else {
            session_regenerate_id(true);
            $_SESSION['csrf']      = bin2hex(random_bytes(32));
            $_SESSION['user_id']   = (int)$account['id'];
            $_SESSION['user_name'] = $account['name'];
            $_SESSION['role']      = $role;
            if ($role === 'user') {
                $_SESSION['user_photo'] = $account['user_photo'] ?: null;
            }
            if ($role === 'church_admin') {
                $_SESSION['church_id'] = $account['church_id'] !== null ? (int)$account['church_id'] : null;
            }
            $dest = safe_next($_SESSION['login_next'] ?? null);
            unset($_SESSION['login_next']);
            header('Location: ' . ($dest ?? $roles[$role]['redirect']));
            exit;
        }
    }
}

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// One-click demo logins, shown only when running on this computer (never on a real server).
$isLocal = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1', '::1'], true);
$demoAccounts = [
    ['role' => 'super_admin',  'label' => 'Super Admin',  'email' => 'superadmin@akk.org'],
    ['role' => 'church_admin', 'label' => 'Church Admin', 'email' => 'admin.sacredheart@akk.org'],
    ['role' => 'user',         'label' => 'Public User',  'email' => 'demo.user@example.com'],
];
$demoPassword = 'Password123!';

$pageTitle = 'Sign In';
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
        <h1>Sign In</h1>
        <p class="subtitle">Enter your credentials to access the portal.</p>

        <?php if (!empty($_GET['registered']) && !$error): ?>
            <div class="alert success" role="status">Account created. You can now sign in. Verify your email any time from your profile with the code we emailed you.</div>
        <?php endif; ?>
        <?php if (!empty($_GET['signedout']) && !$error): ?>
            <div class="alert success" role="status">You have been signed out.</div>
        <?php endif; ?>
        <?php if (!empty($_GET['reset']) && !$error): ?>
            <div class="alert success" role="status">Password updated. Sign in with your new password.</div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php" id="login-form" novalidate>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">

            <div class="role-tabs" role="radiogroup" aria-label="Sign in as">
                <?php foreach ($roles as $key => $r): ?>
                    <label>
                        <input type="radio" name="role" value="<?= e($key) ?>" <?= $role === $key ? 'checked' : '' ?>>
                        <span><?= e($r['label']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="field">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" placeholder="name@archkk.org" maxlength="100" value="<?= e($email) ?>" autocomplete="username" required>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="password-wrap">
                    <input type="password" id="password" name="password" autocomplete="current-password" required>
                    <button type="button" class="toggle-pw" aria-label="Show password" aria-pressed="false">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                <a href="forgot-password.php" class="forgot">Forgot Password?</a>
            </div>

            <button type="submit" class="btn-primary">Sign In &rarr;</button>
            <p class="switch-auth divider">New User? <a href="register.php"><strong>Create an Account</strong></a></p>
        </form>

        <?php if ($isLocal): ?>
            <div class="demo-box">
                <p class="demo-title">Demo accounts <span>&middot; local testing only</span></p>
                <div class="demo-buttons">
                    <?php foreach ($demoAccounts as $d): ?>
                        <button type="button" class="demo-btn"
                                data-role="<?= e($d['role']) ?>"
                                data-email="<?= e($d['email']) ?>"
                                data-password="<?= e($demoPassword) ?>">
                            <?= e($d['label']) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>
</main>
<script>
document.querySelector('.toggle-pw').addEventListener('click', function () {
    var input = document.getElementById('password');
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    this.setAttribute('aria-pressed', show);
    this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
});

// Demo buttons: pick the role tab, fill the credentials and sign in.
document.querySelectorAll('.demo-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var form = document.getElementById('login-form');
        form.querySelector('input[name="role"][value="' + btn.dataset.role + '"]').checked = true;
        form.email.value = btn.dataset.email;
        form.password.value = btn.dataset.password;
        form.requestSubmit();
    });
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
