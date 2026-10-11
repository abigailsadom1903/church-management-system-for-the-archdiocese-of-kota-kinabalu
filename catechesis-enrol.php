<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/login_gate.php';
require_signed_in('Catechesis Registration');   // visitors get "please login or register" instead of this page
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/sacrament_helpers.php';
require __DIR__ . '/includes/form_render.php';
require __DIR__ . '/includes/catechesis_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
// Registration is made from a parishioner's own account.
if (($_SESSION['role'] ?? '') !== 'user') {
    $_SESSION['catechesis_flash'] = 'Catechesis registration is made from a parishioner account.';
    header('Location: catechesis.php');
    exit;
}
$userId = (int)$_SESSION['user_id'];
$stmt = $pdo->prepare('SELECT church_id FROM user WHERE user_id = ?');
$stmt->execute([$userId]);
$churchId = (int)$stmt->fetchColumn();   // the home parish: never taken from the form

// The program must belong to the parishioner's own church.
$programId = (int)($_GET['program'] ?? $_POST['program'] ?? 0);
$stmt = $pdo->prepare('SELECT p.*, c.church_name FROM catechesis_programs p JOIN church_info c ON c.church_info_id = p.church_id WHERE p.program_id = ? AND p.church_id = ?');
$stmt->execute([$programId, $churchId]);
$program = $churchId ? $stmt->fetch() : false;
if (!$program) {
    header('Location: catechesis.php');
    exit;
}

// One enrolment per person per program: a draft is carried on, a sent one goes back to the page.
$stmt = $pdo->prepare('SELECT * FROM catechesis_enrolments WHERE program_id = ? AND user_id = ?');
$stmt->execute([$programId, $userId]);
$enr = $stmt->fetch() ?: null;
if ($enr && $enr['status'] !== 'draft') {
    $_SESSION['catechesis_flash'] = 'You are already registered for ' . $program['title'] . '.';
    header('Location: catechesis.php');
    exit;
}

$cfg = cat_config();
$fieldDefs = sx_fields($cfg);
$saved = $enr ? (json_decode((string)$enr['details'], true) ?: []) : [];
$files = $enr ? (json_decode((string)$enr['files'], true) ?: []) : [];
$f = [];
foreach ($fieldDefs as $k => $fd) $f[$k] = (string)($saved[$k] ?? '');
if (!$enr) {   // a helpful starting point from the account
    $u = $pdo->prepare('SELECT user_name, user_email, user_phone FROM user WHERE user_id = ?');
    $u->execute([$userId]);
    if ($row = $u->fetch()) { $f['full_name'] = (string)$row['user_name']; $f['email'] = (string)$row['user_email']; $f['phone'] = (string)($row['user_phone'] ?? ''); }
}
$errors = [];

