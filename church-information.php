<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/login_gate.php';
require_signed_in('Church Information');   // visitors get "please login or register" instead of this page
require __DIR__ . '/includes/church_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Makes "www.example.org" / "facebook.com/page" clickable; only http(s) links are ever produced. */
function web_url(string $value): ?string
{
    $value = trim($value);
    if ($value === '') return null;
    if (!preg_match('#^https?://#i', $value)) $value = 'https://' . $value;
    return filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
}

/** A WhatsApp invite / chat link (chat.whatsapp.com, whatsapp.com, wa.me) made clickable, or null for anything else. */
function whatsapp_url(string $value): ?string
{
    $url = web_url($value);
    if ($url === null) return null;
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    return preg_match('/(^|\.)(whatsapp\.com|wa\.me)$/', $host) ? $url : null;
}

/** "Rev. Msgr. Nicholas Huang" -> "NH", "Fr David Sham" -> "DS" (skips titles such as Rev., Fr, Msgr). */
function initials(string $name): string
{
    $titles = ['fr', 'rev', 'msgr', 'friar', 'very', 'most', 'rt', 'dr'];
    $words = array_values(array_filter(preg_split('/\s+/', trim($name)), fn($w) => $w !== '' && !in_array(strtolower(rtrim($w, '.')), $titles, true)));
    if (!$words) return '✝';
    $out = mb_substr($words[0], 0, 1);
    if (count($words) > 1) $out .= mb_substr(end($words), 0, 1);
    return mb_strtoupper($out);
}

/** Small line icons used by the admin controls. */
function icon(string $name, int $size = 16): string
{
    $paths = [
        'pencil' => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/>',
        'trash'  => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'plus'   => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'edit'   => '<path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.4 2.6a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4Z"/>',
        'phone'  => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/>',
        'pin'    => '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'clock'  => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'mail'   => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'globe'  => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        'fb'     => '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>',
        'whatsapp' => '<path d="M3 21l1.65-3.8a9 9 0 1 1 3.4 2.9L3 21"/><path d="M9 10a.5.5 0 0 0 1 0V9a.5.5 0 0 0-1 0v1a5 5 0 0 0 5 5h1a.5.5 0 0 0 0-1h-1a.5.5 0 0 0 0 1"/>',
        'image-off' => '<line x1="2" x2="22" y1="2" y2="22"/><path d="M10.41 10.41a2 2 0 1 1-2.83-2.83"/><line x1="13.5" x2="6" y1="13.5" y2="21"/><line x1="18" x2="21" y1="12" y2="15"/><path d="M3.59 3.59A1.99 1.99 0 0 0 3 5v14a2 2 0 0 0 2 2h14c.55 0 1.052-.22 1.41-.59"/><path d="M21 15V5a2 2 0 0 0-2-2H9"/>',
        'church' => '<path d="m18 7 4 2v11a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9l4-2"/><path d="M14 22v-4a2 2 0 0 0-2-2a2 2 0 0 0-2 2v4"/><path d="M18 22V5l-6-3-6 3v17"/><path d="M12 7v5"/><path d="M10 9h4"/>',
    ];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[$name] . '</svg>';
}

function delete_uploaded_photo(?string $file): void
{
    if ($file && preg_match('/^[\w.-]+$/', $file)) {
        $path = __DIR__ . '/uploads/church_photos/' . $file;   // only ever removes files we uploaded
        if (is_file($path)) @unlink($path);
    }
}

