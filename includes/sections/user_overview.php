<?php
// "Your account at a glance" on the Home page, shown only to signed-in parishioners (role "user").
// Four cards (next event, catechesis progress, applications, donations) and a list of the latest status updates.
// Everything is inside a function so the home page's variables are never overwritten. Needs $pdo.

function render_user_overview(PDO $pdo): void
{
    if (($_SESSION['role'] ?? '') !== 'user' || empty($_SESSION['user_id'])) return;
    $uid = (int)$_SESSION['user_id'];
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $all = function (string $sql, array $args = []) use ($pdo) {
        try { $st = $pdo->prepare($sql); $st->execute($args); return $st->fetchAll(); } catch (Throwable $ex) { return []; }   // a table may not exist yet
    };

    require_once __DIR__ . '/../sacrament_helpers.php';
    require_once __DIR__ . '/../pastoral_helpers.php';
    require_once __DIR__ . '/../catechesis_helpers.php';
    require_once __DIR__ . '/../donation_helpers.php';
    require_once __DIR__ . '/../event_helpers.php';

    $today = date('Y-m-d');
    $fmtDate = fn(?string $d) => $d ? date('j M Y', strtotime($d)) : '';
    // Status -> pill colour: approved green, rejected red, cancelled grey, anything still moving amber.
    $pillOf = fn(string $s) => match ($s) {
        'approved', 'given' => 'ok',
        'rejected', 'declined' => 'urgent',
        'cancelled' => 'muted',
        default => 'pending',
    };

    // ---- Upcoming events I registered for (registration is for one-off events only) ----
    $events = array_values(array_filter(
        $all('SELECT e.event_id, e.title, e.event_date, e.start_time, e.end_time, e.is_all_day, e.repeat_weekly, e.location
                FROM event_registrations r JOIN events e ON e.event_id = r.event_id
               WHERE r.user_id = ? AND e.event_date >= ?
               ORDER BY e.event_date, e.start_time', [$uid, $today]),
        fn($e) => time() < event_window($e, $e['event_date'])[1]   // drop today's events that have already ended
    ));
    $next = $events[0] ?? null;

    // ---- Catechesis: my active (and completed) enrolments ----
    $enrols = $all("SELECT en.status, en.attendance, p.program_id, p.title, p.total_sessions, p.frequency, p.weekday, p.start_time, p.end_time
                      FROM catechesis_enrolments en JOIN catechesis_programs p ON p.program_id = en.program_id
                     WHERE en.user_id = ? AND en.status IN ('active', 'completed')
                     ORDER BY en.status = 'active' DESC, en.updated_at DESC", [$uid]);
    $cat = $enrols[0] ?? null;
    $catPct = $cat ? cat_progress((int)$cat['attendance'], (int)$cat['total_sessions'], $cat['status']) : 0;

    // ---- Applications: sacraments and pastoral requests that were sent ----
    $sac = $all("SELECT application_id id, sacrament, church_id, status, admin_note, COALESCE(reviewed_at, submitted_at, created_at) at
                   FROM sacrament_applications WHERE user_id = ? AND status <> 'draft'", [$uid]);
    $pas = $all("SELECT request_id id, service, church_id, status, admin_note, COALESCE(reviewed_at, submitted_at, created_at) at
                   FROM pastoral_requests WHERE user_id = ? AND status <> 'draft'", [$uid]);
    $appCounts = ['ok' => 0, 'pending' => 0, 'urgent' => 0];
    foreach (array_merge($sac, $pas) as $a) {
        $k = $pillOf($a['status']);
        if (isset($appCounts[$k])) $appCounts[$k]++;
    }

    // ---- Donations I made while signed in ----
    $dons = $all('SELECT donation_id id, amount, purpose, status, reject_reason, created_at, COALESCE(reviewed_at, created_at) at
                    FROM donations WHERE donor_user_id = ? ORDER BY created_at DESC', [$uid]);
    $donCounts = ['approved' => 0, 'pending' => 0, 'rejected' => 0];
    $donYear = 0.0;
    foreach ($dons as $d) {
        $donCounts[$d['status']] = ($donCounts[$d['status']] ?? 0) + 1;
        if ($d['status'] === 'approved' && substr($d['created_at'], 0, 4) === date('Y')) $donYear += (float)$d['amount'];
    }

    // ---- Latest updates across applications, requests and donations (newest first) ----
    $updates = [];
    foreach ($sac as $a) {
        $updates[] = ['at' => $a['at'], 'icon' => 'book', 'tone' => 'purple',
            'title' => (SACRAMENTS[$a['sacrament']][0] ?? ucfirst($a['sacrament'])) . ($a['sacrament'] === 'confession' ? ' booking' : ' application'),
            'ref' => sx_ref((int)$a['id']), 'status' => sx_status($a['status'])[0], 'pill' => $pillOf($a['status']),
            'note' => $a['admin_note'], 'href' => 'sacrament-status.php?id=' . (int)$a['id']];
    }
    foreach ($pas as $a) {
        $updates[] = ['at' => $a['at'], 'icon' => 'hand', 'tone' => 'blue',
            'title' => pc_title($pdo, $a['service'], (int)$a['church_id']),
            'ref' => pc_ref((int)$a['id']), 'status' => pc_status($a['status'])[0], 'pill' => $pillOf($a['status']),
            'note' => $a['admin_note'], 'href' => 'pastoral-status.php?id=' . (int)$a['id']];
    }
    foreach ($dons as $d) {
        $updates[] = ['at' => $d['at'], 'icon' => 'heart', 'tone' => 'green',
            'title' => 'Donation ' . rm($d['amount']) . ' · ' . $d['purpose'],
            'ref' => donation_ref((int)$d['id']), 'status' => donation_status($d['status'])[0], 'pill' => $pillOf($d['status']),
            'note' => $d['status'] === 'rejected' ? $d['reject_reason'] : null, 'href' => 'donations.php'];
    }
    usort($updates, fn($a, $b) => strcmp((string)$b['at'], (string)$a['at']));
    // "Action needed": the newest application, request or donation turned down in the last 30 days.
    $alert = null;
    foreach ($updates as $u) {
        if ($u['pill'] === 'urgent' && strtotime((string)$u['at']) >= strtotime('-30 days')) { $alert = $u; break; }
    }
    $updates = array_slice($updates, 0, 3);

    // One row per area, each ending in the next step to take ("cta").
    $rows = [];
    if ($next) {
        [$start, $end] = event_window($next, $next['event_date']);
        $rows[] = ['href' => 'event.php?id=' . (int)$next['event_id'], 'icon' => 'calendar', 'tone' => 'sky', 'label' => 'Next up', 'main' => $next['title'],
            'meta' => date('D, j M', strtotime($next['event_date'])) . ' · ' . event_time_text($next) . (count($events) > 1 ? ' · +' . (count($events) - 1) . ' more' : ''),
            'pill' => ['violet', countdown_short($start, $end)], 'cta' => 'View event'];
    } else {
        $rows[] = ['href' => 'events.php', 'icon' => 'calendar', 'tone' => 'sky', 'label' => 'Next up', 'main' => "You're all caught up!",
            'meta' => 'No events registered yet', 'cta' => 'Explore upcoming parish events'];
    }
    if ($cat) {
        $rows[] = ['href' => 'catechesis.php', 'icon' => 'cap', 'tone' => 'teal', 'label' => 'Your learning', 'main' => $cat['title'],
            'meta' => (int)$cat['attendance'] . ' of ' . (int)$cat['total_sessions'] . ' lessons' . ($cat['status'] === 'completed' ? '' : ' · next lesson ' . date('j M', strtotime(cat_next_lesson($cat)))),
            'pill' => [$cat['status'] === 'completed' ? 'ok' : 'muted', $catPct . '%'], 'pct' => $catPct, 'cta' => 'View my progress'];
    } else {
        $rows[] = ['href' => 'catechesis.php', 'icon' => 'cap', 'tone' => 'teal', 'label' => 'Your learning', 'main' => 'Not enrolled yet',
            'meta' => 'Faith formation for every age', 'cta' => 'Browse programmes'];
    }
    $appParts = array_filter([$appCounts['ok'] ? $appCounts['ok'] . ' approved' : '', $appCounts['pending'] ? $appCounts['pending'] . ' in progress' : '', $appCounts['urgent'] ? $appCounts['urgent'] . ' rejected' : '']);
    $rows[] = ['href' => 'sacraments.php', 'icon' => 'book', 'tone' => 'purple', 'label' => 'Applications', 'main' => number_format(count($sac) + count($pas)) . ' sent',
        'meta' => $appParts ? implode(' · ', $appParts) : 'Sacraments & pastoral care',
        'pill' => $appCounts['pending'] ? ['pending', $appCounts['pending'] . ' in progress'] : null,
        'cta' => ($sac || $pas) ? 'Manage applications' : 'Apply for a sacrament'];
    $donParts = array_filter([$donCounts['approved'] ? $donCounts['approved'] . ' approved' : '', $donCounts['pending'] ? $donCounts['pending'] . ' pending' : '', $donCounts['rejected'] ? $donCounts['rejected'] . ' rejected' : '']);
    $rows[] = ['href' => 'donations.php', 'icon' => 'heart', 'tone' => 'green', 'label' => 'Giving ' . date('Y'), 'main' => 'RM ' . number_format($donYear, $donYear == floor($donYear) ? 0 : 2) . ' approved',
        'meta' => $donParts ? implode(' · ', $donParts) : 'No donations yet',
        'pill' => $donCounts['pending'] ? ['pending', $donCounts['pending'] . ' pending'] : null,
        'cta' => $dons ? 'Give again' : 'Make a donation'];
    ?>
    <aside class="uo" aria-labelledby="uo-title">
        <div class="uo-head">
            <h2 id="uo-title">Overview</h2>
            <a href="profile.php">Profile &rarr;</a>
        </div>
        <div class="uo-box" tabindex="0" role="region" aria-label="Overview of my account (scrollable)">
        <?php if ($alert): ?>
            <a class="uo-alert" href="<?= $h($alert['href']) ?>">
                <span class="uo-alert-ico" aria-hidden="true">!</span>
                <span class="uo-text">
                    <span class="uo-label">Action needed</span>
                    <span class="uo-title"><?= $h($alert['title']) ?> was <?= $h(strtolower($alert['status'])) ?></span>
                    <?php if (!empty($alert['note'])): ?><span class="uo-meta">Reason: <?= $h($alert['note']) ?></span><?php endif; ?>
                    <span class="uo-cta">View details &rarr;</span>
                </span>
            </a>
        <?php endif; ?>
        <ul class="uo-list">
            <?php foreach ($rows as $r): ?>
                <li>
                    <a href="<?= $h($r['href']) ?>">
                        <span class="ao-ico tone-<?= $r['tone'] ?>"><?= dash_icon($r['icon'], 16) ?></span>
                        <span class="uo-text">
                            <span class="uo-label"><?= $h($r['label']) ?></span>
                            <span class="uo-title"><?= $h($r['main']) ?></span>
                            <?php if (isset($r['pct'])): ?><span class="uo-bar" role="progressbar" aria-valuenow="<?= $r['pct'] ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Catechesis progress"><span style="width: <?= $r['pct'] ?>%"></span></span><?php endif; ?>
                            <span class="uo-meta"><?= $h($r['meta']) ?></span>
                            <span class="uo-cta"><?= $h($r['cta']) ?> &rarr;</span>
                        </span>
                        <?php if (!empty($r['pill'])): ?><span class="ao-pill pill-<?= $r['pill'][0] ?>"><?= $h($r['pill'][1]) ?></span><?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($updates): ?>
            <h3 class="uo-sub">Recent updates</h3>
            <ul class="uo-list is-updates">
                <?php foreach ($updates as $u): ?>
                    <li>
                        <a href="<?= $h($u['href']) ?>">
                            <span class="uo-text">
                                <span class="uo-title"><?= $h($u['title']) ?></span>
                                <span class="uo-meta"><?= $h($u['ref']) ?> · <?= $h($fmtDate($u['at'])) ?><?php if (!empty($u['note'])): ?> · <?= $h($u['note']) ?><?php endif; ?></span>
                            </span>
                            <span class="ao-pill pill-<?= $u['pill'] ?>"><?= $h($u['status']) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        </div>
    </aside>
    <?php
}
