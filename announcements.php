<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/login_gate.php';
require_signed_in('Announcement & Bulletin');   // visitors get "please login or register" instead of this page
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/announcement_mail.php';
require __DIR__ . '/includes/bulletin_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Removes a stored bulletin PDF / cover image (only ever files inside uploads/bulletins/). */
function delete_bulletin_file(?string $file): void
{
    if ($file && preg_match('/^[\w.-]+$/', $file)) {
        $path = __DIR__ . '/uploads/bulletins/' . $file;
        if (is_file($path)) @unlink($path);
    }
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$churches = $pdo->query('SELECT church_info_id, church_name, church_location, church_photo FROM church_info ORDER BY church_name')->fetchAll();
$byId = array_column($churches, null, 'church_info_id');
$selectedId = pick_church_id($pdo, $byId, (int)($_GET['church'] ?? $_POST['church'] ?? 0));
$c = $byId[$selectedId] ?? null;
$tab = ($_GET['tab'] ?? $_POST['tab'] ?? '') === 'bulletins' ? 'bulletins' : 'announcements';
$canManage = $c && can_manage_church($pdo, $selectedId);   // Super Admin: any church; Church Admin: their own

// Digital Bulletins filters: ?q=title or topic, ?month=YYYY-MM, ?lang=en|ms|zh|ta, ?page=N
const BULLETINS_PER_PAGE = 6;
$q     = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : '';
$lang  = isset(BULLETIN_LANGUAGES[$_GET['lang'] ?? '']) ? $_GET['lang'] : '';
$page  = max(1, (int)($_GET['page'] ?? 1));
$totalBulletins = 0;
$pages = 1;

/** URL of this page for the selected church's bulletins, keeping the active filters. */
function bulletins_url(int $church, string $q, string $month, string $lang, int $page = 1): string
{
    $params = array_filter(['church' => $church, 'tab' => 'bulletins', 'q' => $q, 'month' => $month, 'lang' => $lang, 'page' => $page > 1 ? $page : null],
        fn($v) => $v !== null && $v !== '');
    return 'announcements.php?' . http_build_query($params, '', '&amp;');
}

$flash      = $_SESSION['flash'] ?? null;         // shown in the Stay Updated card
$adminFlash = $_SESSION['admin_flash'] ?? null;   // "Announcement saved." etc. for admins
unset($_SESSION['flash'], $_SESSION['admin_flash']);
$subError = '';
$subEmail = '';
$actionError = '';

// ---- POST: subscribe, or an admin action (pin / delete) ----------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'subscribe';
    $fromDashboard = (($_POST['from'] ?? '') === 'dashboard');   // the buttons on the admin dashboards
    $csrfOk = hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '');

    if ($action === 'subscribe') {
        $subEmail = trim($_POST['email'] ?? '');
        if (!$c) {
            $subError = 'Please choose a church first.';
        } elseif (!$csrfOk) {
            $subError = 'Your session expired. Please try again.';
        } elseif (!empty($_POST['company'])) {
            // Hidden field that only bots fill in: pretend it worked.
            $_SESSION['flash'] = 'Thank you! You are subscribed.';
            header('Location: announcements.php?church=' . $selectedId . '#stay-updated');
            exit;
        } elseif (!filter_var($subEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($subEmail) > 100) {
            $subError = 'Please enter a valid email address.';
        } else {
            $pdo->prepare('INSERT IGNORE INTO newsletter_subscribers (email, church_id, unsub_token) VALUES (?, ?, ?)')
                ->execute([$subEmail, $selectedId, bin2hex(random_bytes(16))]);
            // Same message whether or not the address was already subscribed.
            $_SESSION['flash'] = 'Thank you! You will receive updates from ' . $c['church_name'] . '.';
            header('Location: announcements.php?church=' . $selectedId . '#stay-updated');
            exit;
        }
    } elseif (in_array($action, ['toggle_pin', 'delete_announcement', 'delete_bulletin'], true)) {
        // The church is read from the record itself, never trusted from the form.
        $isBulletin = $action === 'delete_bulletin';
        $stmt = $pdo->prepare($isBulletin
            ? 'SELECT church_id, file_name, cover_image FROM bulletins WHERE bulletin_id = ?'
            : 'SELECT church_id, is_pinned FROM announcements WHERE announcement_id = ?');
        $stmt->execute([(int)($_POST['id'] ?? 0)]);
        $row = $stmt->fetch();

        if (!$csrfOk) {
            $actionError = 'Your session expired. Please sign in again and retry.';
        } elseif (!$row || !can_manage_church($pdo, (int)$row['church_id'])) {
            $actionError = 'You do not have permission to change this item.';
        } else {
            $id = (int)$_POST['id'];
            if ($action === 'toggle_pin') {
                $pdo->prepare('UPDATE announcements SET is_pinned = ? WHERE announcement_id = ?')->execute([$row['is_pinned'] ? 0 : 1, $id]);
                $_SESSION['admin_flash'] = $row['is_pinned'] ? 'Announcement unpinned.' : 'Announcement pinned to the top.';
            } elseif ($action === 'delete_announcement') {
                $pdo->prepare('DELETE FROM announcements WHERE announcement_id = ?')->execute([$id]);
                $_SESSION['admin_flash'] = 'Announcement deleted.';
            } else {
                $pdo->prepare('DELETE FROM bulletins WHERE bulletin_id = ?')->execute([$id]);
                delete_bulletin_file($row['file_name']);
                delete_bulletin_file($row['cover_image']);
                $_SESSION['admin_flash'] = 'Bulletin deleted.';
            }
            header('Location: ' . ($fromDashboard
                ? dashboard_path($isBulletin ? 'bulletins' : 'announcements', (int)$row['church_id'])
                : 'announcements.php?church=' . (int)$row['church_id'] . ($isBulletin ? '&tab=bulletins' : '')));
            exit;
        }
    }
}

