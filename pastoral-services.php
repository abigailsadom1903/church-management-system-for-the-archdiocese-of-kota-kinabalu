<?php
// Pastoral & Spiritual Care: the services a church offers.
// A Church Admin adds, changes, closes, removes and restores the services of their own church.
// The Super Admin can look at any church's services but not change them.
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/sacrament_helpers.php';
require __DIR__ . '/includes/pastoral_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$role = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($role, ['church_admin', 'super_admin'], true)) {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$isSuper = $role === 'super_admin';
$canEdit = !$isSuper;   // the Super Admin monitors; the Church Admin manages
$dashboard = $isSuper ? 'superadmin/dashboard.php' : 'churchadmin/dashboard.php';

$churches = $pdo->query('SELECT church_info_id, church_name FROM church_info ORDER BY church_name')->fetchAll();
$byId = array_column($churches, null, 'church_info_id');
$churchId = pick_church_id($pdo, $byId, $isSuper ? (int)($_GET['church'] ?? 0) : 0);   // a Church Admin's own church, never from the request
if (!can_manage_church($pdo, $churchId)) {
    header('Location: ' . $dashboard);
    exit;
}
$church = $byId[$churchId];
$self = 'pastoral-services.php' . ($isSuper ? '?church=' . $churchId : '');

$flash = $_SESSION['pastoral_flash'] ?? null;
unset($_SESSION['pastoral_flash']);

$services = pc_services($pdo, $churchId, true);
$key = (string)($_GET['edit'] ?? $_POST['key'] ?? '');
$isNew = isset($_GET['new']) || ($_POST['key'] ?? null) === '';
$editing = $key !== '' && isset($services[$key]) && $services[$key]['deleted_at'] === null ? $services[$key] : null;

$f = ['title' => $editing['title'] ?? '', 'description' => $editing['description'] ?? ''];
$errors = [];

// ---- POST: open/close, remove, restore, save ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $row = $services[$key] ?? null;
    $back = function (string $msg) use ($self): never {
        $_SESSION['pastoral_flash'] = $msg;
        header('Location: ' . $self);
        exit;
    };
    if ($isSuper) {
        $back('The Super Admin can view the services but not change them.');
    } elseif (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $back('Your session expired. Please try again.');
    } elseif (in_array($action, ['toggle', 'remove', 'restore'], true)) {
        if (!$row || !(int)$row['service_id']) $back('That service could not be found.');
        if ($action === 'toggle' && $row['deleted_at'] === null) {
            $open = (int)$row['is_open'] ? 0 : 1;
            $pdo->prepare('UPDATE pastoral_services SET is_open = ? WHERE service_id = ? AND church_id = ?')->execute([$open, $row['service_id'], $churchId]);
            $back($row['title'] . ($open ? ' is open for requests again.' : ' is closed: parishioners can still see it, but cannot send a new request.'));
        } elseif ($action === 'remove' && $row['deleted_at'] === null) {
            $pdo->prepare('UPDATE pastoral_services SET deleted_at = NOW() WHERE service_id = ? AND church_id = ?')->execute([$row['service_id'], $churchId]);
            $back($row['title'] . ' was deleted. Requests already sent are kept, and you can restore the service below.');
        } elseif ($action === 'restore' && $row['deleted_at'] !== null) {
            $pdo->prepare('UPDATE pastoral_services SET deleted_at = NULL WHERE service_id = ? AND church_id = ?')->execute([$row['service_id'], $churchId]);
            $back($row['title'] . ' was restored.');
        }
        $back('Nothing was changed.');
    } elseif ($action === 'save') {
        if (!$isNew && !$editing) $back('That service could not be found.');
        $f['title'] = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 60);
        $f['description'] = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($_POST['description'] ?? ''))), 0, 255);
        if ($f['title'] === '') $errors['title'] = 'Enter the name of the service.';
        if ($f['description'] === '') $errors['description'] = 'Describe the service in a sentence.';
        foreach ($services as $k => $s) {   // two cards with the same name would confuse parishioners
            if ($k !== $key && $s['deleted_at'] === null && mb_strtolower($s['title']) === mb_strtolower($f['title'])) $errors['title'] = 'Your church already has a service with this name.';
        }
        if ($isNew && count(array_filter($services, fn($s) => $s['deleted_at'] === null)) >= 24) $errors['form'] = 'A church can offer up to 24 services. Please delete one first.';

        // Cover photo (optional): JPG, PNG or WebP up to 3 MB, checked by its content.
        $up = $_FILES['photo'] ?? null;
        $photoExt = null;
        if ($up && $up['error'] !== UPLOAD_ERR_NO_FILE) {
            $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
            $info = $up['error'] === UPLOAD_ERR_OK ? @getimagesize($up['tmp_name']) : false;
            if ($up['error'] !== UPLOAD_ERR_OK) $errors['photo'] = 'The photo could not be uploaded. Please try again.';
            elseif ($up['size'] > 3 * 1024 * 1024) $errors['photo'] = 'The photo must be 3 MB or smaller.';
            elseif (!$info || !isset($types[$info[2]])) $errors['photo'] = 'Please choose a JPG, PNG or WebP image.';
            else $photoExt = $types[$info[2]];
        }

        if (!$errors) {
            if ($isNew) {
                $key = 'c_' . bin2hex(random_bytes(5));
                $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM pastoral_services WHERE church_id = ?');
                $stmt->execute([$churchId]);
                $pdo->prepare('INSERT INTO pastoral_services (church_id, service_key, title, description, sort_order) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$churchId, $key, $f['title'], $f['description'], (int)$stmt->fetchColumn()]);
                $serviceId = (int)$pdo->lastInsertId();
            } else {
                $serviceId = (int)$editing['service_id'];
                $pdo->prepare('UPDATE pastoral_services SET title = ?, description = ? WHERE service_id = ? AND church_id = ?')->execute([$f['title'], $f['description'], $serviceId, $churchId]);
            }
            $note = '';
            $oldPhoto = $editing['photo'] ?? null;
            if ($photoExt) {
                $dir = __DIR__ . '/uploads/pastoral_photos';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $file = 'service-' . $serviceId . '-' . bin2hex(random_bytes(6)) . '.' . $photoExt;
                if (move_uploaded_file($up['tmp_name'], $dir . '/' . $file)) {
                    $pdo->prepare('UPDATE pastoral_services SET photo = ? WHERE service_id = ? AND church_id = ?')->execute([$file, $serviceId, $churchId]);
                    pc_delete_photo($oldPhoto);
                } else {
                    $note = ' The photo could not be saved.';
                }
            } elseif ($oldPhoto && !empty($_POST['remove_photo'])) {
                $pdo->prepare('UPDATE pastoral_services SET photo = NULL WHERE service_id = ? AND church_id = ?')->execute([$serviceId, $churchId]);
                pc_delete_photo($oldPhoto);
            }
            $back($f['title'] . ($isNew ? ' was added.' : ' was updated.') . $note);
        }
    }
}

