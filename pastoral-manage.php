<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/sacrament_helpers.php';
require __DIR__ . '/includes/pastoral_helpers.php';
require __DIR__ . '/includes/report_tools.php';
require __DIR__ . '/includes/review_queue.php';
require __DIR__ . '/includes/user_mail.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Church Admins answer the requests of their church; the Super Admin views every church (view only).
$role = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($role, ['church_admin', 'super_admin'], true)) {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$isSuper = $role === 'super_admin';
$dashboard = $isSuper ? 'superadmin/dashboard.php' : 'churchadmin/dashboard.php';
$STATUSES = ['submitted', 'review', 'given', 'declined'];

$churches = $pdo->query('SELECT church_info_id, church_name FROM church_info ORDER BY church_name')->fetchAll();
$byId = array_column($churches, null, 'church_info_id');
$churchId = pick_church_id($pdo, $byId, $isSuper ? (int)($_GET['church'] ?? review_busiest_church($pdo, 'pastoral')) : 0);   // a Church Admin's own church, never from the request
if (!can_manage_church($pdo, $churchId)) {
    header('Location: ' . $dashboard);
    exit;
}
$church = $byId[$churchId];

$flash = $_SESSION['pastoral_flash'] ?? null;
unset($_SESSION['pastoral_flash']);
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

// ---- One request ----------------------------------------------------------------------------------
if ($id) {
    $stmt = $pdo->prepare('SELECT r.*, c.church_name, u.user_name, u.user_email, u.user_phone FROM pastoral_requests r JOIN church_info c ON c.church_info_id = r.church_id JOIN user u ON u.user_id = r.user_id WHERE r.request_id = ? AND r.status <> \'draft\'');
    $stmt->execute([$id]);
    $req = $stmt->fetch();
    // Super Admin: any church. Church Admin: only their own church's requests.
    if (!$req || !can_manage_church($pdo, (int)$req['church_id'])) {
        header('Location: pastoral-manage.php');
        exit;
    }
    $cfg = pc_form($pdo, $req['service'], (int)$req['church_id']);
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $new = (string)($_POST['status'] ?? '');
        $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);
        if ($isSuper) $error = 'The Super Admin can view requests but not change them.';
        elseif (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) $error = 'Your session expired. Please try again.';
        elseif (!in_array($new, $STATUSES, true)) $error = 'Please choose a status.';
        else {
            $pdo->prepare('UPDATE pastoral_requests SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE request_id = ?')
                ->execute([$new, $note !== '' ? $note : null, (int)$_SESSION['user_id'], $id]);
            $mailNote = '';
            if ($new !== $req['status'] || ($note !== '' && $note !== (string)$req['admin_note'])) {   // tell the parishioner
                $mailNote = send_status_email($pdo, (int)$req['church_id'], $req['user_email'], $req['user_name'], pc_title($pdo, $req['service'], (int)$req['church_id']), pc_ref($id), pc_status($new)[0], $note, 'pastoral-status.php')
                    ? ' ' . $req['user_name'] . ' has been emailed at ' . $req['user_email'] . '.'
                    : ' The email to ' . $req['user_name'] . ' could not be sent.';
            }
            $_SESSION['pastoral_flash'] = pc_ref($id) . ' is now "' . pc_status($new)[0] . '".' . $mailNote;
            header('Location: pastoral-manage.php?id=' . $id);
            exit;
        }
    }
    $details = json_decode((string)$req['details'], true) ?: [];
    $files = json_decode((string)$req['files'], true) ?: [];
    [$stLabel, $stClass] = pc_status($req['status']);

    $pageTitle = ($isSuper ? 'View' : 'Review') . ' Request';
    $activeNav = 'services';
    include __DIR__ . '/includes/header.php';
    ?>
    <main class="sx-page rv-page-wrap">
        <?php back_button('pastoral-manage.php', 'Back to requests'); ?>
        <header class="page-heading"><h1><?= $isSuper ? 'View' : 'Review' ?> Request</h1></header>
        <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert info-alert" role="alert"><?= e($error) ?></div><?php endif; ?>

        <?php print_heading(($cfg['title'] ?? $req['service']) . ' Request ' . pc_ref($id), $req['church_name'], ['Status' => $stLabel]); ?>
        <div class="rv-ids">
            <span>Request ID: <b><?= e(pc_ref($id)) ?></b></span>
            <span>Service: <b><?= e($cfg['title'] ?? $req['service']) ?></b></span>
            <button type="button" class="sr-print no-print" onclick="window.print()" aria-label="Print this request" title="Print"><?= sx_icon('print', 18) ?></button>
        </div>

        <div class="rv-cards">
            <?php foreach ($cfg['sections'] ?? [] as $s): if (empty($s['fields'])) continue; ?>
                <section class="rv-card">
                    <h2><?= e($s['title'] ?? 'Details') ?></h2>
                    <dl>
                        <?php foreach ($s['fields'] as $fd): $v = sx_show($fd, (string)($details[$fd['k']] ?? '')); ?>
                            <dt><?= e($fd['l']) ?>:</dt><dd><?= $v !== '' ? nl2br(e($v)) : '<span class="muted">&mdash;</span>' ?></dd>
                        <?php endforeach; ?>
                    </dl>
                </section>
            <?php endforeach; ?>
            <section class="rv-card">
                <h2>Requested by</h2>
                <dl>
                    <dt>Name:</dt><dd><?= e($req['user_name']) ?></dd>
                    <dt>Email:</dt><dd><a href="mailto:<?= e($req['user_email']) ?>"><?= e($req['user_email']) ?></a></dd>
                    <dt>Phone:</dt><dd><?= e($req['user_phone']) ?: '&mdash;' ?></dd>
                    <dt>Church:</dt><dd><?= e($req['church_name']) ?></dd>
                    <dt>Sent:</dt><dd><?= e(date('j F Y, g:i A', strtotime($req['created_at']))) ?></dd>
                </dl>
            </section>
        </div>

        <?php if ($cfg['docs'] ?? []): ?>
            <h2 class="rv-docs-title">Receipt</h2>
            <div class="rv-docs">
                <?php foreach ($cfg['docs'] as $key => $doc): $has = !empty($files[$key]) && ($path = sx_doc_path($files[$key])); $ext = $has ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : ''; ?>
                    <?php if ($has): ?><a class="rv-doc" href="pastoral-file.php?id=<?= $id ?>&amp;doc=<?= e($key) ?>" target="_blank" rel="noopener"><span class="rv-doc-ico <?= $ext === 'pdf' ? 'is-pdf' : 'is-img' ?>"><i><?= $ext === 'pdf' ? 'PDF' : 'IMG' ?></i></span><small><?= e($doc[0]) ?></small></a>
                    <?php else: ?><span class="rv-doc is-missing"><span class="rv-doc-ico"><i>&mdash;</i></span><small><?= e($doc[0]) ?> (not provided)</small></span><?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($isSuper): ?>
            <div class="rv-foot"><a class="rv-back" href="pastoral-manage.php">back</a><span class="sx-badge <?= $stClass ?>"><?= e($stLabel) ?></span></div>
            <?php if ($req['admin_note']): ?><p class="st-note rv-note-plain"><b>Note from the church:</b> <?= e($req['admin_note']) ?></p><?php endif; ?>
        <?php else: ?>
            <form method="post" action="pastoral-manage.php?id=<?= $id ?>" class="rv-foot rv-decide">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <a class="rv-back" href="pastoral-manage.php">back</a>
                <div class="rv-decide-fields">
                    <input type="text" name="note" maxlength="255" placeholder="Note for the parishioner (optional)" value="<?= e((string)$req['admin_note']) ?>" aria-label="Note for the parishioner">
                    <select name="status" aria-label="Status">
                        <?php foreach ($STATUSES as $s): ?><option value="<?= $s ?>" <?= $s === $req['status'] ? 'selected' : '' ?>><?= e(pc_status($s)[0]) ?></option><?php endforeach; ?>
                    </select>
                    <button type="submit" class="reg-submit">Save</button>
                </div>
            </form>
        <?php endif; ?>
    </main>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---- The list -----------------------------------------------------------------------------------