/** One editable line of the Contact & Location card (or the priest / church-name rows). */
function info_row(bool $canEdit, array $o): void
{
    $has = $o['has'];
    if (!$canEdit && !$has) return;
    $tag = $o['tag'] ?? 'li';
    $cls = trim(($o['class'] ?? '') . (!empty($o['editing']) ? ' is-editing' : ''));
    echo "<$tag class=\"$cls\" data-field=\"" . e($o['field']) . '">';
    echo $o['icon'] ?? '';
    echo '<div class="row-body"><div class="row-view">';
    echo $has ? $o['view'] : '<span class="row-empty">Not set</span>';
    echo '</div>';
    if ($canEdit) echo '<div class="row-edit">' . $o['input'] . '</div>';
    echo $o['extra'] ?? '';
    if (!empty($o['label'])) echo '<span class="row-label">' . e($o['label']) . '</span>';
    if (!empty($o['error'])) echo '<span class="row-error">' . e($o['error']) . '</span>';
    echo '</div>';
    if ($canEdit) {
        echo '<div class="row-actions">';
        echo '<button type="button" class="ico-btn" data-edit title="Edit" aria-label="Edit ' . e($o['label'] ?? $o['field']) . '">' . icon('pencil', 14) . '</button>';
        if ($has && ($o['deletable'] ?? true)) {
            echo '<button type="submit" name="clear" value="' . e($o['field']) . '" class="ico-btn danger" formnovalidate '
               . 'data-confirm="Remove this information? This cannot be undone." title="Delete" aria-label="Delete ' . e($o['label'] ?? $o['field']) . '">' . icon('trash', 14) . '</button>';
        }
        echo '</div>';
    }
    echo "</$tag>";
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$role    = $_SESSION['role'] ?? '';
$isSuper = !empty($_SESSION['user_id']) && $role === 'super_admin';
$myChurchId = 0;
if (!empty($_SESSION['user_id']) && $role === 'church_admin') {
    $stmt = $pdo->prepare('SELECT church_id FROM admin WHERE admin_id = ? AND admin_role = \'church_admin\'');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $myChurchId = (int)$stmt->fetchColumn();   // read from the database, not trusted from the session
}
/** Super admins may change any church; a church admin only their assigned one. */
function can_edit_church(int $churchId, bool $isSuper, int $myChurchId): bool
{
    return $churchId > 0 && ($isSuper || $churchId === $myChurchId);
}

// ---- POST actions (create / update / delete) ---------------------------------------------
$errors = [];
$posted = [];
$priestForm = null;   // the priest row to reopen (with what was typed) after a failed save
$flash  = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

const FIELD_MAP = [   // form field => column
    'phone'        => 'church_contact_details',
    'address'      => 'church_address',
    'hours'        => 'church_office_hours',
    'email'        => 'church_email',
     'website'     => 'church_website',
    'facebook'     => 'church_facebook',
    'whatsapp'     => 'church_whatsapp',
];

function go(string $url, string $msg): never
{
    $_SESSION['flash'] = $msg;
    header('Location: ' . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    $id     = (int)($_POST['church'] ?? 0);

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '') || empty($_SESSION['user_id'])) {
        $errors['form'] = 'Your session expired. Please sign in again and retry.';
    } elseif ($action === 'delete_church') {
        if (!$isSuper) {
            $errors['form'] = 'Only the Super Admin can delete churches.';
        } else {
            $stmt = $pdo->prepare('SELECT church_photo, (SELECT COUNT(*) FROM donations WHERE church_id = church_info_id) AS donations FROM church_info WHERE church_info_id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row && (int)$row['donations'] > 0) {
                $errors['form'] = 'This church has donation records, so it cannot be deleted. Donation history is kept for the parish\'s accounts.';
            } elseif ($row) {
                $priestPhotos = array_column(church_priests($pdo, $id), 'priest_photo');
                $pdo->prepare('DELETE FROM church_info WHERE church_info_id = ?')->execute([$id]);
                foreach ($priestPhotos as $pf) delete_priest_photo($pf);
                delete_uploaded_photo($row['church_photo']);
                go('church-information.php', 'Church deleted.');
            }
        }
    } elseif ($action === 'priest') {
        // Priests card: add / save (name, title, photo) / remove photo / remove. The church comes from the priest's record.
        $op  = $_POST['op'] ?? 'save';
        $pid = (int)($_POST['priest'] ?? 0);
        $prow = null;
        if ($op === 'add') {
            $pChurch = $id;
        } else {
            $stmt = $pdo->prepare('SELECT * FROM church_priests WHERE priest_id = ?');
            $stmt->execute([$pid]);
            $prow = $stmt->fetch() ?: null;
            $pChurch = $prow ? (int)$prow['church_id'] : 0;
        }
        $back = 'church-information.php?church=' . $pChurch . '#priests';
        if (($op !== 'add' && !$prow) || !can_edit_church($pChurch, $isSuper, $myChurchId)) {
            $errors['form'] = 'You do not have permission to change this priest.';
        } elseif ($op === 'delete') {
            $pdo->prepare('DELETE FROM church_priests WHERE priest_id = ?')->execute([$pid]);
            delete_priest_photo($prow['priest_photo']);
            go($back, $prow['priest_name'] . ' was removed.');
        } elseif ($op === 'photo_remove') {
            $pdo->prepare('UPDATE church_priests SET priest_photo = NULL WHERE priest_id = ?')->execute([$pid]);
            delete_priest_photo($prow['priest_photo']);
            go($back, 'Photo removed.');
        } else {
            $pName  = trim($_POST['priest_name'] ?? '');
            $pTitle = trim($_POST['priest_title'] ?? '');
            $pDob   = trim($_POST['priest_dob'] ?? '');
            $pOrd   = trim($_POST['priest_ordained'] ?? '');
            $pMore  = implode("\n", array_map('trim', preg_split('/\R/', trim($_POST['priest_details'] ?? ''))));
            $priestForm = ['key' => $op === 'add' ? 'new' : $pid, 'name' => $pName, 'title' => $pTitle, 'dob' => $pDob, 'ordained' => $pOrd, 'details' => $pMore];
            $validDate = function (string $d): bool {
                $dt = DateTime::createFromFormat('!Y-m-d', $d);
                return $dt && $dt->format('Y-m-d') === $d && $d <= date('Y-m-d');
            };
            $pErr = '';
            if ($pName === '' || mb_strlen($pName) > 100) $pErr = 'Enter the priest\'s name (up to 100 characters).';
            elseif (mb_strlen($pTitle) > 50) $pErr = 'Keep the title under 50 characters.';
            elseif ($pDob !== '' && !$validDate($pDob)) $pErr = 'Enter a valid date of birth.';
            elseif ($pOrd !== '' && !$validDate($pOrd)) $pErr = 'Enter a valid ordination date.';
            elseif ($pDob !== '' && $pOrd !== '' && $pOrd <= $pDob) $pErr = 'The ordination date must be after the date of birth.';
            elseif (mb_strlen($pMore) > 600) $pErr = 'Keep the other details under 600 characters.';

            // Photo (optional): JPG, PNG or WebP up to 3 MB, checked by its content.
            $pf = $_FILES['priest_photo'] ?? null;
            $pExt = null;
            if (!$pErr && $pf && $pf['error'] !== UPLOAD_ERR_NO_FILE) {
                $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
                $info = $pf['error'] === UPLOAD_ERR_OK ? @getimagesize($pf['tmp_name']) : false;
                if ($pf['error'] !== UPLOAD_ERR_OK) $pErr = 'The photo could not be uploaded. Please try again.';
                elseif ($pf['size'] > 3 * 1024 * 1024) $pErr = 'The photo must be 3 MB or smaller.';
                elseif (!$info || !isset($types[$info[2]])) $pErr = 'Please choose a JPG, PNG or WebP image.';
                else $pExt = $types[$info[2]];
            }

            if ($pErr) {
                $errors['priest'] = $pErr;
            } else {
                if ($op === 'add') {
                    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM church_priests WHERE church_id = ?');
                    $stmt->execute([$pChurch]);
                    $pdo->prepare('INSERT INTO church_priests (church_id, priest_name, priest_title, priest_dob, priest_ordained, priest_details, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$pChurch, $pName, $pTitle ?: null, $pDob ?: null, $pOrd ?: null, $pMore ?: null, (int)$stmt->fetchColumn()]);
                    $pid = (int)$pdo->lastInsertId();
                } else {
                    $pdo->prepare('UPDATE church_priests SET priest_name = ?, priest_title = ?, priest_dob = ?, priest_ordained = ?, priest_details = ? WHERE priest_id = ?')
                        ->execute([$pName, $pTitle ?: null, $pDob ?: null, $pOrd ?: null, $pMore ?: null, $pid]);
                }
                $note = '';
                if ($pExt) {
                    $dir = __DIR__ . '/uploads/priest_photos';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $file = 'priest-' . $pid . '-' . bin2hex(random_bytes(6)) . '.' . $pExt;
                    if (move_uploaded_file($pf['tmp_name'], $dir . '/' . $file)) {
                        $pdo->prepare('UPDATE church_priests SET priest_photo = ? WHERE priest_id = ?')->execute([$file, $pid]);
                        if ($prow) delete_priest_photo($prow['priest_photo']);
                    } else {
                        $note = ' The photo could not be saved.';
                    }
                }
                go($back, ($op === 'add' ? $pName . ' was added.' : 'Changes saved.') . $note);
            }
        }
    } elseif (!can_edit_church($id, $isSuper, $myChurchId)) {
        $errors['form'] = 'You do not have permission to change this church.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM church_info WHERE church_info_id = ?');
        $stmt->execute([$id]);
        $current = $stmt->fetch();
        $back = 'church-information.php?church=' . $id;

        if (!$current) {
            $errors['form'] = 'That church no longer exists.';
        } elseif (isset($_POST['clear'])) {
            // Trash icon: empty a single piece of information.
            $key = $_POST['clear'];
            if ($key === 'photo') {
                delete_uploaded_photo($current['church_photo']);
                $pdo->prepare('UPDATE church_info SET church_photo = NULL WHERE church_info_id = ?')->execute([$id]);
                go($back, 'Photo removed.');
            } elseif (isset(FIELD_MAP[$key])) {
                $pdo->prepare('UPDATE church_info SET ' . FIELD_MAP[$key] . ' = NULL WHERE church_info_id = ?')->execute([$id]);
                go($back, 'Information removed.');
            }
        } else {
            // Save Changes: validate everything that was submitted.
            $clean = [];
            foreach (FIELD_MAP as $field => $col) {
                if (!isset($_POST[$field])) continue;
                $v = trim((string)$_POST[$field]);
                if ($field === 'hours') {
                    $lines = array_filter(array_map('trim', preg_split('/\R/', $v)), fn($l) => $l !== '');
                    $v = implode("\n", $lines);
                }
                $posted[$field] = $v;
                $max = ['phone' => 50, 'address' => 255, 'hours' => 255, 'email' => 100, 'website' => 150, 'facebook' => 150, 'whatsapp' => 255][$field];
                if (mb_strlen($v) > $max) {
                    $errors[$field] = "Please keep this under $max characters.";
                } elseif ($v !== '' && $field === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field] = 'Enter a valid email address.';
                } elseif ($v !== '' && $field === 'phone' && !preg_match('/^\+?[0-9][0-9\s\-()\/,]{4,}$/', $v)) {
                    $errors[$field] = 'Enter a valid phone number.';
                } elseif ($v !== '' && in_array($field, ['website', 'facebook'], true) && web_url($v) === null) {
                    $errors[$field] = 'Enter a valid web address.';
                } elseif ($v !== '' && $field === 'whatsapp' && !whatsapp_url($v)) {
                    $errors[$field] = 'Paste the WhatsApp invite link, e.g. https://chat.whatsapp.com/AbC123...';
                }
                $clean[$col] = $v === '' ? null : $v;
            }

            // Church name and town: Super Admin only.
            if ($isSuper && isset($_POST['name'])) {
                $name = trim($_POST['name']);
                $town = trim($_POST['town'] ?? '');
                $posted['name'] = $name;
                $posted['town'] = $town;
                if ($name === '' || mb_strlen($name) > 100) $errors['name'] = 'Enter the church name (up to 100 characters).';
                if ($town === '' || mb_strlen($town) > 255) $errors['town'] = 'Enter the town.';
                $clean['church_name'] = $name;
                $clean['church_location'] = $town;
            }

            // Photo upload (pencil on the photo).
            $newPhoto = null;
            if (!empty($_FILES['photo']['name'])) {
                $f = $_FILES['photo'];
                $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
                $info = $f['error'] === UPLOAD_ERR_OK ? @getimagesize($f['tmp_name']) : false;
                if ($f['error'] !== UPLOAD_ERR_OK) {
                    $errors['photo'] = 'The photo could not be uploaded. Please try again.';
                } elseif ($f['size'] > 3 * 1024 * 1024) {
                    $errors['photo'] = 'The photo must be 3 MB or smaller.';
                } elseif (!$info || !isset($types[$info[2]])) {
                    $errors['photo'] = 'Please choose a JPG, PNG or WebP image.';
                } else {
                    $dir = __DIR__ . '/uploads/church_photos';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $newPhoto = 'church-' . $id . '-' . bin2hex(random_bytes(6)) . '.' . $types[$info[2]];
                    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $newPhoto)) {
                        $newPhoto = null;
                        $errors['photo'] = 'The photo could not be saved.';
                    }
                }
            }

            if (!$errors) {
                if ($newPhoto) {
                    $clean['church_photo'] = $newPhoto;
                }
                if ($clean) {
                    $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($clean)));
                    $pdo->prepare("UPDATE church_info SET $set WHERE church_info_id = ?")->execute([...array_values($clean), $id]);
                }
                if ($newPhoto) delete_uploaded_photo($current['church_photo']);
                go($back, 'Changes saved.');
            } elseif ($newPhoto) {
                @unlink(__DIR__ . '/uploads/church_photos/' . $newPhoto);   // don't keep a photo from a failed save
            }
        }
    }
}

