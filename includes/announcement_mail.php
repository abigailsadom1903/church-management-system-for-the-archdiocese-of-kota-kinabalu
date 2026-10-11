<?php
// Emails to a church's "Stay Updated" subscribers (announcements.php, announcement-form.php, unsubscribe.php).

require_once __DIR__ . '/mailer.php';

function announcement_email_subject(array $a, array $church): string
{
    return ($a['is_urgent'] ? '[Urgent] ' : '') . $a['title'] . ' — ' . $church['church_name'];
}

/** Plain-text version (for mail programs that do not show HTML, and for the localhost log). */
function announcement_email_text(array $a, array $church, string $viewUrl, string $unsubUrl): string
{
    return "{$a['title']}\r\n"
        . $a['category'] . ' · ' . date('j M Y', strtotime($a['publish_date'])) . ($a['is_urgent'] ? ' · URGENT' : '') . "\r\n\r\n"
        . str_replace("\n", "\r\n", $a['body']) . "\r\n\r\n"
        . "See all announcements: $viewUrl\r\n\r\n"
        . "Parish Office\r\n{$church['church_name']}\r\n\r\n"
        . "You are receiving this because you subscribed to updates from {$church['church_name']}.\r\n"
        . "Unsubscribe: $unsubUrl\r\n";
}

/** The same message as HTML (inline styles, table layout, so mail programs display it properly). */
function announcement_email_html(array $a, array $church, string $viewUrl, string $unsubUrl): string
{
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $meta = $h($a['category']) . ' &middot; ' . $h(date('j M Y', strtotime($a['publish_date'])))
          . ($a['is_urgent'] ? ' &middot; <strong style="color:#b3261e;">URGENT</strong>' : '');

    return '<div style="background:#f6f3ee;padding:28px 16px;font-family:Arial,Helvetica,sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;">'
        . '<tr><td style="font-size:13px;color:#6b4fc8;font-weight:bold;padding-bottom:10px;">New announcement from ' . $h($church['church_name']) . '</td></tr>'
        . '<tr><td style="padding-bottom:22px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #e6e1d8;border-radius:8px;">'
        . '<tr><td style="padding:18px 20px;">'
        . '<div style="font-size:19px;font-weight:bold;color:#2b1a4a;padding-bottom:6px;">' . $h($a['title']) . '</div>'
        . '<div style="font-size:12px;color:#777;padding-bottom:14px;">' . $meta . '</div>'
        . '<div style="font-size:14px;line-height:1.7;color:#333;">' . nl2br($h($a['body'])) . '</div>'
        . '</td></tr></table></td></tr>'
        . '<tr><td style="padding-bottom:22px;"><a href="' . $h($viewUrl) . '" style="display:inline-block;background:#4a1d6e;color:#ffffff;text-decoration:none;font-size:14px;font-weight:bold;padding:10px 18px;border-radius:6px;">View all announcements</a></td></tr>'
        . '<tr><td style="font-size:14px;line-height:1.7;color:#333;padding-bottom:22px;"><strong>Parish Office</strong><br><span style="color:#777;">' . $h($church['church_name']) . '</span></td></tr>'
        . '<tr><td style="font-size:12px;line-height:1.6;color:#888;border-top:1px solid #e6e1d8;padding-top:14px;">You are receiving this because you subscribed to updates from ' . $h($church['church_name']) . '. '
        . '<a href="' . $h($unsubUrl) . '" style="color:#888;">Unsubscribe</a></td></tr>'
        . '</table></div>';
}

/**
 * Sends one announcement to one subscriber (HTML email with a plain-text alternative).
 * Returns true if it was sent - or, on localhost where XAMPP has no mail server, written to
 * logs/announcement-emails.log (blocked from the web).
 */
