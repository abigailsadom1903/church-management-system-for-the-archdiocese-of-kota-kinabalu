<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/login_gate.php';
require_signed_in('Sacrament Application');   // visitors get "please login or register" instead of this page
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/sacrament_helpers.php';
require __DIR__ . '/includes/form_render.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$type = (string)($_GET['type'] ?? $_POST['type'] ?? 'baptism');
$cfg = isset(SACRAMENTS[$type]) ? sx_config($type) : null;
if (!$cfg) {
    header('Location: sacraments.php');
    exit;
}
if ($cfg['kind'] === 'booking') {   // Confession is booked from a calendar
    header('Location: sacrament-confession.php');
    exit;
}

// Applications are made from a parishioner's own account, or by their Church Admin on their behalf (New Registration).
$role = $_SESSION['role'] ?? '';
$forId = (int)($_GET['for'] ?? $_POST['for'] ?? 0);
$onBehalf = false;
if ($role === 'church_admin' && $forId) {
    $stmt = $pdo->prepare('SELECT user_id, church_id FROM user WHERE user_id = ?');
    $stmt->execute([$forId]);
    $target = $stmt->fetch();
    if (!$target || !can_manage_church($pdo, (int)$target['church_id'])) {   // only a parishioner of the admin's own church
        $_SESSION['sacrament_flash'] = 'That parishioner could not be found in your church.';
        header('Location: sacrament-review.php');
        exit;
    }
    $userId = $forId;
    $onBehalf = true;
} elseif ($role !== 'user') {
    $_SESSION['sacrament_flash'] = 'Sacrament applications are made from a parishioner account.';
    header('Location: sacraments.php');
    exit;
} else {
    $userId = (int)$_SESSION['user_id'];
}
$backTo = $onBehalf ? 'sacrament-review.php' : 'sacraments.php';
$forQ = $onBehalf ? '&for=' . $userId : '';
$stmt = $pdo->prepare('SELECT church_id FROM user WHERE user_id = ?');
$stmt->execute([$userId]);
$churchId = (int)$stmt->fetchColumn();   // the home parish: never taken from the form
if (!$churchId) {
    $_SESSION['sacrament_flash'] = 'Please choose your home parish on your profile before applying.';
    header('Location: profile.php');
    exit;
}
$stmt = $pdo->prepare('SELECT church_name, church_location, donation_bank_name, donation_account_name, donation_account_number FROM church_info WHERE church_info_id = ?');
$stmt->execute([$churchId]);
$church = $stmt->fetch();

// ---- Load the draft being continued (only the owner's own). Sent applications are read on the status page. ----
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$app = null;
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM sacrament_applications WHERE application_id = ? AND user_id = ? AND sacrament = ?');
    $stmt->execute([$id, $userId, $type]);
    $app = $stmt->fetch();
    if (!$app) {
        header('Location: sacraments.php');
        exit;
    }
    // A rejected application can be corrected and resubmitted, same as an unfinished draft; anything else
    // (submitted, verifying, review, approved, cancelled) is read on the status page instead.
    if (!in_array($app['status'], ['draft', 'rejected'], true)) {
        header('Location: ' . ($onBehalf ? 'sacrament-review.php?id=' : 'sacrament-status.php?id=') . $id);
        exit;
    }
}

// A Church Admin closes a sacrament's form with its switch (e.g. the intake is full), or sets the last day to apply.
// Nobody may start a fresh application while it's closed. Someone partway through a draft may still finish it
// after the switch is turned off, but not after the deadline has passed.
$intake = sx_intake($pdo, $churchId, $type);
if (!$app && !$intake['open']) {
    $_SESSION['sacrament_flash'] = $intake['why'] === 'deadline'
        ? 'Applications for ' . $cfg['title'] . ' at ' . $church['church_name'] . ' closed on ' . sx_day($intake['until']) . '. Please contact the parish office.'
        : 'Applications for ' . $cfg['title'] . ' are currently closed at ' . $church['church_name'] . '. Please check back later or contact the parish office.';
    header('Location: ' . $backTo);
    exit;
}

$fieldDefs = sx_fields($cfg);
$saved = $app ? (json_decode((string)$app['details'], true) ?: []) : [];
$files = $app ? (json_decode((string)$app['files'], true) ?: []) : [];
$f = [];
foreach ($fieldDefs as $k => $fd) {
    $f[$k] = (string)($saved[$k] ?? '');
    if (!empty($fd['geo'])) $f[$k . '_geo'] = (string)($saved[$k . '_geo'] ?? '');   // the detected spot of a place field
}
$errors = [];
$excludeId = $app ? (int)$app['application_id'] : 0;
$taken = sx_taken($pdo, $type, $churchId, $excludeId);   // dates / times other people already hold