// ---- Which church to show ----------------------------------------------------------------
$churches = $pdo->query('SELECT * FROM church_info ORDER BY church_name')->fetchAll();
$byId = array_column($churches, null, 'church_info_id');

// A Church Admin always sees their own church (no church picker for them).
// Everyone else: ?church=ID, else the signed-in person's own parish, else the first church created.
$isOwnOnly  = $role === 'church_admin' && isset($byId[$myChurchId]);
$selectedId = $isOwnOnly ? $myChurchId : pick_church_id($pdo, $byId, (int)($_GET['church'] ?? $_POST['church'] ?? 0));
$c = $byId[$selectedId] ?? null;
$canEdit = $c && can_edit_church($selectedId, $isSuper, $myChurchId);

if ($c) {
    // After a failed save, show what the admin typed instead of the stored values.
    $v = fn(string $field, string $col) => $posted[$field] ?? (string)$c[$col];
    $photoUrl = church_photo_url($c['church_photo']);
    $priests  = church_priests($pdo, $selectedId);
    $address  = $v('address', 'church_address');
    $street   = trim(preg_replace('/P\.?\s?O\.?\s?Box\s*\d+,?\s*/i', '', $address), " ,");
    $mapQuery = $street !== '' ? $c['church_name'] . ', ' . $street : $c['church_name'] . ', ' . $c['church_location'] . ', Sabah, Malaysia';
    $website  = web_url($v('website', 'church_website'));
    $facebook = web_url($v('facebook', 'church_facebook'));
    $whatsapp = whatsapp_url($v('whatsapp', 'church_whatsapp'));
}

