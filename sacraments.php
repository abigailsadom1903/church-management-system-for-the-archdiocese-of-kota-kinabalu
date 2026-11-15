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
<main class="sx-page sx-sacr">
    <?php back_button('index.php#services', 'Back to Services'); ?>

    <section class="sx-hero<?= $church ? '' : ' no-photo' ?>">
        <div class="sx-hero-text">
            <span class="sx-hero-eyebrow">Sacramental life<?= $church ? ' &middot; ' . e($church['church_name']) : '' ?></span>
            <h1>Sacraments</h1>
            <p>Apply for a sacrament for yourself or a loved one, send your documents online, and follow your request until it's confirmed.</p>
            <div class="sx-hero-acts">
                <a class="sx-hero-btn is-solid" href="#sx-list">Start an application <?= sx_icon('arrow', 15) ?></a>
                <?php if ($isUser): ?>
                    <a class="sx-hero-btn is-soft" href="#sx-mine-title">My applications <span class="sx-hero-count"><?= count($mine) ?></span></a>
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

    <?php if ($church): ?>
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

    <section class="sx-guide" aria-labelledby="sx-path-title">
        <span class="sx-guide-eyebrow">How it works</span>
        <h2 id="sx-path-title">Guided Application Path</h2>
        <p class="sx-guide-lede">Three simple steps from choosing a sacrament to hearing back from your parish.</p>
        <ol class="sx-guide-steps">
            <?php foreach ($steps as $i => [$icon, $title, $text]): ?>
                <li>
                    <span class="sx-guide-ico"><?= sx_icon($icon, 22) ?></span>
                    <span class="sx-guide-k">Step <?= $i + 1 ?></span>
                    <h3><?= e($title) ?></h3>
                    <p><?= e($text) ?></p>
                    <?php if ($i < count($steps) - 1): ?><span class="sx-guide-arrow" aria-hidden="true"><?= sx_icon('right', 16) ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>

    <section class="sx-list" id="sx-list" aria-label="Sacraments">
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
<?php
        // One row per application, with the label shown (a booked confession counts as "Confirmed"), and the filter tabs.
        $rows = [];
        $tabs = [];
        foreach ($mine as $m) {
            $d = json_decode((string)$m['details'], true) ?: [];
            [$stLabel, $stClass] = sx_status($m['status']);
            if ($m['sacrament'] === 'confession' && $m['status'] === 'submitted') { $stLabel = 'Confirmed'; $stClass = 'is-ok'; }
            $isSlot = $m['sacrament'] === 'confession' && !empty($d['date']);
            $rows[] = [$m, $stLabel, $stClass, $isSlot,
                $isSlot ? date('j M Y', strtotime($d['date'])) . ', ' . sx_time_label((string)($d['time'] ?? '')) : sx_subject($m['sacrament'], $d)];
            $tabs[$stLabel] = ($tabs[$stLabel] ?? 0) + 1;
        }
        ?>
        <section class="sx-mine" aria-labelledby="sx-mine-title">
            <div class="sx-mine-head">
                <div>
                    <h2 id="sx-mine-title">My applications</h2>
                    <p>Track every request you&rsquo;ve sent to the parish.</p>
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
                <p class="ann-empty">You have not started an application yet. Choose a sacrament above to begin.</p>
            <?php else: ?>
                <div class="sx-apps">
                    <table class="sx-apps-table">
                        <thead><tr><th>Sacrament</th><th>For</th><th>Status</th><th>Updated</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as [$m, $stLabel, $stClass, $isSlot, $for]):
                            [$name, $icon, $colour] = SACRAMENTS[$m['sacrament']] ?? [ucfirst($m['sacrament']), 'church', '#5b2d82'];
                            $img = is_file(__DIR__ . '/assets/images/sacraments/' . $m['sacrament'] . '.jpg') ? 'assets/images/sacraments/' . $m['sacrament'] . '.jpg' : null;
                            $isDraft = $m['status'] === 'draft';
                        ?>
                            <tr data-s="<?= e($stLabel) ?>">
                                <td data-label="Sacrament">
                                    <span class="sx-app">
                                        <span class="sx-app-av" style="--sx: <?= e($colour) ?>"><?= $img ? '<img src="' . e($img) . '" alt="" loading="lazy">' : sx_icon($icon, 18) ?></span>
                                        <span><b><?= e($name) ?></b><small><?= e($m['church_name']) ?></small></span>
                                    </span>
                                </td>
                                <td data-label="For"><?php if ($for): ?><span class="sx-app-for"><?= sx_icon($isSlot ? 'clock' : 'user', 14) ?> <?= e($for) ?></span><?php else: ?>&mdash;<?php endif; ?></td>
                                <td data-label="Status"><span class="sx-pill <?= $stClass ?>"><?= e($stLabel) ?></span></td>
                                <td data-label="Updated" class="sx-app-date"><?= e(date('j M Y', strtotime($m['updated_at']))) ?></td>
                                <td data-label="Action"><a class="sx-app-btn" href="<?= $isDraft ? 'sacrament-apply.php?type=' . e($m['sacrament']) . '&amp;id=' : 'sacrament-status.php?id=' ?><?= (int)$m['application_id'] ?>"><?= $isDraft ? 'Continue' : 'View' ?> <?= sx_icon('right', 13) ?></a></td>
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
