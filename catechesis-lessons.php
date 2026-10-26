<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/sacrament_helpers.php';
require __DIR__ . '/includes/catechesis_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Lesson materials of one program. The Church Admin adds and changes them; the Super Admin views them (view only).
$role = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($role, ['church_admin', 'super_admin'], true)) {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$isSuper = $role === 'super_admin';
$canEdit = !$isSuper;

$programId = (int)($_GET['program'] ?? $_POST['program'] ?? 0);
$stmt = $pdo->prepare('SELECT p.*, c.church_name FROM catechesis_programs p JOIN church_info c ON c.church_info_id = p.church_id WHERE p.program_id = ?');
$stmt->execute([$programId]);
$program = $stmt->fetch();
if (!$program || !can_manage_church($pdo, (int)$program['church_id'])) {   // a Church Admin: only their own church's programs
    header('Location: catechesis-manage.php');
    exit;
}
$self = 'catechesis-lessons.php?program=' . $programId;
$progName = $program['title'] . ($program['term_label'] ? ' ' . $program['term_label'] : '');

$flash = $_SESSION['catechesis_flash'] ?? null;
unset($_SESSION['catechesis_flash']);

/** One lesson of THIS program (never a lesson id taken on trust from another program). */
function find_lesson(PDO $pdo, int $programId, int $lessonId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM catechesis_lessons WHERE lesson_id = ? AND program_id = ?');
    $stmt->execute([$lessonId, $programId]);
    return $stmt->fetch() ?: null;
}

$editId = $_GET['edit'] ?? $_POST['lesson'] ?? null;          // 'new', or the id of the lesson being edited
$editing = null;
if ($editId !== null && $editId !== 'new') {
    $editing = find_lesson($pdo, $programId, (int)$editId);
    if (!$editing) $editId = null;
}
$f = [
    'title' => $editing['title'] ?? '', 'summary' => $editing['summary'] ?? '', 'body' => $editing['body'] ?? '',
    'video_url' => $editing['video_url'] ?? '', 'is_published' => $editing ? (string)$editing['is_published'] : '1',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($isSuper) {
        $_SESSION['catechesis_flash'] = 'The Super Admin can view lessons but not change them.';
        header('Location: ' . $self);
        exit;
    }
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['form'] = 'Your session expired. Please try again.';
    } elseif ($action === 'save_lesson') {
        foreach (['title' => 150, 'summary' => 300, 'video_url' => 300] as $k => $max) $f[$k] = mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
        $f['body'] = mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST['body'] ?? ''))), 0, 60000);
        $f['is_published'] = !empty($_POST['is_published']) ? '1' : '0';
        if ($f['title'] === '') $errors['title'] = 'Enter a title for the lesson.';
        if ($f['video_url'] !== '' && (!filter_var($f['video_url'], FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $f['video_url']))) {
            $errors['video_url'] = 'Enter a full web address starting with https://';
        }
        [$ext, $fileError] = cat_lesson_check_upload($_FILES['file'] ?? null);
        if ($fileError) $errors['file'] = $fileError;
        if ($f['body'] === '' && $f['video_url'] === '' && !$ext && !($editing && cat_lesson_path($editing['file']) && empty($_POST['remove_file']))) {
            $errors['body'] = 'Add some lesson text, a video link or a handout.';
        }

        if (!$errors) {
            $vals = [$f['title'], $f['summary'] ?: null, $f['body'] ?: null, $f['video_url'] ?: null, (int)$f['is_published']];
            if ($editing) {
                $pdo->prepare('UPDATE catechesis_lessons SET title = ?, summary = ?, body = ?, video_url = ?, is_published = ? WHERE lesson_id = ? AND program_id = ?')
                    ->execute([...$vals, (int)$editing['lesson_id'], $programId]);
                $lessonId = (int)$editing['lesson_id'];
            } else {
                $pos = $pdo->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM catechesis_lessons WHERE program_id = ?');
                $pos->execute([$programId]);
                $pdo->prepare('INSERT INTO catechesis_lessons (title, summary, body, video_url, is_published, program_id, position) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([...$vals, $programId, (int)$pos->fetchColumn()]);
                $lessonId = (int)$pdo->lastInsertId();
            }
            $note = '';
            $oldFile = $editing['file'] ?? null;
            if ($ext) {
                $stored = cat_lesson_store_upload($_FILES['file'], $ext);
                if ($stored) {
                    $original = mb_substr(preg_replace('/[^\w .()-]+/u', '_', basename((string)$_FILES['file']['name'])), 0, 150);
                    $pdo->prepare('UPDATE catechesis_lessons SET file = ?, file_name = ? WHERE lesson_id = ?')->execute([$stored, $original, $lessonId]);
                    cat_lesson_delete_file($oldFile);
                } else {
                    $note = ' The handout could not be saved.';
                }
            } elseif ($oldFile && !empty($_POST['remove_file'])) {
                $pdo->prepare('UPDATE catechesis_lessons SET file = NULL, file_name = NULL WHERE lesson_id = ?')->execute([$lessonId]);
                cat_lesson_delete_file($oldFile);
            }
            $_SESSION['catechesis_flash'] = '"' . $f['title'] . '" ' . ($editing ? 'was saved.' : 'was added.')
                . ($f['is_published'] === '1' ? '' : ' It is a draft, so students cannot see it yet.') . $note;
            header('Location: ' . $self . '#l' . $lessonId);
            exit;
        }
    } else {
        // the one-click actions on a lesson in the list
        $lesson = find_lesson($pdo, $programId, (int)($_POST['lid'] ?? 0));
        if (!$lesson) {
            $_SESSION['catechesis_flash'] = 'That lesson could not be found.';
        } elseif ($action === 'delete_lesson') {
            $pdo->prepare('DELETE FROM catechesis_lessons WHERE lesson_id = ?')->execute([(int)$lesson['lesson_id']]);
            cat_lesson_delete_file($lesson['file']);
            cat_lessons_renumber($pdo, $programId);
            $_SESSION['catechesis_flash'] = '"' . $lesson['title'] . '" was deleted.';
        } elseif ($action === 'publish') {
            $on = $lesson['is_published'] ? 0 : 1;
            $pdo->prepare('UPDATE catechesis_lessons SET is_published = ? WHERE lesson_id = ?')->execute([$on, (int)$lesson['lesson_id']]);
            $_SESSION['catechesis_flash'] = '"' . $lesson['title'] . '" ' . ($on ? 'is now visible to enrolled students.' : 'is hidden from students (draft).');
        } elseif ($action === 'move') {
            cat_lessons_renumber($pdo, $programId);   // make sure the numbers are 1..n before swapping two of them
            $lesson = find_lesson($pdo, $programId, (int)$lesson['lesson_id']);
            $up = ($_POST['dir'] ?? '') === 'up';
            $stmt = $pdo->prepare('SELECT lesson_id, position FROM catechesis_lessons WHERE program_id = ? AND position ' . ($up ? '<' : '>') . ' ? ORDER BY position ' . ($up ? 'DESC' : 'ASC') . ' LIMIT 1');
            $stmt->execute([$programId, (int)$lesson['position']]);
            if ($other = $stmt->fetch()) {
                $upd = $pdo->prepare('UPDATE catechesis_lessons SET position = ?, updated_at = updated_at WHERE lesson_id = ?');
                $upd->execute([(int)$other['position'], (int)$lesson['lesson_id']]);
                $upd->execute([(int)$lesson['position'], (int)$other['lesson_id']]);
            }
        }
        header('Location: ' . $self . (isset($lesson['lesson_id']) && $action !== 'delete_lesson' ? '#l' . (int)$lesson['lesson_id'] : ''));
        exit;
    }
}

