<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/sacrament_helpers.php';
require __DIR__ . '/includes/event_helpers.php';
require __DIR__ . '/includes/catechesis_helpers.php';
require __DIR__ . '/includes/report_tools.php';
require __DIR__ . '/includes/user_mail.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Church Admins manage the programs of their church; the Super Admin views every church (view only).
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
$flash = $_SESSION['catechesis_flash'] ?? null;
unset($_SESSION['catechesis_flash']);

$churches = $pdo->query('SELECT church_info_id, church_name, church_email FROM church_info ORDER BY church_name')->fetchAll();
$byId = array_column($churches, null, 'church_info_id');
$programId = (int)($_GET['program'] ?? $_POST['program'] ?? 0);

// ---- Which church? A program decides it (and is checked); otherwise the admin's own church / the Super Admin's choice. --------
// The Super Admin has no home parish, so with no ?church= given yet they start at "All churches" (a cross-parish
// statistics view) rather than pick_church_id()'s usual fallback (the first church) — that fallback is right for
// the single-church pages that share it, but would silently hide every other parish's programs here.
$program = null;
if ($programId) {
    $stmt = $pdo->prepare('SELECT * FROM catechesis_programs WHERE program_id = ?');
    $stmt->execute([$programId]);
    $program = $stmt->fetch();
    // Super Admin: any church. Church Admin: only their own church's programs.
    if (!$program || !can_manage_church($pdo, (int)$program['church_id'])) {
        header('Location: catechesis-manage.php');
        exit;
    }
    $churchId = (int)$program['church_id'];
} elseif (!$isSuper) {
    $churchId = pick_church_id($pdo, $byId, 0);   // a Church Admin's own church, never from the request
    if (!can_manage_church($pdo, $churchId)) {
        header('Location: ' . $dashboard);
        exit;
    }
} elseif (array_key_exists('church', $_GET) && (string)$_GET['church'] !== '0') {
    $churchId = pick_church_id($pdo, $byId, (int)$_GET['church']);
} else {
    $churchId = 0;   // "All churches": the Super Admin's cross-parish statistics view
}
$church = $churchId ? $byId[$churchId] : null;
$canEdit = !$isSuper;   // the Super Admin monitors; the Church Admin manages

function refuse_super(bool $isSuper): void
{
    if ($isSuper) {
        $_SESSION['catechesis_flash'] = 'The Super Admin can view catechesis but not change it.';
        header('Location: catechesis-manage.php');
        exit;
    }
}

// ---- POST: delete a program ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_program') {
    refuse_super($isSuper);
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '') || !$program) {
        $_SESSION['catechesis_flash'] = 'That program could not be deleted.';
    } else {
        // the students' documents go with their enrolments
        $stmt = $pdo->prepare('SELECT files FROM catechesis_enrolments WHERE program_id = ?');
        $stmt->execute([$programId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) foreach ((json_decode((string)$json, true) ?: []) as $file) sx_delete_doc($file);
        foreach (cat_lessons($pdo, $programId, true) as $l) cat_lesson_delete_file($l['file']);   // and the lesson handouts
        $pdo->prepare('DELETE FROM catechesis_programs WHERE program_id = ?')->execute([$programId]);
        cat_delete_photo($program['photo'] ?? null);
        $_SESSION['catechesis_flash'] = $program['title'] . ' was deleted.';
    }
    header('Location: catechesis-manage.php');
    exit;
}

