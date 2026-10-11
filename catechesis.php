<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/login_gate.php';
require_signed_in('Catechesis Programs');   // visitors get "please login or register" instead of this page
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/sacrament_helpers.php';
require __DIR__ . '/includes/catechesis_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$role = $_SESSION['role'] ?? '';
$isUser = $role === 'user';
$userId = $isUser ? (int)$_SESSION['user_id'] : 0;

// The church shown: a parishioner's own parish, a Church Admin's own church; the Super Admin can pick any.
$churches = $pdo->query('SELECT church_info_id, church_name, church_location, church_photo FROM church_info ORDER BY church_name')->fetchAll();
$byId = array_column($churches, null, 'church_info_id');
$churchId = pick_church_id($pdo, $byId, $role === 'super_admin' ? (int)($_GET['church'] ?? 0) : 0);
$church = $byId[$churchId] ?? null;
$photo = $church ? church_photo_url($church['church_photo']) : null;

$flash = $_SESSION['catechesis_flash'] ?? null;
unset($_SESSION['catechesis_flash']);

$stmt = $pdo->prepare('SELECT * FROM catechesis_programs WHERE church_id = ? ORDER BY category, title');
$stmt->execute([$churchId]);
$programs = $stmt->fetchAll();

// My enrolments in this church's programs (keyed by program).
$mine = [];
if ($isUser) {
    $stmt = $pdo->prepare('SELECT * FROM catechesis_enrolments WHERE user_id = ? AND church_id = ?');
    $stmt->execute([$userId, $churchId]);
    foreach ($stmt->fetchAll() as $r) $mine[(int)$r['program_id']] = $r;
}
$progById = array_column($programs, null, 'program_id');
$attRows = cat_attendance_rows($pdo, array_column($mine, 'enrolment_id'));   // my present / absent dates

// My Progress: the programs I am attending now.
$active = [];
$attended = 0;
$sessions = 0;
$next = null;
foreach ($mine as $pid => $en) {
    if ($en['status'] !== 'active' || !isset($progById[$pid])) continue;
    $p = $progById[$pid];
    $active[] = ['e' => $en, 'p' => $p];
    $attended += (int)$en['attendance'];
    $sessions += (int)$p['total_sessions'];
    $d = cat_next_lesson($p);
    if (!$next || $d < $next['date']) $next = ['date' => $d, 'p' => $p];
}

