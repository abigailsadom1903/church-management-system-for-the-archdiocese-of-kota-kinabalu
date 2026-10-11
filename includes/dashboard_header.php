<?php
// Shared layout for the admin dashboard: top bar plus the Super Admin's side drawer or the Church Admin's top navbar.
// Used by the dashboard pages (one folder deep, so assets and links go through ../) and, through includes/header.php,
// by every admin management page (at the site root), so an admin never leaves the dashboard.
//
// Everything lives in a function so the page's own variables ($q, $label ...) are never overwritten.
// Set $pageTitle and $roleLabel before including. Afterwards $dashSacraments, $dashPastoral and $dashPending hold the waiting counts.
require_once __DIR__ . '/ui.php';

/** The navigation, with paths from the site root. item: [label, href|null, children [label, href, scripts], scripts] */
function dash_nav_items(bool $super): array
{
    $dash = $super ? 'superadmin/dashboard.php' : 'churchadmin/dashboard.php';
    $ann = ['announcements.php', 'announcement-form.php'];
    $bul = ['bulletin-form.php'];
    $events = ['events.php', 'event.php', 'event-form.php', 'event-participants.php'];
    $groups = [
        // A Church Admin manages these on their dashboard; the Super Admin on the Announcements page, where any church can be picked.
        'announce' => ['Announcements & Bulletins', null, [['Announcements', 'announcements.php', $ann], ['Bulletins', 'announcements.php?tab=bulletins', $bul]], []],
        'sacraments' => ['Sacraments', null, [['Applications', 'sacrament-review.php', ['sacrament-review.php', 'sacrament-apply.php']], ['Confession slots', 'confession-manage.php', ['confession-manage.php']], ['Forms & notes', 'sacrament-schedule.php', ['sacrament-schedule.php']]], []],
    ];
    // Requests are answered by the Church Admin; the services themselves are theirs to manage too (the Super Admin only views both).
    $groups['pastoral'] = ['Pastoral Care', null, [['Requests', 'pastoral-manage.php', ['pastoral-manage.php']], ['Services', 'pastoral-services.php', ['pastoral-services.php']]], []];
    if ($super) {
        return [
            'Overview' => [
                ['Overview', $dash, [], ['dashboard.php']],
                ['Admins', 'superadmin/admins.php', [], ['admins.php']],
                ['Home Page', 'superadmin/statistics.php', [], ['statistics.php']],
                ['About Us', 'superadmin/about.php', [], ['about.php']],
                ['My Profile', 'account.php', [], ['account.php']],
            ],
            'Parish' => [
                ['Churches', 'church-information.php', [], ['church-information.php', 'church-form.php']],
                $groups['announce'],
                ['Events', 'events.php?church=0&view=manage', [], $events],
                ['Catechesis', 'catechesis-manage.php', [], ['catechesis-manage.php', 'catechesis-program-form.php']],
            ],
            'Services' => [
                $groups['sacraments'],
                $groups['pastoral'],
                ['Donations', null, [
                    ['Donation reports', 'donation-report.php', ['donation-report.php']],
                    ['Manage donations', 'donation-manage.php', ['donation-manage.php', 'donation-review.php', 'donation-email-preview.php', 'donations.php']],
                    ['Weekly collection', 'collection-manage.php', ['collection-manage.php']],
                    ['Bank details', 'donation-settings.php', ['donation-settings.php']],
                ], []],
            ],
        ];
    }
    return [
        'Overview' => [
            ['Overview', $dash, [], ['dashboard.php']],
            ['My Profile', 'account.php', [], ['account.php']],
        ],
        'Parish' => [
            ['Church Information', 'church-information.php', [], ['church-information.php']],
            $groups['announce'],
            ['Events', 'events.php?view=manage', [], $events],
            ['Catechesis', 'catechesis-manage.php', [], ['catechesis-manage.php', 'catechesis-program-form.php']],
        ],
        'Services' => [
            $groups['sacraments'],
            $groups['pastoral'],
            ['Donations', null, [
                ['Manage donations', 'donation-manage.php', ['donation-manage.php', 'donation-review.php', 'donation-email-preview.php', 'donation-report.php', 'donation-settings.php', 'donations.php']],
                ['Weekly collection', 'collection-manage.php', ['collection-manage.php']],
            ], []],
        ],
    ];
}

