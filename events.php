<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/login_gate.php';
require_signed_in('Church Calendar');   // visitors get "please login or register" instead of this page
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/event_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$role = $_SESSION['role'] ?? '';
$isSuper = !empty($_SESSION['user_id']) && $role === 'super_admin';
$isChurchAdmin = !empty($_SESSION['user_id']) && $role === 'church_admin';

$churches = $pdo->query('SELECT church_info_id, church_name, church_location FROM church_info ORDER BY church_name')->fetchAll();
$byId = array_column($churches, null, 'church_info_id');

// ---- What to show: church (0 = all churches), filter, month, view, day, search --------------
// A Church Admin only ever sees and manages their own church: any ?church= in the URL is ignored
// for them, exactly like the other admin pages (donation-manage.php, sacrament-review.php, ...).
// The Super Admin has no home parish, so with no ?church= given yet they start at "All churches"
// rather than pick_church_id()'s usual fallback (the first church) — that fallback is right for the
// single-church pages that share it, but would silently hide every other church's events here.
// A public parishioner may still browse any church's calendar, so their ?church= is honoured as before.
if ($isChurchAdmin) {
    $churchId = pick_church_id($pdo, $byId, 0);
} elseif (array_key_exists('church', $_GET) && (string)$_GET['church'] === '0') {
    $churchId = 0;
} elseif ($isSuper && !array_key_exists('church', $_GET)) {
    $churchId = 0;
} else {
    $churchId = pick_church_id($pdo, $byId, (int)($_GET['church'] ?? 0));
}
$filter = in_array($_GET['type'] ?? '', ['mass', 'event'], true) ? $_GET['type'] : '';
$month  = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
$monthStart = $month . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));
$today = date('Y-m-d');
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);

$canManageSelected = $churchId > 0 && can_manage_church($pdo, $churchId);
$showManageTab = $canManageSelected || ($isSuper && $churchId === 0);
$view = ($_GET['view'] ?? '') === 'manage' && $showManageTab ? 'manage' : 'calendar';

// Calendar grid: whole weeks, Sunday to Saturday.
$gridFrom = date('Y-m-d', strtotime($monthStart . ' -' . (int)date('w', strtotime($monthStart)) . ' days'));
$gridTo   = date('Y-m-d', strtotime($monthEnd . ' +' . (6 - (int)date('w', strtotime($monthEnd))) . ' days'));
$day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['day'] ?? '')) && $_GET['day'] >= $gridFrom && $_GET['day'] <= $gridTo ? $_GET['day'] : '';

/** URL of this page keeping the current church / filter / month; $over changes parts of it. */
function cal_url(array $over = []): string
{
    global $churchId, $filter, $month, $view, $q;
    $keepSearch = $view === 'calendar' && !array_key_exists('view', $over);   // the search stays on while browsing the calendar
    $p = array_merge(['q' => $keepSearch ? $q : '', 'church' => $churchId, 'type' => $filter, 'month' => $month, 'view' => $view === 'manage' ? 'manage' : '', 'day' => ''], $over);
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null);   // note: church=0 must stay, so 0 is kept
    if ($month === date('Y-m') && ($p['month'] ?? '') === date('Y-m')) unset($p['month']);
    return 'events.php?' . http_build_query($p, '', '&amp;');
}

$flash = $_SESSION['event_flash'] ?? null;
unset($_SESSION['event_flash']);
$error = '';

// ---- POST: delete an event ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_event') {
    $stmt = $pdo->prepare('SELECT church_id FROM events WHERE event_id = ?');
    $stmt->execute([(int)($_POST['id'] ?? 0)]);
    $row = $stmt->fetch();
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif (!$row || !can_manage_church($pdo, (int)$row['church_id'])) {
        $error = 'You do not have permission to delete this event.';   // the church comes from the record, never the form
    } else {
        $pdo->prepare('DELETE FROM events WHERE event_id = ?')->execute([(int)$_POST['id']]);
        $_SESSION['event_flash'] = 'Event deleted.';
        header('Location: ' . html_entity_decode(cal_url(['view' => 'manage'])));
        exit;
    }
}