$lessons = cat_lessons($pdo, $programId, true);
$published = count(array_filter($lessons, fn($l) => $l['is_published']));

// how many of the program's students (active or completed) have done each lesson
$stmt = $pdo->prepare("SELECT enrolment_id FROM catechesis_enrolments WHERE program_id = ? AND status IN ('active', 'completed')");
$stmt->execute([$programId]);
$studentIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
$done = cat_lessons_done($pdo, $studentIds);
$doneBy = [];
foreach ($done as $lessonIds) foreach ($lessonIds as $lid => $_) $doneBy[$lid] = ($doneBy[$lid] ?? 0) + 1;
$nStudents = count($studentIds);
$pubIds = array_column(array_filter($lessons, fn($l) => $l['is_published']), 'lesson_id');
$avgDone = 0;
if ($nStudents && $pubIds) {
    $sum = 0;
    foreach ($studentIds as $eid) $sum += count(array_intersect_key($done[(int)$eid] ?? [], array_flip($pubIds))) / count($pubIds);
    $avgDone = (int)round($sum / $nStudents * 100);
}

function fc3(array $errors, string $k): string { return isset($errors[$k]) ? 'pfield has-error' : 'pfield'; }
function fe3(array $errors, string $k): string { return isset($errors[$k]) ? '<span class="row-error">' . e($errors[$k]) . '</span>' : ''; }

