<?php
// Real email delivery through an SMTP account (PHPMailer, in includes/phpmailer/). Used by every email
// the site sends: announcements, donation results, event messages and password resets.
// Settings live in config/mail.php (see config/mail.example.php).

if (is_file(dirname(__DIR__) . '/config/mail.php')) {
    require_once dirname(__DIR__) . '/config/mail.php';
}

/** True once an SMTP account has been filled in; until then the callers keep their old behaviour. */
function smtp_ready(): bool
{
    return defined('SMTP_USER') && defined('SMTP_PASS') && SMTP_USER !== '' && SMTP_PASS !== '';
}

/**
 * Sends one email through the SMTP account. $html is optional (plain text is always included).
 * The message comes from the SMTP account's own address under $fromName; replies go to $replyTo.
 * The connection is kept open, so sending to many subscribers in one request is quick.
 * Failures are written to logs/mail-errors.log.
 */
function smtp_send(string $to, string $subject, string $text, ?string $html = null, string $fromName = '', string $replyTo = '', array $headers = []): bool
{
    static $mailer = null;

    require_once __DIR__ . '/phpmailer/Exception.php';
    require_once __DIR__ . '/phpmailer/PHPMailer.php';
    require_once __DIR__ . '/phpmailer/SMTP.php';

    try {
        if ($mailer === null) {
            $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
            $mailer->Port = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
            $mailer->SMTPSecure = $mailer->Port === 465 ? 'ssl' : 'tls';
            $mailer->SMTPAuth = true;
            $mailer->Username = SMTP_USER;
            $mailer->Password = str_replace(' ', '', SMTP_PASS);
            $mailer->SMTPKeepAlive = true;
            $mailer->Timeout = 15;
            $mailer->CharSet = 'UTF-8';
        }
        $mailer->clearAllRecipients();
        $mailer->clearReplyTos();
        $mailer->clearCustomHeaders();

        $from = defined('SMTP_FROM') && filter_var(SMTP_FROM, FILTER_VALIDATE_EMAIL) ? SMTP_FROM : SMTP_USER;
        $mailer->setFrom($from, $fromName !== '' ? $fromName : 'Archdiocese of Kota Kinabalu');
        if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $mailer->addReplyTo($replyTo);
        }
        foreach ($headers as $name => $value) {
            $mailer->addCustomHeader($name, $value);
        }
        $mailer->addAddress($to);
        $mailer->Subject = $subject;
        if ($html !== null) {
            $mailer->isHTML(true);
            $mailer->Body = $html;
            $mailer->AltBody = $text;
        } else {
            $mailer->isHTML(false);
            $mailer->Body = $text;
            $mailer->AltBody = '';
        }
        try {
            return $mailer->send();
        } catch (Throwable $first) {
            // A dropped or refused connection is usually momentary: reconnect and try once more.
            $mailer->smtpClose();
            usleep(1500000);
            return $mailer->send();
        }
    } catch (Throwable $ex) {
        $dir = dirname(__DIR__) . '/logs';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($dir . '/mail-errors.log', date('Y-m-d H:i:s') . "  TO: $to  SUBJECT: $subject  ERROR: " . $ex->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        return false;
    }
}

/** Absolute URL of the site folder. Set APP_URL in config/db.php on a real server. */
function site_base_url(): string
{
    if (defined('APP_URL')) {
        return rtrim(APP_URL, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
}

/**
 * The designed (HTML) version of an email that carries a one-time code: the verification and the
 * password-reset emails. Plain strings in, escaped here; $after and $footer may be several sentences.
 */
function code_email_html(string $heading, string $intro, string $codeLabel, string $code, string $after, string $footer): string
{
    $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    return '<!doctype html><html><body style="margin:0;padding:0;background:#f3eff7;">'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3eff7;padding:32px 12px;"><tr><td align="center">'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e4dcee;font-family:Arial,Helvetica,sans-serif;">'
         . '<tr><td style="background:#4a0d6e;padding:22px 28px;border-bottom:4px solid #d4a72c;">'
         . '<div style="font:bold 18px Georgia,serif;color:#ffffff;">Archdiocese of Kota Kinabalu</div>'
         . '<div style="font:12px Arial,sans-serif;color:#e6d6f3;margin-top:4px;">Church Management System</div></td></tr>'
         . '<tr><td style="padding:30px 28px 8px;">'
         . '<p style="margin:0 0 6px;font:bold 20px Georgia,serif;color:#1d1d24;">' . $h($heading) . '</p>'
         . '<p style="margin:0 0 22px;font:15px/1.6 Arial,sans-serif;color:#4a4a55;">' . $h($intro) . '</p>'
         . '<div style="margin:0 0 22px;padding:18px 10px;text-align:center;background:#f6f0fb;border:1px dashed #b89ad3;border-radius:12px;">'
         . '<div style="font:bold 11px Arial,sans-serif;letter-spacing:2px;color:#6b3a93;text-transform:uppercase;margin-bottom:8px;">' . $h($codeLabel) . '</div>'
         . '<div style="font:bold 36px \'Courier New\',monospace;letter-spacing:10px;color:#4a0d6e;">' . $h($code) . '</div></div>'
         . '<p style="margin:0 0 12px;font:14px/1.6 Arial,sans-serif;color:#4a4a55;">' . $h($after) . '</p>'
         . '<p style="margin:0 0 24px;padding:10px 14px;font:13px/1.5 Arial,sans-serif;color:#6b551a;background:#fdf6df;border-left:3px solid #d4a72c;border-radius:4px;">Never share this code. The parish office will never ask you for it.</p>'
         . '</td></tr>'
         . '<tr><td style="padding:16px 28px 22px;border-top:1px solid #eee8f3;font:12px/1.5 Arial,sans-serif;color:#8a8a99;">' . $h($footer) . '<br>United in Faith, Connected in Service</td></tr>'
         . '</table></td></tr></table></body></html>';
}

/**
 * Sends one plain-text email to one person. $church (church_name, church_email) is who it comes from;
 * leave it empty for emails from the Archdiocese site itself. $html is an optional designed version, sent
 * alongside the text through the SMTP account (mail programs that cannot show it use the text).
 * Returns true if it was sent - or, on localhost with no SMTP account in config/mail.php, written to
 * logs/user-emails.log (blocked from the web).
 */
function send_plain_email(string $to, string $subject, string $body, array $church = [], ?string $html = null): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $name = trim((string)($church['church_name'] ?? '')) ?: 'Archdiocese of Kota Kinabalu';
    $mail = trim((string)($church['church_email'] ?? ''));
    $from = filter_var($mail, FILTER_VALIDATE_EMAIL) ? $mail : 'no-reply@archkk.org';
    $body = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $body));

    if (smtp_ready()) {
        return smtp_send($to, $subject, $body, $html, $name, $from);
    }

    $host = $_SERVER['SERVER_NAME'] ?? '';
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        $dir = dirname(__DIR__) . '/logs';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        return file_put_contents(
            $dir . '/user-emails.log',
            date('Y-m-d H:i:s') . "  TO: $to\n  FROM: $from\n  SUBJECT: $subject\n  " . str_replace("\r\n", "\n  ", $body) . "\n",
            FILE_APPEND | LOCK_EX
        ) !== false;
    }

    $clean = fn($v) => str_replace(["\r", "\n", '"', '<', '>'], '', (string)$v);   // no header injection through names / addresses
    $headers = 'From: "' . $clean($name) . '" <' . $from . ">\r\n"
             . 'Reply-To: ' . $from . "\r\n"
             . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8";
    return @mail($clean($to), '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}
