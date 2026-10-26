<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/announcement_mail.php';
require __DIR__ . '/includes/announcement_ai.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Editing (?id=) or creating (?church=). The church always comes from the record when editing.
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$existing = null;
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM announcements WHERE announcement_id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        header('Location: announcements.php');
        exit;
    }
    $churchId = (int)$existing['church_id'];
} else {
    $churchId = (int)($_GET['church'] ?? $_POST['church'] ?? 0);
}

// Super Admin: any church. Church Admin: only their own. Anyone else is sent back.
if (!can_manage_church($pdo, $churchId)) {
    header('Location: announcements.php' . ($churchId ? '?church=' . $churchId : ''));
    exit;
}
$stmt = $pdo->prepare('SELECT church_name, church_location FROM church_info WHERE church_info_id = ?');
$stmt->execute([$churchId]);
$church = $stmt->fetch();

$from = ($_GET['from'] ?? $_POST['from'] ?? '') === 'dashboard' ? 'dashboard' : '';   // opened from an admin dashboard?
$backUrl = $from ? dashboard_path('announcements', $churchId) : 'announcements.php?church=' . $churchId;

$categories = ['General', 'Liturgical', 'Community', 'Family', 'Youth', 'Ministry', 'Charity', 'Event'];

$errors = [];
$f = [
    'title'    => $existing['title'] ?? '',
    'category' => $existing['category'] ?? 'General',
    'priority' => !empty($existing['is_urgent']) ? 'urgent' : 'normal',
    'date'     => $existing['publish_date'] ?? date('Y-m-d'),
    'expires'  => $existing['expires_date'] ?? '',
    'message'  => $existing['body'] ?? '',
    'pinned'   => !empty($existing['is_pinned']),
];

function valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d && $d >= '2000-01-01' && $d <= '2100-12-31';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f['title']    = trim($_POST['title'] ?? '');
    $f['category'] = trim($_POST['category'] ?? '');
    $f['priority'] = ($_POST['priority'] ?? '') === 'urgent' ? 'urgent' : 'normal';
    $f['date']     = trim($_POST['date'] ?? '');
    $f['expires']  = trim($_POST['expires'] ?? '');
    $f['message']  = trim(str_replace("\r\n", "\n", $_POST['message'] ?? ''));
    $f['pinned']   = !empty($_POST['pinned']);

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        if ($f['title'] === '' || mb_strlen($f['title']) > 150) $errors['title'] = 'Enter a title (up to 150 characters).';
        if ($f['category'] === '' || mb_strlen($f['category']) > 50) $errors['category'] = 'Enter a category (up to 50 characters).';
        if (!valid_date($f['date'])) $errors['date'] = 'Enter a valid date.';
        if ($f['expires'] !== '' && !valid_date($f['expires'])) {
            $errors['expires'] = 'Enter a valid date.';
        } elseif ($f['expires'] !== '' && !isset($errors['date']) && $f['expires'] < $f['date']) {
            $errors['expires'] = 'The expiry date cannot be before the announcement date.';
        }
        if ($f['message'] === '' || mb_strlen($f['message']) > 5000) $errors['message'] = 'Enter the message (up to 5,000 characters).';

        if (!$errors) {
            $values = [$f['title'], $f['message'], $f['category'], $f['priority'] === 'urgent' ? 1 : 0, $f['pinned'] ? 1 : 0, $f['date'], $f['expires'] ?: null];
            if ($existing) {
                $pdo->prepare('UPDATE announcements SET title = ?, body = ?, category = ?, is_urgent = ?, is_pinned = ?, publish_date = ?, expires_date = ? WHERE announcement_id = ?')
                    ->execute([...$values, $id]);
            } else {
                $pdo->prepare('INSERT INTO announcements (title, body, category, is_urgent, is_pinned, publish_date, expires_date, church_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([...$values, $churchId, (int)$_SESSION['user_id']]);
            }
            $_SESSION['admin_flash'] = $existing ? 'Announcement updated.' : 'Announcement created.';

            // Tell the "Stay Updated" subscribers (a scheduled one is emailed once its date arrives).
            $emailed = send_due_announcement_emails($pdo, $churchId);
            if ($emailed) {
                $_SESSION['admin_flash'] .= ' Emailed to ' . $emailed . ($emailed === 1 ? ' subscriber.' : ' subscribers.');
            } elseif (announcement_emails_pending($pdo, $churchId)) {
                $_SESSION['admin_flash'] .= ' The email to subscribers could not be sent (see logs/mail-errors.log); it will be tried again.';
            } elseif (!$existing && $f['date'] > date('Y-m-d')) {
                $_SESSION['admin_flash'] .= ' Subscribers will be emailed when it is published.';
            }
            header('Location: ' . $backUrl);
            exit;
        }
    }
}

