<?php
// Event reminders: people registered for an event that is tomorrow are emailed once (there is no cron job).
if (isset($pdo) && $pdo instanceof PDO) {
    require_once __DIR__ . '/event_reminders.php';
    send_due_event_reminders($pdo);
}
// Admins on a management page get the dashboard layout (drawer / navbar + top bar) instead of the public one.
require_once __DIR__ . '/dash_shell.php';
if (dash_use_shell()) {
    $dashBase = '';
    $dashShell = true;
    include __DIR__ . '/dashboard_header.php';
    return;
}
// Shared public-site header. Set $pageTitle and (optionally) $activeNav before including.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$pageTitle = $pageTitle ?? 'Church Management System';
$activeNav = $activeNav ?? '';
require_once __DIR__ . '/ui.php';
$onePage = $onePage ?? false;                 // true on index.php: menu links scroll within the page
$navBase = $onePage ? '' : 'index.php';         // on any other page they go to the section of index.php

// Signed-in visitors get a profile icon that links to their own dashboard.
$dashboards = [
    'super_admin'  => 'superadmin/dashboard.php',
    'church_admin' => 'churchadmin/dashboard.php',
    'user'         => 'profile.php',
];
$dashboardLink = !empty($_SESSION['user_id']) ? ($dashboards[$_SESSION['role'] ?? ''] ?? null) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?> | Archdiocese of Kota Kinabalu</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Noto+Serif:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: 1 ?>">
    <?php require_once __DIR__ . '/theme.php'; theme_head(); ?>
</head>
<body>
<div class="topbar"></div>
<header class="site-header">
    <a href="<?= $navBase ?>#home" class="brand" aria-label="Home">
        <img src="assets/images/logo.png" alt="Archdiocese of Kota Kinabalu logo" width="44" height="48">
    </a>
    <nav class="main-nav" aria-label="Main">
        <a href="<?= $navBase ?>#home" data-nav="home" class="<?= !$onePage && $activeNav === 'home' ? 'active' : '' ?>">Home</a>
        <span class="sep" aria-hidden="true">|</span>
        <a href="<?= $navBase ?>#services" data-nav="services" class="<?= !$onePage && $activeNav === 'services' ? 'active' : '' ?>">Services</a>
        <span class="sep" aria-hidden="true">|</span>
        <a href="<?= $navBase ?>#directory" data-nav="directory" class="<?= !$onePage && $activeNav === 'directory' ? 'active' : '' ?>">Directory</a>
        <span class="sep" aria-hidden="true">|</span>
        <a href="<?= $navBase ?>#about" data-nav="about" class="<?= !$onePage && $activeNav === 'about' ? 'active' : '' ?>">About Us</a>
    </nav>
    <?php theme_toggle(); ?>
    <?php if ($dashboardLink): ?>
        <a href="<?= htmlspecialchars($dashboardLink) ?>" class="avatar-link" title="<?= htmlspecialchars($_SESSION['user_name'] ?? 'My dashboard') ?> &ndash; open my account" aria-label="Open my account">
            <?php $avatarFile = ($_SESSION['role'] ?? '') === 'user' ? ($_SESSION['user_photo'] ?? '') : ''; ?>
            <?php if ($avatarFile && preg_match('/^[\w.-]+$/', $avatarFile) && is_file(__DIR__ . '/../uploads/profile_photos/' . $avatarFile)): ?>
                <img class="avatar-photo" src="uploads/profile_photos/<?= htmlspecialchars($avatarFile) ?>" alt="" width="40" height="40">
            <?php elseif ($churchPhoto = admin_profile_photo()): ?>
                <img class="avatar-photo" src="<?= htmlspecialchars($churchPhoto) ?>" alt="" width="40" height="40">
            <?php else: ?>
            <svg width="40" height="40" viewBox="0 0 40 40" aria-hidden="true"><circle cx="20" cy="20" r="19" fill="#6b6b76"/><circle cx="20" cy="15" r="6.5" fill="#fff"/><path d="M6.5 32.5c2.5-6 7.5-8.5 13.5-8.5s11 2.5 13.5 8.5A19 19 0 0 1 20 39a19 19 0 0 1-13.500-6.500z" fill="#fff"/></svg>
            <?php endif; ?>
        </a>
    <?php else: ?>
        <a href="login.php" class="auth-link<?= $activeNav === 'auth' ? ' active' : '' ?>"<?= $activeNav === 'auth' ? ' aria-current="page"' : '' ?>>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
            <span>Login/ Register</span>
        </a>
    <?php endif; ?>
</header>