function send_announcement_email(string $to, array $a, array $church, string $viewUrl, string $unsubUrl): bool
{
    $subject = announcement_email_subject($a, $church);
    $mail = trim((string)($church['church_email'] ?? ''));
    $from = filter_var($mail, FILTER_VALIDATE_EMAIL) ? $mail : 'no-reply@archkk.org';
    $text = announcement_email_text($a, $church, $viewUrl, $unsubUrl);

    if (smtp_ready()) {   // an SMTP account is set up in config/mail.php: really send it
        return smtp_send($to, $subject, $text, announcement_email_html($a, $church, $viewUrl, $unsubUrl), $church['church_name'], $from, ['List-Unsubscribe' => '<' . $unsubUrl . '>']);
    }

    $host = $_SERVER['SERVER_NAME'] ?? '';
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        $dir = dirname(__DIR__) . '/logs';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        return file_put_contents(
            $dir . '/announcement-emails.log',
            date('Y-m-d H:i:s') . "  TO: $to\n  FROM: $from\n  SUBJECT: $subject\n  " . str_replace("\r\n", "\n  ", $text) . "\n",
            FILE_APPEND | LOCK_EX
        ) !== false;
    }

    $clean = fn($v) => str_replace(["\r", "\n", '"', '<', '>'], '', (string)$v);   // no header injection through names / addresses
    $boundary = 'b' . bin2hex(random_bytes(8));
    $headers = 'From: "' . $clean($church['church_name']) . '" <' . $from . ">\r\n"
             . 'Reply-To: ' . $from . "\r\n"
             . 'List-Unsubscribe: <' . $unsubUrl . ">\r\n"
             . "MIME-Version: 1.0\r\n"
             . 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$text\r\n"
          . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n" . announcement_email_html($a, $church, $viewUrl, $unsubUrl) . "\r\n"
          . "--$boundary--";
    return @mail($clean($to), '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

/**
 * Emails every announcement of this church that is published (not scheduled, not expired) and has not
 * been emailed yet to the church's subscribers. Returns how many emails went out.
 *
 * Called right after an announcement is saved, and again whenever announcements.php is opened, which is
 * what sends the scheduled ones once their date arrives (there is no cron job).
 */
function send_due_announcement_emails(PDO $pdo, int $churchId): int
{
    try {
        $stmt = $pdo->prepare(
            'SELECT announcement_id, title, body, category, is_urgent, publish_date
               FROM announcements
              WHERE church_id = ? AND notified_at IS NULL
                AND publish_date <= CURDATE() AND (expires_date IS NULL OR expires_date >= CURDATE())
           ORDER BY publish_date, announcement_id'
        );
        $stmt->execute([$churchId]);
        $due = $stmt->fetchAll();
        if (!$due) return 0;

        $stmt = $pdo->prepare('SELECT church_name, church_email FROM church_info WHERE church_info_id = ?');
        $stmt->execute([$churchId]);
        $church = $stmt->fetch();
        if (!$church) return 0;

        $stmt = $pdo->prepare('SELECT subscriber_id, email, unsub_token FROM newsletter_subscribers WHERE church_id = ?');
        $stmt->execute([$churchId]);
        $subscribers = $stmt->fetchAll();

        $claim    = $pdo->prepare('UPDATE announcements SET notified_at = NOW(), updated_at = updated_at WHERE announcement_id = ? AND notified_at IS NULL');
        $setToken = $pdo->prepare('UPDATE newsletter_subscribers SET unsub_token = ? WHERE subscriber_id = ?');
        $viewUrl  = site_base_url() . '/announcements.php?church=' . $churchId;
        $sent = 0;

        foreach ($due as $a) {
            // Marked first, so two page loads at the same moment cannot both send it.
            $claim->execute([$a['announcement_id']]);
            if ($claim->rowCount() !== 1) continue;
            $ok = 0;

            foreach ($subscribers as &$s) {
                if (!$s['unsub_token']) {   // signed up before unsubscribe links existed
                    $s['unsub_token'] = bin2hex(random_bytes(16));
                    $setToken->execute([$s['unsub_token'], $s['subscriber_id']]);
                }
                if (send_announcement_email($s['email'], $a, $church, $viewUrl, site_base_url() . '/unsubscribe.php?token=' . $s['unsub_token'])) {
                    $sent++;
                    $ok++;
                } elseif ($ok === 0) {
                    break;   // the very first one failed: the mail account is not working, do not try the rest
                }
            }
            unset($s);

            if ($subscribers && $ok === 0) {
                // Nothing went out: leave it unsent so it is tried again the next time the page is opened.
                $pdo->prepare('UPDATE announcements SET notified_at = NULL, updated_at = updated_at WHERE announcement_id = ?')->execute([$a['announcement_id']]);
                break;
            }
        }
        return $sent;
    } catch (PDOException $ex) {
        return 0;   // columns not added yet (database/announcement_emails.sql)
    }
}

/** True while a published announcement of this church is still waiting to be emailed (the last attempt failed). */
function announcement_emails_pending(PDO $pdo, int $churchId): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM announcements
              WHERE church_id = ? AND notified_at IS NULL
                AND publish_date <= CURDATE() AND (expires_date IS NULL OR expires_date >= CURDATE()) LIMIT 1'
        );
        $stmt->execute([$churchId]);
        return (bool)$stmt->fetch();
    } catch (PDOException $ex) {
        return false;
    }
}
