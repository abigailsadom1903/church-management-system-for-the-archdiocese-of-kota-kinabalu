<?php
// Shared by the Donation & Offering pages:
// donations.php (public form), donation-manage.php, donation-review.php, donation-report.php, receipt.php.

const DONATION_METHODS = [
    'bank' => 'Bank Transfer / Online Banking',
    'qr'   => 'QR Pay (DuitNow)',
];
const DONATION_METHOD_SHORT = ['bank' => 'Bank', 'qr' => 'QR Pay'];
const RECEIPT_MAX_BYTES = 5 * 1024 * 1024;

/** 1234.5 -> "RM 1,234.50" */
function rm($amount): string
{
    return 'RM ' . number_format((float)$amount, 2);
}

/** Reference shown to donors and admins, e.g. D-000012. */
function donation_ref(int $id): string
{
    return 'D-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
}

/** [label, css class, icon] for a donation status. */
function donation_status(string $status): array
{
    return match ($status) {
        'approved' => ['Approved', 'st-approved', 'check'],
        'rejected' => ['Rejected', 'st-rejected', 'x'],
        default    => ['Pending', 'st-pending', 'clock'],
    };
}

/** Line icons (24x24, stroke). */
function don_icon(string $name, int $size = 16): string
{
    $p = [
        'bank'     => '<line x1="3" x2="21" y1="22" y2="22"/><line x1="6" x2="6" y1="18" y2="11"/><line x1="10" x2="10" y1="18" y2="11"/><line x1="14" x2="14" y1="18" y2="11"/><line x1="18" x2="18" y1="18" y2="11"/><polygon points="12 2 20 7 4 7"/>',
        'qr'       => '<rect width="5" height="5" x="3" y="3" rx="1"/><rect width="5" height="5" x="16" y="3" rx="1"/><rect width="5" height="5" x="3" y="16" rx="1"/><path d="M21 16h-3a2 2 0 0 0-2 2v3"/><path d="M21 21v.01"/><path d="M12 7v3a2 2 0 0 1-2 2H7"/><path d="M3 12h.01"/><path d="M12 3h.01"/><path d="M12 16v.01"/><path d="M16 12h1"/><path d="M21 12v.01"/><path d="M12 21v-1"/>',
        'upload'   => '<path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="M12 12v9"/><path d="m16 16-4-4-4 4"/>',
        'eye'      => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'clock'    => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'check'    => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        'x'        => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
        'mail'     => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'heart'    => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'trash'    => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'copy'     => '<rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'cash'     => '<rect width="20" height="12" x="2" y="6" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/>',
        'trend'    => '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>',
        'file'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/>',
        'church'   => '<path d="m18 7 4 2v11a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9l4-2"/><path d="M14 22v-4a2 2 0 0 0-2-2a2 2 0 0 0-2 2v4"/><path d="M18 22V5l-6-3-6 3v17"/><path d="M12 7v5"/><path d="M10 9h4"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
    ];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p[$name] . '</svg>';
}

/** Absolute disk path of a stored receipt, or null. Only file names we generated are accepted. */
function receipt_path(?string $file): ?string
{
    if (!$file || !preg_match('/^[\w.-]+$/', $file)) return null;
    $path = dirname(__DIR__) . '/uploads/receipts/' . $file;
    return is_file($path) ? $path : null;
}

function delete_receipt_file(?string $file): void
{
    if ($path = receipt_path($file)) @unlink($path);
}

function qr_image_url(?string $file): ?string
{
    if (!$file || !preg_match('/^[\w.-]+$/', $file)) return null;
    return is_file(dirname(__DIR__) . '/uploads/donation_qr/' . $file) ? 'uploads/donation_qr/' . $file : null;
}

function delete_qr_file(?string $file): void
{
    if ($file && preg_match('/^[\w.-]+$/', $file)) {
        $path = dirname(__DIR__) . '/uploads/donation_qr/' . $file;
        if (is_file($path)) @unlink($path);
    }
}

