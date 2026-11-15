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
// the published lesson materials of those programs, and which ones I have done
$lessonsOf = [];
foreach ($active as ['p' => $p]) $lessonsOf[(int)$p['program_id']] = cat_lessons($pdo, (int)$p['program_id']);
$lessonsDone = cat_lessons_done($pdo, array_column($mine, 'enrolment_id'));

$pageTitle = 'Catechesis Programs';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="cat-page is-new">
    <!-- Hero: title, quick buttons, and the parish photo -->
    <section class="kx-hero">
        <div class="kx-wrap kx-hero-inner">
            <div class="kx-hero-text">
                <?php back_button('index.php#services', 'Back to Services'); ?>
                <p class="kx-eyebrow">Faith formation<?= $church ? ' &middot; ' . e($church['church_name']) : '' ?></p>
                <h1>Catechesis Programs</h1>
                <p class="kx-lead">Grow in faith at every stage of life &mdash; from Sunday School and RCIA to Bible study and marriage preparation.</p>
                <div class="kx-hero-btns">
                    <?php if ($isUser && $active): ?><a class="kx-btn is-primary" href="#my-progress">My progress <?= cat_icon('down', 14) ?></a><?php endif; ?>
                    <a class="kx-btn" href="#programs">Browse programs</a>
                </div>
                <?php if ($role === 'super_admin'): ?>
                    <form method="get" class="kx-pick">
                        <label for="cat-church">Showing</label>
                        <select id="cat-church" name="church" onchange="this.form.submit()">
                            <?php foreach ($churches as $c): ?><option value="<?= (int)$c['church_info_id'] ?>" <?= (int)$c['church_info_id'] === $churchId ? 'selected' : '' ?>><?= e($c['church_name']) ?></option><?php endforeach; ?>
                        </select>
                        <noscript><button type="submit" class="btn-save">Show</button></noscript>
                    </form>
                <?php endif; ?>
            </div>
            <?php if ($church): ?>
                <div class="kx-hero-art">
                    <div class="kx-photo<?= $photo ? '' : ' is-empty' ?>">
                        <?php if ($photo): ?><img src="<?= e($photo) ?>" alt="<?= e($church['church_name']) ?>"><?php else: ?><span><?= sx_icon('church', 56) ?></span><?php endif; ?>
                    </div>
                    <div class="kx-place">
                        <span class="kx-place-ico"><?= cat_icon('pin', 15) ?></span>
                        <span><b><?= e($church['church_name']) ?></b><small><?= e($church['church_location']) ?></small></span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <div class="kx-wrap kx-body">
        <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>
        <?php if (!$church && $isUser): ?>
            <p class="ann-empty">Choose your home parish on <a href="profile.php">your profile</a> to see your church's catechesis programs.</p>
        <?php endif; ?>

        <?php if ($isUser && $active): ?>
            <!-- My progress: one card per program I attend, and the next lesson -->
            <section class="kx-section" id="my-progress" aria-labelledby="kx-progress-title">
                <div class="kx-section-head">
                    <h2 id="kx-progress-title">My progress</h2>
                    <span class="kx-section-meta"><?= count($active) ?> active program<?= count($active) === 1 ? '' : 's' ?><?= $active[0]['p']['term_label'] ? ' &middot; ' . e($active[0]['p']['term_label']) : '' ?></span>
                </div>
                <div class="kx-progress-grid">
                    <div class="kx-progress-list">
                        <?php foreach ($active as ['e' => $en, 'p' => $p]):
                            $pct = cat_progress((int)$en['attendance'], (int)$p['total_sessions']);
                            $marks = $attRows[(int)$en['enrolment_id']] ?? [];
                            [$nPresent, $nAbsent] = cat_attendance_counts($marks);
                            $undated = (int)($en['attendance_undated'] ?? 0);
                            $lessons = $lessonsOf[(int)$p['program_id']] ?? [];
                            $nDone = count(array_intersect_key($lessonsDone[(int)$en['enrolment_id']] ?? [], array_flip(array_column($lessons, 'lesson_id'))));
                            // Session strip: undated lessons first, then each dated mark (oldest first), then the sessions still to come.
                            $strip = array_fill(0, $undated, ['present', '&mdash;']);
                            foreach (array_reverse($marks) as $m) $strip[] = [$m['status'], e(date('j M', strtotime($m['session_date'])))];
                            for ($i = count($strip) + 1; $i <= (int)$p['total_sessions']; $i++) $strip[] = ['todo', '#' . $i];
                            $circ = 2 * M_PI * 46;
                        ?>
                            <article class="kx-progress">
                                <div class="kx-progress-top">
                                    <div class="kx-ring" role="img" aria-label="<?= $pct ?>% done: <?= (int)$en['attendance'] ?> of <?= (int)$p['total_sessions'] ?> lessons">
                                        <svg viewBox="0 0 104 104" aria-hidden="true"><circle cx="52" cy="52" r="46" class="kx-ring-bg"/><circle cx="52" cy="52" r="46" class="kx-ring-fg" stroke-dasharray="<?= round($circ, 2) ?>" stroke-dashoffset="<?= round($circ * (1 - $pct / 100), 2) ?>"/></svg>
                                        <span><b><?= $pct ?>%</b><small><?= (int)$en['attendance'] ?> of <?= (int)$p['total_sessions'] ?> lessons</small></span>
                                    </div>
                                    <div class="kx-progress-title">
                                        <span class="kx-active-pill">Active session</span>
                                        <h3><?= e($p['title']) ?></h3>
                                        <p><?= e($p['description']) ?></p>
                                    </div>
                                </div>
                                <div class="kx-stats">
                                    <div><small>Attendance</small><b><?= (int)$en['attendance'] ?> <i>/ <?= (int)$p['total_sessions'] ?> sessions</i></b></div>
                                    <div><small>Present &middot; Absent</small><b><?= $nPresent + $undated ?> <i>present</i> &middot; <?= $nAbsent ?> <i>absent</i></b></div>
                                    <div><small>Materials</small><b><?= $nDone ?> <i>of <?= count($lessons) ?> done</i></b></div>
                                </div>
                                <div class="kx-strip-head"><span>Session by session</span><?php if ($undated): ?><small>Earlier sessions have no date kept</small><?php endif; ?></div>
                                <ol class="kx-strip"<?= count($strip) <= 12 ? ' style="grid-template-columns: repeat(' . count($strip) . ', minmax(0, 1fr))"' : '' ?>>
                                    <?php foreach ($strip as $n => [$st, $label]): ?>
                                        <li class="is-<?= $st ?>" title="Session <?= $n + 1 ?>: <?= $st === 'todo' ? 'still to come' : $st ?>"><i></i><small><?= $label ?></small></li>
                                    <?php endforeach; ?>
                                </ol>
                                <div class="kx-legend"><span class="is-present"><i></i>Present</span><span class="is-absent"><i></i>Absent</span><span class="is-todo"><i></i>Still to come</span></div>
                                <div class="kx-progress-btns">
                                    <a class="kx-btn is-primary" href="catechesis-learn.php?program=<?= (int)$p['program_id'] ?>">Continue learning <?= cat_icon('arrow', 14) ?></a>
                                    <?php if ($lessons): ?><a class="kx-btn" href="catechesis-learn.php?program=<?= (int)$p['program_id'] ?>"><?= cat_icon('book', 14) ?> Lesson materials</a><?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($next): $np = $next['p'];
                        $nStart = strtotime($next['date'] . ' ' . ($np['start_time'] ?: '00:00:00'));
                        $nEnd = $np['end_time'] ? strtotime($next['date'] . ' ' . $np['end_time']) : $nStart + 3600;
                    ?>
                        <aside class="kx-next" aria-label="Next lesson" data-start="<?= (int)$nStart ?>" data-end="<?= (int)$nEnd ?>" data-title="<?= e($np['title']) ?>" data-place="<?= e(trim(($np['location'] ?: '') . ($church ? ', ' . $church['church_name'] : ''), ', ')) ?>">
                            <p class="kx-next-label">Next lesson</p>
                            <div class="kx-next-when">
                                <span class="kx-next-date"><small><?= e(strtoupper(date('D', strtotime($next['date'])))) ?></small><b><?= (int)date('j', strtotime($next['date'])) ?></b></span>
                                <div>
                                    <h3><?= e($np['title']) ?></h3>
                                    <p><?= $np['start_time'] ? e(date('g:i A', strtotime($np['start_time']))) . ($np['end_time'] ? ' &ndash; ' . e(date('g:i A', strtotime($np['end_time']))) : '') : e(date('j F Y', strtotime($next['date']))) ?><?= $np['location'] ? ' &middot; ' . e($np['location']) : '' ?></p>
                                </div>
                            </div>
                            <?php if ($np['notes']): ?>
                                <div class="kx-next-note"><?= cat_icon('pencil', 15) ?><p><?= nl2br(e($np['notes'])) ?></p></div>
                            <?php endif; ?>
                            <button type="button" class="kx-addcal"><?= cat_icon('calendar', 14) ?> Add to my calendar</button>
                        </aside>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- All programs of this church, with category filters -->
        <section class="kx-section" id="programs" aria-labelledby="kx-programs-title">
            <div class="kx-section-head">
                <div>
                    <h2 id="kx-programs-title">Available programs</h2>
                    <p class="kx-section-sub">Choose a path that fits where you are in your faith journey.</p>
                </div>
                <?php $cats = array_values(array_unique(array_column($programs, 'category'))); ?>
                <?php if (count($cats) > 1): ?>
                    <div class="kx-chips" role="group" aria-label="Show category">
                        <button type="button" class="is-active" data-cat="" aria-pressed="true">All</button>
                        <?php foreach ($cats as $cat): ?><button type="button" data-cat="<?= e($cat) ?>" aria-pressed="false"><?= e($cat) ?></button><?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (!$programs): ?>
                <p class="ann-empty">This church has not opened any catechesis programs yet.</p>
            <?php else: ?>
                <div class="kx-grid">
                    <?php foreach ($programs as $p):
                        $en = $mine[(int)$p['program_id']] ?? null;
                        $cover = cat_photo_url($p['photo'] ?? null);
                        $isIn = $en && in_array($en['status'], ['active', 'completed'], true);
                    ?>
                        <article class="kx-card" id="program-<?= (int)$p['program_id'] ?>" data-cat="<?= e($p['category']) ?>">
                            <div class="kx-card-img<?= $cover ? '' : ' is-plain' ?>" style="<?= $cover ? "background-image: url('" . e($cover) . "')" : 'background: ' . e(cat_gradient($p['category'])) ?>">
                                <span class="kx-tag"><?= e($p['category']) ?></span>
                                <?php if ($en && $en['status'] !== 'draft'): [$stLabel] = cat_status($en['status']); ?>
                                    <span class="kx-badge<?= $en['status'] === 'active' ? '' : ' is-other' ?>"><?= cat_icon('check', 12) ?> <?= $en['status'] === 'active' ? 'Registered' : e($stLabel) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="kx-card-body">
                                <h3><?= e($p['title']) ?></h3>
                                <p class="kx-desc"><?= e($p['description']) ?></p>
                                <ul class="kx-meta">
                                    <li><?= cat_icon('calendar', 14) ?> <span><?= e(cat_schedule_text($p)) ?></span></li>
                                    <?php if ($p['location']): ?><li><?= cat_icon('pin', 14) ?> <span><?= e($p['location']) ?></span></li><?php endif; ?>
                                </ul>
                                <div class="kx-card-btns">
                                    <?php if (!$isUser): ?>
                                        <a class="kx-btn is-primary" href="catechesis-manage.php?program=<?= (int)$p['program_id'] ?>">View enrolments</a>
                                        <a class="kx-btn" href="catechesis-lessons.php?program=<?= (int)$p['program_id'] ?>"><?= cat_icon('book', 14) ?> Lesson materials</a>
                                    <?php elseif (!$en): ?>
                                        <a class="kx-btn is-primary" href="catechesis-enrol.php?program=<?= (int)$p['program_id'] ?>">Register now <?= cat_icon('arrow', 14) ?></a>
                                    <?php elseif ($en['status'] === 'draft'): ?>
                                        <a class="kx-btn is-primary" href="catechesis-enrol.php?program=<?= (int)$p['program_id'] ?>">Continue registration <?= cat_icon('arrow', 14) ?></a>
                                    <?php elseif ($isIn): ?>
                                        <a class="kx-btn" href="catechesis-learn.php?program=<?= (int)$p['program_id'] ?>"><?= cat_icon('book', 14) ?> Open lessons</a>
                                    <?php else: [$stLabel] = cat_status($en['status']); ?>
                                        <span class="kx-btn is-muted"><?= e($stLabel) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>