$pageTitle = 'Church Information';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="info-page">
    <?php back_button('index.php#services', 'Back to Services'); ?>
    <header class="page-heading">
        <h1>Church Information</h1>
        <p>Contact details, office hours and location for every parish.</p>
    </header>
    <?php if ($isSuper): ?>
        <div class="info-top-tools"><a class="btn-new" href="superadmin/church-form.php"><?= icon('plus', 16) ?> Add a church</a></div>
    <?php endif; ?>

    <?php if ($flash): ?><div class="alert success info-alert" role="status"><?= e($flash) ?></div><?php endif; ?>
    <?php if (isset($errors['form'])): ?><div class="alert info-alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
    <?php if ($errors && !isset($errors['form'])): ?><div class="alert info-alert" role="alert">Some changes were not saved. Please check the highlighted fields.</div><?php endif; ?>

<?php if (!$c): ?>
    <p class="dir-empty">No churches have been added yet.</p>
<?php else: ?>
    <?php if ($canEdit): ?>
        <p class="admin-badge"><?= $isSuper ? 'Super Admin' : 'Church Admin' ?> &middot; you can edit this church</p>
    <?php endif; ?>

    <section class="info-hero">
        <div class="hero-photo" id="hero-photo">
            <?php if ($photoUrl): ?>
                <img src="<?= e($photoUrl) ?>" alt="<?= e($c['church_name']) ?>">
            <?php else: ?>
                <div class="info-photo-fallback" aria-hidden="true"><?= icon('church', 56) ?></div>
            <?php endif; ?>
            <?php if ($canEdit): ?>
                <div class="photo-drop-hint" aria-hidden="true"><?= icon('plus', 22) ?><span>Drop photo here</span></div>
                <div class="photo-actions">
                    <label class="ico-btn" title="<?= $photoUrl ? 'Change photo' : 'Add photo' ?>">
                        <?= icon($photoUrl ? 'pencil' : 'plus', 14) ?>
                        <input type="file" name="photo" form="admin-form" accept="image/jpeg,image/png,image/webp" id="photo-input" data-adjust="16:10" hidden>
                    </label>
                    <?php if ($c['church_photo']): ?>
                        <button type="submit" form="admin-form" name="clear" value="photo" class="ico-btn danger" formnovalidate data-confirm="Remove this photo? This cannot be undone." title="Delete photo"><?= icon('trash', 14) ?></button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($canEdit): ?><p class="photo-hint">Drag a photo onto the picture, or use <?= $photoUrl ? 'the pencil' : 'the + button' ?> to browse for one.</p><?php endif; ?>
        <?php if (isset($errors['photo'])): ?><p class="row-error photo-error"><?= e($errors['photo']) ?></p><?php endif; ?>
        <h2><?= e($posted['name'] ?? $c['church_name']) ?></h2>
        <p class="info-town"><?= e($posted['town'] ?? $c['church_location']) ?></p>
    </section>

    <?php if (!$isOwnOnly): ?>
    <form method="get" action="church-information.php" class="info-select" id="church-form">
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
    <?php endif; ?>

    <form method="post" action="church-information.php" enctype="multipart/form-data" id="admin-form" novalidate>
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="church" value="<?= (int)$selectedId ?>">

        <?php if ($isSuper && $canEdit): ?>
        <section class="info-card">
            <h3>Church <button type="button" class="ico-btn card-edit" data-edit-all title="Edit all"><?= icon('edit', 16) ?></button></h3>
            <ul class="info-rows">
                <?php
                info_row($canEdit, [
                    'field' => 'name', 'icon' => '<span class="row-ico">' . icon('church', 18) . '</span>', 'label' => 'Church name',
                    'has' => true, 'deletable' => false, 'editing' => isset($errors['name']), 'error' => $errors['name'] ?? '',
                    'view' => '<span class="row-val">' . e($posted['name'] ?? $c['church_name']) . '</span>',
                    'input' => '<input type="text" name="name" maxlength="100" value="' . e($posted['name'] ?? $c['church_name']) . '">',
                ]);
                info_row($canEdit, [
                    'field' => 'town', 'icon' => '<span class="row-ico">' . icon('pin', 18) . '</span>', 'label' => 'Town (shown in the Directory)',
                    'has' => true, 'deletable' => false, 'editing' => isset($errors['town']), 'error' => $errors['town'] ?? '',
                    'view' => '<span class="row-val">' . e($posted['town'] ?? $c['church_location']) . '</span>',
                    'input' => '<input type="text" name="town" maxlength="255" value="' . e($posted['town'] ?? $c['church_location']) . '">',
                ]);
                ?>
            </ul>
        </section>
        <?php endif; ?>

        <section class="info-card" id="priests">
            <h3><?= count($priests) > 1 ? 'Priests' : 'Parish Priest' ?>
                <?php if ($canEdit): ?>
                    <button type="button" class="ico-btn add-btn" data-priest-add title="Add a priest" aria-label="Add a priest"><?= icon('plus', 16) ?></button>
                <?php endif; ?>
            </h3>
            <?php if (!$priests && !$canEdit): ?>
                <p class="info-missing">Parish priest details are not available yet.</p>
            <?php endif; ?>
            <ul class="priest-list">
                <?php
                // Each priest's controls belong to their own small form (form="pf-<id>"), printed after #admin-form.
                $rowsToShow = $priests;
                if ($canEdit) $rowsToShow[] = ['priest_id' => 'new', 'priest_name' => '', 'priest_title' => '', 'priest_photo' => null];
                $longDate = fn(?string $d) => $d ? date('j F Y', strtotime($d)) : '';
                foreach ($rowsToShow as $p):
                    $key = $p['priest_id'];
                    $isNew = $key === 'new';
                    $fid = 'pf-' . $key;
                    $reopen = $priestForm && (string)$priestForm['key'] === (string)$key;
                    $name  = $reopen ? $priestForm['name'] : $p['priest_name'];
                    $title = $reopen ? $priestForm['title'] : (string)$p['priest_title'];
                    $dob      = $reopen ? $priestForm['dob'] : (string)($p['priest_dob'] ?? '');
                    $ordained = $reopen ? $priestForm['ordained'] : (string)($p['priest_ordained'] ?? '');
                    $details  = $reopen ? $priestForm['details'] : (string)($p['priest_details'] ?? '');
                    $photo = priest_photo_url($p['priest_photo']);
                    $showNew = $reopen || !$priests;   // the empty "add" row is open straight away when there are no priests yet
                ?>
                    <li class="priest-row<?= $reopen || ($isNew && $showNew) ? ' is-editing' : '' ?><?= $isNew ? ' is-new' : '' ?>"<?= $isNew && !$showNew ? ' hidden' : '' ?> data-priest="<?= e((string)$key) ?>"
                        <?php if (!$isNew): // what the pop-up shows when the priest is clicked ?>
                            data-name="<?= e($p['priest_name']) ?>" data-title="<?= e((string)$p['priest_title']) ?>" data-photo="<?= e((string)$photo) ?>"
                            data-dob="<?= e($longDate($p['priest_dob'] ?? null)) ?>" data-ordained="<?= e($longDate($p['priest_ordained'] ?? null)) ?>" data-details="<?= e((string)($p['priest_details'] ?? '')) ?>"
                        <?php endif; ?>>
                        <div class="priest-pic">
                            <?php if ($photo): ?>
                                <img src="<?= e($photo) ?>" alt="<?= e($p['priest_name']) ?>"<?= $isNew ? '' : ' data-priest-open' ?>>
                            <?php else: ?>
                                <span class="priest-avatar" aria-hidden="true"><?= $isNew ? icon('plus', 20) : e(initials($p['priest_name'])) ?></span>
                            <?php endif; ?>
                            <?php if ($canEdit): ?>
                                <label class="priest-pic-btn" title="<?= $isNew ? 'Choose a photo' : ($photo ? 'Change photo' : 'Add photo') ?>">
                                    <?= icon($photo ? 'pencil' : 'plus', 12) ?>
                                    <span class="sr-only"><?= $isNew ? 'Choose a photo' : ($photo ? 'Change the photo of ' : 'Add a photo of ') . e($p['priest_name']) ?></span>
                                    <input type="file" name="priest_photo" form="<?= $fid ?>" accept="image/jpeg,image/png,image/webp" data-adjust="4:5" hidden<?= $isNew ? ' data-preview' : ' data-autosubmit' ?>>
                                </label>
                            <?php endif; ?>
                        </div>
                        <div class="row-body">
                            <?php if (!$isNew): ?>
                                <div class="row-view">
                                    <button type="button" class="priest-open" data-priest-open aria-haspopup="dialog" title="View details">
                                        <span class="priest-name"><?= e($p['priest_name']) ?></span>
                                        <?php if ($p['priest_title']): ?><span class="priest-title"><?= e($p['priest_title']) ?></span><?php endif; ?>
                                    </button>
                                </div>
                            <?php endif; ?>
                            <?php if ($canEdit): ?>
                                <div class="row-edit priest-edit">
                                    <input type="text" name="priest_name" form="<?= $fid ?>" maxlength="100" placeholder="Name, e.g. Rev. Fr. John Tan" aria-label="Priest's name" value="<?= e($name) ?>">
                                    <input type="text" name="priest_title" form="<?= $fid ?>" maxlength="50" placeholder="Title, e.g. Parish Priest or Assistant Priest" aria-label="Title" value="<?= e($title) ?>">
                                    <div class="priest-edit-dates">
                                        <label>Date of birth <input type="date" name="priest_dob" form="<?= $fid ?>" max="<?= date('Y-m-d') ?>" value="<?= e($dob) ?>"></label>
                                        <label>Ordained priest <input type="date" name="priest_ordained" form="<?= $fid ?>" max="<?= date('Y-m-d') ?>" value="<?= e($ordained) ?>"></label>
                                    </div>
                                    <textarea name="priest_details" form="<?= $fid ?>" rows="4" maxlength="600" aria-label="Other details" placeholder="Other details, one per line, e.g.&#10;St Michael Church, P.O.Box 110&#10;Tel: 088-711009"><?= e($details) ?></textarea>
                                    <div class="priest-edit-actions">
                                        <?php if ($priests): ?><button type="button" class="btn-cancel" data-priest-cancel>Cancel</button><?php endif; ?>
                                        <button type="submit" form="<?= $fid ?>" name="op" value="<?= $isNew ? 'add' : 'save' ?>" class="btn-save"><?= $isNew ? 'Add priest' : 'Save' ?></button>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if ($reopen && isset($errors['priest'])): ?><span class="row-error"><?= e($errors['priest']) ?></span><?php endif; ?>
                        </div>
                        <?php if ($canEdit && !$isNew): ?>
                            <div class="row-actions">
                                <button type="button" class="ico-btn" data-priest-edit title="Edit" aria-label="Edit <?= e($p['priest_name']) ?>"><?= icon('pencil', 14) ?></button>
                                <?php if ($photo): ?>
                                    <button type="submit" form="<?= $fid ?>" name="op" value="photo_remove" class="ico-btn" data-confirm="Remove the photo of <?= e($p['priest_name']) ?>?" title="Remove photo" aria-label="Remove the photo of <?= e($p['priest_name']) ?>"><?= icon('image-off', 14) ?></button>
                                <?php endif; ?>
                                <button type="submit" form="<?= $fid ?>" name="op" value="delete" class="ico-btn danger" data-confirm="Remove <?= e($p['priest_name']) ?> from this church? This cannot be undone." title="Remove priest" aria-label="Remove <?= e($p['priest_name']) ?>"><?= icon('trash', 14) ?></button>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="info-card" id="contact-card">
            <h3>Contact &amp; Location
                <?php if ($canEdit): ?><button type="button" class="ico-btn card-edit" data-edit-all title="Edit all" aria-label="Edit all contact details"><?= icon('edit', 16) ?></button><?php endif; ?>
            </h3>
            <ul class="info-rows">
                <?php
                $phone = $v('phone', 'church_contact_details');
                info_row($canEdit, [
                    'field' => 'phone', 'label' => 'Phone', 'has' => $phone !== '', 'editing' => isset($errors['phone']), 'error' => $errors['phone'] ?? '',
                    'icon' => '<span class="row-ico">' . icon('phone', 18) . '</span>',
                    'view' => '<a class="row-val" href="tel:' . e(preg_replace('/[^\d+]/', '', preg_split('/[\/,;]/', $phone)[0])) . '">' . e($phone) . '</a>',
                    'input' => '<input type="text" name="phone" maxlength="50" placeholder="+60 88-000 000" value="' . e($phone) . '">',
                ]);

                // Address is always shown: with no street address it falls back to the town so the map still works.
                $mapHtml = '<div class="info-map"><iframe title="Map of ' . e($c['church_name']) . '" loading="lazy" allowfullscreen src="https://www.google.com/maps?q='
                         . e(rawurlencode($mapQuery)) . '&amp;z=16&amp;output=embed"></iframe></div>';
                $mapLink = '<a class="map-link" href="https://www.google.com/maps/search/?api=1&amp;query=' . e(rawurlencode($mapQuery)) . '" target="_blank" rel="noopener">Open in Google Maps</a>';
                info_row($canEdit, [
                    'field' => 'address', 'label' => 'Address', 'has' => true, 'editing' => isset($errors['address']), 'error' => $errors['address'] ?? '',
                    'deletable' => $address !== '',
                    'icon' => '<span class="row-ico">' . icon('pin', 18) . '</span>',
                    'view' => '<span class="row-val">' . e($address !== '' ? $address : $c['church_location'] . ', Sabah, Malaysia') . '</span>',
                    'input' => '<input type="text" name="address" maxlength="255" placeholder="Street address, town, postcode" value="' . e($address) . '">',
                    'extra' => $mapHtml . $mapLink,
                ]);

                $hours = $v('hours', 'church_office_hours');
                info_row($canEdit, [
                    'field' => 'hours', 'label' => 'Office Hours', 'has' => $hours !== '', 'editing' => isset($errors['hours']), 'error' => $errors['hours'] ?? '',
                    'icon' => '<span class="row-ico">' . icon('clock', 18) . '</span>',
                    'view' => '<span class="row-val">' . nl2br(e($hours)) . '</span>',
                    'input' => '<textarea name="hours" rows="3" maxlength="255" placeholder="One line per day, e.g.&#10;Mon - Fri: 8:30 AM - 4:30 PM&#10;Sun: Closed">' . e($hours) . '</textarea>',
                ]);

                $email = $v('email', 'church_email');
                info_row($canEdit, [
                    'field' => 'email', 'label' => 'Email', 'has' => $email !== '', 'editing' => isset($errors['email']), 'error' => $errors['email'] ?? '',
                    'icon' => '<span class="row-ico">' . icon('mail', 18) . '</span>',
                    'view' => '<a class="row-val" href="mailto:' . e($email) . '">' . e($email) . '</a>',
                    'input' => '<input type="email" name="email" maxlength="100" placeholder="office@example.org" value="' . e($email) . '">',
                ]);

                $webRaw = $v('website', 'church_website');
                info_row($canEdit, [
                    'field' => 'website', 'label' => 'Website', 'has' => $website !== null, 'editing' => isset($errors['website']), 'error' => $errors['website'] ?? '',
                    'icon' => '<span class="row-ico">' . icon('globe', 18) . '</span>',
                    'view' => '<a class="row-val" href="' . e((string)$website) . '" target="_blank" rel="noopener">' . e($webRaw) . '</a>',
                    'input' => '<input type="text" name="website" maxlength="150" placeholder="www.example.org" value="' . e($webRaw) . '">',
                ]);

                $fbRaw = $v('facebook', 'church_facebook');
                info_row($canEdit, [
                    'field' => 'facebook', 'label' => 'Facebook', 'has' => $facebook !== null, 'editing' => isset($errors['facebook']), 'error' => $errors['facebook'] ?? '',
                    'icon' => '<span class="row-ico">' . icon('fb', 18) . '</span>',
                    'view' => '<a class="row-val" href="' . e((string)$facebook) . '" target="_blank" rel="noopener">' . e($fbRaw) . '</a>',
                    'input' => '<input type="text" name="facebook" maxlength="150" placeholder="facebook.com/yourpage" value="' . e($fbRaw) . '">',
                ]);

                $waRaw = $v('whatsapp', 'church_whatsapp');
                info_row($canEdit, [
                    'field' => 'whatsapp', 'label' => 'WhatsApp Community', 'has' => $whatsapp !== null, 'editing' => isset($errors['whatsapp']), 'error' => $errors['whatsapp'] ?? '',
                    'icon' => '<span class="row-ico is-wa">' . icon('whatsapp', 18) . '</span>',
                    'view' => '<a class="btn-wa" href="' . e((string)$whatsapp) . '" target="_blank" rel="noopener">' . icon('whatsapp', 16) . ' Join our WhatsApp Community</a>',
                    'input' => '<input type="url" name="whatsapp" maxlength="255" placeholder="https://chat.whatsapp.com/..." value="' . e($waRaw) . '">',
                ]);
                ?>
            </ul>

            <?php if (!$canEdit && $phone === '' && $hours === '' && $email === '' && !$website && !$facebook && !$whatsapp): ?>
                <p class="info-missing">More contact details for this parish will be added soon.</p>
            <?php endif; ?>

            <?php if ($canEdit): ?>
                <div class="save-bar" id="save-bar" <?= $errors ? '' : 'hidden' ?>>
                    <button type="button" class="btn-cancel" id="cancel-edit">Cancel</button>
                    <button type="submit" class="btn-save">Save Changes</button>
                </div>
            <?php endif; ?>
        </section>
    </form>

    <?php if ($canEdit): foreach (array_merge(array_column($priests, 'priest_id'), ['new']) as $pk): ?>
        <form method="post" action="church-information.php" enctype="multipart/form-data" id="pf-<?= e((string)$pk) ?>" hidden novalidate>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="priest">
            <input type="hidden" name="church" value="<?= (int)$selectedId ?>">
            <input type="hidden" name="priest" value="<?= e((string)$pk) ?>">
        </form>
    <?php endforeach; endif; ?>

    <div class="pm-overlay" id="priest-modal" hidden>
        <div class="pm-box" role="dialog" aria-modal="true" aria-labelledby="pm-name">
            <button type="button" class="pm-close" id="pm-close" aria-label="Close"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>
            <div class="pm-photo" id="pm-photo"></div>
            <h3 class="pm-name" id="pm-name"></h3>
            <p class="pm-title" id="pm-title"></p>
            <dl class="pm-facts" id="pm-facts"></dl>
            <p class="pm-details" id="pm-details"></p>
            <p class="pm-empty" id="pm-empty">More details about this priest will be added soon.</p>
        </div>
    </div>

    <?php if ($isSuper): ?>
        <section class="super-tools">
            <form method="post" action="church-information.php" class="delete-church-form" data-confirm-form="Delete <?= e($c['church_name']) ?> and all of its information? This cannot be undone.">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="delete_church">
                <input type="hidden" name="church" value="<?= (int)$selectedId ?>">
                <button type="submit" class="btn-danger"><?= icon('trash', 15) ?> Delete this church</button>
            </form>
        </section>
    <?php endif; ?>