function fc(array $errors, string $key): string { return isset($errors[$key]) ? 'pfield has-error' : 'pfield'; }

$pageTitle = $existing ? 'Edit Announcement' : 'Create Announcement';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="form-page">
    <?php back_button($backUrl, $from ? 'Back to dashboard' : 'Back to announcements'); ?>
    <header class="page-heading">
        <h1><?= $existing ? 'Edit Announcement' : 'Create Announcement' ?></h1>
        <p><?= e($church['church_name']) ?> &middot; <?= e($church['church_location']) ?></p>
    </header>

    <?php if (isset($errors['form'])): ?><div class="alert info-alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
    <?php if ($errors && !isset($errors['form'])): ?><div class="alert info-alert" role="alert">Please check the highlighted fields.</div><?php endif; ?>

    <section class="ai-gen" id="ai-gen" data-church="<?= $churchId ?>" data-csrf="<?= e($_SESSION['csrf']) ?>">
        <div class="ai-gen-bar">
            <div>
                <strong>Need help with the wording?</strong>
                <span>Describe it in your own words and let AI write a first draft. Nothing is posted until you press Save.</span>
            </div>
            <button type="button" class="ai-gen-open" id="ai-open" aria-expanded="false" aria-controls="ai-panel">&#10024; Generate with AI</button>
        </div>

        <div class="ai-gen-panel" id="ai-panel" hidden>
            <div class="pfield ai-first">
                <label for="ai-details">What is the announcement about?</label>
                <textarea id="ai-details" rows="4" maxlength="2000" placeholder="e.g. Parish Family Day on Sunday 25 October, 9 AM at the Parish Hall, for all families. Register at the parish office by 18 October. Bring a dish to share."></textarea>
            </div>
            <div class="ai-gen-row">
                <label for="ai-language">Language</label>
                <select id="ai-language">
                    <?php foreach (ANNOUNCEMENT_AI_LANGUAGES as $code => $name): ?><option value="<?= e($code) ?>"><?= e($name) ?></option><?php endforeach; ?>
                </select>
                <span class="ai-gen-note">Include the date, time and place. The AI will not make any up.</span>
                <button type="button" class="btn-cancel" id="ai-close">Close</button>
                <button type="button" class="btn-save" id="ai-generate">Generate</button>
            </div>

            <div class="alert info-alert" id="ai-error" role="alert" hidden></div>

            <div class="ai-gen-preview" id="ai-preview" hidden>
                <h2>Preview <small>You can edit the draft here before using it.</small></h2>
                <div class="ai-gen-missing" id="ai-missing" hidden>
                    <strong>You may want to add:</strong>
                    <ul></ul>
                </div>
                <div class="pfield">
                    <label for="ai-out-title">Title</label>
                    <input type="text" id="ai-out-title" maxlength="150">
                </div>
                <div class="pfield">
                    <label for="ai-out-body">Message</label>
                    <textarea id="ai-out-body" rows="9" maxlength="5000"></textarea>
                </div>
                <div class="ai-gen-actions">
                    <button type="button" class="btn-cancel" id="ai-regenerate">Regenerate</button>
                    <button type="button" class="btn-save" id="ai-use">Use This Content</button>
                </div>
            </div>
        </div>
    </section>

    <div class="alert success info-alert ai-used" id="ai-used" role="status" hidden>The draft is in the form below. Check it, set the date and category, then press Save to post it.</div>

    <form method="post" action="announcement-form.php" class="profile-card form-card" novalidate>
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <?php if ($from): ?><input type="hidden" name="from" value="dashboard"><?php endif; ?>
        <?php if ($existing): ?><input type="hidden" name="id" value="<?= $id ?>"><?php else: ?><input type="hidden" name="church" value="<?= $churchId ?>"><?php endif; ?>

        <div class="form-stack">
            <div class="<?= fc($errors, 'title') ?>">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" maxlength="150" value="<?= e($f['title']) ?>" required autofocus>
                <?php if (isset($errors['title'])): ?><span class="row-error"><?= e($errors['title']) ?></span><?php endif; ?>
            </div>

            <div class="pf-row">
                <div class="<?= fc($errors, 'category') ?>">
                    <label for="category">Category</label>
                    <input type="text" id="category" name="category" maxlength="50" list="category-list" value="<?= e($f['category']) ?>" required>
                    <datalist id="category-list"><?php foreach ($categories as $cat): ?><option value="<?= e($cat) ?>"><?php endforeach; ?></datalist>
                    <?php if (isset($errors['category'])): ?><span class="row-error"><?= e($errors['category']) ?></span><?php endif; ?>
                </div>
                <div class="pfield">
                    <label for="priority">Priority</label>
                    <select id="priority" name="priority">
                        <option value="normal" <?= $f['priority'] === 'normal' ? 'selected' : '' ?>>Normal</option>
                        <option value="urgent" <?= $f['priority'] === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                    </select>
                </div>
            </div>

            <div class="pf-row">
                <div class="<?= fc($errors, 'date') ?>">
                    <label for="date">Date</label>
                    <input type="date" id="date" name="date" value="<?= e($f['date']) ?>" required>
                    <?php if (isset($errors['date'])): ?><span class="row-error"><?= e($errors['date']) ?></span><?php endif; ?>
                </div>
                <div class="<?= fc($errors, 'expires') ?>">
                    <label for="expires">Expires on <span class="opt">(optional)</span></label>
                    <input type="date" id="expires" name="expires" value="<?= e($f['expires']) ?>">
                    <?php if (isset($errors['expires'])): ?><span class="row-error"><?= e($errors['expires']) ?></span><?php endif; ?>
                </div>
            </div>

            <div class="<?= fc($errors, 'message') ?>">
                <label for="message">Message</label>
                <textarea id="message" name="message" rows="7" maxlength="5000" required><?= e($f['message']) ?></textarea>
                <?php if (isset($errors['message'])): ?><span class="row-error"><?= e($errors['message']) ?></span><?php endif; ?>
            </div>

            <label class="check-row">
                <input type="checkbox" name="pinned" value="1" <?= $f['pinned'] ? 'checked' : '' ?>>
                <span>Pin announcement <small>(keeps it at the top of the list)</small></span>
            </label>
        </div>

        <div class="profile-actions">
            <a href="<?= e($backUrl) ?>" class="btn-cancel">Cancel</a>
            <button type="submit" class="btn-save">Save</button>
        </div>
    </form>
