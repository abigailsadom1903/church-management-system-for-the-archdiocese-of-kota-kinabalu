<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/login_gate.php';
require_signed_in('Pastoral & Spiritual Care');   // visitors get "please login or register" instead of this page
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/sacrament_helpers.php';
require __DIR__ . '/includes/pastoral_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$role = $_SESSION['role'] ?? '';
$isUser = $role === 'user';

// The church shown: a parishioner's own parish, a Church Admin's own church; the Super Admin can pick any.
$churches = $pdo->query('SELECT church_info_id, church_name, church_location, church_photo FROM church_info ORDER BY church_name')->fetchAll();
$byId = array_column($churches, null, 'church_info_id');
$churchId = pick_church_id($pdo, $byId, $role === 'super_admin' ? (int)($_GET['church'] ?? 0) : 0);
$church = $byId[$churchId] ?? null;
$photo = $church ? church_photo_url($church['church_photo']) : null;

$flash = $_SESSION['pastoral_flash'] ?? null;
unset($_SESSION['pastoral_flash']);

$mine = [];
if ($isUser) {
    $stmt = $pdo->prepare('SELECT r.request_id, r.service, r.church_id, r.status, r.details, r.admin_note, r.created_at, r.submitted_at, c.church_name FROM pastoral_requests r JOIN church_info c ON c.church_info_id = r.church_id WHERE r.user_id = ? ORDER BY r.created_at DESC, r.request_id DESC');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $mine = $stmt->fetchAll();
}


$steps = [
    ['clipboard', 'Send your request', 'Fill out a short online form telling us what you need.'],
    ['check', 'Parish review', 'Your church reviews the request and gets back to you.'],
    ['heartplus', 'Receive care', 'Our team offers the ministry, counselling or prayer you asked for.'],
];
$offered = pc_services($pdo, $churchId);   // the services this church offers (set by its Church Admin on pastoral-services.php)

// My requests: one row each, plus the status filter tabs.
$rows = [];
$tabs = [];
foreach ($mine as $m) {
    [$stLabel, $stClass] = pc_status($m['status']);
    $rows[] = [$m, $stLabel, $stClass, json_decode((string)$m['details'], true) ?: []];
    $tabs[$stLabel] = ($tabs[$stLabel] ?? 0) + 1;
}