<?php endif; ?>
</main>
<script>
(function () {
    var form = document.getElementById('admin-form');
    var bar = document.getElementById('save-bar');

    document.getElementById('church')?.addEventListener('change', function () {
        document.getElementById('church-form').submit();
    });

    // Priests card: edit a row in place, add a new one, and upload a photo as soon as it is chosen.
    var priests = document.getElementById('priests');
    if (priests) {
        priests.querySelectorAll('[data-priest-edit]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var row = btn.closest('.priest-row');
                row.classList.add('is-editing');
                row.querySelector('input[type=text]').focus();
            });
        });
        priests.querySelectorAll('[data-priest-cancel]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                location.href = 'church-information.php?church=' + form.elements['church'].value + '#priests';   // reload = discard
            });
        });
        priests.querySelector('[data-priest-add]')?.addEventListener('click', function () {
            var row = priests.querySelector('.priest-row.is-new');
            row.hidden = false;
            row.classList.add('is-editing');
            row.querySelector('input[type=text]').focus();
        });
        priests.querySelectorAll('input[data-autosubmit]').forEach(function (input) {
            input.addEventListener('change', function () { if (input.files.length) input.form.submit(); });
        });
        // Click a priest (name or photo) to see their details in a pop-up.
        var modal = document.getElementById('priest-modal'), opener = null;
        var pm = function (id) { return document.getElementById('pm-' + id); };
        function closePriest() {
            modal.hidden = true;
            if (opener) opener.focus();
        }
        function openPriest(row, from) {
            var d = row.dataset, facts = pm('facts');
            pm('photo').textContent = '';
            if (d.photo) {
                var img = document.createElement('img');
                img.src = d.photo;
                img.alt = d.name;
                pm('photo').appendChild(img);
            } else {
                var avatar = row.querySelector('.priest-avatar');
                if (avatar) pm('photo').appendChild(avatar.cloneNode(true));
            }
            pm('name').textContent = d.name;
            pm('title').textContent = d.title;
            pm('title').hidden = !d.title;
            facts.textContent = '';
            [['Date of Birth', d.dob], ['Ordained Priest', d.ordained]].forEach(function (f) {
                if (!f[1]) return;
                var dt = document.createElement('dt'), dd = document.createElement('dd');
                dt.textContent = f[0];
                dd.textContent = f[1];
                facts.append(dt, dd);
            });
            facts.hidden = !facts.children.length;
            pm('details').textContent = d.details;
            pm('details').hidden = !d.details;
            pm('empty').hidden = !!(d.dob || d.ordained || d.details);
            opener = from;
            modal.hidden = false;
            pm('close').focus();
        }
        priests.querySelectorAll('[data-priest-open]').forEach(function (el) {
            el.addEventListener('click', function () {
                var row = el.closest('.priest-row');
                openPriest(row, row.querySelector('button.priest-open'));
            });
        });
        pm('close').addEventListener('click', closePriest);
        modal.addEventListener('click', function (ev) { if (ev.target === modal) closePriest(); });
        document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !modal.hidden) closePriest(); });

        priests.querySelectorAll('input[data-preview]').forEach(function (input) {
            input.addEventListener('change', function () {
                var file = input.files[0], pic = input.closest('.priest-pic');
                if (!file || file.type.indexOf('image/') !== 0) return;
                var img = pic.querySelector('img') || document.createElement('img');
                img.src = URL.createObjectURL(file);
                img.alt = '';
                var avatar = pic.querySelector('.priest-avatar');
                if (avatar) avatar.remove();
                pic.prepend(img);
            });
        });
    }

    if (!form || !bar) return;

    function startEditing(el) {
        el.classList.add('is-editing');
        bar.hidden = false;
        var input = el.querySelector('input, textarea');
        if (input) input.focus();
    }

    form.querySelectorAll('[data-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () { startEditing(btn.closest('[data-field]')); });
    });
    form.querySelectorAll('[data-edit-target]').forEach(function (btn) {
        btn.addEventListener('click', function () { startEditing(form.querySelector('[data-field="' + btn.dataset.editTarget + '"]')); });
    });
    form.querySelectorAll('[data-edit-all]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            btn.closest('.info-card').querySelectorAll('[data-field]').forEach(function (r) { r.classList.add('is-editing'); });
            bar.hidden = false;
        });
    });

    document.getElementById('cancel-edit').addEventListener('click', function () {
        location.href = 'church-information.php?church=' + form.elements['church'].value;   // reload = discard changes
    });

    // Choosing a photo uploads it straight away.
    var photoInput = document.getElementById('photo-input');
    photoInput?.addEventListener('change', function () {
        if (this.files.length) form.submit();
    });

    // Dragging a photo from the desktop (or another window) onto the picture works the same way.
    var dropZone = document.getElementById('hero-photo');
    if (dropZone && photoInput) {
        ['dragenter', 'dragover'].forEach(function (evt) {
            dropZone.addEventListener(evt, function (ev) {
                if (!ev.dataTransfer || !Array.from(ev.dataTransfer.types || []).includes('Files')) return;
                ev.preventDefault();
                dropZone.classList.add('is-dragover');
            });
        });
        ['dragleave', 'dragend'].forEach(function (evt) {
            dropZone.addEventListener(evt, function () { dropZone.classList.remove('is-dragover'); });
        });
        dropZone.addEventListener('drop', function (ev) {
            ev.preventDefault();
            dropZone.classList.remove('is-dragover');
            var files = ev.dataTransfer && ev.dataTransfer.files;
            if (!files || !files.length) return;
            photoInput.files = files;   // same input the click-to-browse path uses, so it's adjusted, validated and saved the same way
            photoInput.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
