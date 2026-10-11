<?php
// Analytics on the admin Overview pages: superadmin/dashboard.php (every church, or one picked from the filter)
// and churchadmin/dashboard.php (the admin's own church). One set of queries, scoped by church.
// Charts are inline SVG / HTML like render_collection_chart(): no library, and every value is readable without hovering.

require_once __DIR__ . '/donation_helpers.php';
require_once __DIR__ . '/sacrament_helpers.php';
require_once __DIR__ . '/pastoral_helpers.php';

const AN_RANGES = [3 => 'Last 3 months', 6 => 'Last 6 months', 12 => 'Last 12 months', 24 => 'Last 24 months'];

/** The period picked in the filter (?range=), 12 months by default. */
function an_months($requested): int
{
    $m = (int)$requested;
    return isset(AN_RANGES[$m]) ? $m : 12;
}

/** Runs a query; a table that is not created yet gives no rows instead of an error. */
function an_rows(PDO $pdo, string $sql): array
{
    try {
        return $pdo->query($sql)->fetchAll();
    } catch (PDOException $ex) {
        return [];
    }
}

function an_one(PDO $pdo, string $sql): array
{
    return an_rows($pdo, $sql)[0] ?? [];
}

/**
 * Everything the analytics page shows, for one church ($churchId) or every church (null).
 * The period is the last $months calendar months including this one; "prev" is the same length just before it.
 */
