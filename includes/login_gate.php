<?php
// "Register Or Login To Get Started": the notice visitors see instead of the parish services.

/** Is this a page path we may send someone back to after they log in? (a local file only, never another site) */
function safe_next(?string $next): ?string
{
    $next = trim((string)$next);
    return preg_match('/^[A-Za-z0-9_\-]+\.php(\?[A-Za-z0-9_=&%.\-]*)?(#[A-Za-z0-9_\-]*)?$/', $next) ? $next : null;
}

/** The current page as a safe "next" value (e.g. "events.php?church=2"). */
function current_page_next(): string
{
    $path = basename((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
    $query = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
    return safe_next($path . ($query !== '' ? '?' . $query : '')) ?? 'index.php#services';
}

/** The notice itself: a lock, the message, and the Login / Register buttons. */
function render_login_gate(string $next = 'index.php#services'): void
{
    $next = safe_next($next) ?? 'index.php#services';
    ?>
    <div class="login-gate" role="region" aria-label="Sign in required">
        <span class="gate-icon" aria-hidden="true">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        </span>
        <h3>Register Or Login To Get Started</h3>
        <p>Church information, announcements, donations and the parish calendar are for signed-in parishioners.</p>
        <div class="gate-actions">
            <a class="btn-save" href="login.php?next=<?= urlencode($next) ?>">Login</a>
            <a class="btn-cancel" href="register.php">Register</a>
        </div>
    </div>
    <?php
}

/**
 * Use at the top of a service page (after session_start): signed-in people carry on; a visitor gets
 * the page shell with the notice instead of the content, and nothing on the page is run for them.
 */
function require_signed_in(string $title): void
{
    if (!empty($_SESSION['user_id'])) return;

    $pageTitle = $title;
    $activeNav = 'services';
    include __DIR__ . '/header.php';
    ?>
    <main class="gate-page">
        <?php back_button('index.php#services', 'Back to Services'); ?>
        <header class="page-heading">
            <h1><?= htmlspecialchars($title) ?></h1>
        </header>
        <?php render_login_gate(current_page_next()); ?>
    </main>
    <?php
    include __DIR__ . '/footer.php';
    exit;
}
