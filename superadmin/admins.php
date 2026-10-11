<?php
// Super Admin: every admin account. Add a Church Admin (or another Super Admin), give someone a new
// "set your password" link, or remove an account. New admins choose their own password through a
// one-time link (admin-setup.php), so nobody else ever knows it.
require __DIR__ . '/../includes/auth.php';
require_role('super_admin');
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/church_helpers.php';
require __DIR__ . '/../includes/admin_invite.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$meId = (int)$_SESSION['user_id'];

$churches = $pdo->query('SELECT church_info_id, church_name, church_location FROM church_info ORDER BY church_name')->fetchAll();
$churchById = array_column($churches, null, 'church_info_id');

$flash = $_SESSION['admins_flash'] ?? null;
$newLink = $_SESSION['admins_link'] ?? null;   // shown once: the link is not stored anywhere readable
unset($_SESSION['admins_flash'], $_SESSION['admins_link']);
$errors = [];
$f = ['name' => '', 'email' => '', 'role' => 'church_admin', 'church' => (int)($_GET['church'] ?? 0)];

/** Keep the new link for the next page view, with what the Super Admin needs to send it on. */
function remember_link(array $admin, array $link, bool $isReset): void
{
    $_SESSION['admins_link'] = ['name' => $admin['admin_name'], 'email' => $admin['admin_email'], 'url' => $link[0], 'expires' => $link[1], 'reset' => $isReset];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['form'] = 'Your session expired. Please try again.';
    } elseif ($action === 'add') {
        $f['name']   = trim($_POST['name'] ?? '');
        $f['email']  = mb_strtolower(trim($_POST['email'] ?? ''));
        $f['role']   = ($_POST['role'] ?? '') === 'super_admin' ? 'super_admin' : 'church_admin';
        $f['church'] = (int)($_POST['church'] ?? 0);

        if ($f['name'] === '' || mb_strlen($f['name']) > 100) $errors['name'] = 'Enter the full name (up to 100 characters).';
        if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($f['email']) > 100) {
            $errors['email'] = 'Enter a valid email address.';
        } else {
            $stmt = $pdo->prepare('SELECT 1 FROM admin WHERE admin_email = ?');
            $stmt->execute([$f['email']]);
            if ($stmt->fetch()) $errors['email'] = 'An admin account with this email already exists.';
        }
        if ($f['role'] === 'church_admin' && !isset($churchById[$f['church']])) $errors['church'] = 'Choose the church this admin will manage.';

        if (!$errors) {
            // A random password nobody knows: the account can only be used once they set their own.
            $pdo->prepare('INSERT INTO admin (admin_name, admin_email, admin_password, admin_role, church_id) VALUES (?, ?, ?, ?, ?)')
                ->execute([$f['name'], $f['email'], password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $f['role'],
                           $f['role'] === 'church_admin' ? $f['church'] : null]);
            $id = (int)$pdo->lastInsertId();
            $link = admin_invite_create($pdo, $id, site_root_url(1));
            remember_link(['admin_name' => $f['name'], 'admin_email' => $f['email']], $link, false);
            $_SESSION['admins_flash'] = $f['name'] . ' was added' . ($f['role'] === 'church_admin' ? ' as Church Admin of ' . $churchById[$f['church']]['church_name'] : ' as a Super Admin') . '.';
            header('Location: admins.php#link');
            exit;
        }
    } else {
        $stmt = $pdo->prepare('SELECT * FROM admin WHERE admin_id = ?');
        $stmt->execute([(int)($_POST['id'] ?? 0)]);
        $target = $stmt->fetch();
        if (!$target) {
            $errors['form'] = 'That admin account no longer exists.';
        } elseif ($action === 'link') {
            // New admin who lost their link, or someone who forgot their password.
            $link = admin_invite_create($pdo, (int)$target['admin_id'], site_root_url(1));
            remember_link($target, $link, true);
            $_SESSION['admins_flash'] = 'A new link was made for ' . $target['admin_name'] . '. Any older link no longer works.';
            header('Location: admins.php#link');
            exit;
        } elseif ($action === 'remove') {
            $supers = (int)$pdo->query("SELECT COUNT(*) FROM admin WHERE admin_role = 'super_admin'")->fetchColumn();
            if ((int)$target['admin_id'] === $meId) {
                $errors['form'] = 'You cannot remove your own account.';
            } elseif ($target['admin_role'] === 'super_admin' && $supers <= 1) {
                $errors['form'] = 'The last Super Admin cannot be removed.';
            } else {
                // Records they made stay (their links to this account are cleared by the database).
                $pdo->prepare('DELETE FROM admin WHERE admin_id = ?')->execute([(int)$target['admin_id']]);
                if ($target['admin_photo'] && preg_match('/^[\w.-]+$/', $target['admin_photo'])) @unlink(dirname(__DIR__) . '/uploads/admin_photos/' . $target['admin_photo']);
                $_SESSION['admins_flash'] = $target['admin_name'] . ' was removed and can no longer sign in.';
                header('Location: admins.php');
                exit;
            }
        }
    }
}

