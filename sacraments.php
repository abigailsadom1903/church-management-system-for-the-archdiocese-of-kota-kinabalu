<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/login_gate.php';
require_signed_in('Sacraments');   // visitors get "please login or register" instead of this page
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/sacrament_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$role = $_SESSION['role'] ?? '';
$isUser = $role === 'user';

// The church shown: a parishioner's own parish, a Church Admin's own church; the Super Admin can pick any.
$churches = $pdo->query('SELECT church_info_id, church_name, church_location, church_photo FROM church_info ORDER BY church_name')->fetchAll();
$byId = array_column($churches, null, 'church_info_id');
$requested = $role === 'super_admin' ? (int)($_GET['church'] ?? 0) : 0;
$churchId = pick_church_id($pdo, $byId, $requested);
$church = $byId[$churchId] ?? null;
$photo = $church ? church_photo_url($church['church_photo']) : null;

$flash = $_SESSION['sacrament_flash'] ?? null;
unset($_SESSION['sacrament_flash']);

// Which of this church's sacrament forms are closed (switched off, or past their last day to apply) and
// which have a deadline coming up (see sacrament-schedule.php).
$intakes = $churchId ? sx_intakes($pdo, $churchId) : [];
$closed = array_keys(array_filter($intakes, fn($i) => !$i['open']));

// My applications (public users only).
$mine = [];
if ($isUser) {
    $stmt = $pdo->prepare(
        'SELECT a.application_id, a.sacrament, a.status, a.details, a.updated_at, c.church_name
           FROM sacrament_applications a JOIN church_info c ON c.church_info_id = a.church_id
          WHERE a.user_id = ? ORDER BY a.updated_at DESC, a.application_id DESC'
    );
    $stmt->execute([(int)$_SESSION['user_id']]);
    $mine = $stmt->fetchAll();
}

$steps = [
    ['dots',   'Select Sacrament',   'Choose the sacrament that fits your spiritual journey from our directory'],
    ['upload', 'Submit Documents',   'Upload necessary identification and supporting documents'],
    ['check',  'Admin Review',       'Your church will review your request'],
];

$pageTitle = 'Sacraments';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="sx-page">
    <?php back_button('index.php#services', 'Back to Services'); ?>

    <header class="page-heading">
        <h1>Sacraments</h1>
    </header>

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
            <form method="get" class="sx-pick" id="sx-pick">
                <label for="sx-church">Showing</label>
                <select id="sx-church" name="church" onchange="this.form.submit()">
                    <?php foreach ($churches as $c): ?>
                        <option value="<?= (int)$c['church_info_id'] ?>" <?= (int)$c['church_info_id'] === $churchId ? 'selected' : '' ?>><?= e($c['church_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="btn-save">Show</button></noscript>
            </form>
        <?php endif; ?>
    <?php elseif ($isUser): ?>
        <p class="ann-empty">Choose your home parish on <a href="profile.php">your profile</a> so we can show your church here.</p>
    <?php endif; ?>

    <section class="sx-path" aria-labelledby="sx-path-title">
        <h2 id="sx-path-title">Guided Application Path</h2>
        <ol class="sx-steps">
            <?php foreach ($steps as $i => [$icon, $title, $text]): ?>
                <li>
                    <span class="sx-step-ico"><?= sx_icon($icon, 30) ?></span>
                    <h3><?= e($title) ?></h3>
                    <p><?= e($text) ?></p>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>

    <section class="sx-list" aria-label="Sacraments">
        <?php foreach (SACRAMENTS as $key => [$name, $icon, $colour, $ready]):
            $isClosed = $ready && in_array($key, $closed, true);
            // A photo in assets/images/sacraments/<key>.jpg replaces the line icon in the circle.
            $photo = is_file(__DIR__ . '/assets/images/sacraments/' . $key . '.jpg') ? 'assets/images/sacraments/' . $key . '.jpg' : null;
            $circle = $photo
                ? '<span class="sx-circle has-photo"><img src="' . e($photo) . '" alt="" loading="lazy"></span>'
                : '<span class="sx-circle">' . sx_icon($icon, 54) . '</span>';
        ?>
            <?php if ($isClosed): ?>
                <span class="sx-item is-closed" style="--sx: <?= e($colour) ?>">
                    <?= $circle ?>
                    <span class="sx-name"><?= e($name) ?></span>
                    <span class="sx-soon is-closed">Applications closed</span>
                </span>
            <?php else: ?>
                <a class="sx-item" href="sacrament-apply.php?type=<?= e($key) ?>" style="--sx: <?= e($colour) ?>">
                    <?= $circle ?>
                    <span class="sx-name"><?= e($name) ?></span>
                    <?php if (!$ready): ?><span class="sx-soon">Form coming soon</span>
                    <?php elseif (!empty($intakes[$key]['until'])): ?><span class="sx-soon is-deadline">Apply by <?= e(sx_day($intakes[$key]['until'])) ?></span><?php endif; ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </section>

    <?php if ($isUser): ?>
        <section class="sx-mine" aria-labelledby="sx-mine-title">
            <h2 id="sx-mine-title">My Applications</h2>
            <?php if (!$mine): ?>
                <p class="ann-empty">You have not started an application yet. Choose a sacrament above to begin.</p>
            <?php else: ?>
                <div class="reg-table-wrap">
                    <table class="reg-table">
                        <thead><tr><th>Sacrament</th><th>For</th><th>Status</th><th>Updated</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($mine as $m):
                            $d = json_decode((string)$m['details'], true) ?: [];
                            [$stLabel, $stClass] = sx_status($m['status']);
                            if ($m['sacrament'] === 'confession' && $m['status'] === 'submitted') $stLabel = 'Confirmed';
                            $name = SACRAMENTS[$m['sacrament']][0] ?? ucfirst($m['sacrament']);
                        ?>
                            <tr>
                                <td data-label="Sacrament"><b><?= e($name) ?></b><small><?= e($m['church_name']) ?></small></td>
                                <td data-label="For"><?= e($m['sacrament'] === 'confession' && !empty($d['date']) ? date('j M Y', strtotime($d['date'])) . ', ' . sx_time_label((string)($d['time'] ?? '')) : sx_subject($m['sacrament'], $d)) ?: '&mdash;' ?></td>
                                <td data-label="Status"><span class="sx-badge <?= $stClass ?>"><?= e($stLabel) ?></span></td>
                                <td data-label="Updated"><?= e(date('j M Y', strtotime($m['updated_at']))) ?></td>
                                <td data-label="Action"><a class="sx-link" href="<?= $m['status'] === 'draft' ? 'sacrament-apply.php?type=' . e($m['sacrament']) . '&amp;id=' : 'sacrament-status.php?id=' ?><?= (int)$m['application_id'] ?>"><?= $m['status'] === 'draft' ? 'Continue' : 'View' ?></a></td>
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
