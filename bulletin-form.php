<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

const PDF_MAX_BYTES   = 15 * 1024 * 1024;
const COVER_MAX_BYTES = 3 * 1024 * 1024;

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/** Removes a stored bulletin PDF / cover image (only ever files inside uploads/bulletins/). */
function delete_bulletin_file(?string $file): void
{
    if ($file && preg_match('/^[\w.-]+$/', $file)) {
        $path = __DIR__ . '/uploads/bulletins/' . $file;
        if (is_file($path)) @unlink($path);
    }
}
function stored_file_url(?string $file): ?string
{
    return $file && preg_match('/^[\w.-]+$/', $file) && is_file(__DIR__ . '/uploads/bulletins/' . $file) ? 'uploads/bulletins/' . $file : null;
}

// Editing (?id=) or creating (?church=). The church always comes from the record when editing.
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$existing = null;
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM bulletins WHERE bulletin_id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        header('Location: announcements.php?tab=bulletins');
        exit;
    }
    if (!empty($existing['content'])) {   // made with the Bulletin Builder: edited there
        header('Location: bulletin-builder.php?id=' . $id);
        exit;
    }
    $churchId = (int)$existing['church_id'];
} else {
    $churchId = (int)($_GET['church'] ?? $_POST['church'] ?? 0);
}

// Super Admin: any church. Church Admin: only their own. Anyone else is sent back.
if (!can_manage_church($pdo, $churchId)) {
    header('Location: announcements.php' . ($churchId ? '?church=' . $churchId . '&tab=bulletins' : ''));
    exit;
}
$stmt = $pdo->prepare('SELECT church_name, church_location FROM church_info WHERE church_info_id = ?');
$stmt->execute([$churchId]);
$church = $stmt->fetch();

$from = ($_GET['from'] ?? $_POST['from'] ?? '') === 'dashboard' ? 'dashboard' : '';   // opened from an admin dashboard?
$backUrl = $from ? dashboard_path('bulletins', $churchId) : 'announcements.php?church=' . $churchId . '&tab=bulletins';

$errors = [];
$f = [
    'title' => $existing['title'] ?? '',
    'topic' => $existing['topic'] ?? '',
    'lang'  => $existing['language'] ?? 'en',
    'date'  => $existing['bulletin_date'] ?? date('Y-m-d'),
];

function valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d && $d >= '2000-01-01' && $d <= '2100-12-31';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f['title'] = trim($_POST['title'] ?? '');
    $f['topic'] = trim($_POST['topic'] ?? '');
    $f['lang']  = (string)($_POST['lang'] ?? '');
    $f['date']  = trim($_POST['date'] ?? '');
    $removeCover = !empty($_POST['remove_cover']);

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        if ($f['title'] === '' || mb_strlen($f['title']) > 150) $errors['title'] = 'Enter a title (up to 150 characters).';
        if (mb_strlen($f['topic']) > 100) $errors['topic'] = 'Keep the topic under 100 characters.';
        if (!valid_date($f['date'])) $errors['date'] = 'Enter a valid date.';
        if (!isset(BULLETIN_LANGUAGES[$f['lang']])) $errors['lang'] = 'Choose the language of this bulletin.';

        // PDF: required when creating, optional when editing (keeps the current file).
        $pdf = $_FILES['pdf'] ?? null;
        $hasPdf = $pdf && $pdf['error'] !== UPLOAD_ERR_NO_FILE;
        if ($hasPdf) {
            if ($pdf['error'] !== UPLOAD_ERR_OK) {
                $errors['pdf'] = 'The PDF could not be uploaded. Please try again.';
            } elseif ($pdf['size'] > PDF_MAX_BYTES) {
                $errors['pdf'] = 'The PDF must be 15 MB or smaller.';
            } else {
                $fh = fopen($pdf['tmp_name'], 'rb');
                $magic = $fh ? fread($fh, 5) : '';
                if ($fh) fclose($fh);
                if ($magic !== '%PDF-') $errors['pdf'] = 'Please choose a valid PDF file.';   // checks the content, not the file name
            }
        } elseif (!$existing) {
            $errors['pdf'] = 'Please choose the bulletin PDF.';
        }

        // Cover image: optional.
        $cover = $_FILES['cover'] ?? null;
        $hasCover = $cover && $cover['error'] !== UPLOAD_ERR_NO_FILE;
        $coverExt = null;
        if ($hasCover) {
            $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
            $info = $cover['error'] === UPLOAD_ERR_OK ? @getimagesize($cover['tmp_name']) : false;
            if ($cover['error'] !== UPLOAD_ERR_OK) {
                $errors['cover'] = 'The cover image could not be uploaded. Please try again.';
            } elseif ($cover['size'] > COVER_MAX_BYTES) {
                $errors['cover'] = 'The cover image must be 3 MB or smaller.';
            } elseif (!$info || !isset($types[$info[2]])) {
                $errors['cover'] = 'Please choose a JPG, PNG or WebP image.';
            } else {
                $coverExt = $types[$info[2]];
            }
        }

        if (!$errors) {
            $dir = __DIR__ . '/uploads/bulletins';
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            $newPdf = $newCover = null;
            $ok = true;
            if ($hasPdf) {
                $newPdf = 'bulletin-' . $churchId . '-' . bin2hex(random_bytes(6)) . '.pdf';
                $ok = move_uploaded_file($pdf['tmp_name'], $dir . '/' . $newPdf);
            }
            if ($ok && $hasCover) {
                $newCover = 'bulletin-cover-' . $churchId . '-' . bin2hex(random_bytes(6)) . '.' . $coverExt;
                $ok = move_uploaded_file($cover['tmp_name'], $dir . '/' . $newCover);
            }

            if (!$ok) {
                delete_bulletin_file($newPdf);
                delete_bulletin_file($newCover);
                $errors['form'] = 'The files could not be saved. Please try again.';
            } else {
                $pdfName   = $newPdf ?? ($existing['file_name'] ?? null);
                $coverName = $newCover ?? ($removeCover ? null : ($existing['cover_image'] ?? null));

                if ($existing) {
                    $pdo->prepare('UPDATE bulletins SET title = ?, topic = ?, language = ?, bulletin_date = ?, file_name = ?, cover_image = ? WHERE bulletin_id = ?')
                        ->execute([$f['title'], $f['topic'] ?: null, $f['lang'], $f['date'], $pdfName, $coverName, $id]);
                    // Only after the database is updated: drop the files that were replaced or removed.
                    if ($newPdf) delete_bulletin_file($existing['file_name']);
                    if ($existing['cover_image'] && $coverName !== $existing['cover_image']) delete_bulletin_file($existing['cover_image']);
                } else {
                    $pdo->prepare('INSERT INTO bulletins (church_id, title, topic, language, bulletin_date, file_name, cover_image, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$churchId, $f['title'], $f['topic'] ?: null, $f['lang'], $f['date'], $pdfName, $coverName, (int)$_SESSION['user_id']]);
                }
                $_SESSION['admin_flash'] = $existing ? 'Bulletin updated.' : 'Bulletin added.';
                header('Location: ' . $backUrl);
                exit;
            }
        }
    }
}

function fc(array $errors, string $key): string { return isset($errors[$key]) ? 'pfield has-error' : 'pfield'; }

$currentPdf   = $existing ? stored_file_url($existing['file_name']) : null;
$currentCover = $existing ? stored_file_url($existing['cover_image']) : null;