$admins = $pdo->query(
    "SELECT a.admin_id, a.admin_name, a.admin_email, a.admin_role, a.church_id, a.invite_expires, c.church_name
       FROM admin a LEFT JOIN church_info c ON c.church_info_id = a.church_id
   ORDER BY a.admin_role = 'super_admin' DESC, c.church_name, a.admin_name"
)->fetchAll();
$hasAdmin = [];
foreach ($admins as $a) if ($a['admin_role'] === 'church_admin' && $a['church_id']) $hasAdmin[(int)$a['church_id']] = true;
$noAdmin = array_filter($churches, fn($c) => empty($hasAdmin[(int)$c['church_info_id']]));

function fc(array $errors, string $key): string { return isset($errors[$key]) ? 'pfield has-error' : 'pfield'; }
function err(array $errors, string $key): void { if (isset($errors[$key])) echo '<span class="row-error">' . e($errors[$key]) . '</span>'; }

$pageTitle = 'Admins';
$roleLabel = 'Super Admin';
include __DIR__ . '/../includes/dashboard_header.php';
?>
<h1 class="dash-title">Admins</h1>
<p class="dash-sub">Who can manage the system. New admins set their own password through a one-time link that you send them.</p>

<?php if ($flash): ?><div class="alert success cm-flash" role="status"><?= e($flash) ?></div><?php endif; ?>
<?php if (isset($errors['form'])): ?><div class="alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>

<?php if ($newLink):
    $expiresText = date('j M Y, g:i a', strtotime($newLink['expires']));
    $mail = 'mailto:' . rawurlencode($newLink['email']) . '?subject=' . rawurlencode($newLink['reset'] ? 'Reset your password - Archdiocese of Kota Kinabalu' : 'Your admin account - Archdiocese of Kota Kinabalu')
          . '&body=' . rawurlencode("Dear " . $newLink['name'] . ",\n\n" . ($newLink['reset'] ? 'Use this link to choose a new password' : 'An admin account has been created for you on the Church Management System. Use this link to choose your password') . ":\n\n" . $newLink['url'] . "\n\nThe link works once and expires on " . $expiresText . ".\n\nThank you.");
?>
    <section class="panel adm-link" id="link" aria-label="Set-password link">
        <h2><?= $newLink['reset'] ? 'New password link' : 'Send this link' ?> to <?= e($newLink['name']) ?></h2>
        <p>They open it and choose their own password. It works <b>once</b> and expires on <b><?= e($expiresText) ?></b>. This is the only time the link is shown.</p>
        <div class="adm-link-row">
            <input type="text" readonly value="<?= e($newLink['url']) ?>" id="adm-url" aria-label="Set-password link">
            <button type="button" class="btn-new" id="adm-copy"><span>Copy link</span></button>
            <a class="btn-new" href="<?= e($mail) ?>">Email to <?= e($newLink['email']) ?></a>
        </div>
    </section>
<?php endif; ?>

