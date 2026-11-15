<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/login_gate.php';
require_signed_in('Pastoral & Spiritual Care');   // visitors get "please login or register" instead of this page
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/sacrament_helpers.php';
require __DIR__ . '/includes/form_render.php';
require __DIR__ . '/includes/pastoral_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$service = (string)($_GET['service'] ?? $_POST['service'] ?? '');
$cfg = pc_config($service);   // a standard service or one a church added; the church is checked below
if (!$cfg) {
    header('Location: pastoral-care.php');
    exit;
}
// Requests are made from a parishioner's own account.
if (($_SESSION['role'] ?? '') !== 'user') {
    $_SESSION['pastoral_flash'] = 'Pastoral requests are made from a parishioner account.';
    header('Location: pastoral-care.php');
    exit;
}
$userId = (int)$_SESSION['user_id'];
$stmt = $pdo->prepare('SELECT u.church_id, c.church_name, c.donation_bank_name, c.donation_account_name, c.donation_account_number FROM user u LEFT JOIN church_info c ON c.church_info_id = u.church_id WHERE u.user_id = ?');
$stmt->execute([$userId]);
$church = $stmt->fetch();
$churchId = (int)($church['church_id'] ?? 0);   // the home parish: never taken from the form
if (!$churchId) {
    $_SESSION['pastoral_flash'] = 'Please choose your home parish on your profile before sending a request.';
    header('Location: profile.php');
    exit;
}

// The service as this church offers it: its own name for it, and whether it is taking requests.
$svc = pc_service($pdo, $churchId, $service);
if (!$svc || $svc['deleted_at'] !== null) {
    $_SESSION['pastoral_flash'] = 'That service is not offered by ' . $church['church_name'] . '.';
    header('Location: pastoral-care.php');
    exit;
}
$cfg = pc_form($pdo, $service, $churchId);
$isOpen = (bool)(int)$svc['is_open'];
$closedMsg = $church['church_name'] . ' is not taking ' . $cfg['title'] . ' requests at the moment. Please contact the parish office.';
// Nobody may start a new request while the service is closed. Someone with a draft may still open and save it.
if (!$isOpen && !(int)($_GET['id'] ?? $_POST['id'] ?? 0)) {
    $_SESSION['pastoral_flash'] = $closedMsg;
    header('Location: pastoral-care.php');
    exit;
}

// ---- The draft being continued (only the owner's own). Sent requests are read on the status page. -------
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$req = null;
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM pastoral_requests WHERE request_id = ? AND user_id = ? AND service = ?');
    $stmt->execute([$id, $userId, $service]);
    $req = $stmt->fetch();
    if (!$req) {
        header('Location: pastoral-care.php');
        exit;
    }
    // A declined request can be corrected and resubmitted, same as an unfinished draft; anything else
    // (submitted, review, given) is read on the status page instead.
    if (!in_array($req['status'], ['draft', 'declined'], true)) {
        header('Location: pastoral-status.php?id=' . $id);
        exit;
    }
}

$fieldDefs = sx_fields($cfg);
$saved = $req ? (json_decode((string)$req['details'], true) ?: []) : [];
$files = $req ? (json_decode((string)$req['files'], true) ?: []) : [];
$f = [];
foreach ($fieldDefs as $k => $fd) {
    $f[$k] = (string)($saved[$k] ?? '');
    if (!empty($fd['geo'])) $f[$k . '_geo'] = (string)($saved[$k . '_geo'] ?? '');   // the detected spot of a place field
}
$errors = [];
$excludeId = $req ? (int)$req['request_id'] : 0;
$taken = pc_taken($pdo, $service, $churchId, $excludeId);   // counselling times other people already hold