// ---- Save draft / Submit ------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = ($_POST['action'] ?? '') === 'submit' ? 'submit' : 'draft';
    $need = $action === 'submit';   // a draft may be incomplete; a submission may not
    $f = sx_collect($cfg, $_POST);

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        $errors = sx_validate($cfg, $f, $need) + sx_conflicts($type, $f, $taken);

        // Documents: keep what is on file, replace it when a new one is chosen. PDF, JPG or PNG, 5 MB at most.
        $newFiles = [];
        foreach ($cfg['docs'] as $key => $doc) {
            $up = $_FILES['doc_' . $key] ?? null;
            if (!$up || $up['error'] === UPLOAD_ERR_NO_FILE) continue;
            if ($up['error'] !== UPLOAD_ERR_OK) { $errors['doc_' . $key] = 'That file could not be uploaded. Please try again.'; continue; }
            if ($up['size'] > SACRAMENT_DOC_MAX) { $errors['doc_' . $key] = 'The file must be 5 MB or smaller.'; continue; }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($up['tmp_name']);
            if (!isset(SACRAMENT_DOC_TYPES[$mime])) { $errors['doc_' . $key] = 'Please choose a PDF, JPG or PNG file.'; continue; }
            if (!is_dir(SACRAMENT_DOC_DIR)) mkdir(SACRAMENT_DOC_DIR, 0755, true);
            $name = bin2hex(random_bytes(12)) . '.' . SACRAMENT_DOC_TYPES[$mime];
            if (move_uploaded_file($up['tmp_name'], SACRAMENT_DOC_DIR . '/' . $name)) $newFiles[$key] = $name;
            else $errors['doc_' . $key] = 'The file could not be saved.';
        }
        if ($need) {
            foreach ($cfg['docs'] as $key => $doc) {
                if ($doc[2] && empty($files[$key]) && empty($newFiles[$key])) $errors['doc_' . $key] = 'Please upload this document.';
            }
        }

        if ($need && $intake['why'] === 'deadline') {
            $errors['form'] = 'Applications closed on ' . sx_day($intake['until']) . ', so this application can no longer be submitted. Your draft is kept; please contact the parish office.';
        }

        if (!$app) {
            $n = $pdo->prepare("SELECT COUNT(*) FROM sacrament_applications WHERE user_id = ? AND status = 'draft'");
            $n->execute([$userId]);
            if ((int)$n->fetchColumn() >= 10) $errors['form'] = 'You have 10 unfinished drafts. Please finish or submit one first.';
        }

        // A draft with a problem is not saved; a submission with a problem IS kept as a draft so nothing typed or uploaded is lost.
        $hasFormatError = array_diff_key($errors, ['form' => 1]) && !$need;
        if (!isset($errors['form']) && !$hasFormatError) {
            $lockName = 'sx_' . $churchId;
            $locked = false;
            if ($need && in_array($type, ['matrimony', 'anointing'], true)) {
                $g = $pdo->prepare('SELECT GET_LOCK(?, 5)');
                $g->execute([$lockName]);
                $locked = (bool)$g->fetchColumn();
                $taken = sx_taken($pdo, $type, $churchId, $excludeId);
                $errors += sx_conflicts($type, $f, $taken);
            }
            $merged = $files;
            foreach ($newFiles as $key => $name) { sx_delete_doc($merged[$key] ?? null); $merged[$key] = $name; }
            $json = json_encode($f, JSON_UNESCAPED_UNICODE);
            $filesJson = json_encode($merged, JSON_UNESCAPED_UNICODE);
            $complete = $need && !array_diff_key($errors, ['form' => 1]);

            if ($app) {
                // Editing a rejected application clears the old review (note, reviewer, timestamp) so it doesn't
                // linger next to what is, from here, a fresh submission the church has not looked at yet.
                $pdo->prepare('UPDATE sacrament_applications SET church_id = ?, details = ?, files = ?, status = ?, submitted_at = ?, admin_note = NULL, reviewed_by = NULL, reviewed_at = NULL WHERE application_id = ? AND user_id = ?')
                    ->execute([$churchId, $json, $filesJson, $complete ? 'submitted' : 'draft', $complete ? date('Y-m-d H:i:s') : null, $id, $userId]);
            } else {
                $pdo->prepare('INSERT INTO sacrament_applications (user_id, church_id, sacrament, status, details, files, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$userId, $churchId, $type, $complete ? 'submitted' : 'draft', $json, $filesJson, $complete ? date('Y-m-d H:i:s') : null]);
                $id = (int)$pdo->lastInsertId();
            }
            $files = $merged;
            if ($locked) $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);

            if ($complete) {
                $_SESSION['sacrament_flash'] = 'Your ' . strtolower($cfg['title']) . ' application has been submitted. ' . $church['church_name'] . ' will review it.';
                header('Location: ' . ($onBehalf ? 'sacrament-review.php?id=' : 'sacrament-status.php?id=') . $id);
                exit;
            }
            if ($action === 'draft') {
                $_SESSION['sacrament_flash'] = 'Draft saved. You can continue it any time from My Applications.';
                header('Location: ' . $backTo);
                exit;
            }
            // A failed submission: stay here, now bound to the saved draft.
            $stmt = $pdo->prepare('SELECT * FROM sacrament_applications WHERE application_id = ?');
            $stmt->execute([$id]);
            $app = $stmt->fetch();
        } else {
            foreach ($newFiles as $name) sx_delete_doc($name);   // don't keep uploads from a save that was refused
        }
    }
}