// ---- One program: enrolments -----------------------------------------------------------------------------
if ($program) {
    $error = '';
    // update one enrolment (attendance +/-, or status)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['attendance', 'set_status'], true)) {
        refuse_super($isSuper);
        $stmt = $pdo->prepare("SELECT en.enrolment_id, en.attendance, en.status, u.user_name, u.user_email FROM catechesis_enrolments en JOIN user u ON u.user_id = en.user_id WHERE en.enrolment_id = ? AND en.program_id = ? AND en.status <> 'draft'");
        $stmt->execute([(int)($_POST['eid'] ?? 0), $programId]);
        $row = $stmt->fetch();   // the enrolment must belong to THIS program (and so to this church)
        if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '') || !$row) {
            $error = 'That enrolment could not be updated.';
        } elseif ($_POST['action'] === 'attendance') {
            // one lesson date for one student: present, absent, or take the mark off again
            $day = (string)($_POST['date'] ?? '');
            $mark = (string)($_POST['mark'] ?? '');
            $dt = DateTime::createFromFormat('Y-m-d', $day);
            if (!$dt || $dt->format('Y-m-d') !== $day || $day > date('Y-m-d') || $day < '2000-01-01') {
                $error = 'Choose the date of the lesson (today or earlier).';
            } elseif (!in_array($mark, ['present', 'absent', 'remove'], true)) {
                $error = 'Choose Present or Absent.';
            } else {
                $chk = $pdo->prepare('SELECT status FROM catechesis_attendance WHERE enrolment_id = ? AND session_date = ?');
                $chk->execute([(int)$row['enrolment_id'], $day]);
                if ($mark === 'present' && $chk->fetchColumn() !== 'present' && (int)$row['attendance'] >= (int)$program['total_sessions']) {
                    $error = 'This student has already attended all ' . (int)$program['total_sessions'] . ' lessons of the program.';
                } else {
                    cat_attendance_mark($pdo, (int)$row['enrolment_id'], $day, $mark, (int)$_SESSION['user_id']);
                }
            }
        } elseif (in_array($_POST['status'] ?? '', ['active', 'completed', 'dropped'], true)) {
            $pdo->prepare('UPDATE catechesis_enrolments SET status = ? WHERE enrolment_id = ?')->execute([$_POST['status'], (int)$row['enrolment_id']]);
            if ($_POST['status'] !== $row['status']) {   // tell the student
                $sent = send_status_email($pdo, (int)$program['church_id'], $row['user_email'], $row['user_name'],
                    $program['title'] . ($program['term_label'] ? ' ' . $program['term_label'] : '') . ' enrolment', 'ENR-' . str_pad((string)$row['enrolment_id'], 5, '0', STR_PAD_LEFT),
                    cat_status($_POST['status'])[0], null, 'catechesis.php');
                $_SESSION['catechesis_flash'] = $row['user_name'] . ' is now "' . cat_status($_POST['status'])[0] . '".'
                    . ($sent ? ' They have been emailed at ' . $row['user_email'] . '.' : ' The email to them could not be sent.');
            }
        }
        if (!$error) {
            header('Location: catechesis-manage.php?program=' . $programId . '#e' . (int)$row['enrolment_id']);
            exit;
        }
    }

    // Notes (Church Admin): a short standing update for enrolled parishioners (next lesson changes, venue moves, etc.)
    $notesError = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_notes') {
        refuse_super($isSuper);
        $notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 1000);
        if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
            $notesError = 'Your session expired. Please try again.';
        } else {
            $pdo->prepare('UPDATE catechesis_programs SET notes = ?, notes_updated_at = ? WHERE program_id = ?')
                ->execute([$notes !== '' ? $notes : null, $notes !== '' ? date('Y-m-d H:i:s') : null, $programId]);
            $_SESSION['catechesis_flash'] = $notes !== '' ? 'Note saved. Enrolled parishioners will see it on the program.' : 'Note cleared.';
            header('Location: catechesis-manage.php?program=' . $programId . '#notes');
            exit;
        }
    }

    // Broadcast (Church Admin): one email to every active student, sent by the site (each student separately,
    // so nobody sees the other addresses)
    $broadcast = !empty($_GET['broadcast']) && $canEdit;
    $bSubject = '';
    $bMessage = '';
    $bError = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'broadcast') {
        refuse_super($isSuper);
        $broadcast = true;
        $bSubject = mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 150);
        $bMessage = mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST['message'] ?? ''))), 0, 3000);
        if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) $bError = 'Your session expired. Please try again.';
        elseif ($bSubject === '' || $bMessage === '') $bError = 'Please write a subject and a message.';
        else {
            $stmt = $pdo->prepare("SELECT DISTINCT u.user_email FROM catechesis_enrolments en JOIN user u ON u.user_id = en.user_id WHERE en.program_id = ? AND en.status = 'active'");
            $stmt->execute([$programId]);
            $to = array_values(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN), fn($m) => filter_var($m, FILTER_VALIDATE_EMAIL)));
            $bName = $program['title'] . ($program['term_label'] ? ' ' . $program['term_label'] : '');
            $sent = 0;
            foreach ($to as $i => $address) {
                if (send_plain_email($address, $bSubject, $bMessage . "\n\n-- \n" . $bName . "\n" . $church['church_name'] . "\n", $church)) $sent++;
                elseif ($i === 0) break;   // the very first one failed: the mail account is not working, do not try the rest
            }
            if (!$to) $bError = 'There are no active students with an email address to send to.';
            elseif ($sent === 0) $bError = 'The message could not be sent just now. Please try again in a moment.';
            else {
                $_SESSION['catechesis_flash'] = 'Your message was emailed to ' . $sent . ($sent === 1 ? ' student.' : ' students.')
                    . ($sent < count($to) ? ' ' . (count($to) - $sent) . ' could not be reached.' : '');
                header('Location: catechesis-manage.php?program=' . $programId);
                exit;
            }
        }
    }

    // Take Attendance (Church Admin): the whole class for one lesson date, each active student present / absent / not marked
    $attend = !empty($_GET['attend']) && $canEdit;
    $attendDate = (string)($_GET['date'] ?? $_POST['date'] ?? date('Y-m-d'));
    $attendDt = DateTime::createFromFormat('Y-m-d', $attendDate);
    $attendDateOk = $attendDt && $attendDt->format('Y-m-d') === $attendDate && $attendDate <= date('Y-m-d') && $attendDate >= '2000-01-01';
    if (!$attendDateOk && $_SERVER['REQUEST_METHOD'] !== 'POST') $attendDate = date('Y-m-d');
    $attendError = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'class_attendance') {
        refuse_super($isSuper);
        $attend = true;
        if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) $attendError = 'Your session expired. Please try again.';
        elseif (!$attendDateOk) $attendError = 'Choose the date of the lesson (today or earlier).';
        else {
            // only this program's active students, never ids taken on trust from the form
            $stmt = $pdo->prepare("SELECT en.enrolment_id, en.attendance, a.status AS marked
                                     FROM catechesis_enrolments en
                                LEFT JOIN catechesis_attendance a ON a.enrolment_id = en.enrolment_id AND a.session_date = ?
                                    WHERE en.program_id = ? AND en.status = 'active'");
            $stmt->execute([$attendDate, $programId]);
            $sent = is_array($_POST['mark'] ?? null) ? $_POST['mark'] : [];
            $nPresent = $nAbsent = $nFull = 0;
            foreach ($stmt->fetchAll() as $row) {
                $eid = (int)$row['enrolment_id'];
                $mark = (string)($sent[$eid] ?? '');
                if ($mark === 'present' && $row['marked'] !== 'present' && (int)$row['attendance'] >= (int)$program['total_sessions']) { $nFull++; continue; }
                if ($mark === 'present' || $mark === 'absent') {
                    cat_attendance_mark($pdo, $eid, $attendDate, $mark, (int)$_SESSION['user_id']);
                    $mark === 'present' ? $nPresent++ : $nAbsent++;
                } elseif ($row['marked'] !== null) {
                    cat_attendance_mark($pdo, $eid, $attendDate, 'remove', (int)$_SESSION['user_id']);
                }
            }
            $_SESSION['catechesis_flash'] = 'Attendance saved for ' . date('j F Y', strtotime($attendDate)) . ': ' . $nPresent . ' present, ' . $nAbsent . ' absent.'
                . ($nFull ? ' ' . $nFull . ($nFull === 1 ? ' student has' : ' students have') . ' already attended every lesson and ' . ($nFull === 1 ? 'was' : 'were') . ' not marked.' : '');
            header('Location: catechesis-manage.php?program=' . $programId . '&attend=1&date=' . $attendDate . '#attend');
            exit;
        }
    }

    $stmt = $pdo->prepare(
        "SELECT en.*, u.user_name, u.user_email, u.user_phone
           FROM catechesis_enrolments en JOIN user u ON u.user_id = en.user_id
          WHERE en.program_id = ? AND en.status <> 'draft'
          ORDER BY FIELD(en.status, 'active', 'completed', 'dropped'), u.user_name"
    );
    $stmt->execute([$programId]);
    $enrolments = $stmt->fetchAll();
    $total = (int)$program['total_sessions'];
    $attRows = cat_attendance_rows($pdo, array_column($enrolments, 'enrolment_id'));   // the dated present / absent marks
    $lessons = cat_lessons($pdo, $programId);                                           // published lesson materials
    $lessonsDone = cat_lessons_done($pdo, array_column($enrolments, 'enrolment_id'));

    // Export List: a spreadsheet of the enrolments (both admins)
    if (!empty($_GET['export'])) {
        $safe = fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : (string)$v;   // no formulas from typed text
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="enrolments-' . $programId . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Name', 'Email', 'Phone', 'Status', 'Lessons attended', 'Total lessons', 'Progress %', 'Joined', 'Dates present', 'Dates absent']);
        foreach ($enrolments as $r) {
            $marks = $attRows[(int)$r['enrolment_id']] ?? [];
            $days = fn(string $st) => implode('; ', array_reverse(array_column(array_filter($marks, fn($m) => $m['status'] === $st), 'session_date')));
            fputcsv($out, [$safe($r['user_name']), $safe($r['user_email']), $safe($r['user_phone']), cat_status($r['status'])[0], $r['attendance'], $total, cat_progress((int)$r['attendance'], $total, $r['status']), $r['joined_at'] ? date('Y-m-d', strtotime($r['joined_at'])) : '', $days('present'), $days('absent')]);
        }
        fclose($out);
        exit;
    }

    $activeCount = 0;
    $progSum = 0;
    $progN = 0;
    foreach ($enrolments as $r) {
        if ($r['status'] === 'active') $activeCount++;
        if ($r['status'] !== 'dropped') { $progSum += cat_progress((int)$r['attendance'], $total, $r['status']); $progN++; }
    }
    $avg = $progN ? (int)round($progSum / $progN) : 0;

    $pageTitle = 'Program Overview';
    $activeNav = 'services';
    include __DIR__ . '/includes/header.php';
    ?>
    <main class="sx-page cat-page cat-overview">
        <?php back_button('catechesis-manage.php' . ($isSuper ? '?church=' . $churchId : ''), 'Back to programs'); ?>
        <div class="cat-ov-head">
            <header class="page-heading">
                <h1>Program Overview</h1>
                <p class="cat-ov-title"><?= e($program['title']) ?><?= $program['term_label'] ? ' ' . e($program['term_label']) : '' ?></p>
            </header>
            <div class="cat-ov-tools">
                <?php report_tools('catechesis-manage.php?program=' . $programId . '&export=1', 'Print the enrolment list'); ?>
                <a class="cat-tool" href="catechesis-lessons.php?program=<?= $programId ?>"><?= cat_icon('book', 15) ?> Lesson Materials (<?= count($lessons) ?>)</a>
                <?php if ($canEdit): ?><a class="cat-tool" href="catechesis-manage.php?program=<?= $programId ?>&amp;attend=1#attend"><?= cat_icon('check', 15) ?> Take Attendance</a> <a class="cat-tool" href="catechesis-manage.php?program=<?= $programId ?>&amp;broadcast=1#broadcast"><?= cat_icon('mail', 15) ?> Broadcast</a><?php endif; ?>
            </div>
        </div>

        <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert info-alert" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?php print_heading('Enrolments: ' . $program['title'] . ($program['term_label'] ? ' ' . $program['term_label'] : ''), $church['church_name'] ?? '', ['Students' => (string)count($enrolments)]); ?>

        <div class="cat-stats">
            <div class="cat-stat"><small>Enrolled</small><b class="c-purple"><?= count($enrolments) ?></b></div>
            <div class="cat-stat"><small>Avg. Progress</small><b><?= $avg ?>%</b></div>
            <div class="cat-stat"><small>Active</small><b class="c-gold"><?= $activeCount ?></b></div>
        </div>

        <?php if ($canEdit): ?>
            <section class="cat-notes" id="notes" aria-label="Program notes for parishioners">
                <h2>Program Notes</h2>
                <p class="cat-notes-hint">A short standing update shown to everyone enrolled &mdash; the next lesson's date, a venue change, anything they should know.</p>
                <?php if ($notesError): ?><div class="alert info-alert" role="alert"><?= e($notesError) ?></div><?php endif; ?>
                <form method="post" action="catechesis-manage.php?program=<?= $programId ?>">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="update_notes">
                    <input type="hidden" name="program" value="<?= $programId ?>">
                    <textarea name="notes" rows="3" maxlength="1000" placeholder="e.g. Next lesson moved to 4 October, same time and place."><?= e((string)$program['notes']) ?></textarea>
                    <div class="cat-notes-row">
                        <small><?= $program['notes_updated_at'] ? 'Last updated ' . e(date('j M Y, g:i a', strtotime($program['notes_updated_at']))) : 'Not set &mdash; nothing is shown to parishioners yet.' ?></small>
                        <button type="submit" class="cat-tool">Save Note</button>
                    </div>
                </form>
            </section>
        <?php elseif ($program['notes']): ?>
            <section class="cat-notes is-readonly" aria-label="Program notes for parishioners">
                <h2>Program Notes</h2>
                <p><?= nl2br(e($program['notes'])) ?></p>
                <?php if ($program['notes_updated_at']): ?><small>Last updated <?= e(date('j M Y, g:i a', strtotime($program['notes_updated_at']))) ?></small><?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($broadcast && $canEdit): $bcCount = count(array_unique(array_column(array_filter($enrolments, fn($r) => $r['status'] === 'active' && filter_var($r['user_email'], FILTER_VALIDATE_EMAIL)), 'user_email'))); ?>
            <form method="post" action="catechesis-manage.php?program=<?= $programId ?>#broadcast" class="cat-class cat-bc" id="broadcast">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="broadcast">
                <input type="hidden" name="program" value="<?= $programId ?>">
                <div class="cat-class-head">
                    <h2>Broadcast</h2>
                    <span class="cat-bc-to"><?= cat_icon('mail', 14) ?> Emails <?= $bcCount ?> active <?= $bcCount === 1 ? 'student' : 'students' ?></span>
                </div>
                <?php if ($bError): ?><div class="alert info-alert" role="alert"><?= e($bError) ?></div><?php endif; ?>
                <label for="bc-subject">Subject</label>
                <input type="text" id="bc-subject" name="subject" maxlength="150" value="<?= e($bSubject) ?>" placeholder="e.g. No class this Sunday" required>
                <label for="bc-message">Message</label>
                <textarea id="bc-message" name="message" rows="6" maxlength="3000" required><?= e($bMessage) ?></textarea>
                <p class="cat-bc-note">Each student gets their own email from <?= e($church['church_name']) ?>; they cannot see each other's addresses.</p>
                <div class="cat-bc-actions"><a href="catechesis-manage.php?program=<?= $programId ?>">Cancel</a><button type="submit" class="reg-submit"<?= $bcCount ? '' : ' disabled' ?>>Send Email</button></div>
            </form>
        <?php endif; ?>

        <?php if ($attend && $canEdit): $class = array_values(array_filter($enrolments, fn($r) => $r['status'] === 'active')); ?>
            <section class="cat-class" id="attend" aria-labelledby="attend-title">
                <div class="cat-class-head">
                    <h2 id="attend-title">Take Attendance</h2>
                    <form method="get" action="catechesis-manage.php#attend" class="cat-class-date">
                        <input type="hidden" name="program" value="<?= $programId ?>">
                        <input type="hidden" name="attend" value="1">
                        <label for="attend-date">Lesson date</label>
                        <input type="date" id="attend-date" name="date" value="<?= e($attendDate) ?>" max="<?= e(date('Y-m-d')) ?>" onchange="this.form.submit()" required>
                        <noscript><button type="submit" class="cat-tool">Show</button></noscript>
                    </form>
                </div>
                <?php if ($attendError): ?><div class="alert info-alert" role="alert"><?= e($attendError) ?></div><?php endif; ?>
                <?php if (!$class): ?>
                    <p class="cat-att-empty">There are no active students in this program.</p>
                <?php else: ?>
                    <form method="post" action="catechesis-manage.php?program=<?= $programId ?>&amp;attend=1" class="cat-class-form">
                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="class_attendance">
                        <input type="hidden" name="program" value="<?= $programId ?>">
                        <input type="hidden" name="date" value="<?= e($attendDate) ?>">
                        <div class="cat-class-bar">
                            <span><?= e(date('l, j F Y', strtotime($attendDate))) ?> &middot; <?= count($class) ?> <?= count($class) === 1 ? 'student' : 'students' ?></span>
                            <span class="cat-class-all"><button type="button" data-all="present">All present</button><button type="button" data-all="absent">All absent</button></span>
                        </div>
                        <ul class="cat-class-list">
                            <?php foreach ($class as $r):
                                $eid = (int)$r['enrolment_id'];
                                $cur = '';
                                foreach ($attRows[$eid] ?? [] as $m) if ($m['session_date'] === $attendDate) $cur = $m['status'];
                                if (is_array($_POST['mark'] ?? null) && isset($_POST['mark'][$eid])) $cur = (string)$_POST['mark'][$eid];
                            ?>
                                <li>
                                    <span class="cat-class-name"><?= e($r['user_name']) ?></span>
                                    <span class="cat-class-pick" role="radiogroup" aria-label="Attendance of <?= e($r['user_name']) ?>">
                                        <label class="is-present"><input type="radio" name="mark[<?= $eid ?>]" value="present" <?= $cur === 'present' ? 'checked' : '' ?>><span>Present</span></label>
                                        <label class="is-absent"><input type="radio" name="mark[<?= $eid ?>]" value="absent" <?= $cur === 'absent' ? 'checked' : '' ?>><span>Absent</span></label>
                                        <label class="is-none"><input type="radio" name="mark[<?= $eid ?>]" value="" <?= $cur === '' ? 'checked' : '' ?>><span>Not marked</span></label>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="cat-bc-actions"><a href="catechesis-manage.php?program=<?= $programId ?>">Close</a><button type="submit" class="reg-submit">Save Attendance</button></div>
                    </form>
                    <script>
                    document.querySelectorAll('.cat-class-all button').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            document.querySelectorAll('.cat-class-list input[value="' + btn.dataset.all + '"]').forEach(function (r) { r.checked = true; });
                        });
                    });
                    </script>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <h2 class="cat-heading is-left">Current Enrollments</h2>
        <?php if (!$enrolments): ?>
            <p class="ann-empty">Nobody has registered for this program yet.</p>
        <?php endif; ?>
        <div class="cat-enrols">
            <?php foreach ($enrolments as $r):
                $pct = cat_progress((int)$r['attendance'], $total, $r['status']);
                [$stLabel, $stClass] = cat_status($r['status']);
                $d = json_decode((string)$r['details'], true) ?: [];
                $files = json_decode((string)$r['files'], true) ?: [];
                $eid = (int)$r['enrolment_id'];
            ?>
                <article class="cat-enrol is-<?= e($r['status']) ?>" id="e<?= $eid ?>">
                    <div class="cat-enrol-who">
                        <h3><?= e($r['user_name']) ?> <span class="cat-status <?= $stClass ?>"><?= e($stLabel) ?></span></h3>
                        <p><?= cat_icon('mail', 13) ?> <?= e($r['user_email']) ?></p>
                        <p><?= cat_icon('calendar', 13) ?> Joined <?= e(date('M d, Y', strtotime($r['joined_at'] ?: $r['created_at']))) ?></p>
                        <?php if ($lessons): ?><p><?= cat_icon('book', 13) ?> <?= count(array_intersect_key($lessonsDone[$eid] ?? [], array_flip(array_column($lessons, 'lesson_id')))) ?> of <?= count($lessons) ?> lessons done</p><?php endif; ?>
                        <?php $links = []; foreach (cat_config()['docs'] as $key => $doc) if (!empty($files[$key]) && sx_doc_path($files[$key])) $links[] = '<a href="catechesis-file.php?id=' . $eid . '&amp;doc=' . e($key) . '" target="_blank" rel="noopener">' . e($doc[0]) . '</a>'; ?>
                        <?php if ($links): ?><p class="cat-enrol-docs">Documents: <?= implode(' &middot; ', $links) ?></p><?php endif; ?>
                    </div>
                    <div class="cat-enrol-progress">
                        <div class="cat-bar-label"><span>CURRICULUM PROGRESS</span><span><?= $pct ?>%</span></div>
                        <div class="cat-bar" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><i style="width: <?= $pct ?>%"></i></div>
                        <?php
                        $marks = $attRows[$eid] ?? [];
                        [$nPresent, $nAbsent] = cat_attendance_counts($marks);
                        ?>
                        <div class="cat-attend-row">
                            <span>ATTENDANCE</span>
                            <span class="cat-att-sum"><b class="is-present"><?= $nPresent + (int)$r['attendance_undated'] ?> present</b> &middot; <b class="is-absent"><?= $nAbsent ?> absent</b></span>
                        </div>
                        <?php if ($canEdit): ?>
                            <form method="post" action="catechesis-manage.php?program=<?= $programId ?>" class="cat-att-form">
                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                <input type="hidden" name="action" value="attendance">
                                <input type="hidden" name="program" value="<?= $programId ?>">
                                <input type="hidden" name="eid" value="<?= $eid ?>">
                                <input type="date" name="date" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" aria-label="Lesson date for <?= e($r['user_name']) ?>" required>
                                <button type="submit" name="mark" value="present" class="is-present"><?= cat_icon('check', 13) ?> Present</button>
                                <button type="submit" name="mark" value="absent" class="is-absent">Absent</button>
                            </form>
                        <?php endif; ?>
                        <?= cat_attendance_list($marks, (int)$r['attendance_undated'], !$canEdit ? null : fn(string $day) =>
                            '<form method="post" action="catechesis-manage.php?program=' . $programId . '">'
                            . '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '"><input type="hidden" name="action" value="attendance">'
                            . '<input type="hidden" name="program" value="' . $programId . '"><input type="hidden" name="eid" value="' . $eid . '">'
                            . '<input type="hidden" name="date" value="' . e($day) . '">'
                            . '<button type="submit" name="mark" value="remove" class="cat-att-del" aria-label="Remove the mark for ' . e(date('j M Y', strtotime($day))) . '" title="Remove">&times;</button></form>') ?>
                    </div>
                    <?php if ($canEdit): ?>
                        <form method="post" action="catechesis-manage.php?program=<?= $programId ?>" class="cat-status-buttons">
                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                            <input type="hidden" name="action" value="set_status">
                            <input type="hidden" name="program" value="<?= $programId ?>">
                            <input type="hidden" name="eid" value="<?= $eid ?>">
                            <?php foreach (['active' => 'ACTIVE', 'completed' => 'COMPLETED', 'dropped' => 'DROPPED'] as $st => $lab): ?>
                                <button type="submit" name="status" value="<?= $st ?>" class="<?= $r['status'] === $st ? 'is-on' : '' ?>"<?= $r['status'] === $st ? ' aria-pressed="true"' : '' ?>><?= $lab ?></button>
                            <?php endforeach; ?>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </main>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---- The list of programs, or (Super Admin, no church picked) a cross-parish statistics & report view -------