// ---- Content for the selected church -----------------------------------------------------
$active = $inactive = $bulletins = [];
if ($c) {
    send_due_announcement_emails($pdo, $selectedId);   // scheduled announcements whose date has arrived

    $stmt = $pdo->prepare(
        'SELECT announcement_id, title, body, category, is_urgent, is_pinned, publish_date, expires_date,
                (publish_date <= CURDATE() AND (expires_date IS NULL OR expires_date >= CURDATE())) AS is_active,
                (publish_date > CURDATE()) AS is_scheduled
           FROM announcements
          WHERE church_id = ?
       ORDER BY is_pinned DESC, is_urgent DESC, publish_date DESC, announcement_id DESC'
    );
    $stmt->execute([$selectedId]);
    foreach ($stmt as $a) {
        if ($a['is_active']) $active[] = $a;
        elseif ($canManage) $inactive[] = $a;   // scheduled / expired ones are only listed for admins
    }

    if ($tab === 'bulletins') {
        $where  = ['church_id = ?'];
        $params = [$selectedId];
        if ($q !== '') {
            $where[]  = '(title LIKE ? OR topic LIKE ?)';
            $like     = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like);
        }
        if ($month !== '') {
            $where[]  = 'bulletin_date >= ? AND bulletin_date < DATE_ADD(?, INTERVAL 1 MONTH)';
            array_push($params, $month . '-01', $month . '-01');
        }
        if ($lang !== '') {
            $where[]  = 'language = ?';
            $params[] = $lang;
        }
        $whereSql = implode(' AND ', $where);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM bulletins WHERE $whereSql");
        $stmt->execute($params);
        $totalBulletins = (int)$stmt->fetchColumn();
        $pages = max(1, (int)ceil($totalBulletins / BULLETINS_PER_PAGE));
        $page  = min($page, $pages);

        $stmt = $pdo->prepare(
            "SELECT bulletin_id, title, topic, language, bulletin_date, file_name, cover_image, content IS NOT NULL AS generated FROM bulletins WHERE $whereSql
              ORDER BY bulletin_date DESC, bulletin_id DESC LIMIT " . BULLETINS_PER_PAGE . ' OFFSET ' . (($page - 1) * BULLETINS_PER_PAGE)
        );
        $stmt->execute($params);
        $bulletins = $stmt->fetchAll();
    }
}
$photoUrl = $c ? church_photo_url($c['church_photo']) : null;