// ---- Load the events ------------------------------------------------------------------------
/** Events that can appear between $from and $to, for the chosen church / filter. */
function load_events(PDO $pdo, int $churchId, string $filter, string $from, string $to): array
{
    $where = ['e.event_date <= ?', '((e.repeat_weekly = 0 AND e.event_date >= ?) OR (e.repeat_weekly = 1 AND (e.repeat_until IS NULL OR e.repeat_until >= ?)))'];
    $params = [$to, $from, $from];
    if ($churchId > 0) { $where[] = 'e.church_id = ?'; $params[] = $churchId; }
    if ($filter !== '') { $where[] = 'e.category = ?'; $params[] = $filter; }
    $stmt = $pdo->prepare('SELECT e.*, c.church_name FROM events e JOIN church_info c ON c.church_info_id = e.church_id WHERE ' . implode(' AND ', $where));
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$byDate = [];
$upcoming = [];
$stats = ['events' => 0, 'registrants' => 0, 'participants' => 0];
$regUpcoming = $regPast = $otherUpcoming = $otherPast = [];
$found = [];

if ($view === 'calendar') {
    foreach (load_events($pdo, $churchId, $filter, $gridFrom, $gridTo) as $ev) {
        foreach (event_occurrences($ev, $gridFrom, $gridTo) as $d) $byDate[$d][] = ['date' => $d, 'event' => $ev];
    }
    foreach ($byDate as &$list) sort_occurrences($list);
    unset($list);

    // "Upcoming": the next few things from today, independent of the month being viewed.
    $horizon = date('Y-m-d', strtotime('+60 days'));
    foreach (load_events($pdo, $churchId, $filter, $today, $horizon) as $ev) {
        foreach (event_occurrences($ev, $today, $horizon) as $d) $upcoming[] = ['date' => $d, 'event' => $ev];
    }
    sort_occurrences($upcoming);
    $upcoming = array_slice($upcoming, 0, 8);

    // Search (?q=): every mass / event from today on that matches, one row per event at its next date.
    // Each word typed must appear somewhere in the title, kind, place, description or church name.
    $found = [];
    if ($q !== '') {
        $where = ['1 = 1'];
        $params = [];
        foreach (array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY), 0, 6) as $word) {
            $like = '%' . addcslashes($word, '%_\\') . '%';
            $where[] = '(e.title LIKE ? OR e.event_type LIKE ? OR e.location LIKE ? OR e.description LIKE ? OR c.church_name LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($churchId > 0) { $where[] = 'e.church_id = ?'; $params[] = $churchId; }
        if ($filter !== '') { $where[] = 'e.category = ?'; $params[] = $filter; }
        $stmt = $pdo->prepare('SELECT e.*, c.church_name FROM events e JOIN church_info c ON c.church_info_id = e.church_id WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $ev) {
            $next = event_next_date($ev, $today);
            if ($next !== null) $found[] = ['date' => $next, 'event' => $ev];
        }
        sort_occurrences($found);
    }
} else {
    // The numbers at the top: events that take registrations, sign-ups, and the people they cover.
    $churchSql = $churchId > 0 ? ' AND e.church_id = ?' : '';
    $stmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT e.event_id) AS events, COUNT(r.registration_id) AS registrants, COALESCE(SUM(r.people), 0) AS participants
           FROM events e LEFT JOIN event_registrations r ON r.event_id = e.event_id
          WHERE e.registration_required = 1 AND e.repeat_weekly = 0' . $churchSql
    );
    $stmt->execute($churchId > 0 ? [$churchId] : []);
    $stats = $stmt->fetch();

    // Search: event title / location, or the name / email of anyone registered.
    $scopeSql = $churchSql;
    $sp = $churchId > 0 ? [$churchId] : [];
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $scopeSql .= ' AND (e.title LIKE ? OR e.location LIKE ? OR EXISTS (SELECT 1 FROM event_registrations rr WHERE rr.event_id = e.event_id AND (rr.name LIKE ? OR rr.email LIKE ?)))';
        array_push($sp, $like, $like, $like, $like);
    }

    $stmt = $pdo->prepare(
        'SELECT e.*, c.church_name, COUNT(r.registration_id) AS regs, COALESCE(SUM(r.people), 0) AS people
           FROM events e JOIN church_info c ON c.church_info_id = e.church_id
      LEFT JOIN event_registrations r ON r.event_id = e.event_id
          WHERE e.registration_required = 1 AND e.repeat_weekly = 0' . $scopeSql . '
       GROUP BY e.event_id ORDER BY e.event_date, e.start_time'
    );
    $stmt->execute($sp);
    $regEvents = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT e.*, c.church_name FROM events e JOIN church_info c ON c.church_info_id = e.church_id
          WHERE NOT (e.registration_required = 1 AND e.repeat_weekly = 0)' . $scopeSql . ' ORDER BY e.event_date, e.start_time'
    );
    $stmt->execute($sp);
    $otherEvents = $stmt->fetchAll();

    $canCache = [];
    $split = function (array $events, array &$upcomingOut, array &$pastOut) use (&$canCache, $pdo, $today): void {
        foreach ($events as $ev) {
            $cid = (int)$ev['church_id'];
            $ev['can_manage'] = $canCache[$cid] ??= can_manage_church($pdo, $cid);
            $next = event_next_date($ev, $today);
            if ($next !== null) { $ev['shown_date'] = $next; $upcomingOut[] = $ev; }
            else { $ev['shown_date'] = $ev['repeat_weekly'] && $ev['repeat_until'] ? $ev['repeat_until'] : $ev['event_date']; $pastOut[] = $ev; }
        }
        usort($upcomingOut, fn($a, $b) => [$a['shown_date'], (string)$a['start_time']] <=> [$b['shown_date'], (string)$b['start_time']]);
        usort($pastOut, fn($a, $b) => $b['shown_date'] <=> $a['shown_date']);
        $pastOut = array_slice($pastOut, 0, 30);
    };
    $split($regEvents, $regUpcoming, $regPast);
    $split($otherEvents, $otherUpcoming, $otherPast);
}