$pageTitle = 'Catechesis Programs';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="sx-page cat-page">
    <?php back_button('index.php#services', 'Back to Services'); ?>
    <header class="page-heading"><h1>Catechesis Programs</h1></header>

    <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>

    <?php if ($church): ?>
        <figure class="sx-church">
            <?php if ($photo): ?>
                <img src="<?= e($photo) ?>" alt="<?= e($church['church_name']) ?>">
            <?php else: ?>
                <span class="sx-church-ph"><?= sx_icon('church', 46) ?></span>
            <?php endif; ?>
            <figcaption><?= e($church['church_name']) ?><small><?= e($church['church_location']) ?></small></figcaption>
        </figure>
        <?php if ($role === 'super_admin'): ?>
            <form method="get" class="sx-pick">
                <label for="cat-church">Showing</label>
                <select id="cat-church" name="church" onchange="this.form.submit()">
                    <?php foreach ($churches as $c): ?><option value="<?= (int)$c['church_info_id'] ?>" <?= (int)$c['church_info_id'] === $churchId ? 'selected' : '' ?>><?= e($c['church_name']) ?></option><?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="btn-save">Show</button></noscript>
            </form>
        <?php endif; ?>
    <?php elseif ($isUser): ?>
        <p class="ann-empty">Choose your home parish on <a href="profile.php">your profile</a> to see your church's catechesis programs.</p>
    <?php endif; ?>

    <?php if ($isUser && $active): ?>
        <section class="cat-progress" aria-labelledby="cat-progress-title">
            <h2 id="cat-progress-title" class="cat-heading">My Progress</h2>
            <div class="cat-progress-grid">
                <div class="cat-attend">
                    <span class="cat-attend-ico"><?= cat_icon('calendar', 20) ?></span>
                    <small>Attendance</small>
                    <b><?= $attended ?>/<?= $sessions ?> <em>Sessions</em></b>
                </div>
                <div class="cat-active-list">
                    <?php foreach ($active as ['e' => $en, 'p' => $p]): $pct = cat_progress((int)$en['attendance'], (int)$p['total_sessions']); ?>
                        <article class="cat-active">
                            <div class="cat-active-top"><span class="cat-pill">Active session</span><span class="cat-est"><?= e($p['term_label'] ?: '') ?></span></div>
                            <h3><?= e($p['title']) ?></h3>
                            <p><?= e($p['description']) ?></p>
                            <?php if ($p['notes']): ?><p class="cat-notice"><?= cat_icon('mail', 13) ?> <?= nl2br(e($p['notes'])) ?></p><?php endif; ?>
                            <div class="cat-bar-label"><span><?= $pct ?>% Progress</span><span><?= (int)$en['attendance'] ?> / <?= (int)$p['total_sessions'] ?> Lessons</span></div>
                            <div class="cat-bar" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><i style="width: <?= $pct ?>%"></i></div>
                            <?php
                            $marks = $attRows[(int)$en['enrolment_id']] ?? [];
                            [$nPresent, $nAbsent] = cat_attendance_counts($marks);
                            ?>
                            <details class="cat-att-details" open>
                                <summary>My attendance <span class="cat-att-sum"><b class="is-present"><?= $nPresent + (int)($en['attendance_undated'] ?? 0) ?> present</b> &middot; <b class="is-absent"><?= $nAbsent ?> absent</b></span></summary>
                                <?= cat_attendance_list($marks, (int)($en['attendance_undated'] ?? 0)) ?>
                            </details>
                            <a class="cat-continue" href="#program-<?= (int)$p['program_id'] ?>">Continue Learning <?= cat_icon('arrow', 14) ?></a>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if ($next): ?>
                    <aside class="cat-next" aria-label="Next lesson">
                        <h3>Next Lesson</h3>
                        <?php if ($next['p']['notes']): ?>
                            <div class="cat-next-notice">
                                <span><?= cat_icon('mail', 20) ?></span>
                                <p><?= nl2br(e($next['p']['notes'])) ?></p>
                            </div>
                        <?php else: ?>
                            <div class="cat-next-row"><span><?= cat_icon('calendar', 18) ?></span><div><small>Date</small><b><?= e(date('j F Y', strtotime($next['date']))) ?></b></div></div>
                            <?php if ($next['p']['start_time']): ?><div class="cat-next-row"><span><?= cat_icon('clock', 18) ?></span><div><small>Time</small><b><?= e(cat_time($next['p']['start_time'])) ?><?= $next['p']['end_time'] ? ' - ' . e(cat_time($next['p']['end_time'])) : '' ?></b></div></div><?php endif; ?>
                            <?php if ($next['p']['location']): ?><div class="cat-next-row"><span><?= cat_icon('pin', 18) ?></span><div><small>Where</small><b><?= e($next['p']['location']) ?></b></div></div><?php endif; ?>
                        <?php endif; ?>
                        <p class="cat-next-title"><?= e($next['p']['title']) ?></p>
                    </aside>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="cat-programs" aria-labelledby="cat-programs-title">
        <h2 id="cat-programs-title" class="cat-heading">Available Programs</h2>
        <?php if (!$programs): ?>
            <p class="ann-empty">This church has not opened any catechesis programs yet.</p>
        <?php else: ?>
            <div class="cat-grid">
                <?php foreach ($programs as $p):
                    $en = $mine[(int)$p['program_id']] ?? null;
                ?>
                    <article class="cat-card" id="program-<?= (int)$p['program_id'] ?>">
                        <div class="cat-card-img<?= cat_photo_url($p['photo'] ?? null) ? ' has-photo' : '' ?>" style="<?= e(cat_cover_style($p)) ?>"><span><?= e($p['category']) ?></span></div>
                        <div class="cat-card-body">
                            <h3><?= e($p['title']) ?></h3>
                            <p class="cat-desc"><?= e($p['description']) ?></p>
                            <?php if ($p['notes']): ?><p class="cat-notice"><?= cat_icon('mail', 13) ?> <?= nl2br(e($p['notes'])) ?></p><?php endif; ?>
                            <ul class="cat-meta">
                                <li><?= cat_icon('calendar', 15) ?> <span><?= e(cat_schedule_text($p)) ?></span></li>
                                <?php if ($p['location']): ?><li><?= cat_icon('pin', 15) ?> <span><?= e($p['location']) ?></span></li><?php endif; ?>
                            </ul>
                            <?php if (!$isUser): ?>
                                <?php if ($role !== 'user'): ?><a class="cat-btn" href="catechesis-manage.php?program=<?= (int)$p['program_id'] ?>">View Enrolments</a><?php endif; ?>
                            <?php elseif (!$en): ?>
                                <a class="cat-btn" href="catechesis-enrol.php?program=<?= (int)$p['program_id'] ?>">Register Now</a>
                            <?php elseif ($en['status'] === 'draft'): ?>
                                <a class="cat-btn" href="catechesis-enrol.php?program=<?= (int)$p['program_id'] ?>">Continue Registration</a>
                            <?php else: [$stLabel] = cat_status($en['status']); ?>
                                <span class="cat-btn is-done"><?= cat_icon('check', 15) ?> <?= $en['status'] === 'active' ? 'Registered' : e($stLabel) ?></span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
