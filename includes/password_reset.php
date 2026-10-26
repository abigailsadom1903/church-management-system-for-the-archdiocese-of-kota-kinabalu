<?php
// Helpers for the Forgot Password flow (forgot-password.php / reset-password.php).
// The email carries a 6-digit code (no link, so it works whatever address the site runs on); the person types it
// on reset-password.php together with their new password. Each account using the email address gets its own code.

require_once __DIR__ . '/mailer.php';

const RESET_CODE_MINUTES = 15;
const RESET_CODE_TRIES = 5;
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

    $stmt = $pdo->prepare('SELECT admin_id, admin_name, admin_role FROM admin WHERE admin_email = ?');
    $stmt->execute([$email]);
    foreach ($stmt as $row) {
        $accounts[] = ['type' => 'admin', 'id' => (int)$row['admin_id'], 'name' => $row['admin_name'], 'role' => $row['admin_role']];
    }

    $stmt = $pdo->prepare('SELECT user_id, user_name FROM user WHERE user_email = ?');
    $stmt->execute([$email]);
    foreach ($stmt as $row) {
        $accounts[] = ['type' => 'user', 'id' => (int)$row['user_id'], 'name' => $row['user_name'], 'role' => 'user'];
    }

    return $accounts;
}

/** Only this hash is stored, so a database leak does not expose live codes. The account is part of it, so codes never clash. */
function reset_code_hash(string $type, int $id, string $code): string
{
    return hash('sha256', $type . ':' . $id . ':' . $code);
}

/**
 * Creates a new reset code for an account (replacing any earlier one) and returns it,
 * or null if a code was already issued within the cooldown window.
 */
function create_reset_code(PDO $pdo, string $type, int $id): ?string
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

    // Only the newest code should work.
    $pdo->prepare('DELETE FROM password_resets WHERE account_type = ? AND account_id = ?')->execute([$type, $id]);

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pdo->prepare(
        'INSERT INTO password_resets (account_type, account_id, token_hash, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . RESET_CODE_MINUTES . ' MINUTE))'
    )->execute([$type, $id, reset_code_hash($type, $id, $code)]);

    return $code;
}

/** Emails a reset code. $account is a row from find_accounts_by_email(); its role is named, in case one address has two accounts. */
function send_reset_code_email(string $email, array $account, string $code): bool
{
    $kind = ['super_admin' => 'Super Admin', 'church_admin' => 'Church Admin'][$account['role']] ?? 'parishioner';
    $minutes = RESET_CODE_MINUTES;
    $subject = $code . ' is your password reset code - Archdiocese of Kota Kinabalu';
    $intro = "We received a request to reset the password of your {$kind} account. To choose a new password, enter this code on the reset page:";
    $after = "The code works for {$minutes} minutes and can be used once. If it runs out, you can ask for a new one on the same page.";
    $footer = 'If you did not ask to reset your password, you can ignore this email. Your password will stay the same.';

    $text = "Hello {$account['name']},\n\n{$intro}\n\n    {$code}\n\n{$after}\n\n"
          . "Never share this code. The parish office will never ask you for it.\n\n"
          . "{$footer}\n\nArchdiocese of Kota Kinabalu\n";
    $html = code_email_html('Hello, ' . $account['name'], $intro, 'Your password reset code', $code, $after, $footer);

    return send_plain_email($email, $subject, $text, [], $html);
}

/**
 * The reset row whose code was typed, for any account using this email address, or null.
 * A wrong code counts as a try against every live code of the address; after RESET_CODE_TRIES they stop working.
 */
function find_reset_by_code(PDO $pdo, string $email, string $code): ?array
{
    $rows = [];
    foreach (find_accounts_by_email($pdo, $email) as $a) {
        $stmt = $pdo->prepare('SELECT * FROM password_resets WHERE account_type = ? AND account_id = ? AND expires_at > NOW() AND attempts < ?');
        $stmt->execute([$a['type'], $a['id'], RESET_CODE_TRIES]);
        if ($row = $stmt->fetch()) $rows[] = $row;
    }
    if (preg_match('/^\d{6}$/', $code)) {
        foreach ($rows as $row) {
            if (hash_equals($row['token_hash'], reset_code_hash($row['account_type'], (int)$row['account_id'], $code))) return $row;
        }
    }
    foreach ($rows as $row) {
        $pdo->prepare('UPDATE password_resets SET attempts = attempts + 1 WHERE reset_id = ?')->execute([(int)$row['reset_id']]);
    }
    return null;
}
