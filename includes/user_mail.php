<?php
// Emails to a parishioner about their own account and requests: the welcome / verification email
// (register.php, profile.php, verify-email.php) and the result of a sacrament
// application, pastoral request or confession booking (sacrament-review.php, pastoral-manage.php,
// confession-manage.php).

require_once __DIR__ . '/mailer.php';

/**
 * Welcomes a new parishioner. With $needsVerifying the email carries a link to verify-email.php; only the
 * SHA-256 of its token is stored, so a database leak does not expose live links. Asking again replaces the link.
 */
function send_welcome_email(PDO $pdo, int $userId, string $email, string $name, bool $needsVerifying = true): bool
{
    $body = "Hello {$name},\n\n"
          . "Welcome to the Church Management System of the Archdiocese of Kota Kinabalu. Your account has been created.\n\n";

    if ($needsVerifying) {
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('UPDATE user SET verify_token_hash = ?, updated_at = updated_at WHERE user_id = ?')->execute([hash('sha256', $token), $userId]);
        $body .= "Please confirm that this is your email address by opening the link below:\n\n"
               . site_base_url() . '/verify-email.php?token=' . $token . "\n\n"
               . "You can already sign in; verifying simply confirms that parish notices will reach you.\n\n";
    } else {
        $body .= 'You can sign in at ' . site_base_url() . "/login.php\n\n";
    }

    $body .= "If you did not create this account, you can ignore this email.\n";
    return send_plain_email($email, ($needsVerifying ? 'Welcome - please verify your email' : 'Welcome') . ' - Archdiocese of Kota Kinabalu', $body);
}

/** Marks the account that owns this verification token as verified. Returns its row (user_name, user_email) or null. */
function verify_email_token(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT user_id, user_name, user_email FROM user WHERE verify_token_hash = ?');
    $stmt->execute([hash('sha256', $token)]);
    $user = $stmt->fetch();
    if (!$user) {
        return null;
    }
    $pdo->prepare('UPDATE user SET user_verified = 1, verify_token_hash = NULL WHERE user_id = ?')->execute([$user['user_id']]);
    return $user;
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
