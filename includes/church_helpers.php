<?php
// Helpers shared by the pages that show one church at a time
// (church-information.php, announcements.php).

/** Languages a bulletin can be published in: code stored in bulletins.language => label. */
const BULLETIN_LANGUAGES = [
    'en' => 'English',
    'ms' => 'Bahasa Malaysia',
    'zh' => 'Mandarin',
    'kzj' => 'Kadazan',
];

/** Where a saved church photo lives: admin uploads first, then the bundled assets folder. */
function church_photo_url(?string $file): ?string
{
    if (!$file || !preg_match('/^[\w.-]+$/', $file)) return null;
    $root = dirname(__DIR__);
    foreach (['uploads/church_photos/', 'assets/images/churches/'] as $dir) {
        if (is_file($root . '/' . $dir . $file)) return $dir . $file;
    }
    return null;
}

/**
 * Which church a page should show: the requested one if it exists, otherwise the signed-in
 * person's own parish (their home parish, or the church they administer), otherwise the first
 * church created. Returns 0 when there are no churches.
 *
 * @param array<int,array> $byId churches keyed by church_info_id
 */
function pick_church_id(PDO $pdo, array $byId, int $requested): int
{
    if (isset($byId[$requested])) return $requested;

    $own = 0;
    if (!empty($_SESSION['user_id'])) {
        $role = $_SESSION['role'] ?? '';
        if ($role === 'church_admin') {
            $stmt = $pdo->prepare('SELECT church_id FROM admin WHERE admin_id = ? AND admin_role = \'church_admin\'');
        } elseif ($role === 'user') {
            $stmt = $pdo->prepare('SELECT church_id FROM user WHERE user_id = ?');
        }
        if (isset($stmt)) {
            $stmt->execute([(int)$_SESSION['user_id']]);
            $own = (int)$stmt->fetchColumn();
        }
    }
    if (isset($byId[$own])) return $own;

    return $byId ? min(array_keys($byId)) : 0;
}

/**
 * May the signed-in person add, change and delete content for this church?
 * Super Admin: any church. Church Admin: only the church they are assigned to (read from the
 * database, not trusted from the session). Everyone else: no.
 */
function can_manage_church(PDO $pdo, int $churchId): bool
{
    if ($churchId <= 0 || empty($_SESSION['user_id'])) return false;

    $role = $_SESSION['role'] ?? '';
    if ($role === 'super_admin') {
        $stmt = $pdo->prepare('SELECT 1 FROM church_info WHERE church_info_id = ?');
        $stmt->execute([$churchId]);
        return (bool)$stmt->fetchColumn();
    }
    if ($role === 'church_admin') {
        $stmt = $pdo->prepare('SELECT church_id FROM admin WHERE admin_id = ? AND admin_role = \'church_admin\'');
        $stmt->execute([(int)$_SESSION['user_id']]);
        return (int)$stmt->fetchColumn() === $churchId;
    }
    return false;
}

/**
 * Where "back to the dashboard" goes for the signed-in admin, e.g. after saving an announcement.
 * $anchor is the panel to scroll to. The Super Admin has no announcements or bulletins panel on the dashboard,
 * so those two go to the Announcements page for that church instead.
 * Falls back to the public page for anyone who is not an admin.
 */
function dashboard_path(string $anchor, int $churchId): string
{
    $content = ['announcements' => '', 'bulletins' => '&tab=bulletins'];   // both admins manage these on the Announcements page
    if (isset($content[$anchor])) return 'announcements.php?church=' . $churchId . $content[$anchor];
    return match ($_SESSION['role'] ?? '') {
        'super_admin'  => 'superadmin/dashboard.php#' . $anchor,
        'church_admin' => 'churchadmin/dashboard.php#' . $anchor,
        default        => 'announcements.php?church=' . $churchId,
    };
}

/** Small line icons for the admin controls (announcement / bulletin buttons). */
function ann_icon(string $name, int $size = 15): string
{
    $paths = [
        'pencil' => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/>',
        'trash'  => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'plus'   => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'pin'    => '<path d="M12 17v5"/><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"/>',
    ];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[$name] . '</svg>';
}
// ---- Priests (a church can have several; see database/church_priests.sql) ----------------------

/**
 * SQL for "all this church's priests, comma-separated", to use as a column where only the names are needed:
 *   SELECT c.church_name, <PRIEST_NAMES_SQL> AS church_priest FROM church_info c ...
 * (the table must be aliased c).
 */
const PRIEST_NAMES_SQL = "(SELECT GROUP_CONCAT(p.priest_name ORDER BY p.sort_order, p.priest_id SEPARATOR ', ') FROM church_priests p WHERE p.church_id = c.church_info_id)";

/** A church's priests in display order. */
function church_priests(PDO $pdo, int $churchId): array
{
    try {
        $stmt = $pdo->prepare('SELECT * FROM church_priests WHERE church_id = ? ORDER BY sort_order, priest_id');
        $stmt->execute([$churchId]);
        return $stmt->fetchAll();
    } catch (PDOException $ex) {
        return [];   // table not created yet
    }
}

/** Where a priest's photo lives: admin uploads first, then the bundled clergy photos (database/seed_clergy.php). */
function priest_photo_url(?string $file): ?string
{
    if (!$file || !preg_match('/^[\w.-]+$/', $file)) return null;
    $root = dirname(__DIR__);
    foreach (['uploads/priest_photos/', 'assets/images/priests/'] as $dir) {
        if (is_file($root . '/' . $dir . $file)) return $dir . $file;
    }
    return null;
}

/** Only ever removes a photo an admin uploaded; the bundled clergy photos are shared between churches and stay. */
function delete_priest_photo(?string $file): void
{
    if ($file && preg_match('/^[\w.-]+$/', $file)) @unlink(dirname(__DIR__) . '/uploads/priest_photos/' . $file);
}