$dayList = $day ? ($byDate[$day] ?? []) : [];
$prevMonth = date('Y-m', strtotime($monthStart . ' -1 month'));
$nextMonth = date('Y-m', strtotime($monthStart . ' +1 month'));
$churchLabel = $churchId > 0 ? $byId[$churchId]['church_name'] : 'All churches';

$pageTitle = $view === 'manage' ? 'Event Management' : 'Church Calendar';
$activeNav = 'services';

/** The Calendar | Participants switch (shown to admins only). */
$tabs = function () use ($showManageTab, $view): void {
    if (!$showManageTab) return; ?>
    <nav class="cal-tabs" aria-label="Calendar or participants">
        <a href="<?= cal_url(['view' => '']) ?>" class="<?= $view === 'calendar' ? 'is-active' : '' ?>">Calendar</a>
        <a href="<?= cal_url(['view' => 'manage']) ?>" class="<?= $view === 'manage' ? 'is-active' : '' ?>">Participants</a>
    </nav>
<?php };

/** One event that takes registrations: counts, Manage button, and an expandable details panel. */
$renderRegRow = function (array $ev) use ($q): void {
    $rid = 'x' . (int)$ev['event_id'];
    $cls = event_type_class($ev['event_type']); ?>
    <article class="mg-row <?= $cls ?>">
        <div class="mg-main">
            <h3><a href="event.php?id=<?= (int)$ev['event_id'] ?>"><?= e($ev['title']) ?></a> <span class="ev-tag <?= $cls ?>"><?= e($ev['event_type']) ?></span></h3>
            <p class="ev-meta">
                <span><?= ev_icon('calendar', 14) ?> <?= e(date('D, M j, Y', strtotime($ev['shown_date']))) ?></span>
                <?php if ($ev['location']): ?><span><?= ev_icon('pin', 14) ?> <?= e($ev['location']) ?></span><?php endif; ?>
                <span><?= ev_icon('clock', 14) ?> <?= e(event_time_text($ev)) ?></span>
            </p>
        </div>
        <div class="mg-counts">
            <span class="mg-count"><b><?= (int)$ev['regs'] ?></b><small>Registered</small></span>
            <span class="mg-count"><b><?= (int)$ev['people'] ?><?= $ev['capacity'] ? '<i>/' . (int)$ev['capacity'] . '</i>' : '' ?></b><small>Participants</small></span>
        </div>
        <?php if ($ev['can_manage']): ?>
            <a class="btn-manage" href="event-participants.php?id=<?= (int)$ev['event_id'] ?>"><?= ev_icon('users', 15) ?> Manage</a>
        <?php endif; ?>
        <button type="button" class="ico-btn rg-toggle" aria-expanded="false" aria-controls="<?= $rid ?>" title="Details" aria-label="Details for <?= e($ev['title']) ?>"><?= ev_icon('down', 16) ?></button>
        <div class="mg-more" id="<?= $rid ?>" hidden>
            <?php if ($ev['description']): ?><p><?= nl2br(e($ev['description'])) ?></p><?php endif; ?>
            <p class="mg-church"><?= ev_icon('church', 14) ?> <?= e($ev['church_name']) ?><?= $ev['capacity'] ? ' &middot; ' . (int)$ev['capacity'] . ' places' : ' &middot; unlimited places' ?></p>
            <?php if ($ev['can_manage']): ?>
                <form method="post" action="<?= cal_url(['view' => 'manage']) ?>" class="mg-actions">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="delete_event">
                    <input type="hidden" name="id" value="<?= (int)$ev['event_id'] ?>">
                    <a class="btn-new" href="event.php?id=<?= (int)$ev['event_id'] ?>">View page</a>
                    <a class="btn-new" href="event-form.php?id=<?= (int)$ev['event_id'] ?>"><?= ev_icon('pencil', 14) ?> Edit</a>
                    <button type="submit" class="btn-danger sm" data-confirm="Delete this event and its <?= (int)$ev['regs'] ?> registrations? This cannot be undone."><?= ev_icon('trash', 14) ?> Delete</button>
                </form>
            <?php endif; ?>
        </div>
    </article>
<?php };