$pageTitle = 'Pastoral & Spiritual Care';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="sx-page sx-sacr pc-page">
    <?php back_button('index.php#services', 'Back to Services'); ?>

    <section class="sx-hero<?= $church ? '' : ' no-photo' ?>">
        <div class="sx-hero-text">
            <span class="sx-hero-eyebrow">Care for one another<?= $church ? ' &middot; ' . e($church['church_name']) : '' ?></span>
            <h1>Pastoral &amp; Spiritual Care</h1>
            <p>Ask for a Mass to be offered, share a prayer intention, or invite a priest to be with you and your family when you need it most.</p>
            <div class="sx-hero-acts">
                <a class="sx-hero-btn is-solid" href="#pc-services-title">Make a request <?= sx_icon('arrow', 15) ?></a>
                <?php if ($isUser): ?>
                    <a class="sx-hero-btn is-soft" href="#pc-mine-title">My requests <span class="sx-hero-count"><?= count($mine) ?></span></a>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($church): ?>
            <figure class="sx-hero-photo">
                <?php if ($photo): ?>
                    <img src="<?= e($photo) ?>" alt="<?= e($church['church_name']) ?>">
                <?php else: ?>
                    <span class="sx-hero-ph"><?= sx_icon('church', 54) ?></span>
                <?php endif; ?>
                <figcaption>
                    <span class="sx-hero-pin"><?= sx_icon('pin', 16) ?></span>
                    <span><b><?= e($church['church_name']) ?></b><?php if ($church['church_location']): ?><small><?= e($church['church_location']) ?></small><?php endif; ?></span>
                </figcaption>
            </figure>
        <?php endif; ?>
    </section>

    <div class="sx-band">
    <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>

    <?php if ($church && $role === 'super_admin'): ?>
        <form method="get" class="sx-pick">
            <label for="pc-church">Showing</label>
            <select id="pc-church" name="church" onchange="this.form.submit()">
                <?php foreach ($churches as $c): ?><option value="<?= (int)$c['church_info_id'] ?>" <?= (int)$c['church_info_id'] === $churchId ? 'selected' : '' ?>><?= e($c['church_name']) ?></option><?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn-save">Show</button></noscript>
        </form>
    <?php elseif (!$church && $isUser): ?>
        <p class="ann-empty">Choose your home parish on <a href="profile.php">your profile</a> so we can send your request to the right church.</p>
    <?php endif; ?>

    <section class="sx-guide" aria-labelledby="pc-path-title">
        <span class="sx-guide-eyebrow">How it works</span>
        <h2 id="pc-path-title">Three simple steps</h2>
        <ol class="sx-guide-steps">
            <?php foreach ($steps as $i => [$icon, $title, $text]): ?>
                <li>
                    <span class="sx-guide-ico"><?= pc_icon($icon, 22) ?></span>
                    <span class="sx-guide-k">Step <?= $i + 1 ?></span>
                    <h3><?= e($title) ?></h3>
                    <p><?= e($text) ?></p>
                    <?php if ($i < count($steps) - 1): ?><span class="sx-guide-arrow" aria-hidden="true"><?= sx_icon('right', 16) ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>

    <section class="pcx-services" aria-labelledby="pc-services-title">
        <h2 id="pc-services-title" class="pcx-title">How can we support you?</h2>
        <p class="pcx-sub">Choose a service to start your request.</p>
        <?php if ($offered): ?>
            <div class="pcx-grid">
                <?php foreach ($offered as $key => $svc):
                    $isOpen = (bool)(int)$svc['is_open'];
                    $cover = pc_cover_url($key, $svc['photo']);   // the church's photo, else assets/images/pastoral/<key>.jpg, else the icon ?>
                    <article class="pcx-card<?= $isOpen ? '' : ' is-closed' ?>">
                        <div class="pcx-cover<?= $cover ? ' has-photo' : '' ?>">
                            <?php if ($cover): ?><img src="<?= e($cover) ?>" alt="" loading="lazy"><?php else: ?><?= pc_cover_icon($key, 46) ?><?php endif; ?>
                            <?php if (!$isOpen): ?><span class="pcx-tag">Not taking requests</span><?php endif; ?>
                            <span class="pcx-badge"><?= pc_cover_icon($key, 16) ?></span>
                        </div>
                        <div class="pcx-body">
                            <h3><?= e($svc['title']) ?></h3>
                            <p><?= e($svc['description']) ?></p>
                            <?php if ($isOpen): ?>
                                <a class="pcx-btn" href="pastoral-request.php?service=<?= e($key) ?>" aria-label="Request: <?= e($svc['title']) ?>">Request <?= sx_icon('arrow', 14) ?></a>
                            <?php else: ?>
                                <span class="pcx-btn is-closed" role="note">Requests closed</span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="ann-empty">This church is not offering pastoral services online at the moment. Please contact the parish office.</p>
        <?php endif; ?>
    </section>

    <?php if ($isUser): ?>
        <section class="sx-mine" aria-labelledby="pc-mine-title">
            <div class="sx-mine-head">
                <div>
                    <h2 id="pc-mine-title">My requests</h2>
                    <p>Follow each request and read any note from the parish.</p>
                </div>
                <?php if (count($tabs) > 1): ?>
                    <div class="sx-tabs" role="group" aria-label="Filter by status">
                        <button type="button" class="is-on" data-f="" aria-pressed="true">All <span><?= count($rows) ?></span></button>
                        <?php foreach ($tabs as $label => $n): ?>
                            <button type="button" data-f="<?= e($label) ?>" aria-pressed="false"><?= e($label) ?> <span><?= $n ?></span></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (!$rows): ?>
                <p class="ann-empty">You have not sent a request yet. Choose a service above to begin.</p>
            <?php else: ?>
                <div class="sx-apps">
                    <table class="sx-apps-table">
                        <thead><tr><th>Request</th><th>About</th><th>Status</th><th>Sent</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as [$m, $stLabel, $stClass, $d]):
                            $isDraft = $m['status'] === 'draft';
                            $img = pc_cover_url($m['service'], null);
                            $about = pc_summary($m['service'], $d);
                        ?>
                            <tr data-s="<?= e($stLabel) ?>">
                                <td data-label="Request">
                                    <span class="sx-app">
                                        <span class="sx-app-av pcx-av"><?= $img ? '<img src="' . e($img) . '" alt="" loading="lazy">' : pc_cover_icon($m['service'], 18) ?></span>
                                        <span><b><?= e(pc_title($pdo, $m['service'], (int)$m['church_id'])) ?></b><small><?= e(pc_ref((int)$m['request_id'])) ?> &middot; <?= e($m['church_name']) ?></small></span>
                                    </span>
                                </td>
                                <td data-label="About">
                                    <span class="pcx-about"><?= $about !== '' ? e($about) : '&mdash;' ?>
                                        <?php if ($m['admin_note']): ?><span class="pcx-note"><?= sx_icon('dialogue', 12) ?> <b>Parish note:</b> <?= e($m['admin_note']) ?></span><?php endif; ?>
                                    </span>
                                </td>
                                <td data-label="Status"><span class="sx-pill <?= $stClass ?>"><?= e($stLabel) ?></span></td>
                                <td data-label="Sent" class="sx-app-date"><?= e(date('j M Y', strtotime($m['submitted_at'] ?: $m['created_at']))) ?></td>
                                <td data-label="Action"><a class="sx-app-btn" href="<?= $isDraft ? 'pastoral-request.php?service=' . e($m['service']) . '&amp;id=' : 'pastoral-status.php?id=' ?><?= (int)$m['request_id'] ?>"><?= $isDraft ? 'Continue' : 'View' ?> <?= sx_icon('right', 13) ?></a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <script>
                (function () {
                    var tabs = document.querySelectorAll('.sx-tabs button');
                    tabs.forEach(function (b) {
                        b.addEventListener('click', function () {
                            var f = b.getAttribute('data-f');
                            tabs.forEach(function (t) { var on = t === b; t.classList.toggle('is-on', on); t.setAttribute('aria-pressed', on ? 'true' : 'false'); });
                            document.querySelectorAll('.sx-apps-table tbody tr').forEach(function (r) { r.hidden = !!f && r.getAttribute('data-s') !== f; });
                        });
                    });
                })();
                </script>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
