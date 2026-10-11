<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Church Admins (their own church) and the Super Admin (any church) add parishioner accounts here.
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['church_admin', 'super_admin'], true)) {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$isSuper = $_SESSION['role'] === 'super_admin';

$churches = $pdo->query('SELECT church_info_id, church_name, church_location FROM church_info ORDER BY church_name')->fetchAll();
$byId = array_column($churches, null, 'church_info_id');
$churchId = pick_church_id($pdo, $byId, (int)($_POST['church'] ?? $_GET['church'] ?? 0));
if (!can_manage_church($pdo, $churchId)) {
    // A Church Admin who asks for another church gets their own; anyone else goes back to their dashboard.
    $own = pick_church_id($pdo, $byId, 0);
    header('Location: ' . (can_manage_church($pdo, $own) && $own !== $churchId ? 'user-form.php?church=' . $own : dashboard_path('parishioners', 0)));
    exit;
}
$church = $byId[$churchId];
$backUrl = $isSuper ? 'superadmin/dashboard.php?church=' . $churchId . '#parishioners' : 'churchadmin/dashboard.php#parishioners';

$errors = [];
$f = ['name' => '', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f['name']     = trim($_POST['name'] ?? '');
    $f['email']    = trim($_POST['email'] ?? '');
    $f['phone']    = trim($_POST['phone'] ?? '');
    $password      = (string)($_POST['password'] ?? '');

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        // Same rules as the public registration form (register.php).
        if ($f['name'] === '' || mb_strlen($f['name']) > 100) $errors['name'] = 'Enter the full name (up to 100 characters).';
        if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($f['email']) > 100) $errors['email'] = 'Enter a valid email address.';
        if (!preg_match('/^\+?[0-9][0-9\s\-]{6,18}$/', $f['phone'])) $errors['phone'] = 'Enter a valid phone number.';
        if (strlen($password) < 8 || !preg_match('/\d/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors['password'] = 'The password must be at least 8 characters long, with a number and a special symbol.';
        }
        if (!isset($errors['email'])) {
            $stmt = $pdo->prepare('SELECT 1 FROM user WHERE user_email = ?');
            $stmt->execute([$f['email']]);
            if ($stmt->fetch()) $errors['email'] = 'An account with this email already exists.';
        }

        if (!$errors) {
            $pdo->prepare('INSERT INTO user (admin_id, church_id, user_name, user_email, user_password, user_phone, user_verified) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([(int)$_SESSION['user_id'], $churchId, $f['name'], $f['email'], password_hash($password, PASSWORD_DEFAULT), $f['phone'], 1]);   // added by staff, so no email check needed
            $_SESSION['user_flash'] = 'Account created for ' . $f['name'] . '. They can sign in as a Public User with ' . $f['email'] . ' and the password you set.';
            header('Location: ' . $backUrl);
            exit;
        }
    }
}

function fc(array $errors, string $key): string { return isset($errors[$key]) ? 'pfield has-error' : 'pfield'; }

$pageTitle = 'Add Parishioner';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="form-page">
    <?php back_button($backUrl, 'Back to dashboard'); ?>
    <header class="page-heading">
        <h1>Add Parishioner</h1>
        <p><?= e($church['church_name']) ?> &middot; <?= e($church['church_location']) ?></p>
    </header>

    <?php if (isset($errors['form'])): ?><div class="alert info-alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
    <?php if ($errors && !isset($errors['form'])): ?><div class="alert info-alert" role="alert">Please check the highlighted fields.</div><?php endif; ?>

    <form method="post" action="user-form.php" class="profile-card form-card" novalidate>
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <?php if ($isSuper): ?>
            <div class="pfield">
                <label for="church">Home parish</label>
                <select id="church" name="church">
                    <?php foreach ($churches as $ch): ?>
                        <option value="<?= (int)$ch['church_info_id'] ?>" <?= (int)$ch['church_info_id'] === $churchId ? 'selected' : '' ?>><?= e($ch['church_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <input type="hidden" name="church" value="<?= $churchId ?>">
        <?php endif; ?>

        <div class="form-stack">
            <div class="<?= fc($errors, 'name') ?>">
                <label for="name">Full Name</label>
                <input type="text" id="name" name="name" maxlength="100" placeholder="e.g. Abigail Robert" value="<?= e($f['name']) ?>" required autofocus>
                <?php if (isset($errors['name'])): ?><span class="row-error"><?= e($errors['name']) ?></span><?php endif; ?>
            </div>

            <div class="pf-row">
                <div class="<?= fc($errors, 'email') ?>">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" maxlength="100" autocomplete="off" value="<?= e($f['email']) ?>" required>
                    <?php if (isset($errors['email'])): ?><span class="row-error"><?= e($errors['email']) ?></span><?php endif; ?>
                </div>
                <div class="<?= fc($errors, 'phone') ?>">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" maxlength="20" placeholder="+60 12-345 6789" value="<?= e($f['phone']) ?>" required>
                    <?php if (isset($errors['phone'])): ?><span class="row-error"><?= e($errors['phone']) ?></span><?php endif; ?>
                </div>
            </div>

            <div class="<?= fc($errors, 'password') ?>">
                <label for="password">Temporary Password</label>
                <div class="pw-gen">
                    <input type="text" id="password" name="password" maxlength="72" autocomplete="new-password" spellcheck="false" required>
                    <button type="button" class="btn-cancel" id="pw-generate">Generate</button>
                </div>
                <?php if (isset($errors['password'])): ?><span class="row-error"><?= e($errors['password']) ?></span><?php endif; ?>
                <span class="file-note">At least 8 characters, with a number and a special symbol. Give it to the parishioner. They can change it from their profile, or use &ldquo;Forgot password&rdquo;.</span>
            </div>
        </div>

        <div class="profile-actions">
            <a href="<?= e($backUrl) ?>" class="btn-cancel">Cancel</a>
            <button type="submit" class="btn-save">Create account</button>
        </div>
    </form>
</main>
<script>
// Fill in a random password that meets the rules (letters, a digit and a symbol).
document.getElementById('pw-generate').addEventListener('click', function () {
    var letters = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ', digits = '23456789', symbols = '!@#$%*?';
    var pick = function (set) { var a = new Uint32Array(1); crypto.getRandomValues(a); return set[a[0] % set.length]; };
    var pw = '';
    for (var i = 0; i < 8; i++) pw += pick(letters);
    pw += pick(digits) + pick(digits) + pick(symbols);
    var input = document.getElementById('password');
    input.value = pw;
    input.focus();
    input.select();
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