// ---- Save draft / Submit --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = ($_POST['action'] ?? '') === 'submit' ? 'submit' : 'draft';
    $need = $action === 'submit';   // a draft may be incomplete; a submission may not
    $f = sx_collect($cfg, $_POST);

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        $errors = sx_validate($cfg, $f, $need);
        // A guardian is needed when the student is under 18.
        if ($need && $f['dob'] !== '' && !isset($errors['dob']) && cat_age($f['dob']) < 18) {
            foreach (['g_name' => 'Enter the guardian\'s name.', 'g_phone' => 'Enter the guardian\'s phone number.', 'g_relationship' => 'Enter the relationship to the student.'] as $k => $msg) {
                if ($f[$k] === '') $errors[$k] = $msg;
            }
        }

        // Documents: PDF, JPG or PNG, 5 MB at most; a new file replaces the one on record.
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

        // A draft with a problem is not saved; a submission with a problem IS kept as a draft so nothing typed or uploaded is lost.
        $hasFormatError = array_diff_key($errors, ['form' => 1]) && !$need;
        if (!isset($errors['form']) && !$hasFormatError) {
            $merged = $files;
            foreach ($newFiles as $key => $name) { sx_delete_doc($merged[$key] ?? null); $merged[$key] = $name; }
            $json = json_encode($f, JSON_UNESCAPED_UNICODE);
            $filesJson = json_encode($merged, JSON_UNESCAPED_UNICODE);
            $complete = $need && !array_diff_key($errors, ['form' => 1]);
            $status = $complete ? 'active' : 'draft';   // registering enrols the student straight away

            if ($enr) {
                $pdo->prepare('UPDATE catechesis_enrolments SET details = ?, files = ?, status = ?, joined_at = ? WHERE enrolment_id = ? AND user_id = ?')
                    ->execute([$json, $filesJson, $status, $complete ? date('Y-m-d H:i:s') : null, (int)$enr['enrolment_id'], $userId]);
            } else {
                $pdo->prepare('INSERT INTO catechesis_enrolments (program_id, user_id, church_id, status, details, files, joined_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$programId, $userId, $churchId, $status, $json, $filesJson, $complete ? date('Y-m-d H:i:s') : null]);
            }
            $files = $merged;

            if ($complete) {
                $_SESSION['catechesis_flash'] = 'You are registered for ' . $program['title'] . '. See you at the first lesson!';
                header('Location: catechesis.php');
                exit;
            }
            if ($action === 'draft') {
                $_SESSION['catechesis_flash'] = 'Draft saved. You can continue it any time from Available Programs.';
                header('Location: catechesis.php');
                exit;
            }
            // A failed submission: stay here, now bound to the saved draft.
            $stmt = $pdo->prepare('SELECT * FROM catechesis_enrolments WHERE program_id = ? AND user_id = ?');
            $stmt->execute([$programId, $userId]);
            $enr = $stmt->fetch() ?: null;
        } else {
            foreach ($newFiles as $name) sx_delete_doc($name);   // don't keep uploads from a save that was refused
        }
    }
}

$taken = [];
$pageTitle = 'Catechesis Registration';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="sx-page sx-form-page">
    <?php back_button('catechesis.php', 'Back to Catechesis'); ?>
    <header class="page-heading">
        <h1>Catechesis Registration and Enrolment Form</h1>
        <p>Registering for: <b><?= e($program['title']) ?></b> &middot; <?= e($program['church_name']) ?></p>
    </header>

    <div class="sx-shell">
        <form method="post" action="catechesis-enrol.php?program=<?= $programId ?>" enctype="multipart/form-data" class="sx-form" novalidate>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="program" value="<?= $programId ?>">
            <?php if (isset($errors['form'])): ?><div class="alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
            <?php if ($errors && !isset($errors['form'])): ?><div class="alert info-alert" role="alert">Your entries are saved as a draft. Please fix the highlighted items and submit again.</div><?php endif; ?>

            <?php foreach ($cfg['sections'] as $s): ?>
                <section class="sx-section">
                    <?php if (!empty($s['title'])): ?><h2><?= e($s['title']) ?></h2><?php endif; ?>
                    <?php if (!empty($s['note'])): ?><p class="sx-note"><?= e($s['note']) ?></p><?php endif; ?>
                    <?php if (!empty($s['fields'])): ?>
                        <div class="sx-grid"><?php foreach ($s['fields'] as $fd) sx_render_field($fd, $f, $errors, $taken); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($s['docs'])): ?>
                        <div class="sx-uploads"><?php foreach ($s['docs'] as $key) sx_render_doc($key, $cfg['docs'][$key], $errors, $files, $enr, 'enrolment_id', 'catechesis-file.php'); ?></div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
            <p class="sx-hint">PDF, JPG or PNG, up to 5 MB each. Your documents are private: only you and your church's administrators can open them.</p>

            <div class="sx-actions">
                <button type="submit" name="action" value="draft" class="sx-draft"><?= sx_icon('save', 18) ?> Save draft &amp; exit</button>
                <button type="submit" name="action" value="submit" class="reg-submit">Submit</button>
            </div>
        </form>
    </div>
</main>
<script>
document.querySelectorAll('.sx-tile input[type=file]').forEach(function (input) {
    input.addEventListener('change', function () {
        var label = input.closest('label'), text = label.querySelector('.sx-drop-text');
        if (input.files.length) { text.textContent = input.files[0].name; label.classList.add('has-file'); }
    });
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