/** One announcement card; admins also get pin / edit / delete controls. */
function render_announcement(array $a, bool $canManage, string $csrf): void
{
    $ts = strtotime($a['publish_date']);
    $long = mb_strlen($a['body']) > 190;
    $status = $a['is_scheduled'] ? 'Scheduled' : (!$a['is_active'] ? 'Expired' : '');
    ?>
    <article class="ann-card<?= $status ? ' is-inactive' : '' ?>">
        <div class="ann-date" aria-label="<?= e(date('j F Y', $ts)) ?>">
            <span class="ann-day"><?= e(date('j', $ts)) ?></span>
            <span class="ann-month"><?= e(strtoupper(date('M', $ts))) ?></span>
        </div>
        <div class="ann-content">
            <div class="ann-tags">
                <?php if ($a['is_pinned']): ?><span class="tag-pinned"><?= ann_icon('pin', 11) ?> Pinned</span><?php endif; ?>
                <?php if ($a['is_urgent']): ?><span class="tag-urgent">Urgent</span><?php endif; ?>
                <span class="tag-cat"><?= e($a['category']) ?></span>
                <?php if ($status): ?><span class="tag-status"><?= e($status) ?></span><?php endif; ?>

                <?php if ($canManage): ?>
                    <form method="post" action="announcements.php" class="ann-actions">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="id" value="<?= (int)$a['announcement_id'] ?>">
                        <button type="submit" name="action" value="toggle_pin" class="ico-btn pin<?= $a['is_pinned'] ? ' is-on' : '' ?>" title="<?= $a['is_pinned'] ? 'Unpin' : 'Pin to the top' ?>" aria-label="<?= $a['is_pinned'] ? 'Unpin' : 'Pin' ?> announcement" aria-pressed="<?= $a['is_pinned'] ? 'true' : 'false' ?>"><?= ann_icon('pin') ?></button>
                        <a class="ico-btn" href="announcement-form.php?id=<?= (int)$a['announcement_id'] ?>" title="Edit" aria-label="Edit announcement"><?= ann_icon('pencil') ?></a>
                        <button type="submit" name="action" value="delete_announcement" class="ico-btn danger" data-confirm="Delete this announcement? This cannot be undone." title="Delete" aria-label="Delete announcement"><?= ann_icon('trash') ?></button>
                    </form>
                <?php endif; ?>
            </div>
            <h3><?= e($a['title']) ?></h3>
            <p class="ann-body<?= $long ? ' is-clamped' : '' ?>"><?= nl2br(e($a['body'])) ?></p>
            <?php if ($long): ?>
                <button type="button" class="ann-more" aria-expanded="false">Read More <span aria-hidden="true">&rsaquo;</span></button>
            <?php endif; ?>
            <?php if ($canManage && $a['expires_date']): ?>
                <p class="ann-expiry">Expires on <?= e(date('j F Y', strtotime($a['expires_date']))) ?></p>
            <?php endif; ?>
        </div>
    </article>
    <?php
}

$pageTitle = 'Announcement & Bulletin';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="ann-page">
    <?php back_button('index.php#services', 'Back to Services'); ?>
    <header class="page-heading">
        <h1>Announcement &amp; Bulletin</h1>
        <p>News, notices and weekly bulletins from every parish.</p>
    </header>

    <?php if ($adminFlash): ?><div class="alert success info-alert" role="status"><?= e($adminFlash) ?></div><?php endif; ?>
    <?php if ($actionError): ?><div class="alert info-alert" role="alert"><?= e($actionError) ?></div><?php endif; ?>

