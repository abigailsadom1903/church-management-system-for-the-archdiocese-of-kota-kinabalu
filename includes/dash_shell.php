<?php
// Which pages show inside the admin dashboard (side drawer / navbar and top bar) instead of the public site layout.
// An admin who opens any of these stays in the dashboard; parishioners and visitors still get the public layout.

/** The management pages an admin uses. Everything else (home, login, profile, parishioner pages...) keeps the public layout. */
const DASH_SHELL_PAGES = [
    'announcements.php', 'announcement-form.php', 'bulletin-form.php', 'bulletin-builder.php',
    'catechesis-manage.php', 'catechesis-program-form.php',
    'church-information.php',
    'confession-manage.php',
    'donations.php', 'donation-manage.php', 'collection-manage.php', 'donation-report.php', 'donation-settings.php', 'donation-review.php', 'donation-email-preview.php',
    'events.php', 'event.php', 'event-form.php', 'event-participants.php',
    'pastoral-manage.php', 'pastoral-services.php',
    'sacrament-review.php', 'sacrament-apply.php', 'sacrament-file.php', 'sacrament-schedule.php',
    'account.php', 'user-form.php',
];

function dash_use_shell(): bool
{
    return in_array($_SESSION['role'] ?? '', ['church_admin', 'super_admin'], true)
        && in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), DASH_SHELL_PAGES, true);
}