/** Stops spreadsheet programs from running a cell as a formula (=, +, -, @ at the start). */
function csv_safe(string $v): string
{
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

/** Address the donor's email comes from: the church's own email when it has one. */
function donation_email_from(array $church): string
{
    $mail = trim((string)($church['church_email'] ?? ''));
    return filter_var($mail, FILTER_VALIDATE_EMAIL) ? $mail : 'no-reply@archkk.org';
}

function donation_email_subject(array $d, array $church): string
{
    return $d['status'] === 'rejected'
        ? 'About Your Donation — ' . $church['church_name']
        : 'Thank You for Your Donation — ' . $church['church_name'];
}

/** "Parish Office / Sacred Heart Cathedral / office@shckk.org · +60 88-223 222" lines for the signature. */
function donation_email_contact(array $church): string
{
    return trim(implode(' · ', array_filter([trim((string)($church['church_email'] ?? '')), trim((string)($church['church_contact_details'] ?? ''))])));
}

/**
 * The email body as HTML (inline styles, table layout, so mail programs display it properly).
 * approved -> thank-you receipt; rejected -> polite notice with the reviewer's reason.
 */
function donation_email_html(array $d, array $church): string
{
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $approved = $d['status'] !== 'rejected';
    $contact = donation_email_contact($church);

    $intro = $approved
        ? 'We are writing to confirm that your generous donation to <strong>' . $h($church['church_name']) . '</strong> has been successfully received and verified. Your contribution is a blessing to our parish community.'
        : 'Thank you for your kindness. Unfortunately we were not able to confirm your donation to <strong>' . $h($church['church_name']) . '</strong>'
          . ($d['reject_reason'] ? ' for the following reason: <em>' . $h($d['reject_reason']) . '</em>.' : '.')
          . ' If you believe this is a mistake, please contact the parish office and quote the reference below.';
    $closing = $approved
        ? 'May God bless you abundantly for your generosity and love for our church. Your support enables us to continue serving the community and carrying out the mission of Christ.'
        : 'Please do not hesitate to contact us if we can help. You are always welcome in our parish community.';

    $row = fn($label, $value) => '<tr><td style="padding:3px 0;font-size:13px;color:#333;"><strong>' . $label . ':</strong> ' . $value . '</td></tr>';
    $details = $row('Reference', $h(donation_ref((int)$d['donation_id'])))
             . $row('Purpose', $h($d['purpose']))
             . $row('Amount', $h(rm($d['amount'])))
             . $row('Payment Method', $h(DONATION_METHODS[$d['method']] ?? $d['method']))
             . $row('Date Submitted', $h(date('j M Y', strtotime($d['created_at']))));

    return '<div style="background:#f6f3ee;padding:28px 16px;font-family:Arial,Helvetica,sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;">'
        . '<tr><td style="font-size:14px;line-height:1.7;color:#333;padding-bottom:18px;">Dear <strong>' . $h($d['donor_name']) . '</strong>,</td></tr>'
        . '<tr><td style="font-size:14px;line-height:1.7;color:#333;padding-bottom:18px;">' . $intro . '</td></tr>'
        . '<tr><td style="padding-bottom:22px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #e6e1d8;border-radius:8px;">'
        . '<tr><td style="padding:16px 18px;"><div style="font-size:13px;font-weight:bold;color:#6b4fc8;padding-bottom:8px;">Donation Details</div>'
        . '<table role="presentation" cellpadding="0" cellspacing="0">' . $details . '</table></td></tr></table></td></tr>'
        . '<tr><td style="font-size:14px;line-height:1.7;color:#333;padding-bottom:22px;">' . $closing . '</td></tr>'
        . '<tr><td style="font-size:14px;line-height:1.7;color:#333;">With gratitude and blessings,<br><strong>Parish Office</strong><br>'
        . '<span style="color:#777;">' . $h($church['church_name']) . '</span>' . ($contact !== '' ? '<br><span style="color:#777;">' . $h($contact) . '</span>' : '') . '</td></tr>'
        . '</table></div>';
}

/** Plain-text twin of the HTML email (for mail programs that do not show HTML, and for the localhost log). */
function donation_email_text(array $d, array $church): string
{
    $approved = $d['status'] !== 'rejected';
    $contact = donation_email_contact($church);
    return "Dear {$d['donor_name']},\r\n\r\n"
        . ($approved
            ? "We are writing to confirm that your generous donation to {$church['church_name']} has been successfully received and verified. Your contribution is a blessing to our parish community.\r\n"
            : "Thank you for your kindness. Unfortunately we were not able to confirm your donation to {$church['church_name']}."
              . ($d['reject_reason'] ? " Reason: {$d['reject_reason']}." : '') . " If you believe this is a mistake, please contact the parish office and quote the reference below.\r\n")
        . "\r\nDONATION DETAILS\r\n"
        . 'Reference: ' . donation_ref((int)$d['donation_id']) . "\r\n"
        . "Purpose: {$d['purpose']}\r\n"
        . 'Amount: ' . rm($d['amount']) . "\r\n"
        . 'Payment Method: ' . (DONATION_METHODS[$d['method']] ?? $d['method']) . "\r\n"
        . 'Date Submitted: ' . date('j M Y', strtotime($d['created_at'])) . "\r\n\r\n"
        . ($approved
            ? "May God bless you abundantly for your generosity and love for our church. Your support enables us to continue serving the community and carrying out the mission of Christ.\r\n"
            : "Please do not hesitate to contact us if we can help.\r\n")
        . "\r\nWith gratitude and blessings,\r\nParish Office\r\n{$church['church_name']}\r\n" . ($contact !== '' ? $contact . "\r\n" : '');
}

/**
 * Tells a donor the result of the review (HTML email with a plain-text alternative).
 * Returns true if it was sent - or, on localhost where XAMPP has no mail server, written to
 * logs/donation-emails.log (blocked from the web; the admin can also preview it on screen).
 */
function send_donation_result_email(array $d, array $church): bool
{
    $subject = donation_email_subject($d, $church);
    $from = donation_email_from($church);
    $text = donation_email_text($d, $church);

    require_once __DIR__ . '/mailer.php';
    if (smtp_ready()) {   // an SMTP account is set up in config/mail.php: really send it
        return smtp_send($d['donor_email'], $subject, $text, donation_email_html($d, $church), $church['church_name'], $from);
    }

    $host = $_SERVER['SERVER_NAME'] ?? '';
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        $dir = dirname(__DIR__) . '/logs';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        return file_put_contents(
            $dir . '/donation-emails.log',
            date('Y-m-d H:i:s') . "  TO: {$d['donor_email']}\n  FROM: $from\n  SUBJECT: $subject\n  " . str_replace("\r\n", "\n  ", $text) . "\n",
            FILE_APPEND | LOCK_EX
        ) !== false;
    }

    $clean = fn($v) => str_replace(["\r", "\n", '"', '<', '>'], '', (string)$v);   // no header injection through names
    $boundary = 'b' . bin2hex(random_bytes(8));
    $headers = 'From: "' . $clean($church['church_name']) . '" <' . $from . ">\r\n"
             . 'Reply-To: ' . $from . "\r\n"
             . "MIME-Version: 1.0\r\n"
             . 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$text\r\n"
          . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n" . donation_email_html($d, $church) . "\r\n"
          . "--$boundary--";
    return @mail($clean($d['donor_email']), '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

// ---- Weekly Collection ----------------------------------------------------------------------
// Each week's total, entered on collection-manage.php and charted for parishioners on donations.php.

/** The church's most recent $limit weeks, oldest first: [['week_date' => 'Y-m-d', 'amount' => '123.00', 'note' => ...], ...] */
function collection_rows(PDO $pdo, int $churchId, int $limit = 12): array
{
    try {
        $stmt = $pdo->prepare('SELECT week_date, amount, note FROM weekly_collections WHERE church_id = ? ORDER BY week_date DESC LIMIT ' . max(1, $limit));
        $stmt->execute([$churchId]);
        return array_reverse($stmt->fetchAll());
    } catch (PDOException $ex) {
        return [];   // table not created yet (database/weekly_collections.sql)
    }
}

/** A round step for the y-axis so there are about 4 gridlines (1, 2 or 5 x 10^n). */
function collection_axis_step(float $max): float
{
    if ($max <= 0) return 100;
    $raw = $max / 4;
    $mag = 10 ** floor(log10($raw));
    foreach ([1, 2, 5, 10] as $m) {
        if ($raw <= $m * $mag) return $m * $mag;
    }
    return 10 * $mag;
}

/** Short money label for the axis: 1500 -> "1.5k". */
function collection_short(float $v): string
{
    if ($v >= 1000) return rtrim(rtrim(number_format($v / 1000, 1), '0'), '.') . 'k';
    return number_format($v);
}

/**
 * Bar chart of weekly totals (inline SVG, no library). One bar per recorded week, hover or focus a
 * bar for its exact amount, and a table view underneath for screen readers and anyone who wants the numbers.
 */
function render_collection_chart(array $rows, string $title = 'Weekly Collection'): void
{
    if (!$rows) return;
    $W = 720; $H = 260;
    $padL = 46; $padR = 12; $padT = 14; $padB = 34;
    $plotW = $W - $padL - $padR; $plotH = $H - $padT - $padB;

    $amounts = array_map(fn($r) => (float)$r['amount'], $rows);
    $step = collection_axis_step(max($amounts));
    $top = max($step, ceil(max($amounts) / $step) * $step);
    $n = count($rows);
    $slot = $plotW / $n;
    $barW = min(44, $slot * 0.62);
    $labelEvery = (int)ceil($n / 8);   // at most ~8 date labels so they never collide
    $y = fn(float $v) => $padT + $plotH - ($v / $top) * $plotH;
    $r = 4;
    ?>
    <figure class="wc-chart">
        <svg viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="<?= e($title) ?>: bar chart of the last <?= $n ?> recorded weeks. Exact figures are in the table below.">
            <?php for ($v = 0; $v <= $top + 0.001; $v += $step): $gy = round($y($v), 1); ?>
                <line class="wc-grid<?= $v == 0 ? ' is-base' : '' ?>" x1="<?= $padL ?>" x2="<?= $W - $padR ?>" y1="<?= $gy ?>" y2="<?= $gy ?>"/>
                <text class="wc-tick" x="<?= $padL - 8 ?>" y="<?= $gy + 4 ?>" text-anchor="end"><?= e(collection_short($v)) ?></text>
            <?php endfor; ?>
            <?php foreach ($rows as $i => $row):
                $amt = (float)$row['amount'];
                $cx = $padL + $slot * ($i + 0.5);
                $x = round($cx - $barW / 2, 1);
                $by = $y($amt);
                $h = max(0, $padT + $plotH - $by);
                $rr = min($r, $h, $barW / 2);
                $bw = round($barW, 1);
                // Rounded top corners only; the bar sits square on the baseline.
                $d = sprintf('M%.1f %.1f V%.1f Q%.1f %.1f %.1f %.1f H%.1f Q%.1f %.1f %.1f %.1f V%.1f Z',
                    $x, $padT + $plotH, $by + $rr, $x, $by, $x + $rr, $by, $x + $bw - $rr, $x + $bw, $by, $x + $bw, $by + $rr, $padT + $plotH);
                $date = date('j M Y', strtotime($row['week_date']));
                $tip = 'Week of ' . $date . ': ' . rm($amt) . ($row['note'] ? ' — ' . $row['note'] : '');
            ?>
                <g class="wc-bar<?= $i === $n - 1 ? ' is-latest' : '' ?>" tabindex="0" data-tip="<?= e($tip) ?>">
                    <rect class="wc-hit" x="<?= round($cx - $slot / 2, 1) ?>" y="<?= $padT ?>" width="<?= round($slot, 1) ?>" height="<?= $plotH ?>"/>
                    <?php if ($h > 0): ?><path d="<?= $d ?>"/><?php endif; ?>
                    <title><?= e($tip) ?></title>
                </g>
                <?php if (($n - 1 - $i) % $labelEvery === 0): ?>
                    <text class="wc-tick" x="<?= round($cx, 1) ?>" y="<?= $H - 12 ?>" text-anchor="middle"><?= e(date('j M', strtotime($row['week_date']))) ?></text>
                <?php endif; ?>
            <?php endforeach; ?>
        </svg>
        <div class="wc-tip" hidden></div>
        <details class="wc-table">
            <summary>Show as table</summary>
            <table>
                <thead><tr><th>Week of</th><th class="num">Amount</th><th>Note</th></tr></thead>
                <tbody>
                <?php foreach (array_reverse($rows) as $row): ?>
                    <tr><td><?= e(date('j M Y', strtotime($row['week_date']))) ?></td><td class="num"><?= e(rm($row['amount'])) ?></td><td><?= $row['note'] ? e($row['note']) : '&mdash;' ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </details>
    </figure>
    <script>
    (function () {
        var fig = document.currentScript.previousElementSibling;
        var tip = fig.querySelector('.wc-tip');
        function show(g) {
            var fb = fig.getBoundingClientRect();
            var r =(g.querySelector('path') || g.querySelector('.wc-hit')).getBoundingClientRect();
            tip.textContent = g.dataset.tip;
            tip.hidden = false;
            var left = r.left - fb.left + r.width / 2 - tip.offsetWidth / 2;
            tip.style.left = Math.max(0, Math.min(left, fig.clientWidth - tip.offsetWidth)) + 'px';
            tip.style.top = Math.max(0, r.top - fb.top - tip.offsetHeight - 8) + 'px';
        }
        fig.querySelectorAll('.wc-bar').forEach(function (g) {
            g.querySelector('title').remove();   // the custom tooltip replaces the slow native one
            ['mouseenter', 'focus'].forEach(function (t) { g.addEventListener(t, function () { show(g); }); });
            ['mouseleave', 'blur'].forEach(function (t) { g.addEventListener(t, function () { tip.hidden = true; }); });
        });
    })();
    </script>
    <?php
}