function an_data(PDO $pdo, ?int $churchId, int $months): array
{
    $first = new DateTimeImmutable('first day of this month 00:00');
    $startD = $first->modify('-' . ($months - 1) . ' months');
    $prevD = $startD->modify('-' . $months . ' months');
    $start = $pdo->quote($startD->format('Y-m-d'));
    $prev = $pdo->quote($prevD->format('Y-m-d'));
    // Church ids are ints, so they go into the SQL as they are; dates are quoted above.
    $in = fn(string $col) => $churchId ? $col . ' = ' . (int)$churchId : '1=1';

    // One bucket per month, oldest first, so a month with nothing in it still shows as zero.
    $buckets = [];
    for ($i = 0; $i < $months; $i++) {
        $m = $startD->modify("+$i months");
        $buckets[$m->format('Y-m')] = ['label' => $m->format('M'), 'full' => $m->format('F Y'), 'value' => 0.0];
    }
    $monthly = function (array $rows) use ($buckets): array {
        foreach ($rows as $r) {
            if (isset($buckets[$r['ym']])) $buckets[$r['ym']]['value'] = (float)$r['v'];
        }
        return array_values($buckets);
    };
    $period = fn(string $col) => "SUM($col >= $start) AS cur, SUM($col >= $prev AND $col < $start) AS prev";
    $d = ['months' => $months, 'start' => $startD, 'church_id' => $churchId];

    // ---- Parishioners
    $d['members'] = an_one($pdo, "SELECT COUNT(*) AS total, COALESCE(SUM(user_verified), 0) AS verified, {$period('created_at')} FROM user WHERE {$in('church_id')}");
    $d['members_monthly'] = $monthly(an_rows($pdo,
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS v FROM user WHERE {$in('church_id')} AND created_at >= $start GROUP BY ym"));
    $ages = an_rows($pdo,
        "SELECT CASE WHEN date_of_birth IS NULL THEN 'Not given'
                     WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) < 18 THEN 'Under 18'
                     WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) < 31 THEN '18 – 30'
                     WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) < 46 THEN '31 – 45'
                     WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) < 61 THEN '46 – 60'
                     ELSE '61 and over' END AS band, COUNT(*) AS v
           FROM user WHERE {$in('church_id')} GROUP BY band");
    $ages = array_column($ages, 'v', 'band');
    $d['ages'] = [];
    foreach (['Under 18', '18 – 30', '31 – 45', '46 – 60', '61 and over', 'Not given'] as $band) {   // fixed order: it is an ordered scale
        $d['ages'][] = ['label' => $band, 'value' => (float)($ages[$band] ?? 0)];
    }

    // ---- Giving: approved online donations + weekly collections
    $d['donations'] = an_one($pdo,
        "SELECT SUM(CASE WHEN created_at >= $start THEN amount ELSE 0 END) AS cur,
                SUM(CASE WHEN created_at >= $prev AND created_at < $start THEN amount ELSE 0 END) AS prev,
                COUNT(DISTINCT CASE WHEN created_at >= $start THEN donor_email END) AS donors
           FROM donations WHERE {$in('church_id')} AND status = 'approved'");
    $d['donations_monthly'] = $monthly(an_rows($pdo,
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, SUM(amount) AS v FROM donations
          WHERE {$in('church_id')} AND status = 'approved' AND created_at >= $start GROUP BY ym"));
    $d['donation_purposes'] = array_map(fn($r) => ['label' => $r['purpose'], 'value' => (float)$r['v'], 'note' => $r['n'] . ' gift' . ($r['n'] == 1 ? '' : 's')], an_rows($pdo,
        "SELECT purpose, SUM(amount) AS v, COUNT(*) AS n FROM donations
          WHERE {$in('church_id')} AND status = 'approved' AND created_at >= $start GROUP BY purpose ORDER BY v DESC LIMIT 8"));
    $st = an_one($pdo, "SELECT SUM(status = 'approved') AS a, SUM(status = 'pending') AS p, SUM(status = 'rejected') AS r
                          FROM donations WHERE {$in('church_id')} AND created_at >= $start");
    $d['donation_status'] = [['Approved', (int)($st['a'] ?? 0), 'is-good'], ['Waiting for review', (int)($st['p'] ?? 0), 'is-warn'], ['Rejected', (int)($st['r'] ?? 0), 'is-bad']];
    $d['collections'] = an_one($pdo,
        "SELECT SUM(CASE WHEN week_date >= $start THEN amount ELSE 0 END) AS cur,
                SUM(CASE WHEN week_date >= $prev AND week_date < $start THEN amount ELSE 0 END) AS prev
           FROM weekly_collections WHERE {$in('church_id')}");
    $d['collections_monthly'] = $monthly(an_rows($pdo,
        "SELECT DATE_FORMAT(week_date, '%Y-%m') AS ym, SUM(amount) AS v FROM weekly_collections
          WHERE {$in('church_id')} AND week_date >= $start AND week_date <= CURDATE() GROUP BY ym"));

    // ---- Sacrament applications (drafts are not counted: nobody has sent them yet)
    $sent = "COALESCE(submitted_at, created_at)";
    $d['sacraments'] = an_one($pdo, "SELECT {$period($sent)} FROM sacrament_applications WHERE {$in('church_id')} AND status <> 'draft'");
    $d['sacrament_types'] = array_map(fn($r) => ['label' => SACRAMENTS[$r['sacrament']][0] ?? ucfirst($r['sacrament']), 'value' => (float)$r['v']], an_rows($pdo,
        "SELECT sacrament, COUNT(*) AS v FROM sacrament_applications
          WHERE {$in('church_id')} AND status <> 'draft' AND $sent >= $start GROUP BY sacrament ORDER BY v DESC"));
    $st = an_one($pdo,
        "SELECT SUM(status = 'approved') AS a, SUM(status IN ('submitted', 'verifying', 'review')) AS w, SUM(status = 'rejected') AS r, SUM(status = 'cancelled') AS c
           FROM sacrament_applications WHERE {$in('church_id')} AND status <> 'draft' AND $sent >= $start");
    $d['sacrament_status'] = [['Approved', (int)($st['a'] ?? 0), 'is-good'], ['Waiting or in review', (int)($st['w'] ?? 0), 'is-warn'], ['Rejected', (int)($st['r'] ?? 0), 'is-bad'], ['Cancelled', (int)($st['c'] ?? 0), 'is-none']];

    // ---- Pastoral care requests
    $d['pastoral'] = an_one($pdo, "SELECT {$period($sent)} FROM pastoral_requests WHERE {$in('church_id')} AND status <> 'draft'");
    $byService = [];
    foreach (an_rows($pdo,
        "SELECT p.service, ps.title, COUNT(*) AS v FROM pastoral_requests p
      LEFT JOIN pastoral_services ps ON ps.church_id = p.church_id AND ps.service_key = p.service
          WHERE {$in('p.church_id')} AND p.status <> 'draft' AND COALESCE(p.submitted_at, p.created_at) >= $start
       GROUP BY p.service, ps.title") as $r) {
        // Across every church the standard services keep their standard name, so one church's renaming does not split them.
        $label = (!$churchId && isset(PASTORAL[$r['service']])) ? PASTORAL[$r['service']][0] : ($r['title'] ?: (PASTORAL[$r['service']][0] ?? 'Other request'));
        $byService[$label] = ($byService[$label] ?? 0) + (float)$r['v'];
    }
    arsort($byService);
    $d['pastoral_services'] = array_map(fn($l, $v) => ['label' => $l, 'value' => $v], array_keys($byService), $byService);
    $st = an_one($pdo,
        "SELECT SUM(status = 'given') AS a, SUM(status IN ('submitted', 'review')) AS w, SUM(status = 'declined') AS r
           FROM pastoral_requests WHERE {$in('church_id')} AND status <> 'draft' AND $sent >= $start");
    $d['pastoral_status'] = [['Given', (int)($st['a'] ?? 0), 'is-good'], ['Waiting or in review', (int)($st['w'] ?? 0), 'is-warn'], ['Declined', (int)($st['r'] ?? 0), 'is-bad']];

    // ---- Events
    $d['events'] = an_one($pdo,
        "SELECT SUM(CASE WHEN r.created_at >= $start THEN r.people ELSE 0 END) AS cur,
                SUM(CASE WHEN r.created_at >= $prev AND r.created_at < $start THEN r.people ELSE 0 END) AS prev
           FROM event_registrations r JOIN events e ON e.event_id = r.event_id WHERE {$in('e.church_id')}");
    $d['events_upcoming'] = (int)(an_one($pdo,
        "SELECT COUNT(*) AS n FROM events WHERE {$in('church_id')}
            AND (event_date >= CURDATE() OR (repeat_weekly = 1 AND (repeat_until IS NULL OR repeat_until >= CURDATE())))")['n'] ?? 0);
    $d['top_events'] = array_map(fn($r) => [
        'label' => $r['title'],
        'sub' => date('j M Y', strtotime($r['event_date'])) . ($churchId ? '' : ' · ' . $r['church_name']),
        'value' => (float)$r['v'],
        'note' => $r['capacity'] ? 'of ' . (int)$r['capacity'] . ' places' : '',
    ], an_rows($pdo,
        "SELECT e.title, e.event_date, e.capacity, c.church_name, SUM(r.people) AS v
           FROM event_registrations r
           JOIN events e ON e.event_id = r.event_id
           JOIN church_info c ON c.church_info_id = e.church_id
          WHERE {$in('e.church_id')} AND r.created_at >= $start
       GROUP BY e.event_id ORDER BY v DESC LIMIT 8"));

    // ---- Catechesis
    $d['catechesis'] = an_one($pdo,
        "SELECT SUM(status = 'active') AS active, SUM(status = 'completed') AS completed FROM catechesis_enrolments WHERE {$in('church_id')}");
    $att = an_one($pdo,
        "SELECT SUM(a.status = 'present') AS present, COUNT(*) AS marked FROM catechesis_attendance a
           JOIN catechesis_enrolments e ON e.enrolment_id = a.enrolment_id
          WHERE {$in('e.church_id')} AND a.session_date >= $start");
    $d['attendance_rate'] = !empty($att['marked']) ? (int)round($att['present'] / $att['marked'] * 100) : null;
    $d['programs'] = array_map(fn($r) => [
        'label' => $r['title'] . ($r['term_label'] ? ' (' . $r['term_label'] . ')' : ''),
        'sub' => $churchId ? '' : $r['church_name'],
        'value' => (float)$r['active'],
        'note' => (int)$r['completed'] ? (int)$r['completed'] . ' completed' : '',
    ], an_rows($pdo,
        "SELECT p.title, p.term_label, c.church_name, COALESCE(SUM(e.status = 'active'), 0) AS active, COALESCE(SUM(e.status = 'completed'), 0) AS completed
           FROM catechesis_programs p
           JOIN church_info c ON c.church_info_id = p.church_id
      LEFT JOIN catechesis_enrolments e ON e.program_id = p.program_id
          WHERE {$in('p.church_id')}
       GROUP BY p.program_id ORDER BY active DESC, p.title LIMIT 8"));

    // ---- Every church side by side (Super Admin, all churches)
    $d['churches'] = $churchId ? [] : an_rows($pdo,
        "SELECT c.church_info_id, c.church_name, c.church_location,
                (SELECT GROUP_CONCAT(a.admin_name ORDER BY a.admin_name SEPARATOR ', ') FROM admin a WHERE a.church_id = c.church_info_id AND a.admin_role = 'church_admin') AS admin_name,
                (SELECT COUNT(*) FROM user u WHERE u.church_id = c.church_info_id) AS members,
                (SELECT COUNT(*) FROM user u WHERE u.church_id = c.church_info_id AND u.created_at >= $start) AS new_members,
                (SELECT COALESCE(SUM(amount), 0) FROM donations d WHERE d.church_id = c.church_info_id AND d.status = 'approved' AND d.created_at >= $start)
              + (SELECT COALESCE(SUM(amount), 0) FROM weekly_collections w WHERE w.church_id = c.church_info_id AND w.week_date >= $start) AS giving,
                (SELECT COUNT(*) FROM sacrament_applications s WHERE s.church_id = c.church_info_id AND s.status <> 'draft' AND COALESCE(s.submitted_at, s.created_at) >= $start) AS sacraments,
                (SELECT COUNT(*) FROM pastoral_requests p WHERE p.church_id = c.church_info_id AND p.status <> 'draft' AND COALESCE(p.submitted_at, p.created_at) >= $start) AS pastoral,
                (SELECT COALESCE(SUM(r.people), 0) FROM event_registrations r JOIN events e ON e.event_id = r.event_id WHERE e.church_id = c.church_info_id AND r.created_at >= $start) AS attendees
           FROM church_info c
       ORDER BY members DESC, c.church_name");

    return $d;
}

/** "+25% vs previous 12 months", with an arrow so the direction never rests on colour alone. */
function an_delta(float $cur, float $prev, int $months): string
{
    $vs = ' vs previous ' . $months . ' months';
    if ($prev <= 0) {
        return $cur > 0 ? '<span class="an-delta is-up">' . dash_icon('trend-up', 14) . ' New' . $vs . '</span>' : '<span class="an-delta">No activity yet</span>';
    }
    $pct = (int)round(($cur - $prev) / $prev * 100);
    if ($pct === 0) return '<span class="an-delta">No change' . $vs . '</span>';
    $up = $pct > 0;
    return '<span class="an-delta ' . ($up ? 'is-up' : 'is-down') . '">' . dash_icon($up ? 'trend-up' : 'trend-down', 14) . ' ' . ($up ? '+' : '') . $pct . '%' . $vs . '</span>';
}

function an_kpi(string $icon, string $value, string $label, string $sub): void
{
    ?>
    <div class="stat an-kpi">
        <span class="stat-ico"><?= dash_icon($icon, 22) ?></span>
        <div>
            <span class="stat-label"><?= e($label) ?></span>
            <span class="stat-num"><?= e($value) ?></span>
            <?= $sub ?>
        </div>
    </div>
    <?php
}

/** "RM 1,240" for the tiles (whole ringgit). */
function an_rm(float $v): string
{
    return 'RM ' . number_format($v);
}

/**
 * Column chart of monthly values (inline SVG). Hover or focus a column for its exact value; the largest column is
 * labelled, and the figures are in the table underneath.
 */
function an_columns(array $pts, bool $money, string $title): void
{
    $max = max(array_column($pts, 'value') ?: [0]);
    if ($max <= 0) {
        echo '<p class="an-empty">Nothing recorded in this period.</p>';
        return;
    }
    $fmt = fn(float $v) => $money ? rm($v) : number_format($v);
    $W = 640; $H = 230;
    $padL = 44; $padR = 8; $padT = 22; $padB = 30;
    $plotW = $W - $padL - $padR; $plotH = $H - $padT - $padB;
    $step = collection_axis_step($max);
    if (!$money) $step = max(1, $step);   // whole people, never half
    $top = max($step, ceil($max / $step) * $step);
    $n = count($pts);
    $slot = $plotW / $n;
    $barW = min(24, $slot * 0.6);
    $labelEvery = (int)ceil($n / 12);
    $y = fn(float $v) => $padT + $plotH - ($v / $top) * $plotH;
    $base = $padT + $plotH;
    $maxAt = array_search($max, array_column($pts, 'value'));
    ?>
    <figure class="an-chart">
        <svg viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="<?= e($title) ?>, by month. Exact figures are in the table below.">
            <?php for ($v = 0; $v <= $top + 0.001; $v += $step): $gy = round($y($v), 1); ?>
                <line class="an-gridline<?= $v == 0 ? ' is-base' : '' ?>" x1="<?= $padL ?>" x2="<?= $W - $padR ?>" y1="<?= $gy ?>" y2="<?= $gy ?>"/>
                <text class="an-tick" x="<?= $padL - 8 ?>" y="<?= $gy + 4 ?>" text-anchor="end"><?= e($money ? collection_short($v) : number_format($v)) ?></text>
            <?php endfor; ?>
            <?php foreach ($pts as $i => $p):
                $v = (float)$p['value'];
                $cx = $padL + $slot * ($i + 0.5);
                $x = $cx - $barW / 2;
                $by = $y($v);
                $h = $base - $by;
                $r = min(4, $h, $barW / 2);
                // Rounded data end, square on the baseline.
                $d = sprintf('M%.1f %.1f V%.1f Q%.1f %.1f %.1f %.1f H%.1f Q%.1f %.1f %.1f %.1f V%.1f Z',
                    $x, $base, $by + $r, $x, $by, $x + $r, $by, $x + $barW - $r, $x + $barW, $by, $x + $barW, $by + $r, $base);
            ?>
                <g class="an-col" tabindex="0" data-tip="<?= e($p['full'] . ': ' . $fmt($v)) ?>">
                    <rect class="an-hit" x="<?= round($cx - $slot / 2, 1) ?>" y="<?= $padT ?>" width="<?= round($slot, 1) ?>" height="<?= $plotH ?>"/>
                    <?php if ($h > 0): ?><path d="<?= $d ?>"/><?php endif; ?>
                </g>
                <?php if ($i === $maxAt): ?>
                    <text class="an-val" x="<?= round($cx, 1) ?>" y="<?= round($by - 7, 1) ?>" text-anchor="middle"><?= e($money ? 'RM ' . collection_short($v) : number_format($v)) ?></text>
                <?php endif; ?>
                <?php if (($n - 1 - $i) % $labelEvery === 0): ?>
                    <text class="an-tick" x="<?= round($cx, 1) ?>" y="<?= $H - 10 ?>" text-anchor="middle"><?= e($p['label']) ?></text>
                <?php endif; ?>
            <?php endforeach; ?>
        </svg>
        <div class="an-tip" hidden></div>
        <details class="wc-table an-table">
            <summary>Show as table</summary>
            <table>
                <thead><tr><th>Month</th><th class="num"><?= $money ? 'Amount' : 'Count' ?></th></tr></thead>
                <tbody>
                <?php foreach (array_reverse($pts) as $p): ?>
                    <tr><td><?= e($p['full']) ?></td><td class="num"><?= e($fmt((float)$p['value'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </details>
    </figure>
    <?php
}

/** Horizontal bars, largest first, each with its value written at the end. rows: [label, value, sub?, note?] */
function an_bars(array $rows, bool $money = false, string $empty = 'Nothing recorded in this period.'): void
{
    $max = max(array_column($rows, 'value') ?: [0]);
    if ($max <= 0) {
        echo '<p class="an-empty">' . e($empty) . '</p>';
        return;
    }
    ?>
    <ul class="an-bars">
        <?php foreach ($rows as $r): $v = (float)$r['value']; ?>
            <li>
                <span class="an-bar-label"><?= e($r['label']) ?><?php if (!empty($r['sub'])): ?><small><?= e($r['sub']) ?></small><?php endif; ?></span>
                <span class="an-bar-track"><span class="an-bar-fill" style="width: <?= $v > 0 ? max(1, round($v / $max * 100, 1)) : 0 ?>%"></span></span>
                <span class="an-bar-val"><?= e($money ? rm($v) : number_format($v)) ?><?php if (!empty($r['note'])): ?><small><?= e($r['note']) ?></small><?php endif; ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
}

/** One bar split by status (status colours, each with its label, count and share in the legend). segs: [label, count, class] */
function an_stack(array $segs, string $title): void
{
    $total = array_sum(array_column($segs, 1));
    if (!$total) return;
    $segs = array_filter($segs, fn($s) => $s[1] > 0);
    $pct = fn(int $n) => (int)round($n / $total * 100) . '%';
    $desc = implode(', ', array_map(fn($s) => $s[0] . ' ' . $s[1], $segs));
    ?>
    <div class="an-status">
        <p class="an-status-title"><?= e($title) ?> <span><?= number_format($total) ?> in total</span></p>
        <div class="an-stack" role="img" aria-label="<?= e($title . ': ' . $desc) ?>">
            <?php foreach ($segs as [$label, $n, $cls]): ?>
                <span class="an-seg <?= $cls ?>" style="flex-grow: <?= $n ?>" title="<?= e($label . ': ' . $n . ' (' . $pct($n) . ')') ?>"></span>
            <?php endforeach; ?>
        </div>
        <ul class="an-legend">
            <?php foreach ($segs as [$label, $n, $cls]): ?>
                <li><i class="an-seg <?= $cls ?>"></i><?= e($label) ?> <b><?= number_format($n) ?></b> <span>(<?= $pct($n) ?>)</span></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php
}

/** Round print button, like the one report_tools() shows (the page prints as it is, charts included). */
function an_print_button(): void
{
    ?>
    <span class="rpt-tools no-print">
        <button type="button" class="sr-print" onclick="window.print()" aria-label="Print this page" title="Print">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
        </button>
    </span>
    <?php
}

/** The period / church filter row. $churches = [id => name] for the Super Admin, [] for a Church Admin. */
function an_filters(int $months, array $churches = [], int $churchId = 0): void
{
    ?>
    <form class="an-filters no-print" method="get" action="dashboard.php">
        <label>
            <span>Period</span>
            <select name="range">
                <?php foreach (AN_RANGES as $m => $label): ?>
                    <option value="<?= $m ?>"<?= $m === $months ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if ($churches): ?>
            <label>
                <span>Church</span>
                <select name="church">
                    <option value="0">All churches</option>
                    <?php foreach ($churches as $id => $name): ?>
                        <option value="<?= (int)$id ?>"<?= (int)$id === $churchId ? ' selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <button type="submit" class="btn-report">Apply</button>
    </form>
    <script>
    (function () {
        var form = document.currentScript.previousElementSibling;
        form.querySelector('button').hidden = true;   // with scripts on, picking an option applies it straight away
        form.querySelectorAll('select').forEach(function (s) { s.addEventListener('change', function () { form.submit(); }); });
    })();
    </script>
    <?php
}

/** The analytics page body. $base is the path back to the site root, for links to the management pages. */
function an_render(array $d, string $base = '../'): void
{
    $months = $d['months'];
    $since = $d['start']->format('j M Y');
    $m = $d['members'];
    $giving = (float)($d['donations']['cur'] ?? 0) + (float)($d['collections']['cur'] ?? 0);
    $givingPrev = (float)($d['donations']['prev'] ?? 0) + (float)($d['collections']['prev'] ?? 0);
    $cat = $d['catechesis'];
    ?>
    <p class="an-period">Showing <?= e(strtolower(AN_RANGES[$months])) ?>, from <?= e($since) ?> to today.</p>

    <div class="stat-grid an-kpis">
        <?php
        an_kpi('users', number_format((float)($m['total'] ?? 0)), 'Parishioners', '<span class="an-sub">' . number_format((float)($m['cur'] ?? 0)) . ' new in this period</span>' . an_delta((float)($m['cur'] ?? 0), (float)($m['prev'] ?? 0), $months));
        an_kpi('bank', an_rm($giving), 'Total giving', '<span class="an-sub">Donations and weekly collections</span>' . an_delta($giving, $givingPrev, $months));
        an_kpi('droplet', number_format((float)($d['sacraments']['cur'] ?? 0)), 'Sacrament applications', an_delta((float)($d['sacraments']['cur'] ?? 0), (float)($d['sacraments']['prev'] ?? 0), $months));
        an_kpi('hand', number_format((float)($d['pastoral']['cur'] ?? 0)), 'Pastoral care requests', an_delta((float)($d['pastoral']['cur'] ?? 0), (float)($d['pastoral']['prev'] ?? 0), $months));
        an_kpi('calendar', number_format((float)($d['events']['cur'] ?? 0)), 'People registered for events', '<span class="an-sub">' . $d['events_upcoming'] . ' upcoming or repeating event' . ($d['events_upcoming'] === 1 ? '' : 's') . '</span>' . an_delta((float)($d['events']['cur'] ?? 0), (float)($d['events']['prev'] ?? 0), $months));
        an_kpi('cap', number_format((float)($cat['active'] ?? 0)), 'Active catechesis students', '<span class="an-sub">' . ($d['attendance_rate'] === null ? 'No attendance marked in this period' : $d['attendance_rate'] . '% attendance in this period') . '</span>');
        ?>
    </div>

    <div class="an-grid">
        <section class="panel">
            <div class="panel-head"><h2>New parishioners <span class="panel-sub">per month</span></h2></div>
            <?php an_columns($d['members_monthly'], false, 'New parishioners'); ?>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>Parishioners by age <span class="panel-sub">all registered</span></h2></div>
            <?php an_bars($d['ages'], false, 'No parishioners have registered yet.'); ?>
            <p class="an-foot"><?= number_format((float)($m['verified'] ?? 0)) ?> of <?= number_format((float)($m['total'] ?? 0)) ?> accounts have a verified email address.</p>
        </section>

        <section class="panel">
            <div class="panel-head">
                <h2>Online donations <span class="panel-sub">approved, per month</span></h2>
                <div class="panel-tools no-print"><a class="panel-link" href="<?= e($base) ?>donation-manage.php">Manage &rarr;</a></div>
            </div>
            <?php an_columns($d['donations_monthly'], true, 'Approved online donations'); ?>
            <p class="an-foot"><?= rm((float)($d['donations']['cur'] ?? 0)) ?> from <?= (int)($d['donations']['donors'] ?? 0) ?> donor<?= (int)($d['donations']['donors'] ?? 0) === 1 ? '' : 's' ?> in this period.</p>
        </section>
        <section class="panel">
            <div class="panel-head">
                <h2>Weekly collections <span class="panel-sub">per month</span></h2>
                <div class="panel-tools no-print"><a class="panel-link" href="<?= e($base) ?>collection-manage.php">Manage &rarr;</a></div>
            </div>
            <?php an_columns($d['collections_monthly'], true, 'Weekly collections'); ?>
            <p class="an-foot"><?= rm((float)($d['collections']['cur'] ?? 0)) ?> collected in this period.</p>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Donations by purpose <span class="panel-sub">approved</span></h2></div>
            <?php an_bars($d['donation_purposes'], true, 'No approved donations in this period.'); ?>
            <?php an_stack($d['donation_status'], 'Donations received, by status'); ?>
        </section>
        <section class="panel">
            <div class="panel-head">
                <h2>Sacrament applications <span class="panel-sub">by sacrament</span></h2>
                <div class="panel-tools no-print"><a class="panel-link" href="<?= e($base) ?>sacrament-review.php">Review &rarr;</a></div>
            </div>
            <?php an_bars($d['sacrament_types'], false, 'No applications in this period.'); ?>
            <?php an_stack($d['sacrament_status'], 'Applications, by status'); ?>
        </section>

        <section class="panel">
            <div class="panel-head">
                <h2>Pastoral care requests <span class="panel-sub">by service</span></h2>
                <div class="panel-tools no-print"><a class="panel-link" href="<?= e($base) ?>pastoral-manage.php">Review &rarr;</a></div>
            </div>
            <?php an_bars($d['pastoral_services'], false, 'No requests in this period.'); ?>
            <?php an_stack($d['pastoral_status'], 'Requests, by status'); ?>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>Most popular events <span class="panel-sub">people registered</span></h2></div>
            <?php an_bars($d['top_events'], false, 'Nobody registered for an event in this period.'); ?>
        </section>

        <section class="panel an-wide">
            <div class="panel-head">
                <h2>Catechesis programmes <span class="panel-sub">active students</span></h2>
                <div class="panel-tools no-print"><a class="panel-link" href="<?= e($base) ?>catechesis-manage.php">Manage &rarr;</a></div>
            </div>
            <?php an_bars($d['programs'], false, 'No catechesis programmes yet.'); ?>
        </section>
    </div>

    <?php if ($d['churches']): ?>
        <section class="panel" id="churches">
            <div class="panel-head">
                <h2>Churches <span class="panel-sub"><?= count($d['churches']) ?> parishes &middot; figures for this period &middot; click a heading to sort</span></h2>
                <div class="panel-tools no-print">
                    <label class="dash-search"><?= dash_icon('search', 16) ?><input type="search" id="an-church-filter" placeholder="Search churches" aria-label="Search churches"></label>
                    <a class="btn-new" href="church-form.php"><?= ann_icon('plus', 16) ?> New Church</a>
                </div>
            </div>
            <div class="table-wrap is-scroll">
                <table id="an-church-table" class="an-sortable">
                    <thead><tr>
                        <th><button type="button">Church</button></th>
                        <th><button type="button">Church Admin</button></th>
                        <th class="num"><button type="button" data-num>Parishioners</button></th>
                        <th class="num"><button type="button" data-num>New</button></th>
                        <th class="num"><button type="button" data-num>Giving</button></th>
                        <th class="num"><button type="button" data-num>Sacraments</button></th>
                        <th class="num"><button type="button" data-num>Pastoral</button></th>
                        <th class="num"><button type="button" data-num>Event sign-ups</button></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($d['churches'] as $c): ?>
                        <tr>
                            <td>
                                <a href="dashboard.php?church=<?= (int)$c['church_info_id'] ?>&amp;range=<?= $months ?>" title="Show this church's figures"><?= e($c['church_name']) ?></a>
                                <?php if ($c['church_location']): ?><small class="an-cell-sub"><?= e($c['church_location']) ?></small><?php endif; ?>
                            </td>
                            <td><?= $c['admin_name'] ? e($c['admin_name']) : '<a class="adm-add-link" href="admins.php?church=' . (int)$c['church_info_id'] . '#add">+ Add admin</a>' ?></td>
                            <?php foreach (['members', 'new_members', 'giving', 'sacraments', 'pastoral', 'attendees'] as $k): $v = (float)$c[$k]; ?>
                                <td class="num<?= $v ? '' : ' is-zero' ?>" data-v="<?= $v ?>"><?= e($k === 'giving' ? an_rm($v) : number_format($v)) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <script>
    (function () {
        // One tooltip per column chart: shows the month and exact value on hover or keyboard focus.
        document.querySelectorAll('.an-chart').forEach(function (fig) {
            var tip = fig.querySelector('.an-tip');
            fig.querySelectorAll('.an-col').forEach(function (g) {
                function show() {
                    var fb = fig.getBoundingClientRect();
                    var r = (g.querySelector('path') || g.querySelector('.an-hit')).getBoundingClientRect();
                    tip.textContent = g.dataset.tip;
                    tip.hidden = false;
                    var left = r.left - fb.left + r.width / 2 - tip.offsetWidth / 2;
                    tip.style.left = Math.max(0, Math.min(left, fig.clientWidth - tip.offsetWidth)) + 'px';
                    tip.style.top = Math.max(0, r.top - fb.top - tip.offsetHeight - 8) + 'px';
                }
                ['mouseenter', 'focus'].forEach(function (t) { g.addEventListener(t, show); });
                ['mouseleave', 'blur'].forEach(function (t) { g.addEventListener(t, function () { tip.hidden = true; }); });
            });
        });

        var table = document.getElementById('an-church-table');
        if (!table) return;
        document.getElementById('an-church-filter').addEventListener('input', function () {
            var q = this.value.trim().toLowerCase();
            table.querySelectorAll('tbody tr').forEach(function (row) {
                row.hidden = q !== '' && row.cells[0].textContent.toLowerCase().indexOf(q) === -1;
            });
        });
        // Sort by a column: numbers largest first, names A to Z; a second click reverses.
        table.querySelectorAll('thead button').forEach(function (btn, col) {
            btn.addEventListener('click', function () {
                var num = btn.hasAttribute('data-num');
                var th = btn.parentElement, was = th.getAttribute('aria-sort');
                var desc = was ? was === 'ascending' : num;
                table.querySelectorAll('thead th').forEach(function (h) { h.removeAttribute('aria-sort'); });
                th.setAttribute('aria-sort', desc ? 'descending' : 'ascending');
                var body = table.tBodies[0];
                Array.from(body.rows).sort(function (a, b) {
                    var x = num ? parseFloat(a.cells[col].dataset.v) : a.cells[col].textContent.toLowerCase();
                    var y = num ? parseFloat(b.cells[col].dataset.v) : b.cells[col].textContent.toLowerCase();
                    return (x < y ? -1 : x > y ? 1 : 0) * (desc ? -1 : 1);
                }).forEach(function (row) { body.appendChild(row); });
            });
        });
    })();
    </script>
    <?php
}