$pageTitle = $existing ? 'Edit Bulletin' : 'New Bulletin';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="form-page">
    <?php back_button($backUrl, $from ? 'Back to dashboard' : 'Back to bulletins'); ?>
    <header class="page-heading">
        <h1><?= $existing ? 'Edit Bulletin' : 'New Bulletin' ?></h1>
        <p><?= e($church['church_name']) ?> &middot; <?= e($church['church_location']) ?></p>
    </header>

    <?php if (isset($errors['form'])): ?><div class="alert info-alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
    <?php if ($errors && !isset($errors['form'])): ?><div class="alert info-alert" role="alert">Please check the highlighted fields.</div><?php endif; ?>

    <form method="post" action="bulletin-form.php" enctype="multipart/form-data" class="profile-card form-card" novalidate>
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <?php if ($from): ?><input type="hidden" name="from" value="dashboard"><?php endif; ?>
        <?php if ($existing): ?><input type="hidden" name="id" value="<?= $id ?>"><?php else: ?><input type="hidden" name="church" value="<?= $churchId ?>"><?php endif; ?>

        <div class="form-stack">
            <div class="<?= fc($errors, 'title') ?>">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" maxlength="150" placeholder="e.g. 4th Sunday of Easter" value="<?= e($f['title']) ?>" required autofocus>
                <?php if (isset($errors['title'])): ?><span class="row-error"><?= e($errors['title']) ?></span><?php endif; ?>
            </div>

            <div class="pf-row">
                <div class="<?= fc($errors, 'topic') ?>">
                    <label for="topic">Topic <span class="opt">(optional)</span></label>
                    <input type="text" id="topic" name="topic" maxlength="100" placeholder="e.g. Easter" value="<?= e($f['topic']) ?>">
                    <?php if (isset($errors['topic'])): ?><span class="row-error"><?= e($errors['topic']) ?></span><?php endif; ?>
                </div>
                <div class="<?= fc($errors, 'date') ?>">
                    <label for="date">Date</label>
                    <input type="date" id="date" name="date" value="<?= e($f['date']) ?>" required>
                    <?php if (isset($errors['date'])): ?><span class="row-error"><?= e($errors['date']) ?></span><?php endif; ?>
                </div>
            </div>

            <div class="<?= fc($errors, 'lang') ?>">
                <label for="lang">Language</label>
                <select id="lang" name="lang" required>
                    <?php foreach (BULLETIN_LANGUAGES as $code => $label): ?>
                        <option value="<?= e($code) ?>"<?= $f['lang'] === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['lang'])): ?><span class="row-error"><?= e($errors['lang']) ?></span><?php endif; ?>
            </div>

            <div class="<?= fc($errors, 'pdf') ?>">
                <label for="pdf">Bulletin PDF <?= $existing ? '<span class="opt">(leave empty to keep the current file)</span>' : '' ?></label>
                <input type="file" id="pdf" name="pdf" accept="application/pdf,.pdf">
                <?php if ($currentPdf): ?><span class="file-note">Current file: <a href="<?= e($currentPdf) ?>" target="_blank" rel="noopener">view PDF</a></span><?php endif; ?>
                <?php if (isset($errors['pdf'])): ?><span class="row-error"><?= e($errors['pdf']) ?></span><?php endif; ?>
                <span class="file-note">PDF, up to 15 MB.</span>
            </div>

            <div class="<?= fc($errors, 'cover') ?>">
                <label for="cover">Cover image <span class="opt">(optional)</span></label>
                <input type="file" id="cover" name="cover" accept="image/jpeg,image/png,image/webp" data-adjust="1:1">
                <?php if ($currentCover): ?>
                    <div class="cover-current">
                        <img src="<?= e($currentCover) ?>" alt="Current cover" width="72" height="72">
                        <label class="check-row"><input type="checkbox" name="remove_cover" value="1"><span>Remove the current cover</span></label>
                    </div>
                <?php endif; ?>
                <?php if (isset($errors['cover'])): ?><span class="row-error"><?= e($errors['cover']) ?></span><?php endif; ?>
                <span class="file-note">JPG, PNG or WebP, up to 3 MB. A square image looks best.</span>
            </div>
        </div>

        <div class="profile-actions">
            <a href="<?= e($backUrl) ?>" class="btn-cancel">Cancel</a>
            <button type="submit" class="btn-save">Save</button>
        </div>
    </form>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