<section class="panel" id="list">
    <div class="panel-head">
        <h2>All admins <span class="panel-sub"><?= count($admins) ?> accounts</span></h2>
        <a class="btn-new" href="#add"><?= ann_icon('plus', 16) ?> Add admin</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Church</th><th>Status</th><th class="num">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($admins as $a):
                $pending = $a['invite_expires'] !== null;
                $expired = $pending && strtotime($a['invite_expires']) < time();
                [$stText, $stClass] = !$pending ? ['Active', 'ok'] : ($expired ? ['Link expired', 'muted'] : ['Link sent', 'pending']);
                $isMe = (int)$a['admin_id'] === $meId;
            ?>
                <tr>
                    <td><?= e($a['admin_name']) ?><?= $isMe ? ' <small class="adm-you">(you)</small>' : '' ?></td>
                    <td><?= e($a['admin_email']) ?></td>
                    <td><?= $a['admin_role'] === 'super_admin' ? 'Super Admin' : 'Church Admin' ?></td>
                    <td><?= $a['church_name'] ? e($a['church_name']) : '&mdash;' ?></td>
                    <td><span class="badge <?= $stClass ?>" title="<?= $pending ? e(($expired ? 'Expired ' : 'Expires ') . date('j M Y, g:i a', strtotime($a['invite_expires']))) : '' ?>"><?= $stText ?></span></td>
                    <td class="num">
                        <form method="post" action="admins.php" class="cm-actions">
                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                            <input type="hidden" name="id" value="<?= (int)$a['admin_id'] ?>">
                            <?php if (!$isMe): ?>
                                <button type="submit" name="action" value="link" class="adm-btn" title="<?= $pending ? 'Make a new set-password link' : 'Reset their password with a new link' ?>"><?= $pending ? 'New link' : 'Reset password' ?></button>
                                <button type="submit" name="action" value="remove" class="ico-btn sm danger" data-confirm="Remove <?= e($a['admin_name']) ?>? They will no longer be able to sign in. The records they created are kept." title="Remove" aria-label="Remove <?= e($a['admin_name']) ?>"><?= ann_icon('trash', 13) ?></button>
                            <?php else: ?>
                                <a class="adm-btn" href="../account.php">My profile</a>
                            <?php endif; ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="adm-grid">
    <section class="panel" id="add">
        <h2>Add admin</h2>
        <form method="post" action="admins.php#add" class="form-stack" novalidate>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="add">
            <div class="<?= fc($errors, 'name') ?>">
                <label for="adm-name">Full name</label>
                <input type="text" id="adm-name" name="name" maxlength="100" value="<?= e($f['name']) ?>" required<?= $f['church'] ? ' autofocus' : '' ?>>
                <?php err($errors, 'name'); ?>
            </div>
            <div class="<?= fc($errors, 'email') ?>">
                <label for="adm-email">Email address <span class="opt">(they sign in with it)</span></label>
                <input type="email" id="adm-email" name="email" maxlength="100" value="<?= e($f['email']) ?>" required>
                <?php err($errors, 'email'); ?>
            </div>
            <div class="pfield">
                <label for="adm-role">Role</label>
                <select id="adm-role" name="role">
                    <option value="church_admin" <?= $f['role'] === 'church_admin' ? 'selected' : '' ?>>Church Admin &mdash; manages one church</option>
                    <option value="super_admin" <?= $f['role'] === 'super_admin' ? 'selected' : '' ?>>Super Admin &mdash; manages everything</option>
                </select>
            </div>
            <div class="<?= fc($errors, 'church') ?>" id="adm-church-field"<?= $f['role'] === 'super_admin' ? ' hidden' : '' ?>>
                <label for="adm-church">Church</label>
                <select id="adm-church" name="church">
                    <option value="">Choose a church&hellip;</option>
                    <?php foreach ($churches as $c): $cid = (int)$c['church_info_id']; ?>
                        <option value="<?= $cid ?>" <?= $cid === $f['church'] ? 'selected' : '' ?>><?= e($c['church_name']) ?> &ndash; <?= e($c['church_location']) ?><?= empty($hasAdmin[$cid]) ? ' (no admin yet)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <?php err($errors, 'church'); ?>
            </div>
            <div class="profile-actions"><button type="submit" class="btn-save">Add admin &amp; make link</button></div>
        </form>
    </section>

    <section class="panel adm-missing">
        <h2>Churches without an admin <span class="panel-sub"><?= count($noAdmin) ?> of <?= count($churches) ?></span></h2>
        <?php if (!$noAdmin): ?>
            <p class="empty">Every church has a Church Admin.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($noAdmin as $c): ?>
                    <li><span><?= e($c['church_name']) ?> <small><?= e($c['church_location']) ?></small></span>
                        <a href="admins.php?church=<?= (int)$c['church_info_id'] ?>#add">Add admin</a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<script>
// The church list only matters for a Church Admin.
(function () {
    var role = document.getElementById('adm-role'), field = document.getElementById('adm-church-field');
    role.addEventListener('change', function () { field.hidden = role.value !== 'church_admin'; });
    var copy = document.getElementById('adm-copy');
    if (copy) copy.addEventListener('click', function () {
        var input = document.getElementById('adm-url'), label = copy.querySelector('span');
        input.select();
        (navigator.clipboard ? navigator.clipboard.writeText(input.value) : Promise.reject()).then(function () {
            label.textContent = 'Copied!';
            setTimeout(function () { label.textContent = 'Copy link'; }, 1800);
        }).catch(function () { document.execCommand('copy'); label.textContent = 'Copied!'; });
    });
})();
</script>
<?php include __DIR__ . '/../includes/dashboard_footer.php'; ?>
