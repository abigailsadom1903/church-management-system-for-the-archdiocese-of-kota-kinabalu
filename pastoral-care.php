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
    ['clipboard', 'Submit Request', 'Fill out the online request form with your needs'],
    ['check', 'Admin Review', 'Your church will review your request'],
    ['heartplus', 'Spiritual and Pastoral Support', 'Receive the requested ministry, counselling or prayer from our dedicated team'],
];

$pageTitle = 'Pastoral & Spiritual Care';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="sx-page pc-page">
    <?php back_button('index.php#services', 'Back to Services'); ?>

    <header class="page-heading"><h1>Pastoral &amp; Spiritual Care</h1></header>

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
                <label for="pc-church">Showing</label>
                <select id="pc-church" name="church" onchange="this.form.submit()">
                    <?php foreach ($churches as $c): ?><option value="<?= (int)$c['church_info_id'] ?>" <?= (int)$c['church_info_id'] === $churchId ? 'selected' : '' ?>><?= e($c['church_name']) ?></option><?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="btn-save">Show</button></noscript>
            </form>
        <?php endif; ?>
    <?php elseif ($isUser): ?>
        <p class="ann-empty">Choose your home parish on <a href="profile.php">your profile</a> so we can send your request to the right church.</p>
    <?php endif; ?>

    <section class="sx-path" aria-labelledby="pc-path-title">
        <h2 id="pc-path-title">Guided Application Path</h2>
        <ol class="sx-steps">
            <?php foreach ($steps as [$icon, $title, $text]): ?>
                <li>
                    <span class="sx-step-ico"><?= pc_icon($icon, 32) ?></span>
                    <h3><?= e($title) ?></h3>
                    <p><?= e($text) ?></p>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>

    <section class="pc-services" aria-labelledby="pc-services-title">
        <h2 id="pc-services-title" class="pc-heading">Services</h2>
        <div class="pc-grid">
            <?php // The services this church offers (set by its Church Admin on pastoral-services.php).
            $offered = pc_services($pdo, $churchId);
            foreach ($offered as $key => $svc):
                $title = $svc['title'];
                $text = $svc['description'];
                $isOpen = (bool)(int)$svc['is_open'];
                $cover = pc_cover_url($key, $svc['photo']);   // the church's photo, else assets/images/pastoral/<key>.jpg, else the icon ?>
                <article class="pc-card<?= $isOpen ? '' : ' is-closed' ?>">
                    <div class="pc-cover<?= $cover ? ' has-photo' : '' ?>">
                        <?php if ($cover): ?><img src="<?= e($cover) ?>" alt="" loading="lazy"><?php else: ?><?= pc_cover_icon($key) ?><?php endif; ?>
                    </div>
                    <div class="pc-body">
                        <h3><?= e($title) ?></h3>
                        <p><?= e($text) ?></p>
                        <?php if ($isOpen): ?>
                            <a class="pc-btn" href="pastoral-request.php?service=<?= e($key) ?>" aria-label="Request: <?= e($title) ?>">Request</a>
                        <?php else: ?>
                            <span class="pc-btn is-closed" role="note">Requests closed</span>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if (!$offered): ?><p class="ann-empty">This church is not offering pastoral services online at the moment. Please contact the parish office.</p><?php endif; ?>
    </section>

    <?php if ($isUser): ?>
        <section class="sx-mine" aria-labelledby="pc-mine-title">
            <h2 id="pc-mine-title">My Requests</h2>
            <?php if (!$mine): ?>
                <p class="ann-empty">You have not sent a request yet. Choose a service above to begin.</p>
            <?php else: ?>
                <div class="reg-table-wrap">
                    <table class="reg-table">
                        <thead><tr><th>Request</th><th>About</th><th>Status</th><th>Sent</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($mine as $m):
                            $d = json_decode((string)$m['details'], true) ?: [];
                            [$stLabel, $stClass] = pc_status($m['status']);
                        ?>
                            <tr>
                                <td data-label="Request"><b><?= e(pc_title($pdo, $m['service'], (int)$m['church_id'])) ?></b><small><?= e(pc_ref((int)$m['request_id'])) ?> &middot; <?= e($m['church_name']) ?></small></td>
                                <td data-label="About"><?= e(pc_summary($m['service'], $d)) ?: '&mdash;' ?><?php if ($m['admin_note']): ?><small><b>Note from the church:</b> <?= e($m['admin_note']) ?></small><?php endif; ?></td>
                                <td data-label="Status"><span class="sx-badge <?= $stClass ?>"><?= e($stLabel) ?></span></td>
                                <td data-label="Sent"><?= e(date('j M Y', strtotime($m['submitted_at'] ?: $m['created_at']))) ?></td>
                                <td data-label="Action"><a class="sx-link" href="<?= $m['status'] === 'draft' ? 'pastoral-request.php?service=' . e($m['service']) . '&amp;id=' : 'pastoral-status.php?id=' ?><?= (int)$m['request_id'] ?>"><?= $m['status'] === 'draft' ? 'Continue' : 'View' ?></a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
