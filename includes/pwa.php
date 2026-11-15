<?php
// Installable app ("Add to Home Screen"): app icon, colours and the service worker, for every page.
// Called inside <head> by header.php and dashboard_header.php; $base is the path back to the site folder
// ("" for pages in the site folder, "../" for pages in superadmin/ and churchadmin/).

function pwa_head(string $base = ''): void
{
    $b = htmlspecialchars($base, ENT_QUOTES, 'UTF-8');
    $v = @filemtime(__DIR__ . '/../assets/js/pwa.js') ?: 1;
    echo <<<HTML
    <link rel="manifest" href="{$b}manifest.json">
    <meta name="theme-color" content="#5b2d82">
    <meta name="application-name" content="ArchKK">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="ArchKK">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="icon" type="image/png" sizes="32x32" href="{$b}assets/images/app/favicon-32.png">
    <link rel="apple-touch-icon" href="{$b}assets/images/app/apple-touch-icon.png">
    <script src="{$b}assets/js/pwa.js?v={$v}" data-base="{$b}" defer></script>

HTML;
}