/** One event without registration (a Mass schedule, a notice...): compact row with edit / delete. */
$renderOtherRow = function (array $ev): void { ?>
    <article class="ev-row">
        <div class="ev-badge" aria-label="<?= e(date('j F Y', strtotime($ev['shown_date']))) ?>"><span><?= e(strtoupper(date('M', strtotime($ev['shown_date'])))) ?></span><b><?= (int)date('j', strtotime($ev['shown_date'])) ?></b></div>
        <div class="ev-main">
            <h3><a href="event.php?id=<?= (int)$ev['event_id'] ?>&amp;date=<?= e($ev['shown_date']) ?>"><?= e($ev['title']) ?></a> <span class="ev-tag <?= event_type_class($ev['event_type']) ?>"><?= e($ev['event_type']) ?></span></h3>
            <p class="ev-meta">
                <span><?= ev_icon('clock', 14) ?> <?= e(event_time_text($ev)) ?></span>
                <?php if ($ev['location']): ?><span><?= ev_icon('pin', 14) ?> <?= e($ev['location']) ?></span><?php endif; ?>
                <span><?= ev_icon('church', 14) ?> <?= e($ev['church_name']) ?></span>
                <?php if ($ev['repeat_weekly']): ?><span><?= ev_icon('repeat', 14) ?> Every <?= e(date('l', strtotime($ev['event_date']))) ?></span><?php endif; ?>
            </p>
        </div>
        <?php if ($ev['can_manage']): ?>
            <form method="post" action="<?= cal_url(['view' => 'manage']) ?>" class="ev-actions">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="delete_event">
                <input type="hidden" name="id" value="<?= (int)$ev['event_id'] ?>">
                <a class="ico-btn sm" href="event-form.php?id=<?= (int)$ev['event_id'] ?>" title="Edit" aria-label="Edit <?= e($ev['title']) ?>"><?= ev_icon('pencil', 14) ?></a>
                <button type="submit" class="ico-btn sm danger" data-confirm="Delete this event? This cannot be undone." title="Delete" aria-label="Delete <?= e($ev['title']) ?>"><?= ev_icon('trash', 14) ?></button>
            </form>
        <?php endif; ?>
    </article>
<?php };

