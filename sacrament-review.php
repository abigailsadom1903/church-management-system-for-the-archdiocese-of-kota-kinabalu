<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/sacrament_helpers.php';
require __DIR__ . '/includes/report_tools.php';
require __DIR__ . '/includes/review_queue.php';
require __DIR__ . '/includes/user_mail.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Church Admins manage the applications of their church; the Super Admin views every church (view only).
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

$STATUSES = ['submitted', 'verifying', 'review', 'approved', 'rejected'];
$FORMS = array_filter(SACRAMENTS, fn($k) => $k !== 'confession', ARRAY_FILTER_USE_KEY);   // Confession has its own page
$flash = $_SESSION['sacrament_flash'] ?? null;
unset($_SESSION['sacrament_flash']);

$churches = $pdo->query('SELECT church_info_id, church_name FROM church_info ORDER BY church_name')->fetchAll();
$byId = array_column($churches, null, 'church_info_id');
// The church in view: a Church Admin's own (never from the request); the Super Admin's choice.
$churchId = pick_church_id($pdo, $byId, $isSuper ? (int)($_GET['church'] ?? review_busiest_church($pdo, 'sacraments')) : 0);
if (!can_manage_church($pdo, $churchId)) {
    header('Location: ' . $dashboard);
    exit;
}
$church = $byId[$churchId];

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

// ---- Delete an application (Church Admin) ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $stmt = $pdo->prepare("SELECT application_id, church_id, files FROM sacrament_applications WHERE application_id = ? AND sacrament <> 'confession'");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($isSuper) {
        $_SESSION['sacrament_flash'] = 'The Super Admin can view applications but not change them.';
    } elseif (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '') || !$row || !can_manage_church($pdo, (int)$row['church_id'])) {
        $_SESSION['sacrament_flash'] = 'That application could not be deleted.';
    } else {
        foreach ((json_decode((string)$row['files'], true) ?: []) as $file) sx_delete_doc($file);
        $pdo->prepare('DELETE FROM sacrament_applications WHERE application_id = ?')->execute([$id]);
        $_SESSION['sacrament_flash'] = sx_ref($id) . ' was deleted.';
    }
    header('Location: sacrament-review.php');
    exit;
}