$pageTitle = 'Lesson Materials';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="sx-page cat-page cat-lessons-page">
    <?php back_button('catechesis-manage.php?program=' . $programId, 'Back to program overview'); ?>
    <div class="cat-ov-head">
        <header class="page-heading">
            <h1>Lesson Materials</h1>
            <p class="cat-ov-title"><?= e($progName) ?></p>
        </header>
        <div class="cat-ov-tools">
            <a class="cat-tool" href="catechesis-learn.php?program=<?= $programId ?>"><?= cat_icon('eye', 15) ?> Preview as student</a>
            <?php if ($canEdit && $editId === null): ?><a class="cat-tool" href="<?= e($self) ?>&amp;edit=new#lesson-form"><?= cat_icon('plus', 15) ?> Add lesson</a><?php endif; ?>
        </div>
    </div>

    <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>
    <?php if (isset($errors['form'])): ?><div class="alert info-alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>

    <div class="cat-stats">
        <div class="cat-stat"><small>Lessons</small><b class="c-purple"><?= count($lessons) ?></b></div>
        <div class="cat-stat"><small>Published</small><b><?= $published ?></b></div>
        <div class="cat-stat"><small>Avg. Completed</small><b class="c-gold"><?= $avgDone ?>%</b></div>
    </div>

    <?php if ($canEdit && $editId !== null): $fileUrl = $editing && cat_lesson_path($editing['file']); ?>
        <form method="post" action="<?= e($self) ?>#lesson-form" class="cat-class cat-lesson-form" id="lesson-form" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="save_lesson">
            <input type="hidden" name="program" value="<?= $programId ?>">
            <input type="hidden" name="lesson" value="<?= $editing ? (int)$editing['lesson_id'] : 'new' ?>">
            <div class="cat-class-head"><h2><?= $editing ? 'Edit Lesson ' . (int)$editing['position'] : 'New Lesson' ?></h2></div>

            <div class="<?= fc3($errors, 'title') ?>"><label for="ls-title">Title</label><input type="text" id="ls-title" name="title" maxlength="150" value="<?= e($f['title']) ?>" placeholder="e.g. The Creed: I believe in God" required><?= fe3($errors, 'title') ?></div>
            <div class="pfield"><label for="ls-summary">Short summary <span class="opt">(optional, shown in the lesson list)</span></label><input type="text" id="ls-summary" name="summary" maxlength="300" value="<?= e($f['summary']) ?>"></div>
            <div class="<?= fc3($errors, 'body') ?>"><label for="ls-body">Lesson text</label>
                <textarea id="ls-body" name="body" rows="12" maxlength="60000" placeholder="Write or paste the lesson. Leave an empty line between paragraphs. Web addresses become links."><?= e($f['body']) ?></textarea><?= fe3($errors, 'body') ?></div>
            <div class="<?= fc3($errors, 'video_url') ?>"><label for="ls-video">Video link <span class="opt">(optional &middot; YouTube and Vimeo play inside the lesson)</span></label><input type="url" id="ls-video" name="video_url" maxlength="300" value="<?= e($f['video_url']) ?>" placeholder="https://www.youtube.com/watch?v=..."><?= fe3($errors, 'video_url') ?></div>
            <div class="<?= fc3($errors, 'file') ?>"><label for="ls-file">Handout <span class="opt">(optional)</span></label>
                <input type="file" id="ls-file" name="file" accept=".pdf,.docx,.pptx,.jpg,.jpeg,.png,.mp3">
                <span class="file-note">PDF, Word, PowerPoint, JPG, PNG or MP3, up to <?= CAT_LESSON_MAX_MB ?> MB. Only enrolled students can download it.</span>
                <?php if ($fileUrl): ?>
                    <span class="cat-lesson-cur"><?= cat_icon('file', 14) ?> <a href="catechesis-lesson-file.php?id=<?= (int)$editing['lesson_id'] ?>" target="_blank" rel="noopener"><?= e($editing['file_name'] ?: 'Current handout') ?></a> <small><?= e(cat_lesson_file_label($editing)) ?></small>
                        <label class="cat-photo-remove"><input type="checkbox" name="remove_file" value="1"> Remove</label></span>
                <?php endif; ?>
                <?= fe3($errors, 'file') ?></div>
            <label class="ss-toggle"><input type="checkbox" name="is_published" value="1" <?= $f['is_published'] === '1' ? 'checked' : '' ?>> Published &mdash; enrolled students can see this lesson</label>

            <div class="cat-bc-actions"><a href="<?= e($self) ?>">Cancel</a><button type="submit" class="reg-submit"><?= $editing ? 'Save lesson' : 'Add lesson' ?></button></div>
        </form>
    <?php endif; ?>

    <h2 class="cat-heading is-left">Lessons</h2>
    <?php if (!$lessons): ?>
        <p class="ann-empty">No lessons yet.<?= $canEdit ? ' Use <b>Add lesson</b> to write the first one &mdash; text, a video link, a handout, or all three.' : '' ?></p>
    <?php else: ?>
        <ol class="cat-lesson-list">
            <?php foreach ($lessons as $i => $l): $lid = (int)$l['lesson_id']; $fileLabel = cat_lesson_file_label($l); ?>
                <li class="cat-lesson-row<?= $l['is_published'] ? '' : ' is-draft' ?><?= $editing && (int)$editing['lesson_id'] === $lid ? ' is-editing' : '' ?>" id="l<?= $lid ?>">
                    <span class="cat-lesson-no"><?= $i + 1 ?></span>
                    <div class="cat-lesson-info">
                        <h3><?= e($l['title']) ?><?php if (!$l['is_published']): ?> <span class="cat-status">Draft</span><?php endif; ?></h3>
                        <?php if ($l['summary']): ?><p><?= e($l['summary']) ?></p><?php endif; ?>
                        <ul class="cat-lesson-tags">
                            <?php if (trim((string)$l['body']) !== ''): ?><li><?= cat_icon('book', 13) ?> Reading</li><?php endif; ?>
                            <?php if ($l['video_url']): ?><li><?= cat_icon('play', 13) ?> Video</li><?php endif; ?>
                            <?php if ($fileLabel): ?><li><?= cat_icon('file', 13) ?> <?= e($fileLabel) ?></li><?php endif; ?>
                            <?php if ($l['is_published']): ?><li class="is-done"><?= cat_icon('check', 13) ?> <?= (int)($doneBy[$lid] ?? 0) ?> of <?= $nStudents ?> <?= $nStudents === 1 ? 'student' : 'students' ?> done</li><?php endif; ?>
                        </ul>
                    </div>
                    <div class="cat-lesson-tools">
                        <a class="cat-icon-btn" href="catechesis-learn.php?program=<?= $programId ?>&amp;lesson=<?= $lid ?>" aria-label="Preview <?= e($l['title']) ?>" title="Preview"><?= cat_icon('eye', 14) ?></a>
                        <?php if ($canEdit): ?>
                            <?php foreach (['up' => $i > 0, 'down' => $i < count($lessons) - 1] as $dir => $ok): ?>
                                <form method="post" action="<?= e($self) ?>" class="inline-form">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="move">
                                    <input type="hidden" name="program" value="<?= $programId ?>"><input type="hidden" name="lid" value="<?= $lid ?>"><input type="hidden" name="dir" value="<?= $dir ?>">
                                    <button type="submit" class="cat-icon-btn" aria-label="Move <?= e($l['title']) ?> <?= $dir ?>" title="Move <?= $dir ?>"<?= $ok ? '' : ' disabled' ?>><?= cat_icon($dir, 14) ?></button>
                                </form>
                            <?php endforeach; ?>
                            <form method="post" action="<?= e($self) ?>" class="inline-form">
                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="publish">
                                <input type="hidden" name="program" value="<?= $programId ?>"><input type="hidden" name="lid" value="<?= $lid ?>">
                                <button type="submit" class="cat-tool cat-pub"><?= $l['is_published'] ? 'Unpublish' : 'Publish' ?></button>
                            </form>
                            <a class="cat-icon-btn" href="<?= e($self) ?>&amp;edit=<?= $lid ?>#lesson-form" aria-label="Edit <?= e($l['title']) ?>" title="Edit"><?= cat_icon('pencil', 14) ?></a>
                            <form method="post" action="<?= e($self) ?>" class="inline-form">
                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="delete_lesson">
                                <input type="hidden" name="program" value="<?= $programId ?>"><input type="hidden" name="lid" value="<?= $lid ?>">
                                <button type="submit" class="cat-icon-btn is-del" data-confirm="Delete &quot;<?= e($l['title']) ?>&quot;? Its handout and the students' progress on it go too. This cannot be undone." aria-label="Delete <?= e($l['title']) ?>" title="Delete"><?= cat_icon('trash', 14) ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