$isAll = $isSuper && $churchId === 0;
$programs = [];
$totalEnrol = 0;
$byChurchStats = [];   // Super Admin "All churches" only: church_id => [name, programs, enrolled, active, progSum, progN]
$catCounts = [];       // Super Admin "All churches" only: category => program count
$overallActive = 0;
$overallAvg = 0;

if ($isAll) {
    foreach ($churches as $c) {
        $byChurchStats[(int)$c['church_info_id']] = ['name' => $c['church_name'], 'programs' => 0, 'enrolled' => 0, 'active' => 0, 'progSum' => 0, 'progN' => 0];
    }
    $progRows = $pdo->query('SELECT program_id, church_id, category, total_sessions FROM catechesis_programs')->fetchAll();
    foreach ($progRows as $p) {
        $cid = (int)$p['church_id'];
        if (isset($byChurchStats[$cid])) $byChurchStats[$cid]['programs']++;
        $catCounts[$p['category']] = ($catCounts[$p['category']] ?? 0) + 1;
    }
    $progById = array_column($progRows, null, 'program_id');
    $enrolRows = $pdo->query("SELECT program_id, status, attendance FROM catechesis_enrolments WHERE status <> 'draft'")->fetchAll();
    foreach ($enrolRows as $en) {
        $p = $progById[$en['program_id']] ?? null;
        $cid = $p ? (int)$p['church_id'] : 0;
        if (!isset($byChurchStats[$cid])) continue;
        $byChurchStats[$cid]['enrolled']++;
        if ($en['status'] === 'active') { $byChurchStats[$cid]['active']++; $overallActive++; }
        if ($en['status'] !== 'dropped') { $byChurchStats[$cid]['progSum'] += cat_progress((int)$en['attendance'], (int)$p['total_sessions'], $en['status']); $byChurchStats[$cid]['progN']++; }
    }
    $totalPrograms = count($progRows);
    $totalEnrol = count($enrolRows);
    $progSum = array_sum(array_column($byChurchStats, 'progSum'));
    $progN = array_sum(array_column($byChurchStats, 'progN'));
    $overallAvg = $progN ? (int)round($progSum / $progN) : 0;
    arsort($catCounts);
} else {
    $stmt = $pdo->prepare(
        "SELECT p.*, (SELECT COUNT(*) FROM catechesis_enrolments en WHERE en.program_id = p.program_id AND en.status <> 'draft') AS enrolled
           FROM catechesis_programs p WHERE p.church_id = ? ORDER BY p.category, p.title"
    );
    $stmt->execute([$churchId]);
    $programs = $stmt->fetchAll();
    $totalEnrol = array_sum(array_column($programs, 'enrolled'));
}