// ---- New Registration: pick a parishioner and a sacrament, then fill the form for them ----------------
if (!$id && !empty($_GET['new']) && !$isSuper) {
    $stmt = $pdo->prepare('SELECT user_id, user_name, user_email FROM user WHERE church_id = ? ORDER BY user_name');
    $stmt->execute([$churchId]);
    $people = $stmt->fetchAll();
    $pageTitle = 'New Registration';
    $activeNav = 'services';
    include __DIR__ . '/includes/header.php';
    ?>
    <main class="sx-page sx-narrow">
        <?php back_button('sacrament-review.php', 'Back to sacraments'); ?>
        <header class="page-heading"><h1>New Registration</h1><p>Register a parishioner of <?= e($church['church_name']) ?> for a sacrament.</p></header>
        <form method="get" action="sacrament-apply.php" class="sx-shell sx-newreg">
            <?php if (!$people): ?>
                <p class="ann-empty">No parishioners have registered with this church yet.</p>
            <?php else: ?>
                <div class="pfield">
                    <label for="nr-user">Parishioner</label>
                    <select id="nr-user" name="for" required>
                        <option value="">Select a parishioner&hellip;</option>
                        <?php foreach ($people as $p): ?><option value="<?= (int)$p['user_id'] ?>"><?= e($p['user_name']) ?> &middot; <?= e($p['user_email']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="pfield">
                    <label for="nr-type">Sacrament</label>
                    <select id="nr-type" name="type" required>
                        <?php foreach ($FORMS as $k => $s): ?><option value="<?= e($k) ?>"><?= e($s[0]) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="sx-actions"><a class="sx-draft" href="sacrament-review.php">Cancel</a><button type="submit" class="reg-submit">Continue</button></div>
            <?php endif; ?>
        </form>
    </main>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---- One application --------------------------------------------------------------------------------
if ($id) {
    $stmt = $pdo->prepare(
        "SELECT a.*, c.church_name, u.user_name, u.user_email, u.user_phone
           FROM sacrament_applications a
           JOIN church_info c ON c.church_info_id = a.church_id
           JOIN user u ON u.user_id = a.user_id
          WHERE a.application_id = ? AND a.status <> 'draft' AND a.sacrament <> 'confession'"
    );
    $stmt->execute([$id]);
    $app = $stmt->fetch();
    // Super Admin: any church. Church Admin: only their own church's applications.
    if (!$app || !can_manage_church($pdo, (int)$app['church_id'])) {
        header('Location: sacrament-review.php');
        exit;
    }
    $cfg = sx_config($app['sacrament']);

    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $new = (string)($_POST['status'] ?? '');
        $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);
        if ($isSuper) {
            $error = 'The Super Admin can view applications but not change them.';
        } elseif (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
            $error = 'Your session expired. Please try again.';
        } elseif (!in_array($new, $STATUSES, true)) {
            $error = 'Please choose a status.';
        } elseif ($new === 'rejected' && $note === '') {
            $error = 'Please give the applicant a reason before rejecting.';
        } else {
            $pdo->prepare('UPDATE sacrament_applications SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE application_id = ?')
                ->execute([$new, $note !== '' ? $note : null, (int)$_SESSION['user_id'], $id]);
            $mailNote = '';
            if ($new !== $app['status'] || ($note !== '' && $note !== (string)$app['admin_note'])) {   // tell the parishioner
                $mailNote = send_status_email($pdo, (int)$app['church_id'], $app['user_email'], $app['user_name'], SACRAMENTS[$app['sacrament']][0] . ' application', sx_ref($id), sx_status($new)[0], $note, 'sacrament-status.php')
                    ? ' ' . $app['user_name'] . ' has been emailed at ' . $app['user_email'] . '.'
                    : ' The email to ' . $app['user_name'] . ' could not be sent.';
            }
            $_SESSION['sacrament_flash'] = sx_ref($id) . ' is now "' . sx_status($new)[0] . '".' . $mailNote;
            header('Location: sacrament-review.php?id=' . $id);
            exit;
        }
    }

    $details = json_decode((string)$app['details'], true) ?: [];
    $files = json_decode((string)$app['files'], true) ?: [];
    [$stLabel, $stClass] = sx_status($app['status']);
    $done = sx_progress($app['status']);
    $rejected = $app['status'] === 'rejected';
    $steps = ['Application Submitted', 'Document Verification', 'Review', 'Approved'];

    $pageTitle = ($isSuper ? 'View' : 'Review') . ' Sacrament Application';
    $activeNav = 'services';
    include __DIR__ . '/includes/header.php';
    ?>
    <main class="sx-page rv-page-wrap">
        <?php back_button('sacrament-review.php', 'Back to sacraments'); ?>
        <header class="page-heading"><h1><?= $isSuper ? 'View' : 'Review' ?> Sacrament Application</h1></header>

        <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert info-alert" role="alert"><?= e($error) ?></div><?php endif; ?>

        <?php print_heading($cfg['title'] . ' Application ' . sx_ref($id), $app['church_name'], ['Status' => $stLabel]); ?>
        <div class="rv-ids">
            <span>Application ID: <b><?= e(sx_ref($id)) ?></b></span>
            <span>Sacrament: <b><?= e($cfg['title']) ?></b></span>
            <button type="button" class="sr-print no-print" onclick="window.print()" aria-label="Print this application" title="Print"><?= sx_icon('print', 18) ?></button>
        </div>

        <ol class="st-track is-compact" aria-label="Progress">
            <?php foreach ($steps as $i => $label):
                $n = $i + 1;
                $isDone = $rejected ? $n <= 3 : $n <= $done;
                $isNo = $rejected && $n === 4;
            ?>
                <li class="<?= $isNo ? 'is-no' : ($isDone ? 'is-done' : '') ?>"<?= $n === $done ? ' aria-current="step"' : '' ?>>
                    <span class="st-dot"><?= $isNo ? sx_icon('x', 22) : sx_icon('check', 22) ?></span>
                    <span class="st-label"><?= e($isNo ? 'Rejected' : $label) ?></span>
                </li>
            <?php endforeach; ?>
        </ol>

        <div class="rv-cards">
            <?php
            $n = 0;
            foreach ($cfg['sections'] as $s) {
                if (empty($s['fields'])) continue;
                $title = $s['title'] ?? ($n === 0 ? 'Details' : 'More details');
                $n++;
                echo '<section class="rv-card"><h2>' . e($title ?: 'Details') . '</h2><dl>';
                foreach ($s['fields'] as $fd) {
                    $v = sx_show($fd, (string)($details[$fd['k']] ?? ''));
                    echo '<dt>' . e($fd['l']) . ':</dt><dd>' . ($v !== '' ? nl2br(e($v)) : '<span class="muted">&mdash;</span>') . '</dd>';
                }
                echo '</dl></section>';
            }
            ?>
            <div class="rv-pair"><?php /* the applicant and their documents side by side */ ?>
                <section class="rv-card">
                    <h2>Applicant</h2>
                    <dl>
                        <dt>Name:</dt><dd><?= e($app['user_name']) ?></dd>
                        <dt>Email:</dt><dd><a href="mailto:<?= e($app['user_email']) ?>"><?= e($app['user_email']) ?></a></dd>
                        <dt>Phone:</dt><dd><?= e($app['user_phone']) ?: '&mdash;' ?></dd>
                        <dt>Church:</dt><dd><?= e($app['church_name']) ?></dd>
                        <dt>Submitted:</dt><dd><?= e(date('j F Y', strtotime($app['submitted_at'] ?: $app['created_at']))) ?></dd>
                    </dl>
                </section>
                <?php if ($cfg['docs']): ?>
                    <section class="rv-card rv-docs-card">
                        <h2>Supporting Documents</h2>
                        <div class="rv-docs">
                            <?php foreach ($cfg['docs'] as $key => $doc):
                                $has = !empty($files[$key]) && ($path = sx_doc_path($files[$key]));
                                $ext = $has ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';
                            ?>
                                <?php if ($has): ?>
                                    <a class="rv-doc" href="sacrament-file.php?id=<?= $id ?>&amp;doc=<?= e($key) ?>" target="_blank" rel="noopener" title="Open <?= e($doc[0]) ?>">
                                        <span class="rv-doc-ico <?= $ext === 'pdf' ? 'is-pdf' : 'is-img' ?>"><i><?= $ext === 'pdf' ? 'PDF' : 'IMG' ?></i></span>
                                        <small><?= e($doc[0]) ?></small>
                                    </a>
                                <?php else: ?>
                                    <span class="rv-doc is-missing"><span class="rv-doc-ico"><i>&mdash;</i></span><small><?= e($doc[0]) ?> (not provided)</small></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($app['admin_note'] && $isSuper): ?><p class="st-note rv-note-plain"><b>Note from the church:</b> <?= e($app['admin_note']) ?></p><?php endif; ?>

        <?php if ($isSuper): ?>
            <div class="rv-foot"><a class="rv-back" href="sacrament-review.php">back</a><span class="sx-badge <?= $stClass ?>"><?= e($stLabel) ?></span></div>
        <?php else: ?>
            <?php render_decision_panel([
                'action' => 'sacrament-review.php?id=' . $id, 'csrf' => $_SESSION['csrf'], 'id' => $id, 'back' => 'sacrament-review.php',
                'ref' => sx_ref($id), 'status' => $app['status'], 'badge' => [$stLabel, $stClass], 'note' => (string)$app['admin_note'],
                'steps' => ['submitted' => 'Submitted', 'verifying' => 'Document Verification', 'review' => 'Under Review'],
                'approve' => ['approved', 'Approve', 'Approved'], 'reject' => ['rejected', 'Reject', 'Rejected'], 'who' => 'applicant', 'kind' => 'application',
            ]); ?>
        <?php endif; ?>
    </main>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---- The list -----------------------------------------------------------------------------------
$base = ["a.status <> 'draft'", "a.sacrament <> 'confession'", 'a.church_id = ?'];
$baseParams = [$churchId];

$stmt = $pdo->prepare('SELECT a.status, COUNT(*) FROM sacrament_applications a WHERE ' . implode(' AND ', $base) . ' GROUP BY a.status');
$stmt->execute($baseParams);
$counts = array_fill_keys($STATUSES, 0);
foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$st, $n]) if (isset($counts[$st])) $counts[$st] = (int)$n;

