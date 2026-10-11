<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/donation_helpers.php';
require __DIR__ . '/includes/report_tools.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Super Admin only: donations across every church.
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'super_admin') {
    header('Location: ' . (empty($_SESSION['user_id']) ? 'login.php' : 'donations.php'));
    exit;
}

$churches = $pdo->query('SELECT church_info_id, church_name FROM church_info ORDER BY church_name')->fetchAll();
$churchIds = array_column($churches, 'church_info_id');
$churchId = in_array((int)($_GET['church'] ?? 0), array_map('intval', $churchIds), true) ? (int)$_GET['church'] : 0;   // 0 = all churches
$tab = ($_GET['tab'] ?? '') === 'summary' ? 'summary' : 'records';
$page = max(1, (int)($_GET['page'] ?? 1));
const REPORT_PER_PAGE = 20;

// Only approved donations count as collections. Pending / rejected ones are handled by the church admins.
$where = ["d.status = 'approved'"];
$params = [];
if ($churchId) { $where[] = 'd.church_id = ?'; $params[] = $churchId; }
$whereSql = implode(' AND ', $where);

// ---- CSV: "Generate Report" ------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    $stmt = $pdo->prepare("SELECT d.*, c.church_name FROM donations d JOIN church_info c ON c.church_info_id = d.church_id WHERE $whereSql ORDER BY d.created_at DESC, d.donation_id DESC");
    $stmt->execute($params);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="donations-report-' . date('Ymd') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // so Excel reads the UTF-8 correctly
    fputcsv($out, ['Reference', 'Date', 'Church', 'Donor', 'Email', 'Phone', 'Purpose', 'Method', 'Amount (RM)']);
    foreach ($stmt as $r) {
        fputcsv($out, [
            donation_ref((int)$r['donation_id']), date('Y-m-d', strtotime($r['created_at'])), csv_safe($r['church_name']), csv_safe($r['donor_name']),
            csv_safe($r['donor_email']), csv_safe($r['donor_phone']), csv_safe($r['purpose']), DONATION_METHOD_SHORT[$r['method']], number_format((float)$r['amount'], 2, '.', ''),
        ]);
    }
    fclose($out);
    exit;
}

// ---- Numbers -----------------------------------------------------------------------------
$stmt = $pdo->prepare("SELECT COUNT(*) AS records, COALESCE(SUM(d.amount), 0) AS total, COUNT(DISTINCT d.church_id) AS reporting FROM donations d WHERE $whereSql");
$stmt->execute($params);
$s = $stmt->fetch();
$records = (int)$s['records'];
$total = (float)$s['total'];
$average = $records ? $total / $records : 0;