$stmt = $pdo->prepare('SELECT status, COUNT(*) FROM pastoral_requests WHERE church_id = ? AND status <> \'draft\' GROUP BY status');
$stmt->execute([$churchId]);
$counts = array_fill_keys($STATUSES, 0);
foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$st, $n]) if (isset($counts[$st])) $counts[$st] = (int)$n;

$fStatus = in_array($_GET['status'] ?? '', $STATUSES, true) ? $_GET['status'] : '';
$svcList = pc_services($pdo, $churchId, true);   // also the deleted ones: their old requests are still listed
$fService = isset($svcList[$_GET['service'] ?? '']) ? $_GET['service'] : '';
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$where = ['r.church_id = ?', "r.status <> 'draft'"];
$params = [$churchId];
if ($fStatus !== '') { $where[] = 'r.status = ?'; $params[] = $fStatus; }
if ($fService !== '') { $where[] = 'r.service = ?'; $params[] = $fService; }
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $num = preg_match('/^(?:pc-?)?0*(\d+)$/i', $q, $m) ? (int)$m[1] : 0;
    $where[] = '(u.user_name LIKE ? OR u.user_email LIKE ? OR r.details LIKE ? OR r.request_id = ?)';
    array_push($params, $like, $like, $like, $num);
}
$stmt = $pdo->prepare('SELECT r.request_id, r.service, r.status, r.details, r.created_at, u.user_name, u.user_phone, u.user_email FROM pastoral_requests r JOIN user u ON u.user_id = r.user_id WHERE ' . implode(' AND ', $where) . ' ORDER BY (r.status = \'submitted\') DESC, r.created_at DESC, r.request_id DESC' . (($_GET['export'] ?? '') === 'csv' ? '' : ' LIMIT 300'));
$stmt->execute($params);
$rows = $stmt->fetchAll();
$tiles = ['submitted' => ['Submitted', 't-blue'], 'review' => ['Under Review', 't-lilac'], 'given' => ['Approved', 't-green'], 'declined' => ['Declined', 't-red']];