// The side card: the church's own notes for this sacrament.
$stmt = $pdo->prepare('SELECT * FROM sacrament_schedules WHERE church_id = ? AND sacrament = ?');
$stmt->execute([$churchId, $type]);
$sched = $stmt->fetch() ?: null;

$pageTitle = $cfg['title'] . ' Application';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="sx-page sx-form-page">
    <?php back_button($backTo, $onBehalf ? 'Back to sacraments' : 'Back to Sacraments'); ?>

    <header class="page-heading">
        <h1><?= e($cfg['title']) ?></h1>
        <?php if ($cfg['intro']): ?><p><?= e($cfg['intro']) ?></p><?php endif; ?>
    </header>

    <div class="sx-shell">
        <div class="sx-layout<?= $cfg['side'] ? ' has-side' : '' ?>">
            <form method="post" action="sacrament-apply.php?type=<?= e($type) ?><?= $app ? '&amp;id=' . (int)$app['application_id'] : '' ?><?= $onBehalf ? '&amp;for=' . $userId : '' ?>" enctype="multipart/form-data" class="sx-form" novalidate>
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="type" value="<?= e($type) ?>">
                <input type="hidden" name="id" value="<?= $app ? (int)$app['application_id'] : 0 ?>">
                <?php if ($onBehalf): ?><input type="hidden" name="for" value="<?= $userId ?>"><?php endif; ?>
                <?php if (isset($errors['form'])): ?><div class="alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
                <?php if ($errors && !isset($errors['form'])): ?><div class="alert info-alert" role="alert">Your entries are saved as a draft. Please fix the highlighted items and submit again.</div><?php endif; ?>
                <?php if ($intake['until'] && $intake['why'] !== 'deadline'): ?>
                    <p class="sx-deadline" role="note"><?= sx_icon('calendar', 16) ?><span>Applications open until <b><?= e(sx_day($intake['until'])) ?></b>. Please submit before the end of that day.</span></p>
                <?php elseif ($intake['why'] === 'deadline'): ?>
                    <p class="sx-deadline is-past" role="note"><?= sx_icon('calendar', 16) ?><span>Applications closed on <b><?= e(sx_day($intake['until'])) ?></b>. This draft can no longer be submitted.</span></p>
                <?php endif; ?>

                <?php foreach ($cfg['sections'] as $s): ?>
                    <section class="sx-section<?= !empty($s['small']) ? ' is-small' : '' ?>">
                        <?php if (!empty($s['title'])): ?><h2><?= e($s['title']) ?></h2><?php endif; ?>
                        <?php if (!empty($s['strong']) || !empty($s['note'])): ?>
                            <p class="sx-note"><?php if (!empty($s['strong'])): ?><strong><?= e($s['strong']) ?></strong> <?php endif; ?><?= e($s['note'] ?? '') ?></p>
                        <?php endif; ?>

                        <?php if (!empty($s['fields'])): ?>
                            <div class="sx-grid">
                                <?php foreach ($s['fields'] as $fd) sx_render_field($fd, $f, $errors, $taken); ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($s['bank'])): ?>
                            <div class="sx-bank">
                                <h3>Bank Details</h3>
                                <?php if ($church['donation_bank_name'] && $church['donation_account_number']): ?>
                                    <dl>
                                        <dt>Bank</dt><dd><?= e($church['donation_bank_name']) ?></dd>
                                        <dt>Account Name</dt><dd><?= e($church['donation_account_name']) ?></dd>
                                        <dt>Account Number</dt><dd class="sx-acct"><?= e($church['donation_account_number']) ?></dd>
                                    </dl>
                                <?php else: ?>
                                    <p>The parish office will give you the bank details.</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($s['docs'])): ?>
                            <div class="sx-uploads<?= !empty($s['bank']) ? ' with-bank' : '' ?>">
                                <?php foreach ($s['docs'] as $key) sx_render_doc($key, $cfg['docs'][$key], $errors, $files, $app); ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
                <?php if ($cfg['docs']): ?><p class="sx-hint">PDF, JPG or PNG, up to 5 MB each. Your documents are private: only you and your church's administrators can open them.</p><?php endif; ?>

                <div class="sx-actions">
                    <button type="submit" name="action" value="draft" class="sx-draft"><?= sx_icon('save', 18) ?> Save draft &amp; exit</button>
                    <button type="submit" name="action" value="submit" class="reg-submit">Submit</button>
                </div>
            </form>

            <?php if ($cfg['side'] === 'baptism'): ?>
                <aside class="sx-side" aria-label="About infant baptism">
                    <span class="sx-side-ico"><?= sx_icon('info', 26) ?></span>
                    <h2>Infant Baptism <small>(Infant &ndash; 7 years old)</small></h2>
                    <?php if ($sched && ($sched['line_date'] || $sched['line_brief'] || $sched['line_close'])): ?>
                        <ul>
                            <?php if ($sched['line_date']): ?><li><?= sx_icon('check', 14) ?><span><b>Date:</b> <?= e($sched['line_date']) ?></span></li><?php endif; ?>
                            <?php if ($sched['line_brief']): ?><li><?= sx_icon('check', 14) ?><span><b>Godparent briefing:</b> <?= e($sched['line_brief']) ?></span></li><?php endif; ?>
                            <?php if ($sched['line_close']): ?><li><?= sx_icon('check', 14) ?><span><b>Registration Deadline:</b> <?= e($sched['line_close']) ?></span></li><?php endif; ?>
                        </ul>
                    <?php else: ?>
                        <p class="sx-side-note">Your parish office will confirm the baptism date and the godparent briefing after your application is reviewed.</p>
                    <?php endif; ?>
                    <p class="sx-side-church"><?= sx_icon('church', 14) ?> <?= e($church['church_name']) ?></p>
                </aside>
            <?php elseif ($cfg['side'] === 'anointing'): ?>
                <aside class="sx-side is-plain" aria-label="Emergency and guidance">
                    <?php if ($sched && $sched['line_date']): ?>
                        <div class="sx-emergency">
                            <span class="sx-emergency-tag"><?= sx_icon('star', 12) ?> After-hours emergency</span>
                            <a class="sx-emergency-num" href="tel:<?= e(preg_replace('/[^+0-9]/', '', $sched['line_date'])) ?>"><?= e($sched['line_date']) ?></a>
                            <small>Reserved for Viaticum and imminent danger of death.</small>
                        </div>
                    <?php endif; ?>
                    <div class="sx-guidance">
                        <h2><?= sx_icon('info', 16) ?> Liturgical Guidance</h2>
                        <ul>
                            <li><?= sx_icon('check', 14) ?><span>The Anointing of the Sick is for all who are suffering from serious illness, not just those at the point of death.</span></li>
                            <li><?= sx_icon('check', 14) ?><span>Ideally, the sacrament should be celebrated while the patient is still conscious and can participate.</span></li>
                            <li><?= sx_icon('check', 14) ?><span>Prepare a small table with a white cloth, a crucifix, and a candle if circumstances allow.</span></li>
                        </ul>
                    </div>
                </aside>
            <?php elseif ($cfg['side'] === 'notes'): ?>
                <aside class="sx-side" aria-label="About this sacrament">
                    <span class="sx-side-ico"><?= sx_icon('info', 26) ?></span>
                    <h2><?= e($cfg['title']) ?></h2>
                    <?php if ($sched && ($sched['line_date'] || $sched['line_brief'] || $sched['line_close'])): ?>
                        <ul>
                            <?php if ($sched['line_date']): ?><li><?= sx_icon('check', 14) ?><span><b>Date:</b> <?= e($sched['line_date']) ?></span></li><?php endif; ?>
                            <?php if ($sched['line_brief']): ?><li><?= sx_icon('check', 14) ?><span><b>Note:</b> <?= e($sched['line_brief']) ?></span></li><?php endif; ?>
                            <?php if ($sched['line_close']): ?><li><?= sx_icon('check', 14) ?><span><b>Registration Deadline:</b> <?= e($sched['line_close']) ?></span></li><?php endif; ?>
                        </ul>
                    <?php else: ?>
                        <p class="sx-side-note">Your parish office will confirm the date and any further details after your application is reviewed.</p>
                    <?php endif; ?>
                    <p class="sx-side-church"><?= sx_icon('church', 14) ?> <?= e($church['church_name']) ?></p>
                </aside>
            <?php endif; ?>
        </div>
    </div>
</main>
<script>
document.querySelectorAll('.sx-drop input[type=file], .sx-tile input[type=file]').forEach(function (input) {
    input.addEventListener('change', function () {
        var label = input.closest('label'), text = label.querySelector('.sx-drop-text');
        if (input.files.length) { text.textContent = input.files[0].name; label.classList.add('has-file'); }
    });
});
</script>
<?php include __DIR__ . '/includes/date_picker.php'; ?>
<script src="assets/js/geo-field.js?v=<?= @filemtime(__DIR__ . '/assets/js/geo-field.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