</main>
<script>
// "Generate with AI": sends the details to announcement-ai.php, shows an editable draft, and only copies it
// into the form below when the admin presses "Use This Content". Saving is still the admin's own click.
(function () {
    var box = document.getElementById('ai-gen');
    if (!box) return;
    var $ = function (id) { return document.getElementById(id); };
    var panel = $('ai-panel'), openBtn = $('ai-open'), genBtn = $('ai-generate'), regenBtn = $('ai-regenerate');
    var errorBox = $('ai-error'), preview = $('ai-preview'), missing = $('ai-missing');
    var busy = false;

    function setOpen(open) {
        panel.hidden = !open;
        openBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            $('ai-details').focus();
        }
    }
    function showError(msg) { errorBox.textContent = msg; errorBox.hidden = !msg; }
    function setBusy(on) {
        busy = on;
        genBtn.disabled = regenBtn.disabled = on;
        genBtn.textContent = on ? 'Writing…' : 'Generate';
        regenBtn.textContent = on ? 'Writing…' : 'Regenerate';
    }

    function generate() {
        if (busy) return;
        showError('');
        if ($('ai-details').value.trim().length < 10) { showError('Describe the announcement in a sentence or two first.'); $('ai-details').focus(); return; }
        setBusy(true);
        fetch('announcement-ai.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': box.dataset.csrf },
            body: JSON.stringify({
                church: Number(box.dataset.church),
                title: $('title').value, details: $('ai-details').value, language: $('ai-language').value
            })
        }).then(function (res) {
            return res.json().catch(function () { return { error: 'The AI generator did not answer. Please try again.' }; });
        }).then(function (data) {
            if (data.error) { showError(data.error); return; }
            $('ai-out-title').value = data.title || $('title').value;
            $('ai-out-body').value = data.body || '';
            var list = missing.querySelector('ul');
            list.textContent = '';
            (data.missing || []).forEach(function (m) { var li = document.createElement('li'); li.textContent = m; list.appendChild(li); });
            missing.hidden = !(data.missing || []).length;
            preview.hidden = false;
            preview.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }).catch(function () {
            showError('Could not reach the AI generator. Check your connection and try again.');
        }).then(function () { setBusy(false); });
    }

    openBtn.addEventListener('click', function () { setOpen(panel.hidden); });
    $('ai-close').addEventListener('click', function () { setOpen(false); });
    genBtn.addEventListener('click', generate);
    regenBtn.addEventListener('click', generate);

    $('ai-use').addEventListener('click', function () {
        var title = $('title'), message = $('message');
        if ((message.value.trim() || title.value.trim()) && (message.value !== $('ai-out-body').value) &&
            !confirm('Replace the title and message already in the form with this draft?')) return;
        title.value = $('ai-out-title').value.slice(0, 150);
        message.value = $('ai-out-body').value.slice(0, 5000);
        setOpen(false);
        var note = $('ai-used');
        note.hidden = false;
        note.scrollIntoView({ behavior: 'smooth', block: 'center' });
        message.focus({ preventScroll: true });
    });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