// ---- Generate Report: the list as filtered, as a CSV ----
if (($_GET['export'] ?? '') === 'csv') {
    send_report('pastoral-requests-' . $church['church_name'],
        ['Request ID', 'Service', 'About', 'Requested by', 'Email', 'Phone', 'Status', 'Sent', 'Church'],
        array_map(fn($r) => [
            pc_ref((int)$r['request_id']), pc_title($pdo, $r['service'], $churchId), pc_summary($r['service'], json_decode((string)$r['details'], true) ?: []),
            $r['user_name'], $r['user_email'], $r['user_phone'], pc_status($r['status'])[0], date('Y-m-d', strtotime($r['created_at'])), $church['church_name'],
        ], $rows));
}

$pageTitle = 'Pastoral & Spiritual Care Requests';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="sx-page sr-page">
    <?php back_button($dashboard, 'Back to dashboard'); ?>
    <header class="page-heading"><h1>Pastoral &amp; Spiritual Care</h1><p><?= e($church['church_name']) ?></p></header>
    <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>

    <?php if ($isSuper): ?>
        <form method="get" class="sr-church">
            <label for="pm-church">Select Church</label>
            <select id="pm-church" name="church" onchange="this.form.submit()">
                <?php foreach ($churches as $c): ?><option value="<?= (int)$c['church_info_id'] ?>" <?= (int)$c['church_info_id'] === $churchId ? 'selected' : '' ?>><?= e(review_option_label($pdo, 'pastoral', (int)$c['church_info_id'], $c['church_name'])) ?></option><?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>

    <div class="sr-tiles is-four">
        <?php foreach ($tiles as $st => [$label, $cls]): ?>
            <a class="sr-tile <?= $cls ?>" href="pastoral-manage.php?<?= e(http_build_query(array_filter(['church' => $isSuper ? $churchId : '', 'status' => $st]))) ?>"><b><?= $counts[$st] ?></b><span><?= e($label) ?></span></a>
        <?php endforeach; ?>
    </div>

    <form method="get" class="sr-filter" role="search">
        <?php if ($isSuper): ?><input type="hidden" name="church" value="<?= $churchId ?>"><?php endif; ?>
        <select name="service" aria-label="Service" onchange="this.form.submit()">
            <option value="">All Services</option>
            <?php foreach ($svcList as $k => $s): ?><option value="<?= e($k) ?>" <?= $fService === $k ? 'selected' : '' ?>><?= e($s['title']) ?><?= $s['deleted_at'] !== null ? ' (deleted)' : '' ?></option><?php endforeach; ?>
        </select>
        <select name="status" aria-label="Status" onchange="this.form.submit()">
            <option value="">All Statuses</option>
            <?php foreach ($tiles as $st => [$label]): ?><option value="<?= $st ?>" <?= $fStatus === $st ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <label class="sr-search"><?= sx_icon('search', 15) ?><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name or ID..." aria-label="Search name or ID"></label>
        <span class="sr-spacer"></span>
        <a class="ps-link" href="pastoral-services.php<?= $isSuper ? '?church=' . $churchId : '' ?>"><?= $isSuper ? 'View services' : 'Manage services' ?></a>
        <?php report_tools(report_csv_url(), 'Print the request list'); ?>
    </form>

    <?php print_heading('Pastoral & Spiritual Care Requests', $church['church_name'], [
        'Service' => $fService !== '' ? $svcList[$fService]['title'] : '', 'Status' => $fStatus !== '' ? $tiles[$fStatus][0] : '', 'Search' => $q,
        'Requests' => (string)count($rows),
    ]); ?>

    <?php if (!$rows): ?>
        <p class="ann-empty"><?= ($fStatus || $fService || $q) ? 'No requests match those filters.' : 'No requests yet.' ?></p>
    <?php else: ?>
        <div class="reg-table-wrap sr-table-wrap">
            <table class="reg-table sr-table">
                <thead><tr><th>ID</th><th>Service</th><th>About</th><th>Requested by</th><th>Status</th><th>Sent</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    [$stLabel, $stClass] = pc_status($r['status']);
                    $d = json_decode((string)$r['details'], true) ?: [];
                    $link = 'pastoral-manage.php?id=' . (int)$r['request_id'];
                ?>
                    <tr<?= $r['status'] === 'submitted' ? ' class="needs-review"' : '' ?>>
                        <td data-label="ID"><a class="sr-id" href="<?= $link ?>"><?= e(pc_ref((int)$r['request_id'])) ?></a></td>
                        <td data-label="Service"><span class="sr-sac s-pastoral"><?= e(pc_title($pdo, $r['service'], $churchId)) ?></span></td>
                        <td data-label="About"><?= e(pc_summary($r['service'], $d)) ?: '&mdash;' ?></td>
                        <td data-label="Requested by"><?= e($r['user_name']) ?><small><?= e($r['user_phone'] ?: $r['user_email']) ?></small></td>
                        <td data-label="Status"><span class="sx-badge <?= $stClass ?>"><?= e($stLabel) ?></span><?= $r['status'] === 'submitted' ? '<span class="needs-review-tag">Needs review</span>' : '' ?></td>
                        <td data-label="Sent"><?= e(date('d M Y', strtotime($r['created_at']))) ?></td>
                        <td data-label="Action"><a class="sr-review" href="<?= $link ?>"><?= sx_icon('right', 12) ?> <?= $isSuper ? 'View' : 'Review' ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