$fStatus = in_array($_GET['status'] ?? '', $STATUSES, true) ? $_GET['status'] : '';
$fSac = isset($FORMS[$_GET['sacrament'] ?? '']) ? $_GET['sacrament'] : '';
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$where = $base;
$params = $baseParams;
if ($fStatus !== '') { $where[] = 'a.status = ?'; $params[] = $fStatus; }
if ($fSac !== '') { $where[] = 'a.sacrament = ?'; $params[] = $fSac; }
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $num = preg_match('/^(?:app-?)?0*(\d+)$/i', $q, $m) ? (int)$m[1] : 0;
    $where[] = '(u.user_name LIKE ? OR u.user_email LIKE ? OR a.details LIKE ? OR a.application_id = ?)';
    array_push($params, $like, $like, $like, $num);
}
$stmt = $pdo->prepare(
    'SELECT a.application_id, a.sacrament, a.status, a.submitted_at, a.created_at, a.details, u.user_name, u.user_email, u.user_phone
       FROM sacrament_applications a
       JOIN user u ON u.user_id = a.user_id
      WHERE ' . implode(' AND ', $where) . '
   ORDER BY (a.status = \'submitted\') DESC, a.submitted_at DESC, a.application_id DESC' . (($_GET['export'] ?? '') === 'csv' ? '' : ' LIMIT 300')
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ---- Generate Report: the list as filtered, as a CSV ----
if (($_GET['export'] ?? '') === 'csv') {
    send_report('sacrament-applications-' . $church['church_name'],
        ['App ID', 'Sacrament', 'Subject / Couple', 'Applicant', 'Email', 'Phone', 'Status', 'Submitted', 'Church'],
        array_map(fn($r) => [
            sx_ref((int)$r['application_id']), SACRAMENTS[$r['sacrament']][0] ?? $r['sacrament'],
            sx_subject($r['sacrament'], json_decode((string)$r['details'], true) ?: []), $r['user_name'], $r['user_email'], $r['user_phone'],
            sx_status($r['status'])[0], date('Y-m-d', strtotime($r['submitted_at'] ?: $r['created_at'])), $church['church_name'],
        ], $rows));
}

$tiles = ['submitted' => ['Submitted', 't-blue'], 'verifying' => ['Verification', 't-yellow'], 'review' => ['Under Review', 't-lilac'], 'approved' => ['Approved', 't-green'], 'rejected' => ['Rejected', 't-red']];

$pageTitle = 'Sacraments';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="sx-page sr-page">
    <?php back_button($dashboard, 'Back to dashboard'); ?>
    <header class="page-heading">
        <h1>Sacraments</h1>
        <?php if (!$isSuper): ?><p><?= e($church['church_name']) ?></p><?php endif; ?>
    </header>
    <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>

    <?php if ($isSuper): ?>
        <form method="get" class="sr-church">
            <label for="sr-church"><?= sx_icon('church', 14) ?> Select Church</label>
            <select id="sr-church" name="church" onchange="this.form.submit()">
                <?php foreach ($churches as $c): ?><option value="<?= (int)$c['church_info_id'] ?>" <?= (int)$c['church_info_id'] === $churchId ? 'selected' : '' ?>><?= e(review_option_label($pdo, 'sacraments', (int)$c['church_info_id'], $c['church_name'])) ?></option><?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn-save">Show</button></noscript>
        </form>
    <?php endif; ?>

    <div class="sr-tiles">
        <?php foreach ($tiles as $st => [$label, $cls]): ?>
            <a class="sr-tile <?= $cls ?>" href="sacrament-review.php?<?= e(http_build_query(array_filter(['church' => $isSuper ? $churchId : '', 'status' => $st]))) ?>"><b><?= $counts[$st] ?></b><span><?= e($label) ?></span></a>
        <?php endforeach; ?>
    </div>

    <form method="get" class="sr-filter" role="search">
        <?php if ($isSuper): ?><input type="hidden" name="church" value="<?= $churchId ?>"><?php endif; ?>
        <select name="sacrament" aria-label="Sacrament" onchange="this.form.submit()">
            <option value="">All Sacraments</option>
            <?php foreach ($FORMS as $k => $s): ?><option value="<?= e($k) ?>" <?= $fSac === $k ? 'selected' : '' ?>><?= e($s[0]) ?></option><?php endforeach; ?>
        </select>
        <select name="status" aria-label="Status" onchange="this.form.submit()">
            <option value="">All Statuses</option>
            <?php foreach ($tiles as $st => [$label]): ?><option value="<?= $st ?>" <?= $fStatus === $st ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <label class="sr-search"><?= sx_icon('search', 15) ?><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name or App ID..." aria-label="Search name or App ID"></label>
        <span class="sr-spacer"></span>
        <?php report_tools(report_csv_url(), 'Print the application list'); ?>
        <?php if (!$isSuper): ?>
            <a class="sr-new" href="sacrament-review.php?new=1"><?= sx_icon('plus', 15) ?> New Registration</a>
        <?php endif; ?>
    </form>

    <?php print_heading('Sacrament Applications', $church['church_name'], [
        'Sacrament' => $fSac !== '' ? $FORMS[$fSac][0] : '', 'Status' => $fStatus !== '' ? $tiles[$fStatus][0] : '', 'Search' => $q,
        'Applications' => (string)count($rows),
    ]); ?>

    <?php if (!$rows): ?>
        <p class="ann-empty"><?= ($fStatus || $fSac || $q) ? 'No applications match those filters.' : 'No applications yet.' ?></p>
    <?php else: ?>
        <div class="reg-table-wrap sr-table-wrap">
            <table class="reg-table sr-table">
                <thead><tr><th>App ID</th><th>Sacrament</th><th>Subject / Couple</th><th>Applicant</th><th>Status</th><th>Submitted</th><?php if (!$isSuper): ?><th>Actions</th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    [$stLabel, $stClass] = sx_status($r['status']);
                    $d = json_decode((string)$r['details'], true) ?: [];
                    $subject = sx_subject($r['sacrament'], $d);
                    $link = 'sacrament-review.php?id=' . (int)$r['application_id'];
                ?>
                    <tr<?= $r['status'] === 'submitted' ? ' class="needs-review"' : '' ?>>
                        <td data-label="App ID"><a class="sr-id" href="<?= $link ?>"><?= e(sx_ref((int)$r['application_id'])) ?></a></td>
                        <td data-label="Sacrament"><span class="sr-sac s-<?= e($r['sacrament']) ?>"><?= e(SACRAMENTS[$r['sacrament']][0] ?? $r['sacrament']) ?></span></td>
                        <td data-label="Subject / Couple"><b><?= e($subject) ?: '&mdash;' ?></b></td>
                        <td data-label="Applicant"><?= e($r['user_name']) ?><small><?= e($r['user_phone'] ?: $r['user_email']) ?></small></td>
                        <td data-label="Status"><span class="sx-badge <?= $stClass ?>"><?= e($stLabel) ?></span><?= $r['status'] === 'submitted' ? '<span class="needs-review-tag">Needs review</span>' : '' ?></td>
                        <td data-label="Submitted"><?= e(date('d M Y', strtotime($r['submitted_at'] ?: $r['created_at']))) ?></td>
                        <?php if (!$isSuper): ?>
                        <td data-label="Actions">
                            <div class="sr-actions">
                                <a class="sr-review" href="<?= $link ?>"><?= sx_icon('right', 12) ?> Review</a>
                                <form method="post" action="sacrament-review.php" class="inline-form">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$r['application_id'] ?>">
                                    <button type="submit" class="sr-del" data-confirm="Delete <?= e(sx_ref((int)$r['application_id'])) ?> and its documents? This cannot be undone." aria-label="Delete <?= e(sx_ref((int)$r['application_id'])) ?>"><?= sx_icon('trash', 14) ?></button>
                                </form>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
