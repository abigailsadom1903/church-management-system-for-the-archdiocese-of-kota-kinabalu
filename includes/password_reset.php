<?php
// Helpers for the Forgot Password flow (forgot-password.php / reset-password.php).

const RESET_TOKEN_TTL_MINUTES = 60;
const RESET_REQUEST_COOLDOWN_SECONDS = 60;

/** Returns an error message if $password breaks the site's password rules, else null. */
function password_rule_error(string $password): ?string
{
    if (strlen($password) < 8 || !preg_match('/\d/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password must be at least 8 characters long, contain a number and a special symbol.';
    }
    return null;
}

/** Every account (admin or user table) that uses this email address. */
function find_accounts_by_email(PDO $pdo, string $email): array
{
    $accounts = [];

    $stmt = $pdo->prepare('SELECT admin_id, admin_name FROM admin WHERE admin_email = ?');
    $stmt->execute([$email]);
    foreach ($stmt as $row) {
        $accounts[] = ['type' => 'admin', 'id' => (int)$row['admin_id'], 'name' => $row['admin_name']];
    }

    $stmt = $pdo->prepare('SELECT user_id, user_name FROM user WHERE user_email = ?');
    $stmt->execute([$email]);
    foreach ($stmt as $row) {
        $accounts[] = ['type' => 'user', 'id' => (int)$row['user_id'], 'name' => $row['user_name']];
    }

    return $accounts;
}

/**
 * Creates a single-use reset token for an account and returns the raw token,
 * or null if a token was already issued within the cooldown window.
 * Only the SHA-256 hash is stored, so a database leak does not expose live links.
 */
function create_reset_token(PDO $pdo, string $type, int $id): ?string
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM password_resets
          WHERE account_type = ? AND account_id = ?
            AND created_at > DATE_SUB(NOW(), INTERVAL ' . RESET_REQUEST_COOLDOWN_SECONDS . ' SECOND)'
    );
    $stmt->execute([$type, $id]);
    if ($stmt->fetch()) {
        return null;
    }

    // Only the newest link should work.
    $pdo->prepare('DELETE FROM password_resets WHERE account_type = ? AND account_id = ?')->execute([$type, $id]);

    $token = bin2hex(random_bytes(32));
    $pdo->prepare(
        'INSERT INTO password_resets (account_type, account_id, token_hash, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . RESET_TOKEN_TTL_MINUTES . ' MINUTE))'
    )->execute([$type, $id, hash('sha256', $token)]);

    return $token;
}

/** Looks up a still-valid reset row for a raw token, or null. */
function find_valid_reset(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM password_resets WHERE token_hash = ? AND expires_at > NOW()');
    $stmt->execute([hash('sha256', $token)]);
    return $stmt->fetch() ?: null;
}

/** Absolute URL of the reset page. Set APP_URL in config/db.php on a real server. */
function reset_link(string $token): string
{
    if (defined('APP_URL')) {
        $base = rtrim(APP_URL, '/');
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    }
    return $base . '/reset-password.php?token=' . $token;
}

/**
 * Emails the reset link. On localhost (no mail server in XAMPP) the link is
 * written to logs/reset-links.log instead, which is blocked from web access.
 */
function send_reset_email(string $email, string $name, string $link): void
{
    $subject = 'Reset your password - Archdiocese of Kota Kinabalu';
    $body = "Hello {$name},\r\n\r\n"
          . "We received a request to reset your password. Use the link below within "
          . RESET_TOKEN_TTL_MINUTES . " minutes:\r\n\r\n{$link}\r\n\r\n"
          . "If you did not request this, you can ignore this email and your password will stay the same.\r\n";

    require_once __DIR__ . '/mailer.php';
    if (smtp_ready()) {   // an SMTP account is set up in config/mail.php: really send it
        smtp_send($email, $subject, $body);
        return;
    }

    $host = $_SERVER['SERVER_NAME'] ?? '';
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        $dir = __DIR__ . '/../logs';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $dir . '/reset-links.log',
            date('Y-m-d H:i:s') . "  {$email}  {$link}\n",
            FILE_APPEND | LOCK_EX
        );
        return;
    }

    // Strip line breaks from anything user-influenced that goes into headers.
    $headers = 'From: no-reply@archkk.org' . "\r\n" . 'Content-Type: text/plain; charset=UTF-8';
    @mail(str_replace(["\r", "\n"], '', $email), $subject, $body, $headers);
}
