<?php
// The "Unsubscribe" link in announcement emails (see includes/announcement_mail.php). No sign-in needed:
// the token in the link is the proof. Opening the link only asks; the button does the removal, so mail
// programs that pre-open links cannot unsubscribe someone by accident.
session_start();
require __DIR__ . '/config/db.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$sub = null;
if (preg_match('/^[0-9a-f]{32}$/', $token)) {
    $stmt = $pdo->prepare(
        'SELECT s.subscriber_id, s.email, c.church_name
           FROM newsletter_subscribers s JOIN church_info c ON c.church_info_id = s.church_id
          WHERE s.unsub_token = ?'
    );
    $stmt->execute([$token]);
    $sub = $stmt->fetch() ?: null;
}

$done = false;
if ($sub && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->prepare('DELETE FROM newsletter_subscribers WHERE subscriber_id = ?')->execute([$sub['subscriber_id']]);
    $done = true;
}

$pageTitle = 'Unsubscribe';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="auth-page">
    <section class="auth-brand">
        <img src="assets/images/logo.png" alt="Archdiocese of Kota Kinabalu logo">
        <h2>Church Management System<br>The Archdiocese of Kota Kinabalu</h2>
        <p>United in Faith, Connected in Service</p>
    </section>

    <section class="auth-card serif">
        <h1>Unsubscribe</h1>

        <?php if ($done): ?>
            <div class="alert success" role="status">
                <strong><?= e($sub['email']) ?></strong> will no longer receive announcement emails from <?= e($sub['church_name']) ?>.
            </div>
        <?php elseif ($sub): ?>
            <p class="subtitle">Stop sending announcement emails from <strong><?= e($sub['church_name']) ?></strong> to <strong><?= e($sub['email']) ?></strong>?</p>
            <form method="post" action="unsubscribe.php">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <button type="submit" class="btn-primary">Unsubscribe</button>
            </form>
        <?php else: ?>
            <div class="alert" role="alert">This unsubscribe link is not valid, or this address has already been unsubscribed.</div>
        <?php endif; ?>

        <p class="switch-auth divider"><a href="index.php"><strong>&larr; Back to Home</strong></a></p>
    </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
