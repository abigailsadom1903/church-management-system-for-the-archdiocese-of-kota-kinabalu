<?php
// "Print" and "Generate Report" for the admin application lists (sacraments, pastoral care, catechesis,
// event registrations, donations). Printing uses the browser's print dialog on the list as filtered,
// with a heading that appears only on paper; the report is a CSV (opens in Excel) of the same rows.

/**
 * The round print button and the "Generate Report" link. $reportUrl: the current page with export=csv.
 * $printUrl: for a paged list, a version of the page showing every row (see print_all()); it opens and prints itself.
 */
function report_tools(string $reportUrl, string $printLabel = 'Print this list', ?string $printUrl = null): void
{
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    ?>
    <span class="rpt-tools no-print">
        <?php if ($printUrl !== null): ?>
        <a class="sr-print" href="<?= $h($printUrl) ?>" aria-label="<?= $h($printLabel) ?>" title="Print">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
        </a>
        <?php else: ?>
        <button type="button" class="sr-print" onclick="window.print()" aria-label="<?= $h($printLabel) ?>" title="Print">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
        </button>
        <?php endif; ?>
        <a class="btn-report" href="<?= $h($reportUrl) ?>">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/></svg>
            Generate Report
        </a>
    </span>
    <?php
}

/** The current page's address with export=csv added (keeps the church and every filter). */
function report_csv_url(array $extra = []): string
{
    $q = array_merge($_GET, $extra, ['export' => 'csv']);
    unset($q['page']);
    return basename($_SERVER['SCRIPT_NAME']) . '?' . http_build_query($q);
}

/** Is this the "print every row" version of a paged list (?print=all)? */
function print_all(): bool
{
    return ($_GET['print'] ?? '') === 'all';
}

/** The current page's address with print=all (keeps the church and every filter). */
function print_all_url(): string
{
    $q = array_merge($_GET, ['print' => 'all']);
    unset($q['page'], $q['export']);
    return basename($_SERVER['SCRIPT_NAME']) . '?' . http_build_query($q);
}

/** On the print=all version: open the print dialog once the page has loaded. */
function print_on_load(): void
{
    if (print_all()) echo "<script>window.addEventListener('load', function () { window.print(); });</script>
";
}

/** Heading that is only printed: the report title, church, filters in force, and when / by whom. */
function print_heading(string $title, string $church = '', array $filters = []): void
{
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $filters = array_filter($filters, fn($v) => $v !== '' && $v !== null);
    ?>
    <div class="print-only print-head">
        <img src="assets/images/logo.png" alt="" width="46" height="46">
        <div>
            <p class="print-org">Archdiocese of Kota Kinabalu<?= $church !== '' ? ' &middot; ' . $h($church) : '' ?></p>
            <h2><?= $h($title) ?></h2>
            <p class="print-meta">
                <?php foreach ($filters as $label => $value): ?><?= $h($label) ?>: <b><?= $h($value) ?></b> &middot; <?php endforeach; ?>
                Printed <?= $h(date('j M Y, g:i a')) ?> by <?= $h($_SESSION['user_name'] ?? '') ?>
            </p>
        </div>
    </div>
    <?php
}

/** A spreadsheet cell can't start a formula (=, +, -, @): stops "CSV injection" when opened in Excel. */
function report_cell($v): string
{
    $v = (string)$v;
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

/** Send a CSV download and stop. $rows: arrays of plain values in the same order as $header. */
function send_report(string $name, array $header, iterable $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^\w.-]+/', '-', $name) . '-' . date('Ymd') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // so Excel reads the UTF-8 correctly
    fputcsv($out, $header);
    foreach ($rows as $row) fputcsv($out, array_map('report_cell', $row));
    fclose($out);
    exit;
}
