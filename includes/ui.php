<?php
// Small shared UI pieces. Loaded by includes/header.php, so every page can use them.

/**
 * A "Back" button for the top of a service page. The main service pages go back to the Services section
 * of the home page; their sub-pages go back to the page they came from.
 */
function back_button(string $href = 'index.php#services', string $label = 'Back to Services'): void
{
    ?>
    <a class="back-btn" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
    </a>
    <?php
}

/** The red "Sign Out" button, the same for parishioners (profile page) and admins (dashboard header). */
function signout_button(string $href): void
{
    ?>
    <a class="back-btn is-signout" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>">
        Sign Out
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/></svg>
    </a>
    <?php
}

/** Small line icons for the dashboards (24x24, drawn in the current text colour). */
function dash_icon(string $name, int $size = 20): string
{
    $paths = [
        'chart'    => '<path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
        'church'   => '<path d="m18 7 4 2v11a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9l4-2"/><path d="M14 22v-4a2 2 0 0 0-4 0v4"/><path d="M18 22V5l-6-3-6 3v17"/><path d="M12 7v5"/><path d="M10 9h4"/>',
        'megaphone'=> '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        'file'     => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
        'calendar' => '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
        'heart'    => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
        'bank'     => '<line x1="3" x2="21" y1="22" y2="22"/><line x1="6" x2="6" y1="18" y2="11"/><line x1="10" x2="10" y1="18" y2="11"/><line x1="14" x2="14" y1="18" y2="11"/><line x1="18" x2="18" y1="18" y2="11"/><polygon points="12 2 20 7 4 7"/>',
        'users'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user-plus'=> '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/>',
        'user-x'   => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="17" x2="22" y1="8" y2="13"/><line x1="22" x2="17" y1="8" y2="13"/>',
        'shield'   => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
        'globe'    => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
        'chevron'  => '<path d="m6 9 6 6 6-6"/>',
        'droplet'  => '<path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/>',
        'book'     => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
        'arrow'    => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'search'   => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'cap'      => '<path d="M21.42 10.92a1 1 0 0 0-.02-1.84L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.83l8.57 3.91a2 2 0 0 0 1.66 0z"/><path d="M22 10v6"/><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"/>',
        'hand'     => '<path d="M11 14h2a2 2 0 1 0 0-4h-3c-.6 0-1.1.2-1.4.6L3 16"/><path d="m7 20 1.6-1.4c.3-.4.8-.6 1.4-.6h4c1.1 0 2.1-.4 2.8-1.2l4.6-4.4a2 2 0 0 0-2.75-2.91l-4.2 3.9"/><path d="m2 15 6 6"/><path d="M19.5 8.5c.7-.7 1.5-1.6 1.5-2.7A2.73 2.73 0 0 0 16 4a2.78 2.78 0 0 0-5 1.8c0 1.2.8 2 1.5 2.8L16 12Z"/>',
        'trend-up' => '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>',
        'trend-down' => '<polyline points="22 17 13.5 8.5 8.5 13.5 2 7"/><polyline points="16 17 22 17 22 11"/>',
        'pencil'   => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/>',
    ];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

/** An admin's own uploaded photo (account.php), as a path from the site root, or null. */
function admin_own_photo(?string $file): ?string
{
    if (!$file || !preg_match('/^[\w.-]+$/', $file)) return null;
    return is_file(dirname(__DIR__) . '/uploads/admin_photos/' . $file) ? 'uploads/admin_photos/' . $file : null;
}

/**
 * An admin's profile picture, as a path from the site root, or null (parishioners, nothing to show):
 *   1. the photo they uploaded on My Profile (account.php)
 *   2. otherwise: Super Admin -> the Catholic Archdiocesan Centre (assets/images/super-admin.png),
 *                 Church Admin -> their church's photo (e.g. "uploads/church_photos/x.jpg")
 */
function admin_profile_photo(): ?string
{
    global $pdo;
    static $cache = [];
    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, ['super_admin', 'church_admin'], true) || empty($_SESSION['user_id']) || !isset($pdo)) return null;
    $id = (int)$_SESSION['user_id'];
    if (array_key_exists($id, $cache)) return $cache[$id];

    $row = [];
    try {
        $stmt = $pdo->prepare('SELECT a.admin_photo, c.church_photo FROM admin a LEFT JOIN church_info c ON c.church_info_id = a.church_id WHERE a.admin_id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch() ?: [];
    } catch (PDOException $ex) { /* admin_photo not added yet: database/admin_photo.sql */ }

    if ($own = admin_own_photo($row['admin_photo'] ?? null)) return $cache[$id] = $own;

    $path = null;
    if ($role === 'super_admin') {
        if (is_file(dirname(__DIR__) . '/assets/images/super-admin.png')) $path = 'assets/images/super-admin.png';
    } else {
        $file = (string)($row['church_photo'] ?? '');
        if ($file !== '' && preg_match('/^[\w.-]+$/', $file)) {
            foreach (['uploads/church_photos/', 'assets/images/churches/'] as $dir) {   // same places as church_photo_url()
                if (is_file(dirname(__DIR__) . '/' . $dir . $file)) { $path = $dir . $file; break; }
            }
        }
    }
    return $cache[$id] = $path;
}

/** A stat tile for the dashboards. */
function dash_stat(string $icon, int $number, string $label): void
{
    ?>
    <div class="stat">
        <span class="stat-ico"><?= dash_icon($icon, 22) ?></span>
        <div><span class="stat-num"><?= $number ?></span><span class="stat-label"><?= htmlspecialchars($label) ?></span></div>
    </div>
    <?php
}