// ---- The form: a new service, or one being changed ---------------------------------------------------
if ($canEdit && ($isNew || $editing)) {
    $coverUrl = $editing ? pc_cover_url($editing['service_key'], $editing['photo']) : null;
    $ownPhoto = $editing ? pc_photo_url($editing['photo']) : null;
    $standard = $editing && !pc_is_custom($editing['service_key']);
    $fc = fn(string $k) => isset($errors[$k]) ? 'pfield has-error' : 'pfield';
    $fe = fn(string $k) => isset($errors[$k]) ? '<span class="row-error">' . e($errors[$k]) . '</span>' : '';

    $pageTitle = $editing ? 'Edit Service' : 'New Service';
    $activeNav = 'services';
    include __DIR__ . '/includes/header.php';
    ?>
    <main class="sx-page sx-form-page">
        <?php back_button('pastoral-services.php', 'Back to services'); ?>
        <header class="page-heading"><h1><?= $editing ? 'Edit Service' : 'New Service' ?></h1><p><?= e($church['church_name']) ?></p></header>

        <div class="sx-shell">
            <form method="post" action="pastoral-services.php<?= $editing ? '?edit=' . e($editing['service_key']) : '?new=1' ?>" class="sx-form" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="key" value="<?= e($editing['service_key'] ?? '') ?>">
                <?php if (isset($errors['form'])): ?><div class="alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>

                <section class="sx-section">
                    <div class="<?= $fc('photo') ?> nc-photo-field">
                        <label class="nc-photo" for="ps-photo" id="ps-drop" title="Choose a cover photo for this service">
                            <img id="ps-preview" alt="" <?= $coverUrl ? 'src="' . e($coverUrl) . '"' : 'hidden' ?>>
                            <span class="nc-photo-empty ps-photo-empty" id="ps-empty"<?= $coverUrl ? ' hidden' : '' ?>><?= pc_cover_icon($editing['service_key'] ?? '', 40) ?><span>Add a photo</span></span>
                        </label>
                        <input type="file" id="ps-photo" name="photo" accept="image/jpeg,image/png,image/webp" data-adjust="2:1" class="sr-only-file">
                        <span class="file-note">Optional &middot; JPG, PNG or WebP, up to 3 MB. Click or drop a photo above. Without one, the card shows an icon.</span>
                        <?php if ($ownPhoto): ?><label class="cat-photo-remove"><input type="checkbox" name="remove_photo" value="1"> Remove the current photo</label><?php endif; ?>
                        <?= $fe('photo') ?>
                    </div>
                    <div class="sx-grid">
                        <div class="<?= $fc('title') ?> is-full"><label for="title">Name of the service</label><input type="text" id="title" name="title" maxlength="60" placeholder="e.g. Blessing of Vehicles" value="<?= e($f['title']) ?>"><?= $fe('title') ?></div>
                        <div class="<?= $fc('description') ?> is-full"><label for="description">Description <span class="opt">(shown on the card)</span></label><textarea id="description" name="description" rows="3" maxlength="255" placeholder="One or two sentences that tell parishioners what this is for"><?= e($f['description']) ?></textarea><?= $fe('description') ?></div>
                    </div>
                    <p class="sx-hint ps-form-note"><?= $standard
                        ? 'This is one of the standard services, so parishioners keep filling in its own request form. You are changing only its name, description and photo.'
                        : 'Parishioners will fill in a short request form for this service: what they need, a preferred date and their contact details.' ?></p>
                </section>

                <div class="sx-actions">
                    <a class="sx-draft" href="pastoral-services.php">Cancel</a>
                    <button type="submit" class="reg-submit"><?= $editing ? 'Save' : 'Add service' ?></button>
                </div>
            </form>
        </div>
    </main>
    <script>
    // Photo: click the picture or drop a file on it, and see it straight away.
    (function () {
        var input = document.getElementById('ps-photo'), drop = document.getElementById('ps-drop');
        var img = document.getElementById('ps-preview'), empty = document.getElementById('ps-empty');
        function show() {
            var file = input.files[0];
            if (!file || file.type.indexOf('image/') !== 0) return;
            img.src = URL.createObjectURL(file);
            img.hidden = false;
            empty.hidden = true;
        }
        input.addEventListener('change', show);
        ['dragenter', 'dragover'].forEach(function (t) { drop.addEventListener(t, function (ev) { ev.preventDefault(); drop.classList.add('is-drag'); }); });
        ['dragleave', 'drop'].forEach(function (t) { drop.addEventListener(t, function (ev) { ev.preventDefault(); drop.classList.remove('is-drag'); }); });
        drop.addEventListener('drop', function (ev) { if (ev.dataTransfer.files.length) { input.files = ev.dataTransfer.files; input.dispatchEvent(new Event('change', { bubbles: true })); } });
    })();
    </script>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---- The list ---------------------------------------------------------------------------------------
$stmt = $pdo->prepare("SELECT service, COUNT(*) AS total, COALESCE(SUM(status = 'submitted'), 0) AS waiting FROM pastoral_requests WHERE church_id = ? AND status <> 'draft' GROUP BY service");
$stmt->execute([$churchId]);
$counts = array_column($stmt->fetchAll(), null, 'service');
$live = array_filter($services, fn($s) => $s['deleted_at'] === null);
$removed = array_filter($services, fn($s) => $s['deleted_at'] !== null);
$openCount = count(array_filter($live, fn($s) => (int)$s['is_open']));

$pageTitle = 'Pastoral & Spiritual Care Services';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="sx-page sr-page ps-page">
    <?php back_button($dashboard, 'Back to dashboard'); ?>
    <header class="page-heading"><h1>Pastoral &amp; Spiritual Care Services</h1><p><?= e($church['church_name']) ?></p></header>
    <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>

    <?php if ($isSuper): ?>
        <form method="get" class="sr-church">
            <label for="ps-church">Select Church</label>
            <select id="ps-church" name="church" onchange="this.form.submit()">
                <?php foreach ($churches as $c): ?><option value="<?= (int)$c['church_info_id'] ?>" <?= (int)$c['church_info_id'] === $churchId ? 'selected' : '' ?>><?= e($c['church_name']) ?></option><?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn-save">Show</button></noscript>
        </form>
        <p class="ps-readonly">You are viewing this church's services. Only its Church Admin can add, change, close or delete them.</p>
    <?php endif; ?>

    <div class="ps-bar">
        <p class="ps-summary"><b><?= count($live) ?></b> <?= count($live) === 1 ? 'service' : 'services' ?> &middot; <b><?= $openCount ?></b> open for requests<?= count($live) - $openCount ? ' &middot; <b>' . (count($live) - $openCount) . '</b> closed' : '' ?></p>
        <span class="ps-bar-tools">
            <a class="ps-link" href="pastoral-manage.php<?= $isSuper ? '?church=' . $churchId : '' ?>">View requests</a>
            <?php if ($canEdit): ?><a class="sr-new" href="pastoral-services.php?new=1"><?= ann_icon('plus', 15) ?> New service</a><?php endif; ?>
        </span>
    </div>

    <?php if (!$live): ?>
        <p class="ann-empty">This church offers no pastoral services at the moment.<?= $canEdit ? ' Add one with <b>New service</b>, or restore one below.' : '' ?></p>
    <?php else: ?>
        <div class="ps-grid">
            <?php foreach ($live as $k => $s):
                $cover = pc_cover_url($k, $s['photo']);
                $isOpen = (bool)(int)$s['is_open'];
                $n = (int)($counts[$k]['total'] ?? 0);
                $waiting = (int)($counts[$k]['waiting'] ?? 0);
            ?>
                <article class="ps-card<?= $isOpen ? '' : ' is-closed' ?>">
                    <div class="ps-cover<?= $cover ? ' has-photo' : '' ?>">
                        <?php if ($cover): ?><img src="<?= e($cover) ?>" alt="" loading="lazy"><?php else: ?><?= pc_cover_icon($k, 40) ?><?php endif; ?>
                        <span class="sx-badge <?= $isOpen ? 'is-ok' : 'is-no' ?>"><?= $isOpen ? 'Open' : 'Closed' ?></span>
                    </div>
                    <div class="ps-body">
                        <h2><?= e($s['title']) ?></h2>
                        <p><?= e($s['description']) ?></p>
                        <p class="ps-meta">
                            <span><?= pc_is_custom($k) ? 'General request form' : 'Standard form' ?></span>
                            <a href="pastoral-manage.php?<?= e(http_build_query(array_filter(['church' => $isSuper ? $churchId : '', 'service' => $k]))) ?>"><?= $n ?> <?= $n === 1 ? 'request' : 'requests' ?><?= $waiting ? ' (' . $waiting . ' waiting)' : '' ?></a>
                        </p>
                        <?php if ($canEdit): ?>
                            <div class="ps-tools">
                                <form method="post" action="pastoral-services.php">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="key" value="<?= e($k) ?>">
                                    <button type="submit" name="action" value="toggle" class="ps-toggle<?= $isOpen ? '' : ' is-reopen' ?>"><?= $isOpen ? 'Close requests' : 'Open requests' ?></button>
                                </form>
                                <a class="cat-icon-btn" href="pastoral-services.php?edit=<?= e($k) ?>" title="Edit" aria-label="Edit <?= e($s['title']) ?>"><?= ann_icon('pencil', 14) ?></a>
                                <form method="post" action="pastoral-services.php">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="key" value="<?= e($k) ?>">
                                    <button type="submit" name="action" value="remove" class="cat-icon-btn is-del" title="Delete" aria-label="Delete <?= e($s['title']) ?>"
                                        data-confirm="Delete <?= e($s['title']) ?>? Parishioners will no longer see it. Requests already sent are kept, and you can restore the service later."><?= ann_icon('trash', 14) ?></button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($removed): ?>
        <section class="ps-removed" aria-labelledby="ps-removed-title">
            <h2 id="ps-removed-title">Deleted services</h2>
            <p>Parishioners do not see these. Requests that were sent for them are still in the request list.</p>
            <ul>
                <?php foreach ($removed as $k => $s): ?>
                    <li>
                        <span><b><?= e($s['title']) ?></b><small>Deleted <?= e(date('j M Y', strtotime($s['deleted_at']))) ?> &middot; <?= (int)($counts[$k]['total'] ?? 0) ?> requests kept</small></span>
                        <?php if ($canEdit): ?>
                            <form method="post" action="pastoral-services.php">
                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                <input type="hidden" name="key" value="<?= e($k) ?>">
                                <button type="submit" name="action" value="restore" class="ps-toggle is-reopen">Restore</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