include __DIR__ . '/includes/header.php';
?>
<main class="cal-page">
    <?php back_button('index.php#services', 'Back to Services'); ?>
<?php if ($view === 'calendar'): ?>
    <header class="page-heading">
        <h1>Church Calendar</h1>
        <p>Masses, events and gatherings across the parishes.</p>
    </header>
<?php endif; ?>

    <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert info-alert" role="alert"><?= e($error) ?></div><?php endif; ?>

    <div class="cal-toolbar">
        <?php if ($isChurchAdmin): ?>
            <span class="cal-church-fixed"><?= ev_icon('church', 15) ?> <?= e($churchLabel) ?></span>
        <?php else: ?>
            <form method="get" action="events.php" class="cal-church" id="cal-church-form">
                <?php if ($filter): ?><input type="hidden" name="type" value="<?= e($filter) ?>"><?php endif; ?>
                <?php if ($view === 'manage'): ?><input type="hidden" name="view" value="manage"><?php endif; ?>
                <?php if ($view === 'calendar' && $q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
                <label for="cal-church" class="sr-only">Church</label>
                <select id="cal-church" name="church" aria-label="Church">
                    <option value="0" <?= $churchId === 0 ? 'selected' : '' ?>>All churches</option>
                    <?php foreach ($churches as $ch): ?>
                        <option value="<?= (int)$ch['church_info_id'] ?>" <?= (int)$ch['church_info_id'] === $churchId ? 'selected' : '' ?>><?= e($ch['church_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>

        <?php if ($view === 'calendar'): ?>
            <nav class="cal-filter" aria-label="Filter events">
                <a href="<?= cal_url(['type' => '']) ?>" class="<?= $filter === '' ? 'is-active' : '' ?>">All</a>
                <a href="<?= cal_url(['type' => 'mass']) ?>" class="<?= $filter === 'mass' ? 'is-active' : '' ?>">Mass Schedule</a>
                <a href="<?= cal_url(['type' => 'event']) ?>" class="<?= $filter === 'event' ? 'is-active' : '' ?>">Event</a>
            </nav>
            <form method="get" action="events.php" class="cal-search" role="search">
                <input type="hidden" name="church" value="<?= $churchId ?>">
                <?php if ($filter): ?><input type="hidden" name="type" value="<?= e($filter) ?>"><?php endif; ?>
                <?php if ($month !== date('Y-m')): ?><input type="hidden" name="month" value="<?= e($month) ?>"><?php endif; ?>
                <?= ev_icon('search', 16) ?>
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search a mass or event&hellip;" aria-label="Search masses and events" maxlength="100">
                <?php if ($q !== ''): ?><a href="<?= cal_url(['q' => '']) ?>" class="cal-search-clear" aria-label="Clear the search">&times;</a><?php endif; ?>
            </form>
            <?php $tabs(); ?>
            <?php if (($_SESSION['role'] ?? '') === 'user'): ?>
                <a class="cal-myreg" href="my-events.php"><?= ev_icon('check', 15) ?> My Registrations</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>

<?php if ($view === 'calendar'): ?>
    <?php if ($q !== ''): $shown = array_slice($found, 0, 40); ?>
        <section class="cal-found" id="results" aria-label="Search results">
            <div class="cal-found-head">
                <h2><?= count($found) ?> <?= count($found) === 1 ? 'result' : 'results' ?> for &ldquo;<?= e($q) ?>&rdquo; <span><?= e($churchLabel) ?><?= $filter ? ' &middot; ' . ($filter === 'mass' ? 'Mass Schedule' : 'Event') : '' ?></span></h2>
                <div class="cal-found-tools">
                    <?php if ($churchId > 0 && !$isChurchAdmin): ?><a href="<?= cal_url(['church' => 0]) ?>#results">Search all churches</a><?php endif; ?>
                    <a href="<?= cal_url(['q' => '']) ?>">Clear search</a>
                </div>
            </div>
            <?php if (!$found): ?>
                <p class="up-empty">No upcoming mass or event matches your search. Try a shorter word<?= $churchId > 0 && !$isChurchAdmin ? ', or search all churches' : '' ?>.</p>
            <?php endif; ?>
            <div class="cal-found-list">
                <?php foreach ($shown as $it): $ev = $it['event']; $reg = $ev['registration_required'] && !$ev['repeat_weekly']; ?>
                    <a class="cal-found-item <?= event_type_class($ev['event_type']) ?>" href="event.php?id=<?= (int)$ev['event_id'] ?>&amp;date=<?= e($it['date']) ?>">
                        <span class="cal-found-date"><small><?= e(strtoupper(date('M', strtotime($it['date'])))) ?></small><b><?= (int)date('j', strtotime($it['date'])) ?></b><small><?= e(date('D', strtotime($it['date']))) ?></small></span>
                        <span class="cal-found-main">
                            <strong><?= e($ev['title']) ?></strong>
                            <span><?= e(event_time_text($ev)) ?><?= $ev['location'] ? ' &middot; ' . e($ev['location']) : '' ?></span>
                            <span class="cal-found-church"><?= e($ev['church_name']) ?></span>
                        </span>
                        <span class="cal-found-tags">
                            <i><?= e($ev['event_type']) ?></i>
                            <?php if ($ev['repeat_weekly']): ?><i class="is-plain">Every <?= e(date('l', strtotime($it['date']))) ?></i><?php endif; ?>
                            <?php if ($reg): ?><i class="is-reg">Registration</i><?php endif; ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php if (count($found) > count($shown)): ?><p class="up-empty">Showing the first <?= count($shown) ?>. Add another word to narrow the search.</p><?php endif; ?>
        </section>
    <?php endif; ?>
    <div class="cal-layout">
        <section class="cal-card" aria-label="Calendar for <?= e(date('F Y', strtotime($monthStart))) ?>">
            <div class="cal-head">
                <h2><?= e(date('F Y', strtotime($monthStart))) ?> <span><?= e($churchLabel) ?></span></h2>
                <div class="cal-head-tools">
                    <?php if ($canManageSelected): ?>
                        <a class="btn-new" href="event-form.php?church=<?= $churchId ?>"><?= ev_icon('plus', 15) ?> New Event</a>
                    <?php endif; ?>
                    <a class="cal-today" href="<?= cal_url(['month' => date('Y-m')]) ?>">Today</a>
                    <a class="cal-arrow" href="<?= cal_url(['month' => $prevMonth]) ?>" aria-label="Previous month"><?= ev_icon('left', 16) ?></a>
                    <a class="cal-arrow" href="<?= cal_url(['month' => $nextMonth]) ?>" aria-label="Next month"><?= ev_icon('right', 16) ?></a>
                </div>
            </div>

            <div class="cal-grid" role="grid">
                <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dow): ?><div class="cal-dow" role="columnheader"><?= $dow ?></div><?php endforeach; ?>
                <?php for ($d = $gridFrom; $d <= $gridTo; $d = date('Y-m-d', strtotime($d . ' +1 day'))):
                    $items = $byDate[$d] ?? [];
                    $cls = 'cal-cell' . (substr($d, 0, 7) !== $month ? ' is-other' : '') . ($d === $today ? ' is-today' : '') . ($d === $day ? ' is-selected' : '');
                ?>
                    <div class="<?= $cls ?>" role="gridcell">
                        <a class="cal-daynum" href="<?= cal_url(['day' => $d, 'month' => substr($d, 0, 7) === $month ? $month : substr($d, 0, 7)]) ?>" aria-label="<?= e(date('l, j F', strtotime($d))) ?><?= $items ? ', ' . count($items) . ' events' : '' ?>"><?= (int)date('j', strtotime($d)) ?></a>
                        <?php foreach (array_slice($items, 0, 3) as $it): $ev = $it['event']; ?>
                            <a class="ev-chip <?= event_type_class($ev['event_type']) ?>" href="event.php?id=<?= (int)$ev['event_id'] ?>&amp;date=<?= $d ?>"
                               title="<?= e($ev['title'] . ' · ' . event_time_text($ev) . ($churchId === 0 ? ' · ' . $ev['church_name'] : '')) ?>">
                                <?php if (!$ev['is_all_day'] && $ev['start_time']): ?><b><?= e(fmt_time($ev['start_time'])) ?></b><?php endif; ?>
                                <span><?= e($ev['title']) ?></span>
                            </a>
                        <?php endforeach; ?>
                        <?php if (count($items) > 3): ?><a class="cal-more" href="<?= cal_url(['day' => $d]) ?>">+<?= count($items) - 3 ?> more</a><?php endif; ?>
                        <?php if ($items): ?>
                            <span class="cal-dots" aria-hidden="true"><?php foreach (array_slice($items, 0, 4) as $it): ?><i class="<?= event_type_class($it['event']['event_type']) ?>"></i><?php endforeach; ?></span>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
            <?php
            // Legend: the kinds of events that appear in this month's grid.
            $legend = [];
            foreach ($byDate as $items) foreach ($items as $it) $legend[event_type_class($it['event']['event_type'])] ??= $it['event']['event_type'];
            ?>
            <?php if ($legend): ?>
                <div class="cal-legend" aria-label="Colour key">
                    <?php foreach ($legend as $cls => $label): ?><span class="<?= $cls ?>"><i></i><?= e($label) ?></span><?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <aside class="up-card" aria-label="<?= $day ? 'Events on the selected day' : 'Upcoming events' ?>">
            <?php if ($day): ?>
                <div class="up-head"><h2><?= e(date('D, j M', strtotime($day))) ?></h2><a class="up-clear" href="<?= cal_url(['day' => '']) ?>">Show upcoming</a></div>
                <?php if (!$dayList): ?><p class="up-empty">Nothing is scheduled for this day.</p><?php endif; ?>
                <?php $groups = $dayList ? [$day => $dayList] : []; ?>
            <?php else: ?>
                <div class="up-head"><h2>Upcoming</h2><span class="week-badge">Week <?= (int)date('W') ?></span></div>
                <?php if (!$upcoming): ?><p class="up-empty">No upcoming events<?= $filter ? ' in this filter' : '' ?>.</p><?php endif; ?>
                <?php $groups = []; foreach ($upcoming as $u) $groups[$u['date']][] = $u; ?>
            <?php endif; ?>

            <?php foreach ($groups as $gDate => $items): ?>
                <h3 class="up-day"><?= $gDate === $today ? 'Today, ' . strtoupper(date('M j', strtotime($gDate))) : strtoupper(date('D, M j', strtotime($gDate))) ?></h3>
                <?php foreach ($items as $it): $ev = $it['event']; $reg = $ev['registration_required'] && !$ev['repeat_weekly']; ?>
                    <div class="up-item <?= event_type_class($ev['event_type']) ?>">
                        <a class="up-title" href="event.php?id=<?= (int)$ev['event_id'] ?>&amp;date=<?= $gDate ?>"><?= e($ev['title']) ?></a>
                        <span class="up-meta"><?= $ev['is_all_day'] ? 'All Day' : e(fmt_time($ev['start_time'])) ?><?= $ev['location'] ? ' · ' . e($ev['location']) : '' ?></span>
                        <?php if ($churchId === 0): ?><span class="up-meta up-church"><?= e($ev['church_name']) ?></span><?php endif; ?>
                        <?php if ($reg): ?><a class="up-reg" href="event.php?id=<?= (int)$ev['event_id'] ?>&amp;date=<?= $gDate ?>#register">Register &rarr;</a><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </aside>
    </div>

<?php else: ?>
    <section class="ev-manage">
        <div class="mg-top">
            <h1>Event Management</h1>
            <div class="mg-top-tools">
                <?php $tabs(); ?>
                <?php if ($canManageSelected): ?><a class="btn-primary-lg" href="event-form.php?church=<?= $churchId ?>"><?= ev_icon('plus', 16) ?> New Event</a><?php endif; ?>
            </div>
        </div>

        <div class="mg-stats">
            <div class="mg-stat"><span>Events with Registration</span><b class="c-purple"><?= (int)$stats['events'] ?></b></div>
            <div class="mg-stat"><span>Total Registrants</span><b class="c-green"><?= (int)$stats['registrants'] ?></b></div>
            <div class="mg-stat"><span>Total Participants</span><b class="c-indigo"><?= (int)$stats['participants'] ?></b></div>
        </div>

        <form method="get" action="events.php" class="mg-search" role="search">
            <input type="hidden" name="view" value="manage">
            <input type="hidden" name="church" value="<?= $churchId ?>">
            <?= ev_icon('search', 16) ?>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search events or participants..." aria-label="Search events or participants" maxlength="100">
            <?php if ($q !== ''): ?><a href="<?= cal_url(['view' => 'manage']) ?>" class="mg-clear">Clear</a><?php endif; ?>
        </form>

        <?php if ($q !== ''): ?><p class="bul-found"><?= count($regUpcoming) + count($regPast) + count($otherUpcoming) + count($otherPast) ?> matching events for &ldquo;<?= e($q) ?>&rdquo;</p><?php endif; ?>

        <?php if (!$regUpcoming && !$regPast): ?>
            <p class="ann-empty"><?= $q !== '' ? 'No events with registration match your search.' : 'No events with registration yet. Create an event and tick &ldquo;Requires Registration&rdquo;.' ?></p>
        <?php endif; ?>
        <?php if ($regUpcoming): ?><div class="mg-list"><?php foreach ($regUpcoming as $ev) $renderRegRow($ev); ?></div><?php endif; ?>
        <?php if ($regPast): ?>
            <h2 class="ann-heading ann-heading-sub">Past events with registration</h2>
            <div class="mg-list is-past"><?php foreach ($regPast as $ev) $renderRegRow($ev); ?></div>
        <?php endif; ?>

        <?php if ($otherUpcoming || $otherPast): ?>
            <h2 class="ann-heading ann-heading-sub">Mass schedules &amp; events without registration</h2>
            <div class="ev-list"><?php foreach ($otherUpcoming as $ev) $renderOtherRow($ev); ?></div>
            <?php if ($otherPast): ?>
                <h2 class="ann-heading ann-heading-sub">Past events</h2>
                <div class="ev-list is-past"><?php foreach ($otherPast as $ev) $renderOtherRow($ev); ?></div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
</main>
<script>
document.getElementById('cal-church').addEventListener('change', function () {
    document.getElementById('cal-church-form').submit();
});
document.querySelectorAll('.rg-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var box = document.getElementById(btn.getAttribute('aria-controls'));
        box.hidden = !box.hidden;
        btn.setAttribute('aria-expanded', !box.hidden);
        btn.classList.toggle('is-open', !box.hidden);
    });
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
