<?php
// Session guard for the role dashboards.
// Usage (top of a protected page):  require __DIR__ . '/../includes/auth.php'; require_role('super_admin');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** Sends anyone who is not signed in as $role back to the login page. */
function require_role(string $role): void
{
    if (($_SESSION['role'] ?? '') !== $role || empty($_SESSION['user_id'])) {
        header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME'], 2), '/\\') . '/login.php');
        exit;
    }
}

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
