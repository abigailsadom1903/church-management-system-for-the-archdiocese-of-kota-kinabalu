<?php
// Emails to a parishioner about their own account and requests: the welcome / verification-code email
// (register.php, google-register.php, profile.php, verify-email.php) and the result of a sacrament
// application, pastoral request or confession booking (sacrament-review.php, pastoral-manage.php,
// confession-manage.php).

require_once __DIR__ . '/mailer.php';

// ---- Email verification by a one-time code ------------------------------------------------------------
// The email carries a 6-digit code (no link, so it works whatever address the site runs on); the parishioner
// types it on verify-email.php. Only a SHA-256 of the user id and code is stored. A code works for
// VERIFY_CODE_MINUTES and VERIFY_CODE_TRIES wrong guesses; asking for a new one replaces it.

const VERIFY_CODE_MINUTES = 15;
const VERIFY_CODE_TRIES = 5;

function verify_code_hash(int $userId, string $code): string
{
    return hash('sha256', $userId . ':' . $code);
}

/**
 * Emails a new verification code. $welcome: the first email after registering (it also says welcome);
 * otherwise a code asked for again, or for a changed email address.
 */
function send_verification_code(PDO $pdo, int $userId, string $email, string $name, bool $welcome = false): bool
{
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE user SET verify_token_hash = ?, verify_expires = ?, verify_attempts = 0, updated_at = updated_at WHERE user_id = ?')
        ->execute([verify_code_hash($userId, $code), date('Y-m-d H:i:s', time() + VERIFY_CODE_MINUTES * 60), $userId]);

    $subject = $code . ' is your verification code - Archdiocese of Kota Kinabalu';
    $intro = $welcome
        ? 'Welcome to the Church Management System of the Archdiocese of Kota Kinabalu. Your account has been created. To confirm that this email address is yours, enter this code on the verification page:'
        : 'Here is your code to verify this email address for your account with the Archdiocese of Kota Kinabalu:';
    $minutes = VERIFY_CODE_MINUTES;

    $after = "The code works for {$minutes} minutes. If it runs out, you can ask for a new one on the same page or on your profile.";
    $footer = 'If you did not create this account, you can ignore this email.';

    $text = "Hello {$name},\n\n{$intro}\n\n    {$code}\n\n{$after}\n\n"
          . "Never share this code. The parish office will never ask you for it.\n\n"
          . "{$footer}\n\nArchdiocese of Kota Kinabalu\n";
    $html = code_email_html(($welcome ? 'Welcome, ' : 'Hello, ') . $name, $intro, 'Your verification code', $code, $after, $footer);

    return send_plain_email($email, $subject, $text, [], $html);
}

/** Welcome email for an account made with Google (google-register.php): Google already verified the address, so no code. */
function send_google_welcome_email(string $email, string $name): bool
{
    $body = "Hello {$name},\n\n"
          . "Welcome to the Church Management System of the Archdiocese of Kota Kinabalu. Your account has been created with your Google account.\n\n"
          . 'Next time, use "Continue with Google" at ' . site_base_url() . "/login.php\n\n"
          . "If you did not create this account, you can ignore this email.\n\nArchdiocese of Kota Kinabalu\n";
    return send_plain_email($email, 'Welcome - Archdiocese of Kota Kinabalu', $body);
}

/**
 * Checks a typed code against the account's current one, and verifies the email when it matches.
 * Returns 'ok', 'wrong' (with tries left), 'expired', 'locked' (too many wrong tries) or 'none' (no code sent).
 */
function verify_email_code(PDO $pdo, int $userId, string $code): string
{
    $stmt = $pdo->prepare('SELECT verify_token_hash, verify_expires, verify_attempts FROM user WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row || !$row['verify_token_hash'] || !$row['verify_expires']) return 'none';
    if ((int)$row['verify_attempts'] >= VERIFY_CODE_TRIES) return 'locked';
    if (strtotime($row['verify_expires']) < time()) return 'expired';

    if (preg_match('/^\d{6}$/', $code) && hash_equals($row['verify_token_hash'], verify_code_hash($userId, $code))) {
        $pdo->prepare('UPDATE user SET user_verified = 1, verify_token_hash = NULL, verify_expires = NULL, verify_attempts = 0 WHERE user_id = ?')->execute([$userId]);
        return 'ok';
    }
    $pdo->prepare('UPDATE user SET verify_attempts = verify_attempts + 1, updated_at = updated_at WHERE user_id = ?')->execute([$userId]);
    return (int)$row['verify_attempts'] + 1 >= VERIFY_CODE_TRIES ? 'locked' : 'wrong';
}

/** church_name and church_email of a church (who a parish email comes from). */
function mail_church(PDO $pdo, int $churchId): array
{
    $stmt = $pdo->prepare('SELECT church_name, church_email FROM church_info WHERE church_info_id = ?');
    $stmt->execute([$churchId]);
    return $stmt->fetch() ?: [];
}

/**
 * Tells a parishioner that the parish office changed the status of something they asked for.
 * $what e.g. "Baptism application", $ref e.g. "APP-00012", $status the label they see on the site
 * ("Approved"), $note the office's note (may be empty), $page where they can see it (relative to the site).
 */
function send_status_email(PDO $pdo, int $churchId, string $to, string $name, string $what, string $ref, string $status, ?string $note, string $page): bool
{
    $church = mail_church($pdo, $churchId);
    $parish = $church['church_name'] ?? 'your parish';
    $body = "Hello {$name},\n\n"
          . "There is an update on your {$what} ({$ref}) at {$parish}.\n\n"
          . "Status: {$status}\n"
          . (trim((string)$note) !== '' ? 'Note from the parish office: ' . trim((string)$note) . "\n" : '')
          . "\nYou can see the details here:\n" . site_base_url() . '/' . $page . "\n\n"
          . "Parish Office\n{$parish}\n";
    return send_plain_email($to, "{$what} {$ref}: {$status} - {$parish}", $body, $church);
}

/** Tells a parishioner that the parish office cancelled their confession booking. $date 'Y-m-d', $time 'H:i' (may be empty). */
function send_confession_cancelled_email(PDO $pdo, int $churchId, string $to, string $name, string $ref, string $date, string $time): bool
{
    $church = mail_church($pdo, $churchId);
    $parish = $church['church_name'] ?? 'your parish';
    $when = ($date !== '' && strtotime($date) ? date('l, j F Y', strtotime($date)) : 'the day you chose')
          . ($time !== '' && strtotime($time) ? ' at ' . date('g:i A', strtotime($time)) : '');
    $body = "Hello {$name},\n\n"
          . "We are sorry to tell you that your confession booking ({$ref}) at {$parish} on {$when} has been cancelled by the parish office.\n\n"
          . "You are welcome to choose another time here:\n" . site_base_url() . "/sacrament-confession.php\n\n"
          . "Parish Office\n{$parish}\n";
    return send_plain_email($to, "Confession booking {$ref} cancelled - {$parish}", $body, $church);
}