<?php if (!$c): ?>
    <p class="dir-empty">No churches have been added yet.</p>
<?php else: ?>
    <?php if ($canManage): ?>
        <p class="admin-badge"><?= ($_SESSION['role'] ?? '') === 'super_admin' ? 'Super Admin' : 'Church Admin' ?> &middot; you can manage this church</p>
    <?php endif; ?>

    <section class="info-hero">
        <div class="hero-photo">
            <?php if ($photoUrl): ?>
                <img src="<?= e($photoUrl) ?>" alt="<?= e($c['church_name']) ?>">
            <?php else: ?>
                <div class="info-photo-fallback" aria-hidden="true">
                    <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"><path d="m18 7 4 2v11a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9l4-2"/><path d="M14 22v-4a2 2 0 0 0-2-2a2 2 0 0 0-2 2v4"/><path d="M18 22V5l-6-3-6 3v17"/><path d="M12 7v5"/><path d="M10 9h4"/></svg>
                </div>
            <?php endif; ?>
        </div>
        <h2><?= e($c['church_name']) ?></h2>
        <p class="info-town"><?= e($c['church_location']) ?></p>
    </section>

    <form method="get" action="announcements.php" class="info-select" id="church-form">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <label for="church">Select Church</label>
        <div class="select-wrap">
            <select id="church" name="church">
                <?php foreach ($churches as $ch): ?>
                    <option value="<?= (int)$ch['church_info_id'] ?>" <?= (int)$ch['church_info_id'] === $selectedId ? 'selected' : '' ?>><?= e($ch['church_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn-primary">Show</button></noscript>
        </div>
    </form>

    <nav class="seg-tabs" aria-label="Announcements or bulletins">
        <a href="announcements.php?church=<?= $selectedId ?>" class="<?= $tab === 'announcements' ? 'is-active' : '' ?>" <?= $tab === 'announcements' ? 'aria-current="page"' : '' ?>>Announcements</a>
        <a href="announcements.php?church=<?= $selectedId ?>&amp;tab=bulletins" class="<?= $tab === 'bulletins' ? 'is-active' : '' ?>" <?= $tab === 'bulletins' ? 'aria-current="page"' : '' ?>>Digital Bulletins</a>
    </nav>

    <div class="ann-layout<?= $tab === 'bulletins' ? ' is-wide' : '' ?>">
        <section class="ann-main">
            <?php if ($tab === 'announcements'): ?>
                <div class="ann-headrow">
                    <h2 class="ann-heading">Active Announcements</h2>
                    <?php if ($canManage): ?>
                        <a class="btn-new" href="announcement-form.php?church=<?= $selectedId ?>"><?= ann_icon('plus', 16) ?> New Announcement</a>
                    <?php endif; ?>
                </div>
                <?php if (!$active): ?>
                    <p class="ann-empty">There are no active announcements for <?= e($c['church_name']) ?> right now.</p>
                <?php endif; ?>
                <?php foreach ($active as $a) render_announcement($a, $canManage, $_SESSION['csrf']); ?>

                <?php if ($inactive): ?>
                    <h2 class="ann-heading ann-heading-sub">Scheduled &amp; Expired <span>(only visible to admins)</span></h2>
                    <?php foreach ($inactive as $a) render_announcement($a, $canManage, $_SESSION['csrf']); ?>
                <?php endif; ?>

            <?php else: ?>
                <form method="get" action="announcements.php" class="bul-filter">
                    <input type="hidden" name="church" value="<?= $selectedId ?>">
                    <input type="hidden" name="tab" value="bulletins">
                    <div class="bf-field bf-search">
                        <label for="bul-q">Search Bulletins</label>
                        <div class="bf-input">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                            <input type="search" id="bul-q" name="q" maxlength="100" placeholder="Search by topic or title..." value="<?= e($q) ?>" autocomplete="off">
                        </div>
                    </div>
                    <div class="bf-field">
                        <label for="bul-month">Year/Month</label>
                        <input type="month" id="bul-month" name="month" value="<?= e($month) ?>" max="<?= date('Y-m') ?>">
                    </div>
                    <div class="bf-field">
                        <label for="bul-lang">Language</label>
                        <select id="bul-lang" name="lang">
                            <option value="">All languages</option>
                            <?php foreach (BULLETIN_LANGUAGES as $code => $label): ?>
                                <option value="<?= e($code) ?>"<?= $lang === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="bf-apply">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16"/><path d="M7 12h10"/><path d="M10 18h4"/></svg>
                        Apply Filters
                    </button>
                </form>

                <div class="ann-headrow">
                    <h2 class="ann-heading">Recent Digital Bulletins</h2>
                    <?php if ($canManage): ?>
                        <span class="bul-new-btns"><a class="btn-new is-main" href="bulletin-builder.php?church=<?= $selectedId ?>"><?= ann_icon('plus', 16) ?> Build a Bulletin</a><a class="btn-new" href="bulletin-form.php?church=<?= $selectedId ?>"><?= ann_icon('plus', 16) ?> Upload PDF</a></span>
                    <?php endif; ?>
                </div>
                <?php if ($q !== '' || $month !== '' || $lang !== ''): ?>
                    <p class="bul-found">
                        <?= $totalBulletins ?> <?= $lang !== '' ? e(BULLETIN_LANGUAGES[$lang]) . ' ' : '' ?><?= $totalBulletins === 1 ? 'bulletin' : 'bulletins' ?> found<?= $q !== '' ? ' for &ldquo;' . e($q) . '&rdquo;' : '' ?><?= $month !== '' ? ' in ' . e(date('F Y', strtotime($month . '-01'))) : '' ?>
                        &middot; <a href="announcements.php?church=<?= $selectedId ?>&amp;tab=bulletins">Clear filters</a>
                    </p>
                <?php endif; ?>

                <?php if (!$bulletins): ?>
                    <p class="ann-empty">
                        <?= ($q !== '' || $month !== '' || $lang !== '') ? 'No bulletins match your search.' : 'No bulletins have been published for ' . e($c['church_name']) . ' yet.' ?>
                    </p>
                <?php else: ?>
                    <div class="bul-grid">
                        <?php foreach ($bulletins as $b):
                            // made with the Bulletin Builder: its page, opening the "Save as PDF" window; otherwise the uploaded PDF file
                            $file  = $b['generated'] ? 'bulletin-view.php?id=' . (int)$b['bulletin_id'] . '&print=1'
                                   : ($b['file_name'] && preg_match('/^[\w.-]+$/', $b['file_name']) && is_file(__DIR__ . '/uploads/bulletins/' . $b['file_name']) ? 'uploads/bulletins/' . $b['file_name'] : null);
                            $cover = $b['cover_image'] && preg_match('/^[\w.-]+$/', $b['cover_image']) && is_file(__DIR__ . '/uploads/bulletins/' . $b['cover_image']) ? 'uploads/bulletins/' . $b['cover_image'] : null;
                        ?>
                            <article class="bul-card">
                                <div class="bul-cover">
                                    <?php if ($cover): ?>
                                        <img src="<?= e($cover) ?>" alt="Cover of <?= e($b['title']) ?>" loading="lazy">
                                    <?php elseif ($b['generated']): ?>
                                        <?= bulletin_cover_html($b['title'], $b['topic'], $b['bulletin_date'], $b['language'], $c['church_name']) ?>
                                    <?php else: ?>
                                        <span class="bul-cover-fallback" aria-hidden="true">
                                            <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/></svg>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="bul-body">
                                    <div class="bul-date-row">
                                        <time class="bul-date" datetime="<?= e($b['bulletin_date']) ?>"><?= e(date('F j, Y', strtotime($b['bulletin_date']))) ?></time>
                                        <?php if ($canManage): ?>
                                            <form method="post" action="announcements.php" class="bul-actions">
                                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                                <input type="hidden" name="id" value="<?= (int)$b['bulletin_id'] ?>">
                                                <a class="ico-btn sm" href="<?= $b['generated'] ? 'bulletin-builder.php' : 'bulletin-form.php' ?>?id=<?= (int)$b['bulletin_id'] ?>" title="Edit" aria-label="Edit bulletin"><?= ann_icon('pencil', 13) ?></a>
                                                <button type="submit" name="action" value="delete_bulletin" class="ico-btn sm danger" data-confirm="Delete this bulletin and its files? This cannot be undone." title="Delete" aria-label="Delete bulletin"><?= ann_icon('trash', 13) ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                    <h3><?= e($b['title']) ?></h3>
                                    <span class="bul-lang"><?= e(BULLETIN_LANGUAGES[$b['language']] ?? $b['language']) ?> Bulletin</span>
                                    <?php if ($file): ?>
                                        <a class="bul-download" href="<?= e($file) ?>"<?= $b['generated'] ? ' title="Opens the bulletin; choose Save as PDF"' : ' download' ?>>
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/></svg>
                                            Download
                                        </a>
                                    <?php else: ?>
                                        <span class="bul-download is-off">Unavailable</span>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($pages > 1): ?>
                        <nav class="bul-pager" aria-label="Bulletin pages">
                            <?php if ($page > 1): ?>
                                <a class="pg-arrow" href="<?= bulletins_url($selectedId, $q, $month, $lang, $page - 1) ?>" aria-label="Previous page">&lsaquo;</a>
                            <?php else: ?>
                                <span class="pg-arrow is-off" aria-hidden="true">&lsaquo;</span>
                            <?php endif; ?>
                            <?php for ($p = 1; $p <= $pages; $p++): ?>
                                <?php if ($p === $page): ?>
                                    <span class="pg-num is-current" aria-current="page"><?= $p ?></span>
                                <?php else: ?>
                                    <a class="pg-num" href="<?= bulletins_url($selectedId, $q, $month, $lang, $p) ?>" aria-label="Page <?= $p ?>"><?= $p ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                            <?php if ($page < $pages): ?>
                                <a class="pg-arrow" href="<?= bulletins_url($selectedId, $q, $month, $lang, $page + 1) ?>" aria-label="Next page">&rsaquo;</a>
                            <?php else: ?>
                                <span class="pg-arrow is-off" aria-hidden="true">&rsaquo;</span>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <?php if ($tab === 'announcements'): ?>
        <aside class="subscribe-card" id="stay-updated">
            <h2>Stay Updated</h2>
            <p>Receive the latest notifications and liturgical updates directly in your inbox.</p>

            <?php if ($flash): ?><div class="sub-msg ok" role="status"><?= e($flash) ?></div><?php endif; ?>
            <?php if ($subError): ?><div class="sub-msg bad" role="alert"><?= e($subError) ?></div><?php endif; ?>

            <form method="post" action="announcements.php?church=<?= $selectedId ?>#stay-updated" novalidate>
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="church" value="<?= $selectedId ?>">
                <input type="text" name="company" class="hp-field" tabindex="-1" autocomplete="off" aria-hidden="true">
                <label for="sub-email">Email Address</label>
                <input type="email" id="sub-email" name="email" placeholder="name@example.com" maxlength="100" autocomplete="email" value="<?= e($subEmail) ?>" required>
                <button type="submit">Subscribe</button>
            </form>
        </aside>
        <?php endif; ?>
    </div>
<?php endif; ?>
</main>
<script>
document.getElementById('church')?.addEventListener('change', function () {
    document.getElementById('church-form').submit();
});

// "Read More": expand a long announcement in place.
document.querySelectorAll('.ann-more').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var body = btn.previousElementSibling;
        var open = body.classList.toggle('is-clamped') === false;
        btn.setAttribute('aria-expanded', open);
        btn.firstChild.textContent = open ? 'Show Less ' : 'Read More ';
    });
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
