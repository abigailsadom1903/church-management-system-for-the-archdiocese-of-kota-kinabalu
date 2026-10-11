<?php
session_start();
require __DIR__ . '/config/db.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Nothing to confirm if nobody is signed in.
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Where "Stay signed in" goes: the page the person came from (a local page only, never another site),
// otherwise their own home (dashboard for the admins, the home page for a parishioner).
$role = $_SESSION['role'] ?? '';
$home = ['super_admin' => 'superadmin/dashboard.php', 'church_admin' => 'churchadmin/dashboard.php'][$role] ?? 'index.php';
$next = trim((string)($_GET['next'] ?? ''));
$stay = preg_match('/^(?:(?:superadmin|churchadmin)\/)?[A-Za-z0-9_\-]+\.php(\?[A-Za-z0-9_=&%.\-]*)?(#[A-Za-z0-9_\-]*)?$/', $next) ? $next : $home;

$pageTitle = 'Sign Out';
$activeNav = '';
include __DIR__ . '/includes/header.php';
?>
<main class="gate-page">
    <div class="login-gate signout-card" role="alertdialog" aria-labelledby="so-title" aria-describedby="so-text">
        <span class="gate-icon" aria-hidden="true">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </span>
        <h3 id="so-title">Are you sure you want to sign out?</h3>
        <p id="so-text">You are signed in as <strong><?= e($_SESSION['user_name'] ?? '') ?></strong>. You will need to sign in again to use your account.</p>
        <div class="gate-actions">
            <a class="btn-cancel" href="<?= e($stay) ?>">Stay signed in</a>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <button type="submit" class="btn-save">Yes, sign out</button>
            </form>
        </div>
    </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
