<?php
// One-time "set your password" links for admin accounts: made by the Super Admin on superadmin/admins.php
// (for a new admin, or to reset a forgotten password), used on admin-setup.php.
// Only a hash of the token is stored, so the link cannot be read back from the database; it works once
// and expires after ADMIN_INVITE_HOURS.

const ADMIN_INVITE_HOURS = 48;

/**
 * Give an admin a fresh link (any older one stops working). Returns [the full link, when it expires 'Y-m-d H:i:s'].
 * $siteRoot: the site's address up to and including the project folder, e.g. "http://localhost/church-management-system/".
 */
function admin_invite_create(PDO $pdo, int $adminId, string $siteRoot): array
{
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + ADMIN_INVITE_HOURS * 3600);
    $pdo->prepare('UPDATE admin SET invite_token_hash = ?, invite_expires = ? WHERE admin_id = ?')
        ->execute([hash('sha256', $token), $expires, $adminId]);
    return [$siteRoot . 'admin-setup.php?token=' . $token, $expires];
}

/** The admin a link belongs to, if it is real and still valid; otherwise null. */
function admin_invite_find(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $stmt = $pdo->prepare('SELECT * FROM admin WHERE invite_token_hash = ? AND invite_expires > NOW()');
    $stmt->execute([hash('sha256', $token)]);
    return $stmt->fetch() ?: null;
}

/** This site's address (scheme, host and project folder), e.g. "http://localhost/church-management-system/". */
function site_root_url(int $depth = 0): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/', $depth + 1));
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim($dir, '/') . '/';
}