$rows = [];
$pages = 1;
$groups = [];
if ($tab === 'records') {
    $pages = print_all() ? 1 : max(1, (int)ceil($records / REPORT_PER_PAGE));   // printing: every record on one page
    $page = min($page, $pages);
    $stmt = $pdo->prepare(
        "SELECT d.*, c.church_name FROM donations d JOIN church_info c ON c.church_info_id = d.church_id
          WHERE $whereSql ORDER BY d.created_at DESC, d.donation_id DESC" . (print_all() ? '' : ' LIMIT ' . REPORT_PER_PAGE . ' OFFSET ' . (($page - 1) * REPORT_PER_PAGE))
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} else {
    // All churches: collections per church. One church: collections per purpose.
    $groupCol = $churchId ? 'd.purpose' : 'c.church_name';
    $stmt = $pdo->prepare(
        "SELECT $groupCol AS label, COUNT(*) AS n, SUM(d.amount) AS amount
           FROM donations d JOIN church_info c ON c.church_info_id = d.church_id
          WHERE $whereSql GROUP BY label ORDER BY amount DESC, label"
    );
    $stmt->execute($params);
    $groups = $stmt->fetchAll();
}

/** Rounds a chart maximum up to a "nice" number (1, 2, 2.5, 5, 10 x 10^n). */
function nice_max(float $v): float
{
    if ($v <= 0) return 100;
    $mag = 10 ** floor(log10($v));
    foreach ([1, 2, 2.5, 5, 10] as $step) if ($step * $mag >= $v) return $step * $mag;
    return 10 * $mag;
}
function short_label(string $s, int $max = 12): string
{
    $s = preg_replace('/\s+Church$/i', '', $s);
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
}
function report_url(array $over = []): string
{
    $p = array_merge(['church' => $_GET['church'] ?? '', 'tab' => $_GET['tab'] ?? '', 'page' => 1], $over);
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0' && $v !== 1 && $v !== 'records');
    return 'donation-report.php' . ($p ? '?' . http_build_query($p, '', '&amp;') : '');
}

$pageTitle = 'Donation Reports';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="dm-page dg-page">
    <?php back_button('donations.php', 'Back to Donation & Offering'); ?>
    <header class="page-heading">
        <h1>Donation and Offering</h1>
        <p>Collections across every parish of the Archdiocese.</p>
    </header>

    <?php $printChurch = $churchId ? $churches[array_search($churchId, array_map('intval', $churchIds), true)]['church_name'] : 'All churches'; ?>
    <?php print_heading('Donation Report: ' . ($tab === 'summary' ? 'Summary' : 'Records'), '', [
        'Church' => $printChurch, 'Confirmed donations' => (string)$records, 'Total collections' => rm($total),
    ]); ?>

    <div class="dg-stats">
        <div class="dg-stat"><div><span class="dg-label">Total Collections</span><span class="dg-num"><?= rm($total) ?></span><span class="dg-sub c-green"><?= $churchId ? e(short_label($churches[array_search($churchId, array_map('intval', $churchIds), true)]['church_name'], 30)) : 'All churches' ?></span></div><span class="dg-ico bg-green"><?= don_icon('cash', 22) ?></span></div>
        <div class="dg-stat"><div><span class="dg-label">Total Records</span><span class="dg-num"><?= $records ?></span><span class="dg-sub">confirmed donations</span></div><span class="dg-ico bg-blue"><?= don_icon('file', 22) ?></span></div>
        <div class="dg-stat"><div><span class="dg-label">Average Donation</span><span class="dg-num"><?= rm($average) ?></span><span class="dg-sub">per transaction</span></div><span class="dg-ico bg-purple"><?= don_icon('trend', 22) ?></span></div>
        <div class="dg-stat"><div><span class="dg-label">Churches Reporting</span><span class="dg-num"><?= (int)$s['reporting'] ?></span><span class="dg-sub">of <?= count($churches) ?> total</span></div><span class="dg-ico bg-violet"><?= don_icon('church', 22) ?></span></div>
    </div>

    <section class="dg-card">
        <div class="dg-head">
            <div>
                <h2>Donation Management</h2>
                <p>View donation records, summaries and totals across all churches, and generate reports.</p>
            </div>
            <div class="dg-tools">
                <nav class="seg-tabs sm" aria-label="Records or summary">
                    <a href="<?= report_url(['tab' => '']) ?>" class="<?= $tab === 'records' ? 'is-active' : '' ?>">Records</a>
                    <a href="<?= report_url(['tab' => 'summary']) ?>" class="<?= $tab === 'summary' ? 'is-active' : '' ?>">Summary</a>
                </nav>
                <form method="get" action="donation-report.php" id="report-form">
                    <?php if ($tab === 'summary'): ?><input type="hidden" name="tab" value="summary"><?php endif; ?>
                    <select name="church" aria-label="Filter by church" onchange="this.form.submit()">
                        <option value="">All Churches</option>
                        <?php foreach ($churches as $ch): ?><option value="<?= (int)$ch['church_info_id'] ?>" <?= (int)$ch['church_info_id'] === $churchId ? 'selected' : '' ?>><?= e($ch['church_name']) ?></option><?php endforeach; ?>
                    </select>
                </form>
                <a class="btn-report" href="donation-settings.php"><?= don_icon('settings', 15) ?> Payment details</a>
                <?php report_tools('donation-report.php?' . http_build_query(array_filter(['church' => $churchId ?: '', 'export' => 'csv'])), 'Print this report', $tab === 'records' ? print_all_url() : null); ?>
            </div>
        </div>

        <?php if ($tab === 'records'): ?>
            <div class="table-wrap">
                <table class="dg-table">
                    <thead><tr><th>Donor</th><th>Church</th><th>Type</th><th>Method</th><th>Date</th><th class="num">Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="dn-name"><?= e($r['donor_name']) ?></td>
                            <td><?= e($r['church_name']) ?></td>
                            <td><span class="type-tag"><?= e($r['purpose']) ?></span></td>
                            <td><span class="pay-tag <?= $r['method'] === 'qr' ? 'is-qr' : 'is-bank' ?>"><?= e(DONATION_METHOD_SHORT[$r['method']]) ?></span></td>
                            <td class="dn-date"><?= e(date('Y-m-d', strtotime($r['created_at']))) ?></td>
                            <td class="num dn-money"><?= rm($r['amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="6" class="empty">No confirmed donations yet<?= $churchId ? ' for this church' : '' ?>.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pages > 1): ?>
                <nav class="bul-pager" aria-label="Report pages">
                    <?php if ($page > 1): ?><a class="pg-arrow" href="<?= report_url(['page' => $page - 1]) ?>" aria-label="Previous page">&lsaquo;</a><?php else: ?><span class="pg-arrow is-off" aria-hidden="true">&lsaquo;</span><?php endif; ?>
                    <?php for ($p = 1; $p <= $pages; $p++): ?>
                        <?php if ($p === $page): ?><span class="pg-num is-current" aria-current="page"><?= $p ?></span><?php else: ?><a class="pg-num" href="<?= report_url(['page' => $p]) ?>" aria-label="Page <?= $p ?>"><?= $p ?></a><?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($page < $pages): ?><a class="pg-arrow" href="<?= report_url(['page' => $page + 1]) ?>" aria-label="Next page">&rsaquo;</a><?php else: ?><span class="pg-arrow is-off" aria-hidden="true">&rsaquo;</span><?php endif; ?>
                </nav>
            <?php endif; ?>

        <?php else: ?>
            <?php if (!$groups): ?>
                <p class="ann-empty dg-empty">No confirmed donations yet<?= $churchId ? ' for this church' : '' ?>.</p>
            <?php else:
                $top = array_slice($groups, 0, 8);
                $max = nice_max((float)max(array_column($top, 'amount')));
                $W = 560; $H = 300; $L = 52; $R = 12; $T = 14; $Bm = 44;
                $plotW = $W - $L - $R; $plotH = $H - $T - $Bm;
                $slot = $plotW / count($top); $barW = min(54, $slot * 0.62);
            ?>
            <div class="dg-summary">
                <div>
                    <h3>Collections by <?= $churchId ? 'Purpose' : 'Church' ?></h3>
                    <svg class="dg-chart" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Bar chart of collections by <?= $churchId ? 'purpose' : 'church' ?>">
                        <?php for ($i = 0; $i <= 4; $i++): $v = $max * $i / 4; $y = $T + $plotH - ($plotH * $i / 4); ?>
                            <line x1="<?= $L ?>" y1="<?= $y ?>" x2="<?= $W - $R ?>" y2="<?= $y ?>" class="grid"/>
                            <text x="<?= $L - 8 ?>" y="<?= $y + 4 ?>" class="axis" text-anchor="end"><?= number_format($v) ?></text>
                        <?php endfor; ?>
                        <?php foreach ($top as $i => $g):
                            $h = $plotH * ((float)$g['amount'] / $max); $x = $L + $slot * $i + ($slot - $barW) / 2; ?>
                            <g><title><?= e($g['label']) ?>: <?= rm($g['amount']) ?></title>
                            <rect x="<?= round($x, 1) ?>" y="<?= round($T + $plotH - $h, 1) ?>" width="<?= round($barW, 1) ?>" height="<?= round($h, 1) ?>" rx="4" class="bar"/>
                            <text x="<?= round($x + $barW / 2, 1) ?>" y="<?= $H - 24 ?>" class="axis" text-anchor="middle"><?= e(short_label($g['label'])) ?></text></g>
                        <?php endforeach; ?>
                    </svg>
                    <?php if (count($groups) > 8): ?><p class="dg-note">The chart shows the top 8; every one is listed in the breakdown.</p><?php endif; ?>
                </div>
                <div>
                    <h3>Breakdown</h3>
                    <ul class="dg-breakdown">
                        <?php foreach ($groups as $g): $pct = $total > 0 ? round((float)$g['amount'] / $total * 100) : 0; ?>
                            <li>
                                <div><strong><?= e($g['label']) ?></strong><small><?= (int)$g['n'] ?> donation<?= (int)$g['n'] === 1 ? '' : 's' ?></small></div>
                                <div class="dg-right"><strong><?= rm($g['amount']) ?></strong><small><?= $pct ?>% of total</small></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>
<?php print_on_load(); ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