// ---- Save draft / Submit --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = ($_POST['action'] ?? '') === 'submit' ? 'submit' : 'draft';
    $need = $action === 'submit';   // a draft may be incomplete; a submission may not
    $f = sx_collect($cfg, $_POST);

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['form'] = 'Your session expired. Please try again.';
    } elseif (!empty($_POST['website'])) {
        header('Location: pastoral-care.php');   // hidden field only bots fill in: pretend it worked
        exit;
    } else {
        $errors = sx_validate($cfg, $f, $need) + pc_conflicts($service, $f, $taken);

        // The receipt: PDF, JPG or PNG, 5 MB at most; a new one replaces the one on file.
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
                if ($doc[2] && empty($files[$key]) && empty($newFiles[$key])) $errors['doc_' . $key] = 'Please upload your receipt.';
            }
        }
        if ($need && !$isOpen) $errors['form'] = $closedMsg . ' Your draft is kept.';
        if (!$req) {
            $n = $pdo->prepare("SELECT COUNT(*) FROM pastoral_requests WHERE user_id = ? AND status = 'draft'");
            $n->execute([$userId]);
            if ((int)$n->fetchColumn() >= 10) $errors['form'] = 'You have 10 unfinished drafts. Please finish or submit one first.';
        }
        if ($need && !$errors) {
            $open = $pdo->prepare("SELECT COUNT(*) FROM pastoral_requests WHERE user_id = ? AND status IN ('submitted', 'review')");
            $open->execute([$userId]);
            if ((int)$open->fetchColumn() >= 10) $errors['form'] = 'You already have 10 requests waiting. Please wait for the church to respond before sending more.';
        }

        // A draft with a problem is not saved; a submission with a problem IS kept as a draft so nothing typed or uploaded is lost.
        $hasFormatError = array_diff_key($errors, ['form' => 1]) && !$need;
        if (!isset($errors['form']) && !$hasFormatError) {
            $lockName = 'pc_' . $churchId;
            $locked = false;
            if ($need && $service === 'counselling') {   // look again under a short lock so two people can't take the same time
                $g = $pdo->prepare('SELECT GET_LOCK(?, 5)');
                $g->execute([$lockName]);
                $locked = (bool)$g->fetchColumn();
                $taken = pc_taken($pdo, $service, $churchId, $excludeId);
                $errors += pc_conflicts($service, $f, $taken);
            }
            $merged = $files;
            foreach ($newFiles as $key => $name) { sx_delete_doc($merged[$key] ?? null); $merged[$key] = $name; }
            $json = json_encode($f, JSON_UNESCAPED_UNICODE);
            $filesJson = json_encode($merged, JSON_UNESCAPED_UNICODE);
            $complete = $need && !array_diff_key($errors, ['form' => 1]);

            if ($req) {
                // Editing a declined request clears the old review (note, reviewer, timestamp) so it doesn't
                // linger next to what is, from here, a fresh submission the church has not looked at yet.
                $pdo->prepare('UPDATE pastoral_requests SET church_id = ?, details = ?, files = ?, status = ?, submitted_at = ?, admin_note = NULL, reviewed_by = NULL, reviewed_at = NULL WHERE request_id = ? AND user_id = ?')
                    ->execute([$churchId, $json, $filesJson, $complete ? 'submitted' : 'draft', $complete ? date('Y-m-d H:i:s') : null, $id, $userId]);
            } else {
                $pdo->prepare('INSERT INTO pastoral_requests (user_id, church_id, service, status, details, files, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$userId, $churchId, $service, $complete ? 'submitted' : 'draft', $json, $filesJson, $complete ? date('Y-m-d H:i:s') : null]);
                $id = (int)$pdo->lastInsertId();
            }
            $files = $merged;
            if ($locked) $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);

            if ($complete) {
                $_SESSION['pastoral_flash'] = 'Your request (' . $cfg['title'] . ') has been sent to ' . $church['church_name'] . '.';
                header('Location: pastoral-status.php?id=' . $id);
                exit;
            }
            if ($action === 'draft') {
                $_SESSION['pastoral_flash'] = 'Draft saved. You can continue it any time from My Requests.';
                header('Location: pastoral-care.php');
                exit;
            }
            // A failed submission: stay here, now bound to the saved draft.
            $stmt = $pdo->prepare('SELECT * FROM pastoral_requests WHERE request_id = ?');
            $stmt->execute([$id]);
            $req = $stmt->fetch();
        } else {
            foreach ($newFiles as $name) sx_delete_doc($name);   // don't keep uploads from a save that was refused
        }
    }
}

