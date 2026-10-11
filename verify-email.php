<?php
// The link in the welcome email (see includes/user_mail.php). No sign-in needed: the token is the proof.
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/user_mail.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$user = verify_email_token($pdo, (string)($_GET['token'] ?? ''));
$signedIn = !empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'user';

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

        <?php if ($user): ?>
            <div class="alert success" role="status">Thank you, <?= e($user['user_name']) ?>. <strong><?= e($user['user_email']) ?></strong> is now verified.</div>
        <?php else: ?>
            <div class="alert" role="alert">This verification link is not valid, or it has already been used. You can ask for a new one from your profile page.</div>
        <?php endif; ?>

        <p class="switch-auth divider"><a href="<?= $signedIn ? 'profile.php' : 'login.php' ?>"><strong><?= $signedIn ? 'Go to my profile' : 'Sign in' ?> &rarr;</strong></a></p>
    </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