// ---- Generate Report: every enrolment of this church (or of every church) ----
if (($_GET['export'] ?? '') === 'csv') {
    $stmt = $pdo->prepare(
        "SELECT c.church_name, p.title, p.term_label, p.category, p.total_sessions, en.status, en.attendance, en.joined_at, u.user_name, u.user_email, u.user_phone
           FROM catechesis_enrolments en
           JOIN catechesis_programs p ON p.program_id = en.program_id
           JOIN church_info c ON c.church_info_id = p.church_id
           JOIN user u ON u.user_id = en.user_id
          WHERE en.status <> 'draft'" . ($isAll ? '' : ' AND p.church_id = ?') . '
       ORDER BY c.church_name, p.category, p.title, u.user_name'
    );
    $stmt->execute($isAll ? [] : [$churchId]);
    send_report('catechesis-enrolments-' . ($isAll ? 'all-churches' : $church['church_name']),
        ['Church', 'Program', 'Term', 'Category', 'Student', 'Email', 'Phone', 'Status', 'Lessons attended', 'Total lessons', 'Progress %', 'Joined'],
        array_map(fn($r) => [
            $r['church_name'], $r['title'], $r['term_label'], $r['category'], $r['user_name'], $r['user_email'], $r['user_phone'], cat_status($r['status'])[0],
            $r['attendance'], $r['total_sessions'], cat_progress((int)$r['attendance'], (int)$r['total_sessions'], $r['status']), $r['joined_at'] ? date('Y-m-d', strtotime($r['joined_at'])) : '',
        ], $stmt->fetchAll()));
}