<script>
// Category chips: show only the programs of the chosen category.
(function () {
    var chips = document.querySelectorAll('.kx-chips button'), cards = document.querySelectorAll('.kx-card');
    chips.forEach(function (chip) {
        chip.addEventListener('click', function () {
            chips.forEach(function (c) { var on = c === chip; c.classList.toggle('is-active', on); c.setAttribute('aria-pressed', on); });
            cards.forEach(function (card) { card.hidden = chip.dataset.cat !== '' && card.dataset.cat !== chip.dataset.cat; });
        });
    });
})();
// Next lesson: "Add to my calendar" downloads an .ics file (works with Google, Outlook, Apple).
(function () {
    var box = document.querySelector('.kx-next');
    if (!box) return;
    box.querySelector('.kx-addcal').addEventListener('click', function () {
        var stamp = function (ms) { return new Date(ms).toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, ''); };
        var esc = function (s) { return String(s).replace(/([,;\\])/g, '\\$1'); };
        var start = +box.dataset.start * 1000, end = +box.dataset.end * 1000;
        var ics = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Archdiocese of Kota Kinabalu//Catechesis//EN', 'BEGIN:VEVENT',
            'UID:' + start + '-' + Math.random().toString(36).slice(2) + '@catechesis', 'DTSTAMP:' + stamp(Date.now()),
            'DTSTART:' + stamp(start), 'DTEND:' + stamp(end), 'SUMMARY:' + esc(box.dataset.title), 'LOCATION:' + esc(box.dataset.place),
            'URL:' + location.href.split('#')[0], 'END:VEVENT', 'END:VCALENDAR'].join('\r\n');
        var a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([ics], { type: 'text/calendar' }));
        a.download = box.dataset.title.replace(/[^\w\s-]/g, '').trim().replace(/\s+/g, '-') + '.ics';
        document.body.appendChild(a); a.click(); a.remove();
    });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