$pageTitle = $cfg['title'] . ' Request';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="sx-page sx-form-page pc-form-page">
    <?php back_button('pastoral-care.php', 'Back to Pastoral & Spiritual Care'); ?>
    <header class="page-heading">
        <h1><?= e($cfg['title']) ?></h1>
        <?php if ($cfg['intro']): ?><p><?= e($cfg['intro']) ?></p><?php endif; ?>
    </header>

    <div class="sx-shell">
        <form method="post" action="pastoral-request.php?service=<?= e($service) ?><?= $req ? '&amp;id=' . (int)$req['request_id'] : '' ?>" enctype="multipart/form-data" class="sx-form<?= !empty($cfg['steps']) ? ' has-steps' : '' ?>" novalidate>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="service" value="<?= e($service) ?>">
            <input type="hidden" name="id" value="<?= $req ? (int)$req['request_id'] : 0 ?>">
            <input type="text" name="website" class="hp-field" tabindex="-1" autocomplete="off" aria-hidden="true">
            <?php if (isset($errors['form'])): ?><div class="alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
            <?php if ($errors && !isset($errors['form'])): ?><div class="alert info-alert" role="alert">Your entries are saved as a draft. Please fix the highlighted items and submit again.</div><?php endif; ?>

            <?php $step = 0; foreach ($cfg['sections'] as $s): ?>
                <section class="sx-section<?= !empty($s['small']) ? ' is-small' : '' ?>">
                    <?php if (!empty($s['title'])): $step++; ?>
                        <h2<?= !empty($cfg['steps']) ? ' class="sx-step-h"' : '' ?>><?php if (!empty($cfg['steps'])): ?><span class="sx-num"><?= $step ?></span><?php endif; ?><?= e($s['title']) ?></h2>
                    <?php endif; ?>
                    <?php if (!empty($s['note'])): ?>
                        <p class="sx-callout"><?= preg_replace('/(RM\d+(?:\.\d+)?)/', '<strong>$1</strong>', e($s['note'])) ?></p>
                    <?php endif; ?>

                    <?php if (!empty($s['fields'])): $beside = count($s['fields']) - 1;   // fields stacked beside a 'side' field ?>
                        <div class="sx-grid<?= array_filter(array_column($s['fields'], 'side')) && $beside > 1 ? ' has-side" style="--side-rows: ' . ($beside - 1) : '' ?>">
                            <?php foreach ($s['fields'] as $fd) sx_render_field($fd, $f, $errors, $taken); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($s['bank']) || !empty($s['docs'])): ?>
                        <div class="sx-pay">
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
                            <?php foreach ($s['docs'] ?? [] as $key) sx_render_doc($key, $cfg['docs'][$key], $errors, $files, $req, 'request_id', 'pastoral-file.php'); ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>

            <?php if ($cfg['docs']): ?><p class="sx-hint">PDF, JPG or PNG, up to 5 MB. Your receipt is private: only you and your church's administrators can open it.</p><?php endif; ?>

            <div class="sx-actions">
                <button type="submit" name="action" value="draft" class="sx-draft"><?= sx_icon('save', 18) ?> Save draft &amp; exit</button>
                <button type="submit" name="action" value="submit" class="reg-submit">Submit</button>
            </div>
        </form>
    </div>
</main>
<script>
document.querySelectorAll('.sx-drop input[type=file]').forEach(function (input) {
    input.addEventListener('change', function () {
        var label = input.closest('label'), text = label.querySelector('.sx-drop-text');
        if (input.files.length) { text.textContent = input.files[0].name; label.classList.add('has-file'); }
    });
});
</script>
<?php include __DIR__ . '/includes/date_picker.php'; ?>
<script src="assets/js/geo-field.js?v=<?= @filemtime(__DIR__ . '/assets/js/geo-field.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
