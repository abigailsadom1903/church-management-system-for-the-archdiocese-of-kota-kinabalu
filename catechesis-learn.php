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
<main class="sx-page cat-page cat-learn-page">
    <?php back_button($preview ? 'catechesis-lessons.php?program=' . $programId : 'catechesis.php', $preview ? 'Back to lesson materials' : 'Back to my programs'); ?>
    <header class="page-heading">
        <h1><?= e($progName) ?></h1>
        <p><?= $preview ? 'Student preview &mdash; drafts are shown, and nothing is marked as done' : 'Lesson materials' ?></p>
    </header>

    <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>

    <?php if (!$lessons): ?>
        <p class="ann-empty">No lessons have been added to this program yet. Your catechist will post them here.</p>
    <?php else: ?>
        <div class="cat-learn">
            <aside class="cat-learn-side" aria-label="Lessons">
                <div class="cat-learn-prog">
                    <div class="cat-bar-label"><span><?= count($done) ?> of <?= count($lessons) ?> lessons done</span><span><?= $pct ?>%</span></div>
                    <div class="cat-bar" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><i style="width: <?= $pct ?>%"></i></div>
                </div>
                <ol class="cat-learn-list">
                    <?php foreach ($lessons as $i => $l): $lid = (int)$l['lesson_id']; $isDone = isset($done[$lid]); $isCur = $current && (int)$current['lesson_id'] === $lid; ?>
                        <li>
                            <a href="<?= e($self) ?>&amp;lesson=<?= $lid ?>" class="<?= $isCur ? 'is-current' : '' ?><?= $isDone ? ' is-done' : '' ?>"<?= $isCur ? ' aria-current="page"' : '' ?>>
                                <span class="cat-learn-no"><?= $isDone ? cat_icon('check', 13) : $i + 1 ?></span>
                                <span class="cat-learn-t"><?= e($l['title']) ?><?php if (!$l['is_published']): ?> <em>(draft)</em><?php endif; ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </aside>

            <article class="cat-learn-main" aria-labelledby="lesson-title">
                <?php $lid = (int)$current['lesson_id']; $embed = cat_video_embed($current['video_url']); $fileLabel = cat_lesson_file_label($current); ?>
                <span class="cat-pill">Lesson <?= $idx + 1 ?> of <?= count($lessons) ?></span>
                <h2 id="lesson-title"><?= e($current['title']) ?></h2>
                <?php if ($current['summary']): ?><p class="cat-learn-sum"><?= e($current['summary']) ?></p><?php endif; ?>

                <?php if ($embed): ?>
                    <div class="cat-learn-video"><iframe src="<?= e($embed) ?>" title="Video: <?= e($current['title']) ?>" loading="lazy" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>
                <?php elseif ($current['video_url']): ?>
                    <p><a class="cat-tool" href="<?= e($current['video_url']) ?>" target="_blank" rel="noopener nofollow"><?= cat_icon('play', 15) ?> Watch the video</a></p>
                <?php endif; ?>

                <?php if ($body = cat_lesson_body($current['body'])): ?><div class="cat-learn-body"><?= $body ?></div><?php endif; ?>

                <?php if ($fileLabel): ?>
                    <a class="cat-learn-file" href="catechesis-lesson-file.php?id=<?= $lid ?>" target="_blank" rel="noopener">
                        <span><?= cat_icon('file', 20) ?></span>
                        <span><b><?= e($current['file_name'] ?: 'Lesson handout') ?></b><small><?= e($fileLabel) ?></small></span>
                        <?= cat_icon('download', 18) ?>
                    </a>
                <?php endif; ?>

                <div class="cat-learn-foot">
                    <?php if ($prev): ?><a class="cat-learn-nav" href="<?= e($self) ?>&amp;lesson=<?= (int)$prev['lesson_id'] ?>"><?= cat_icon('back', 14) ?> Previous</a><?php else: ?><span></span><?php endif; ?>
                    <?php if ($eid): ?>
                        <form method="post" action="<?= e($self) ?>">
                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                            <input type="hidden" name="action" value="done">
                            <input type="hidden" name="program" value="<?= $programId ?>">
                            <input type="hidden" name="lesson" value="<?= $lid ?>">
                            <?php if (isset($done[$lid])): ?>
                                <span class="cat-learn-donemark"><?= cat_icon('check', 15) ?> Done <?= e(date('j M Y', strtotime($done[$lid]))) ?></span>
                                <button type="submit" name="done" value="0" class="cat-learn-undo">Mark as not done</button>
                            <?php else: ?>
                                <button type="submit" name="done" value="1" class="reg-submit"><?= cat_icon('check', 15) ?> Mark as done</button>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                    <?php if ($next): ?><a class="cat-learn-nav" href="<?= e($self) ?>&amp;lesson=<?= (int)$next['lesson_id'] ?>">Next <?= cat_icon('arrow', 14) ?></a><?php else: ?><span></span><?php endif; ?>
                </div>
            </article>
        </div>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
