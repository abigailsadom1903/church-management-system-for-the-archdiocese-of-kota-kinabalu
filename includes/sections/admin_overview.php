<?php
// Admin overview cards on the Home page, shown only to admins who open "View site".
// Super Admin: the whole archdiocese. Church Admin: their own church. Needs $pdo.
// Everything is inside a function so the home page's variables are never overwritten.

function render_admin_overview(PDO $pdo): void
{
    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, ['super_admin', 'church_admin'], true) || empty($_SESSION['user_id'])) return;
    $super = $role === 'super_admin';
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    $churchId = 0;
    if (!$super) {
        $st = $pdo->prepare('SELECT church_id FROM admin WHERE admin_id = ?');
        $st->execute([(int)$_SESSION['user_id']]);
        $churchId = (int)$st->fetchColumn();
        if (!$churchId) return;
    }
    // "AND church_id = ?" for a Church Admin, nothing for the Super Admin.
    $scope = fn(string $col = 'church_id') => $super ? '' : " AND $col = " . $churchId;
    $one = function (string $sql) use ($pdo) {
        try { return $pdo->query($sql)->fetch(); } catch (Throwable $ex) { return []; }   // a table may not exist yet
    };

    $users = $one("SELECT COUNT(*) n,
                          COALESCE(SUM(created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')), 0) this_month
                     FROM user WHERE 1" . $scope());
    $churches = $super ? $one("SELECT COUNT(*) n FROM church_info") : [];
    $don = $one("SELECT COALESCE(SUM(CASE WHEN created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN amount END), 0) this_month,
                        COALESCE(SUM(CASE WHEN created_at >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')
                                           AND created_at <  DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN amount END), 0) last_month,
                        COALESCE(SUM(status = 'pending'), 0) pending
                   FROM donations WHERE status IN ('approved', 'pending')" . $scope());
    $events = $one("SELECT COUNT(*) n, COALESCE(SUM(category = 'mass'), 0) masses,
                           COALESCE(SUM(event_date < CURDATE() + INTERVAL 7 DAY), 0) this_week
                      FROM events WHERE event_date >= CURDATE()" . $scope());
    $ann = $one("SELECT COUNT(*) n, COALESCE(SUM(is_pinned = 1), 0) pinned FROM announcements
                  WHERE publish_date <= CURDATE() AND (expires_date IS NULL OR expires_date >= CURDATE())" . $scope());
    $sac = $one("SELECT COUNT(*) n, COALESCE(SUM(status = 'submitted'), 0) pending FROM sacrament_applications WHERE status <> 'draft' AND sacrament <> 'confession'" . $scope());
    $cat = $one("SELECT (SELECT COUNT(*) FROM catechesis_programs WHERE 1" . $scope() . ") n,
                        (SELECT COUNT(*) FROM catechesis_enrolments WHERE status = 'active'" . $scope() . ") enrolled");
    $pas = $one("SELECT COUNT(*) n, COALESCE(SUM(status = 'submitted'), 0) pending FROM pastoral_requests WHERE status <> 'draft'" . $scope());

    // Donations this month compared with last month (approved + pending, as submitted).
    $month = date('F');
    $prevMonth = date('M', strtotime('first day of last month'));
    $donNow = (float)($don['this_month'] ?? 0);
    $donPrev = (float)($don['last_month'] ?? 0);
    $donTrend = $donPrev > 0 ? round(($donNow - $donPrev) / $donPrev * 100) : null;

    $dash = $super ? 'superadmin/dashboard.php' : 'churchadmin/dashboard.php';
    // Cards with something waiting open the list already filtered to it (see includes/review_queue.php).
    require_once __DIR__ . '/../review_queue.php';
    $reviewScope = $super ? null : $churchId;
    $go = fn(string $queue, string $page) => review_count($pdo, $queue, $reviewScope) ? review_link($pdo, $queue, $reviewScope) : $page;
    $cards = [];
    $cards[] = ['Total Users', number_format((int)($users['n'] ?? 0)), 'users', 'violet', $dash,
        (int)($users['this_month'] ?? 0) ? ['up', '+' . (int)$users['this_month'] . ' this month'] : null, null, []];
    if ($super) {
        $cards[] = ['Churches', number_format((int)($churches['n'] ?? 0)), 'church', 'indigo', 'church-information.php',
            null, number_format((int)($users['n'] ?? 0)) . ((int)($users['n'] ?? 0) === 1 ? ' registered parishioner' : ' registered parishioners'), []];
    } else {
        // A Church Admin has no "Churches" card; their latest weekly collection takes its place.
        $wc = $one("SELECT week_date, amount FROM weekly_collections WHERE church_id = $churchId ORDER BY week_date DESC LIMIT 1");
        $cards[] = ['Weekly Collection', $wc ? 'RM ' . number_format((float)$wc['amount'], 2) : '—', 'chart', 'indigo', 'collection-manage.php',
            null, $wc ? 'Week of ' . date('j M Y', strtotime($wc['week_date'])) : 'No weeks recorded yet', []];
    }
    $cards[] = [$month . ' Donations', 'RM ' . number_format($donNow, $donNow == floor($donNow) ? 0 : 2), 'heart', 'green', $go('donations', 'donation-manage.php'),
        $donTrend === null ? null : [$donTrend >= 0 ? 'up' : 'down', ($donTrend >= 0 ? '+' : '') . $donTrend . '% vs ' . $prevMonth], null,
        (int)($don['pending'] ?? 0) ? [['pending', (int)$don['pending'] . ' To review']] : []];
    $cards[] = ['Upcoming Events', number_format((int)($events['n'] ?? 0)), 'calendar', 'sky', $super ? 'events.php?church=0&view=manage' : 'events.php?view=manage',
        null, null, [['violet', (int)($events['masses'] ?? 0) . ' Masses'], ['muted', (int)($events['this_week'] ?? 0) . ' This week']]];
    $cards[] = ['Announcements', number_format((int)($ann['n'] ?? 0)), 'megaphone', 'red', $super ? 'announcements.php' : $dash . '#announcements',
        null, (int)($ann['pinned'] ?? 0) . ' pinned', []];
    $cards[] = ['Sacrament Apps', number_format((int)($sac['n'] ?? 0)), 'book', 'purple', $go('sacraments', 'sacrament-review.php'),
        null, null, [[(int)($sac['pending'] ?? 0) ? 'pending' : 'ok', (int)($sac['pending'] ?? 0) . ' Pending']]];
    $cards[] = ['Catechesis Programs', number_format((int)($cat['n'] ?? 0)), 'cap', 'teal', 'catechesis-manage.php',
        null, number_format((int)($cat['enrolled'] ?? 0)) . ' enrolled', []];
    $cards[] = ['Pastoral Requests', number_format((int)($pas['n'] ?? 0)), 'hand', 'blue', $go('pastoral', 'pastoral-manage.php'),
        null, null, [[(int)($pas['pending'] ?? 0) ? 'pending' : 'ok', (int)($pas['pending'] ?? 0) . ' Waiting']]];
    ?>
    <section class="ao" aria-labelledby="ao-title">
        <div class="ao-head">
            <h2 id="ao-title"><?= $super ? 'Archdiocese overview' : 'Your parish at a glance' ?></h2>
            <a href="<?= $h($dash) ?>">Open dashboard &rarr;</a>
        </div>
        <div class="ao-grid">
            <?php foreach ($cards as [$label, $value, $icon, $tone, $href, $trend, $sub, $pills]): ?>
                <a class="ao-card" href="<?= $h($href) ?>">
                    <span class="ao-top">
                        <span class="ao-label"><?= $h($label) ?></span>
                        <span class="ao-ico tone-<?= $tone ?>"><?= dash_icon($icon, 20) ?></span>
                    </span>
                    <span class="ao-value<?= str_starts_with($value, 'RM') ? ' is-money' : '' ?>"><?= $h($value) ?></span>
                    <?php if ($trend): ?><span class="ao-trend is-<?= $trend[0] ?>"><?= dash_icon($trend[0] === 'up' ? 'trend-up' : 'trend-down', 14) ?> <?= $h($trend[1]) ?></span><?php endif; ?>
                    <?php if ($sub): ?><span class="ao-sub"><?= $h($sub) ?></span><?php endif; ?>
                    <?php if ($pills): ?><span class="ao-pills"><?php foreach ($pills as [$pt, $ptext]): ?><span class="ao-pill pill-<?= $pt ?>"><?= $h($ptext) ?></span><?php endforeach; ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
}
