<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/login_gate.php';
require_signed_in('Catechesis Lessons');
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/sacrament_helpers.php';
require __DIR__ . '/includes/catechesis_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// The lessons of one program, for its students (enrolment active or completed). The admins of the church open it
// as a preview: they also see draft lessons, and nothing is marked as done.
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$role = $_SESSION['role'] ?? '';
$programId = (int)($_GET['program'] ?? $_POST['program'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM catechesis_programs WHERE program_id = ?');
$stmt->execute([$programId]);
$program = $stmt->fetch();

$enrolment = null;
$preview = false;
if ($program && $role === 'user') {
    $stmt = $pdo->prepare("SELECT * FROM catechesis_enrolments WHERE program_id = ? AND user_id = ? AND status IN ('active', 'completed')");
    $stmt->execute([$programId, (int)$_SESSION['user_id']]);
    $enrolment = $stmt->fetch() ?: null;
} elseif ($program && can_manage_church($pdo, (int)$program['church_id'])) {
    $preview = true;
}
if (!$enrolment && !$preview) {
    $_SESSION['catechesis_flash'] = $program ? 'The lessons of ' . $program['title'] . ' open once your registration has been accepted.' : 'That program could not be found.';
    header('Location: catechesis.php');
    exit;
}
$eid = $enrolment ? (int)$enrolment['enrolment_id'] : 0;
$self = 'catechesis-learn.php?program=' . $programId;

$lessons = cat_lessons($pdo, $programId, $preview);   // students: published lessons only
$byId = array_column($lessons, null, 'lesson_id');

// Mark a lesson done, or not done again (students only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'done') {
    $lid = (int)($_POST['lesson'] ?? 0);
    if ($eid && isset($byId[$lid]) && hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        if (($_POST['done'] ?? '') === '1') {
            $pdo->prepare('INSERT IGNORE INTO catechesis_lesson_progress (enrolment_id, lesson_id) VALUES (?, ?)')->execute([$eid, $lid]);
            // straight on to the next lesson that is not done yet
            $doneNow = cat_lessons_done($pdo, [$eid])[$eid] ?? [];
            $next = null;
            foreach ($lessons as $l) if (!isset($doneNow[(int)$l['lesson_id']]) && (int)$l['lesson_id'] !== $lid) { $next = (int)$l['lesson_id']; break; }
            $_SESSION['catechesis_flash'] = '"' . $byId[$lid]['title'] . '" marked as done.' . ($next ? '' : ' You have finished every lesson so far. Well done!');
            header('Location: ' . $self . '&lesson=' . ($next ?: $lid));
            exit;
        }
        $pdo->prepare('DELETE FROM catechesis_lesson_progress WHERE enrolment_id = ? AND lesson_id = ?')->execute([$eid, $lid]);
    }
    header('Location: ' . $self . '&lesson=' . $lid);
    exit;
}

$flash = $_SESSION['catechesis_flash'] ?? null;
unset($_SESSION['catechesis_flash']);

$done = $eid ? (cat_lessons_done($pdo, [$eid])[$eid] ?? []) : [];
$done = array_intersect_key($done, $byId);
$pct = $lessons ? (int)round(count($done) / count($lessons) * 100) : 0;

// The lesson shown: the one asked for, else the first one not done yet, else the first.
$current = $byId[(int)($_GET['lesson'] ?? 0)] ?? null;
if (!$current) foreach ($lessons as $l) if (!isset($done[(int)$l['lesson_id']])) { $current = $l; break; }
$current = $current ?? ($lessons[0] ?? null);
$idx = $current ? array_search((int)$current['lesson_id'], array_map('intval', array_column($lessons, 'lesson_id')), true) : false;
$prev = $idx !== false && $idx > 0 ? $lessons[$idx - 1] : null;
$next = $idx !== false && $idx < count($lessons) - 1 ? $lessons[$idx + 1] : null;

$progName = $program['title'] . ($program['term_label'] ? ' ' . $program['term_label'] : '');
$pageTitle = $current ? $current['title'] . ' - ' . $program['title'] : $program['title'] . ' Lessons';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<?php
$nLessons = count($lessons);
$nDone = count($done);
$left = $nLessons - $nDone;
$schedule = cat_schedule_text($program);
?>
<main class="cat-lx">
    <section class="cat-lx-hero">
        <div class="cat-lx-wrap">
            <?php back_button($preview ? 'catechesis-lessons.php?program=' . $programId : 'catechesis.php', $preview ? 'Back to lesson materials' : 'Back to my programs'); ?>
            <div class="cat-lx-hero-row">
                <div class="cat-lx-hero-text">
                    <span class="cat-lx-eyebrow">Lesson materials<?= $program['term_label'] ? ' &middot; ' . e($program['term_label']) : '' ?></span>
                    <h1><?= e($program['title']) ?></h1>
                    <ul class="cat-lx-chips">
                        <?php if ($schedule): ?><li><?= cat_icon('calendar', 13) ?> <?= e($schedule) ?></li><?php endif; ?>
                        <?php if ($program['location']): ?><li><?= cat_icon('pin', 13) ?> <?= e($program['location']) ?></li><?php endif; ?>
                    </ul>
                </div>
                <?php if ($preview): ?>
                    <div class="cat-lx-progress is-preview">
                        <span class="cat-lx-ring"><?= cat_icon('eye', 26) ?></span>
                        <div>
                            <span class="cat-lx-progress-k">Student preview</span>
                            <b><?= $nLessons ?> lesson<?= $nLessons === 1 ? '' : 's' ?></b>
                            <small>Drafts are shown; nothing is marked as done</small>
                        </div>
                    </div>
                <?php elseif ($lessons): ?>
                    <div class="cat-lx-progress">
                        <span class="cat-lx-ring" style="--p: <?= $pct ?>" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Lessons done"><span><?= $pct ?>%</span></span>
                        <div>
                            <span class="cat-lx-progress-k">Your progress</span>
                            <b><?= $nDone ?> of <?= $nLessons ?> lessons done</b>
                            <small><?= $left ? $left . ' lesson' . ($left === 1 ? '' : 's') . ' to go' : 'All lessons done. Well done!' ?></small>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="cat-lx-body">
    <div class="cat-lx-wrap">
    <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>

    <?php if (!$lessons): ?>
        <p class="ann-empty">No lessons have been added to this program yet. Your catechist will post them here.</p>
    <?php else: ?>
        <div class="cat-lx-grid">
            <aside class="cat-lx-side" aria-label="Lessons">
                <div class="cat-lx-side-head"><h2>Lessons</h2><span><?= $nLessons ?> chapter<?= $nLessons === 1 ? '' : 's' ?></span></div>
                <ol class="cat-lx-list">
                    <?php foreach ($lessons as $i => $l): $lid = (int)$l['lesson_id']; $isDone = isset($done[$lid]); $isCur = $current && (int)$current['lesson_id'] === $lid; ?>
                        <li>
                            <a href="<?= e($self) ?>&amp;lesson=<?= $lid ?>" class="<?= $isCur ? 'is-current' : '' ?><?= $isDone ? ' is-done' : '' ?>"<?= $isCur ? ' aria-current="page"' : '' ?>>
                                <span class="cat-lx-no"><?= $isDone ? cat_icon('check', 14) : $i + 1 ?></span>
                                <span class="cat-lx-item">
                                    <small>Lesson <?= $i + 1 ?></small>
                                    <b><?= e($l['title']) ?><?php if (!$l['is_published']): ?> <em>(draft)</em><?php endif; ?></b>
                                    <?php if (!$preview): ?><span><?= $isDone ? 'Done &middot; ' . e(date('j M Y', strtotime($done[$lid]))) : ($isCur ? 'In progress' : 'Not started') ?></span><?php endif; ?>
                                </span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <?php if (!empty($program['notes'])): ?>
                    <div class="cat-lx-note"><?= cat_icon('pencil', 14) ?><p><?= nl2br(e($program['notes'])) ?></p></div>
                <?php endif; ?>
            </aside>

            <article class="cat-lx-main" aria-labelledby="lesson-title">
                <?php $lid = (int)$current['lesson_id']; $embed = cat_video_embed($current['video_url']); $fileLabel = cat_lesson_file_label($current); $isDoneCur = isset($done[$lid]); ?>
                <div class="cat-lx-main-head">
                    <span class="cat-pill">Lesson <?= $idx + 1 ?> of <?= $nLessons ?></span>
                    <?php if ($isDoneCur): ?><span class="cat-lx-done"><?= cat_icon('check', 14) ?> Done <b><?= e(date('j M Y', strtotime($done[$lid]))) ?></b></span><?php endif; ?>
                </div>
                <h2 id="lesson-title"><?= e($current['title']) ?></h2>
                <?php if ($current['summary']): ?><p class="cat-learn-sum"><?= e($current['summary']) ?></p><?php endif; ?>

                <?php if ($embed): ?>
                    <div class="cat-learn-video"><iframe src="<?= e($embed) ?>" title="Video: <?= e($current['title']) ?>" loading="lazy" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>
                <?php elseif ($current['video_url']): ?>
                    <p><a class="cat-tool" href="<?= e($current['video_url']) ?>" target="_blank" rel="noopener nofollow"><?= cat_icon('play', 15) ?> Watch the video</a></p>
                <?php endif; ?>

                <?php if ($body = cat_lesson_body($current['body'])): ?><div class="cat-learn-body"><?= $body ?></div><?php endif; ?>

                <?php if ($fileLabel): $ext = strtolower(pathinfo((string)$current['file'], PATHINFO_EXTENSION)); $fileUrl = 'catechesis-lesson-file.php?id=' . $lid; ?>
                    <section class="cat-lx-mats" aria-label="Materials">
                        <div class="cat-lx-mats-head"><h3>Materials</h3><span>1 file</span></div>
                        <div class="cat-lx-file">
                            <span class="cat-lx-file-ico"><?= cat_icon('file', 18) ?><small><?= e(strtoupper($ext)) ?></small></span>
                            <span class="cat-lx-file-t"><b><?= e($current['file_name'] ?: 'Lesson handout') ?></b><small><?= e($fileLabel) ?></small></span>
                            <span class="cat-lx-file-acts">
                                <?php if (!in_array($ext, ['docx', 'pptx'], true)): ?>
                                    <a class="cat-lx-btn is-soft" href="<?= e($fileUrl) ?>" target="_blank" rel="noopener"><?= cat_icon('eye', 15) ?> View</a>
                                <?php endif; ?>
                                <a class="cat-lx-btn is-solid" href="<?= e($fileUrl) ?>&amp;dl=1"><?= cat_icon('download', 15) ?> Download</a>
                            </span>
                        </div>
                    </section>
                <?php endif; ?>

                <div class="cat-lx-foot">
                    <?php if ($prev): ?>
                        <a class="cat-lx-nav" href="<?= e($self) ?>&amp;lesson=<?= (int)$prev['lesson_id'] ?>"><?= cat_icon('back', 14) ?> Previous</a>
                    <?php else: ?>
                        <span class="cat-lx-nav is-disabled" aria-disabled="true"><?= cat_icon('back', 14) ?> Previous</span>
                    <?php endif; ?>
                    <?php if ($eid): ?>
                        <form method="post" action="<?= e($self) ?>">
                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                            <input type="hidden" name="action" value="done">
                            <input type="hidden" name="program" value="<?= $programId ?>">
                            <input type="hidden" name="lesson" value="<?= $lid ?>">
                            <?php if ($isDoneCur): ?>
                                <button type="submit" name="done" value="0" class="cat-lx-undo">Mark as not done</button>
                            <?php else: ?>
                                <button type="submit" name="done" value="1" class="cat-lx-btn is-solid"><?= cat_icon('check', 15) ?> Mark as done</button>
                            <?php endif; ?>
                        </form>
                    <?php else: ?><span></span><?php endif; ?>
                    <?php if ($next): ?>
                        <a class="cat-lx-nav" href="<?= e($self) ?>&amp;lesson=<?= (int)$next['lesson_id'] ?>">Next lesson <?= cat_icon('arrow', 14) ?></a>
                    <?php else: ?>
                        <span class="cat-lx-nav is-disabled" aria-disabled="true">Next lesson <?= cat_icon('arrow', 14) ?></span>
                    <?php endif; ?>
                </div>
            </article>
        </div>
    <?php endif; ?>
    </div>
    </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