$pageTitle = 'Catechesis Management';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="sx-page cat-page">
    <?php back_button($dashboard, 'Back to dashboard'); ?>
    <header class="page-heading"><h1>Catechesis Management</h1><?php if (!$isSuper): ?><p><?= e($church['church_name']) ?></p><?php elseif ($isAll): ?><p>All churches &middot; statistics and reports</p><?php else: ?><p><?= e($church['church_name']) ?></p><?php endif; ?></header>
    <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>

    <?php if ($isSuper): ?>
        <form method="get" class="sr-church">
            <label for="cm-church">Select Church</label>
            <select id="cm-church" name="church" onchange="this.form.submit()">
                <option value="0" <?= $isAll ? 'selected' : '' ?>>All churches</option>
                <?php foreach ($churches as $c): ?><option value="<?= (int)$c['church_info_id'] ?>" <?= (int)$c['church_info_id'] === $churchId ? 'selected' : '' ?>><?= e($c['church_name']) ?></option><?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn-save">Show</button></noscript>
        </form>
    <?php endif; ?>

    <div class="cat-newbar rpt-bar">
        <?php report_tools(report_csv_url(), 'Print this page'); ?>
        <?php if (!$isAll && $canEdit): ?><a class="sr-new" href="catechesis-program-form.php"><?= cat_icon('plus', 15) ?> New program</a><?php endif; ?>
    </div>
    <?php print_heading($isAll ? 'Catechesis: all churches' : 'Catechesis Programs', $isAll ? '' : $church['church_name']); ?>

    <?php if ($isAll): ?>
        <div class="cat-stats is-four">
            <div class="cat-stat"><b class="c-purple"><?= (int)$totalPrograms ?></b><small>Total Programs</small></div>
            <div class="cat-stat"><b class="c-purple"><?= (int)$totalEnrol ?></b><small>Total Enrollments</small></div>
            <div class="cat-stat"><b class="c-gold"><?= (int)$overallActive ?></b><small>Active Students</small></div>
            <div class="cat-stat"><b><?= $overallAvg ?>%</b><small>Avg. Progress</small></div>
        </div>

        <?php if ($catCounts): ?>
            <h2 class="cat-heading is-left">Programs by Category</h2>
            <ul class="cat-cat-bars">
                <?php foreach ($catCounts as $cat => $n): ?>
                    <li>
                        <span class="cat-cat-name"><?= e($cat) ?></span>
                        <span class="cat-cat-bar"><i style="width: <?= $totalPrograms ? round($n / $totalPrograms * 100) : 0 ?>%; background: <?= e(cat_gradient($cat)) ?>"></i></span>
                        <span class="cat-cat-n"><?= (int)$n ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h2 class="cat-heading is-left">By Parish</h2>
        <?php if (!$totalPrograms): ?>
            <p class="ann-empty">No catechesis programs have been created yet.</p>
        <?php else: ?>
            <div class="reg-table-wrap">
                <table class="reg-table cat-report-table">
                    <thead><tr><th>Church</th><th>Programs</th><th>Enrolled</th><th>Active</th><th>Avg. Progress</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($byChurchStats as $cid => $s):
                        $avg = $s['progN'] ? (int)round($s['progSum'] / $s['progN']) : 0;
                    ?>
                        <tr>
                            <td data-label="Church"><b><?= e($s['name']) ?></b></td>
                            <td data-label="Programs"><?= (int)$s['programs'] ?></td>
                            <td data-label="Enrolled"><?= (int)$s['enrolled'] ?></td>
                            <td data-label="Active"><?= (int)$s['active'] ?></td>
                            <td data-label="Avg. Progress"><?= $s['programs'] ? $avg . '%' : '&mdash;' ?></td>
                            <td data-label="Action"><a class="sx-link" href="catechesis-manage.php?church=<?= $cid ?>">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php else: ?>
    <div class="cat-stats is-two">
        <div class="cat-stat"><b class="c-purple"><?= count($programs) ?></b><small>Total Programs</small></div>
        <div class="cat-stat"><b class="c-purple"><?= (int)$totalEnrol ?></b><small>Total Enrollments</small></div>
    </div>


    <?php if (!$programs): ?>
        <p class="ann-empty">No catechesis programs yet.<?= $canEdit ? ' Use <b>New program</b> to add one.' : '' ?></p>
    <?php else: ?>
        <div class="cat-grid">
            <?php foreach ($programs as $p): ?>
                <article class="cat-card">
                    <div class="cat-card-img<?= cat_photo_url($p['photo'] ?? null) ? ' has-photo' : '' ?>" style="<?= e(cat_cover_style($p)) ?>"><span><?= e($p['category']) ?></span></div>
                    <div class="cat-card-body">
                        <?php if ($canEdit): ?>
                            <div class="cat-card-tools">
                                <a class="cat-icon-btn" href="catechesis-program-form.php?id=<?= (int)$p['program_id'] ?>" aria-label="Edit <?= e($p['title']) ?>"><?= cat_icon('pencil', 14) ?></a>
                                <form method="post" action="catechesis-manage.php" class="inline-form">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="action" value="delete_program">
                                    <input type="hidden" name="program" value="<?= (int)$p['program_id'] ?>">
                                    <button type="submit" class="cat-icon-btn is-del" data-confirm="Delete <?= e($p['title']) ?> and all its enrolments? This cannot be undone." aria-label="Delete <?= e($p['title']) ?>"><?= cat_icon('trash', 14) ?></button>
                                </form>
                            </div>
                        <?php endif; ?>
                        <h3><?= e($p['title']) ?></h3>
                        <p class="cat-desc"><?= e($p['description']) ?></p>
                        <ul class="cat-meta">
                            <li><?= cat_icon('calendar', 15) ?> <span><?= e(cat_schedule_text($p)) ?></span></li>
                            <?php if ($p['location']): ?><li><?= cat_icon('pin', 15) ?> <span><?= e($p['location']) ?></span></li><?php endif; ?>
                            <li><?= cat_icon('users', 15) ?> <span><?= (int)$p['enrolled'] ?> enrolled</span></li>
                        </ul>
                        <div class="cat-card-btns">
                            <a class="cat-btn" href="catechesis-manage.php?program=<?= (int)$p['program_id'] ?>">View Enrolments</a>
                            <a class="cat-btn is-alt" href="catechesis-lessons.php?program=<?= (int)$p['program_id'] ?>"><?= cat_icon('book', 15) ?> Lessons</a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