/**
 * Prints the top of an admin page.
 * @param string $base  '../' from a dashboard page in a sub-folder, '' from a page at the site root
 * @param bool   $shell true for a management page at the root: the content sits in a plain <div> (the page brings its own <main>)
 * @return array{sacraments:int,pastoral:int,pending:int}
 */
function dash_header(string $pageTitle, string $roleLabel, int $pending, string $base, bool $shell): array
{
    global $pdo;
    $role = $_SESSION['role'] ?? '';
    $super = $role === 'super_admin';
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $name = (string)($_SESSION['user_name'] ?? '');
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $initials = strtoupper(mb_substr($parts[0] ?? '', 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
    $roleLabel = $roleLabel !== '' ? $roleLabel : ($super ? 'Super Admin' : 'Church Admin');

    // The account must still exist with this role: an admin removed on superadmin/admins.php is signed out at once.
    if (isset($pdo)) {
        $st = $pdo->prepare('SELECT 1 FROM admin WHERE admin_id = ? AND admin_role = ?');
        $st->execute([(int)($_SESSION['user_id'] ?? 0), $role]);
        if (!$st->fetchColumn()) {
            $_SESSION = [];
            session_destroy();
            header('Location: ' . $base . 'login.php');
            exit;
        }
    }

    // What is waiting for someone to look at (the Super Admin: everywhere; a Church Admin: their church).
    // Same counts as the dashboard cards and the lists (includes/review_queue.php).
    $sacraments = 0;
    $pastoral = 0;
    if (isset($pdo)) {
        require_once __DIR__ . '/review_queue.php';
        $scopeId = null;   // Super Admin: every church
        if (!$super) {
            $st = $pdo->prepare('SELECT church_id FROM admin WHERE admin_id = ?');
            $st->execute([(int)($_SESSION['user_id'] ?? 0)]);
            $scopeId = (int)$st->fetchColumn();
        }
        $sacraments = review_count($pdo, 'sacraments', $scopeId);
        $pastoral = review_count($pdo, 'pastoral', $scopeId);
        $pending = review_count($pdo, 'donations', $scopeId);
    }
    // A badge on the menu group and on the exact page inside it, so the admin knows where to click.
    $badges = ['Sacraments' => $sacraments, 'Applications' => $sacraments, 'Pastoral Care' => $pastoral, 'Requests' => $pastoral, 'Donations' => $pending, 'Manage donations' => $pending];
    $badge = fn(string $label) => !empty($badges[$label]) ? '<em class="dn-badge" title="' . (int)$badges[$label] . ' waiting for review">' . (int)$badges[$label] . '</em>' : '';
    $items = dash_nav_items($super);
    $current = fn(array $scripts) => in_array($script, $scripts, true);
    $home = $base . ($super ? 'superadmin' : 'churchadmin') . '/dashboard.php';
    $signOutNext = $shell ? $script : basename(dirname($_SERVER['SCRIPT_NAME'])) . '/' . $script;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | Archdiocese of Kota Kinabalu</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Noto+Serif:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $base ?>assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: 1 ?>">
</head>
<body class="dash has-drawer<?= $shell ? ' is-shell' : '' ?>">
<div class="dash-head">
    <div class="topbar"></div>
    <header class="dash-header-bar">
        <div class="dash-left">
            <button type="button" class="dd-toggle" id="dd-toggle" aria-controls="dash-drawer" aria-expanded="true" aria-label="Open or close the menu"><span></span><span></span><span></span></button>
            <a href="<?= e($home) ?>" class="dash-brand">
                <img src="<?= $base ?>assets/images/logo.png" alt="" width="30" height="34">
                <span>Archdiocese of Kota Kinabalu</span>
            </a>
        </div>
        <div class="dash-user">
            <a class="dash-site" href="<?= $base ?>index.php">View site</a>
            <a class="dash-chip" href="<?= $base ?>account.php" aria-label="My profile">
                <?php if ($avatarPhoto = admin_profile_photo()): ?>
                    <img class="dash-avatar is-photo" src="<?= e($base . $avatarPhoto) ?>" alt="" width="34" height="34">
                <?php else: ?>
                    <span class="dash-avatar" aria-hidden="true"><?= e($initials) ?></span>
                <?php endif; ?>
                <span class="dash-chip-text"><b><?= e($name) ?></b><small><?= e($roleLabel) ?></small></span>
            </a>
            <?php signout_button($base . 'signout.php?next=' . urlencode($signOutNext)); ?>
        </div>
    </header>
</div>

<?php if (true): ?>
<div class="dd-backdrop" id="dd-backdrop"></div>
<aside class="dash-drawer" id="dash-drawer" aria-label="Dashboard navigation">
    <nav>
        <?php foreach ($items as $section => $list): ?>
            <p class="dd-section"><?= e($section) ?></p>
            <ul class="dd-list">
                <?php foreach ($list as [$label, $href, $children, $scripts]): ?>
                    <?php if ($children):
                        $open = false;
                        foreach ($children as [, , $cs]) if ($current($cs)) $open = true;
                    ?>
                        <li class="dd-group<?= $open ? ' is-open' : '' ?>">
                            <button type="button" class="dd-link" aria-expanded="<?= $open ? 'true' : 'false' ?>">
                                <span><?= e($label) ?></span><?= $badge($label) ?>
                                <svg class="dd-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                            </button>
                            <ul class="dd-sub">
                                <?php foreach ($children as [$cLabel, $cHref, $cs]): $on = $current($cs); ?>
                                    <li><a<?= $on ? ' class="is-active" aria-current="page"' : '' ?> href="<?= e($base . $cHref) ?>"><span><?= e($cLabel) ?></span><?= $badge($cLabel) ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php else: $on = $current($scripts); ?>
                        <li><a class="dd-link<?= $on ? ' is-active' : '' ?>" href="<?= e($base . $href) ?>"<?= $on ? ' aria-current="page"' : '' ?>><span><?= e($label) ?></span><?= $badge($label) ?></a></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </nav>
</aside>
<script>
(function () {
    var body = document.body, toggle = document.getElementById('dd-toggle'), backdrop = document.getElementById('dd-backdrop');
    var wide = window.matchMedia('(min-width: 992px)');
    var KEY = 'dash-drawer-closed';
    function isOpen() { return wide.matches ? !body.classList.contains('dd-collapsed') : body.classList.contains('dd-open'); }
    function sync() { toggle.setAttribute('aria-expanded', isOpen() ? 'true' : 'false'); }
    try { if (localStorage.getItem(KEY) === '1') body.classList.add('dd-collapsed'); } catch (e) {}
    sync();
    toggle.addEventListener('click', function () {
        if (wide.matches) {
            body.classList.toggle('dd-collapsed');
            try { localStorage.setItem(KEY, body.classList.contains('dd-collapsed') ? '1' : '0'); } catch (e) {}
        } else {
            body.classList.toggle('dd-open');
        }
        sync();
    });
    backdrop.addEventListener('click', function () { body.classList.remove('dd-open'); sync(); });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { body.classList.remove('dd-open'); sync(); } });
    wide.addEventListener('change', function () { body.classList.remove('dd-open'); sync(); });
    // groups open and close like an accordion
    document.querySelectorAll('.dd-group > .dd-link').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var g = btn.parentElement, open = !g.classList.contains('is-open');
            g.classList.toggle('is-open', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    });
})();
</script>
<?php endif; ?>
<?= $shell ? '<div class="dash-main dash-shell-main">' : '<main class="dash-main">' ?>

<?php
    return ['sacraments' => $sacraments, 'pastoral' => $pastoral, 'pending' => $pending];
}

$__dash = dash_header((string)($pageTitle ?? 'Dashboard'), (string)($roleLabel ?? ''), (int)($dashPending ?? 0), (string)($dashBase ?? '../'), !empty($dashShell));
$dashSacraments = $__dash['sacraments'];
$dashPastoral = $__dash['pastoral'];
$dashPending = $__dash['pending'];
unset($__dash);
