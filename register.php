<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/user_mail.php';

// Home parish options: every church in the directory.
$parishes = $pdo->query(
    "SELECT church_info_id, church_name, church_location FROM church_info ORDER BY church_name"
)->fetchAll();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$old = ['name' => '', 'email' => '', 'phone' => '', 'parish' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['form'] = 'Your session expired. Please try again.';
    }

    $old['name']   = trim($_POST['name'] ?? '');
    $old['email']  = trim($_POST['email'] ?? '');
    $old['phone']  = trim($_POST['phone'] ?? '');
    $old['parish'] = (string)($_POST['parish'] ?? '');
    $password      = $_POST['password'] ?? '';

    if ($old['name'] === '' || mb_strlen($old['name']) > 100) {
        $errors['name'] = 'Please enter your full name.';
    }

    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($old['email']) > 100) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    if (!preg_match('/^\+?[0-9][0-9\s\-]{6,18}$/', $old['phone'])) {
        $errors['phone'] = 'Please enter a valid phone number.';
    }

    $validParishIds = array_column($parishes, 'church_info_id');
    if (!in_array((int)$old['parish'], $validParishIds, true)) {
        $errors['parish'] = 'Please select your home parish.';
    }

    if (strlen($password) < 8 || !preg_match('/\d/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors['password'] = 'Password must be at least 8 characters long, contain a number and a special symbol.';
    }

    if (empty($_POST['terms'])) {
        $errors['terms'] = 'You must agree to the Terms of Service and Privacy Policy.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT 1 FROM user WHERE user_email = ?');
        $stmt->execute([$old['email']]);
        if ($stmt->fetch()) {
            $errors['email'] = 'An account with this email already exists.';
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'INSERT INTO user (church_id, user_name, user_email, user_password, user_phone)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int)$old['parish'],
            $old['name'],
            $old['email'],
            password_hash($password, PASSWORD_DEFAULT),
            $old['phone'],
        ]);
        send_welcome_email($pdo, (int)$pdo->lastInsertId(), $old['email'], $old['name']);   // with the link that verifies the address

        unset($_SESSION['csrf']);
        header('Location: login.php?registered=1');
        exit;
    }
}

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function fieldClass(array $errors, string $key): string { return isset($errors[$key]) ? 'field has-error' : 'field'; }

$pageTitle = 'Create Account';
$activeNav = 'auth';   // the menu underlines "Login/ Register" here, not Home
include __DIR__ . '/includes/header.php';
?>
<main class="auth-page">
    <section class="auth-brand">
        <img src="assets/images/logo.png" alt="Archdiocese of Kota Kinabalu logo">
        <h2>Church Management System<br>The Archdiocese of Kota Kinabalu</h2>
        <p>United in Faith, Connected in Service</p>
    </section>

    <section class="auth-card">
        <h1>Create Account</h1>
        <p class="subtitle">Enter your details to register as a Public User.</p>

        <?php if (isset($errors['form'])): ?>
            <div class="alert" role="alert"><?= e($errors['form']) ?></div>
        <?php endif; ?>

        <form method="post" action="register.php" novalidate>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">

            <div class="<?= fieldClass($errors, 'name') ?>">
                <label for="name">Full Name</label>
                <input type="text" id="name" name="name" placeholder="e.g. Abigail Robert" maxlength="100" value="<?= e($old['name']) ?>" required>
                <?php if (isset($errors['name'])): ?><span class="error-text"><?= e($errors['name']) ?></span><?php endif; ?>
            </div>

            <div class="field-row">
                <div class="<?= fieldClass($errors, 'email') ?>">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" placeholder="abigail@example.com" maxlength="100" value="<?= e($old['email']) ?>" required>
                    <?php if (isset($errors['email'])): ?><span class="error-text"><?= e($errors['email']) ?></span><?php endif; ?>
                </div>
                <div class="<?= fieldClass($errors, 'phone') ?>">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" placeholder="+60 12-345 6789" maxlength="20" value="<?= e($old['phone']) ?>" required>
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

            <div class="<?= fieldClass($errors, 'password') ?>">
                <label for="password">Password</label>
                <div class="password-wrap">
                    <input type="password" id="password" name="password" autocomplete="new-password" required aria-describedby="pw-rules">
                    <button type="button" class="toggle-pw" aria-label="Show password" aria-pressed="false">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                <div class="pw-meter" aria-hidden="true"><span id="pw-bar"></span></div>
                <ul class="pw-rules" id="pw-rules">
                    <li data-rule="len">At least 8 characters</li>
                    <li data-rule="num">A number</li>
                    <li data-rule="sym">A special symbol</li>
                </ul>
                <?php if (isset($errors['password'])): ?><span class="error-text"><?= e($errors['password']) ?></span><?php endif; ?>
            </div>

            <label class="terms <?= isset($errors['terms']) ? 'has-error' : '' ?>">
                <input type="checkbox" name="terms" value="1" <?= !empty($_POST['terms']) ? 'checked' : '' ?>>
                <span>I agree to the Archdiocese <a href="terms.php">Terms of Service</a> and <a href="privacy.php">Privacy Policy</a>.</span>
            </label>
            <?php if (isset($errors['terms'])): ?><p class="error-text terms-error"><?= e($errors['terms']) ?></p><?php endif; ?>

            <button type="submit" class="btn-primary">Create Account</button>
            <p class="switch-auth">Already have an account? <a href="login.php"><strong>Sign In</strong></a></p>
        </form>
    </section>
</main>
<script>
// Show / hide the password, and tick off the rules as they are met.
(function () {
    var input = document.getElementById('password'), toggle = document.querySelector('.toggle-pw');
    var bar = document.getElementById('pw-bar'), rules = document.querySelectorAll('#pw-rules li');

    toggle.addEventListener('click', function () {
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        toggle.setAttribute('aria-pressed', show);
        toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });

    function check() {
        var v = input.value;
        var met = { len: v.length >= 8, num: /\d/.test(v), sym: /[^A-Za-z0-9]/.test(v) };
        var score = 0;
        rules.forEach(function (li) { var ok = met[li.dataset.rule]; li.classList.toggle('ok', ok); if (ok) score++; });
        var level = v === '' ? 0 : (score === 3 ? (v.length >= 12 ? 4 : 3) : score);   // 0 empty, 1-2 weak/fair, 3 good, 4 strong
        bar.className = 'lvl-' + level;
    }
    input.addEventListener('input', check);
    check();
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
