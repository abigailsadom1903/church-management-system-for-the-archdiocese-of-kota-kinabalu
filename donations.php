<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/login_gate.php';
require_signed_in('Donation & Offering');   // visitors get "please login or register" instead of this page
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/donation_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$churches = $pdo->query(
    'SELECT church_info_id, church_name, church_location, church_photo,
            donation_bank_name, donation_account_name, donation_account_number, donation_qr_image
       FROM church_info ORDER BY church_name'
)->fetchAll();
$byId = array_column($churches, null, 'church_info_id');
$selectedId = pick_church_id($pdo, $byId, (int)($_POST['church'] ?? $_GET['church'] ?? 0));
$c = $byId[$selectedId] ?? null;
$canManage = $c && can_manage_church($pdo, $selectedId);

/** Which payment methods this church has set up. */
function church_methods(array $c): array
{
    return [
        'bank' => $c['donation_bank_name'] && $c['donation_account_name'] && $c['donation_account_number'],
        'qr'   => (bool)qr_image_url($c['donation_qr_image']),
    ];
}
$methods = $c ? church_methods($c) : ['bank' => false, 'qr' => false];
$anyMethod = $methods['bank'] || $methods['qr'];

// Signed-in public users get their details filled in.
$old = ['name' => '', 'phone' => '', 'email' => '', 'amount' => '', 'purpose' => '', 'message' => '',
        'method' => $methods['bank'] ? 'bank' : ($methods['qr'] ? 'qr' : '')];
if (($_SESSION['role'] ?? '') === 'user' && !empty($_SESSION['user_id'])) {
    $stmt = $pdo->prepare('SELECT user_name, user_email, user_phone FROM user WHERE user_id = ?');
    $stmt->execute([(int)$_SESSION['user_id']]);
    if ($u = $stmt->fetch()) {
        $old['name'] = $u['user_name'];
        $old['email'] = $u['user_email'];
        $old['phone'] = (string)$u['user_phone'];
    }
}

$done = $_SESSION['donation_done'] ?? null;
unset($_SESSION['donation_done']);
$errors = [];

// ---- Submit a donation --------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $c) {
    $old['name']    = trim($_POST['name'] ?? '');
    $old['phone']   = trim($_POST['phone'] ?? '');
    $old['email']   = trim($_POST['email'] ?? '');
    $old['amount']  = trim($_POST['amount'] ?? '');
    $old['purpose'] = trim($_POST['purpose'] ?? '');
    $old['message'] = trim(str_replace("\r\n", "\n", $_POST['message'] ?? ''));
    $old['method']  = $_POST['method'] ?? '';
    $amount = str_replace([',', ' '], '', preg_replace('/^\s*RM\s*/i', '', $old['amount']));

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['form'] = 'Your session expired. Please try again.';
    } elseif (!empty($_POST['website'])) {
        // Hidden field only bots fill in: pretend it worked.
        $_SESSION['donation_done'] = ['ref' => '', 'church' => $c['church_name']];
        header('Location: donations.php?church=' . $selectedId);
        exit;
    } else {
        if ($old['name'] === '' || mb_strlen($old['name']) > 100) $errors['name'] = 'Please enter your full name.';
        if (!preg_match('/^\+?[0-9][0-9\s\-()]{6,25}$/', $old['phone'])) $errors['phone'] = 'Please enter a valid contact number.';
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($old['email']) > 100) $errors['email'] = 'Please enter a valid email address.';
        if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $amount) || (float)$amount <= 0 || (float)$amount > 1000000) {
            $errors['amount'] = 'Enter an amount between RM 0.01 and RM 1,000,000.';
        }
        if ($old['purpose'] === '' || mb_strlen($old['purpose']) > 100) $errors['purpose'] = 'Please tell us what the donation is for.';
        if (mb_strlen($old['message']) > 500) $errors['message'] = 'Please keep your message under 500 characters.';
        if (!isset(DONATION_METHODS[$old['method']]) || !$methods[$old['method']]) {
            $errors['method'] = 'Please choose a payment method this parish accepts.';
        }

        // Receipt: JPG / PNG image or a PDF, up to 5 MB. The file's content is checked, not its name.
        $file = $_FILES['receipt'] ?? null;
        $ext = null;
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            $errors['receipt'] = 'Please upload your transaction receipt.';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $errors['receipt'] = 'The receipt could not be uploaded. Please try again.';
        } elseif ($file['size'] > RECEIPT_MAX_BYTES) {
            $errors['receipt'] = 'The receipt must be 5 MB or smaller.';
        } else {
            $fh = fopen($file['tmp_name'], 'rb');
            $magic = $fh ? fread($fh, 5) : '';
            if ($fh) fclose($fh);
            $info = @getimagesize($file['tmp_name']);
            if ($magic === '%PDF-') {
                $ext = 'pdf';
            } elseif ($info && $info[2] === IMAGETYPE_JPEG) {
                $ext = 'jpg';
            } elseif ($info && $info[2] === IMAGETYPE_PNG) {
                $ext = 'png';
            } else {
                $errors['receipt'] = 'Please upload a JPG or PNG image, or a PDF.';
            }
        }

        // Basic protection against form spam: at most 5 submissions per email per hour.
        if (!$errors) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM donations WHERE donor_email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
            $stmt->execute([$old['email']]);
            if ((int)$stmt->fetchColumn() >= 5) $errors['form'] = 'Too many submissions from this email address. Please try again later.';
        }

        if (!$errors) {
            $dir = __DIR__ . '/uploads/receipts';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $stored = 'receipt-' . $selectedId . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
                $errors['form'] = 'Your receipt could not be saved. Please try again.';
            } else {
                $pdo->prepare(
                    'INSERT INTO donations (church_id, donor_user_id, donor_name, donor_phone, donor_email, purpose, message, amount, method, receipt_file)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $selectedId,
                    ($_SESSION['role'] ?? '') === 'user' ? (int)$_SESSION['user_id'] : null,
                    $old['name'], $old['phone'], $old['email'], $old['purpose'], $old['message'] ?: null,
                    number_format((float)$amount, 2, '.', ''), $old['method'], $stored,
                ]);
                $_SESSION['donation_done'] = ['ref' => donation_ref((int)$pdo->lastInsertId()), 'church' => $c['church_name']];
                header('Location: donations.php?church=' . $selectedId);
                exit;
            }
        }
    }
}

$photoUrl = $c ? church_photo_url($c['church_photo']) : null;
$collections = $c ? collection_rows($pdo, $selectedId, 12) : [];
$purposes = ['Weekly Offering', 'Tithe', 'Building Fund', 'Renovation Fund', 'Charity Mass', 'Youth Ministry', 'Flower Offering', 'Other'];
function fc(array $errors, string $key): string { return isset($errors[$key]) ? 'pfield has-error' : 'pfield'; }

$pageTitle = 'Donation & Offering';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="don-page">
    <?php back_button('index.php#services', 'Back to Services'); ?>
    <header class="page-heading">
        <h1>Donation &amp; Offering</h1>
        <p>Give to your parish securely and let us know it is on its way.</p>
    </header>

<?php if (!$c): ?>
    <p class="dir-empty">No churches have been added yet.</p>
<?php else: ?>
    <section class="info-hero">
        <div class="hero-photo">
            <?php if ($photoUrl): ?>
                <img src="<?= e($photoUrl) ?>" alt="<?= e($c['church_name']) ?>">
            <?php else: ?>
                <div class="info-photo-fallback" aria-hidden="true"><?= don_icon('church', 56) ?></div>
            <?php endif; ?>
        </div>
        <h2><?= e($c['church_name']) ?></h2>
        <p class="info-town"><?= e($c['church_location']) ?></p>
    </section>

    <form method="get" action="donations.php" class="info-select" id="church-form">
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

    <?php if ($canManage): ?>
        <div class="don-admin-bar">
            <span>You manage this church.</span>
            <a href="donation-manage.php?church=<?= $selectedId ?>">Manage donations &rarr;</a>
            <a href="collection-manage.php?church=<?= $selectedId ?>">Weekly collection &rarr;</a>
            <?php if (($_SESSION['role'] ?? '') === 'super_admin'): ?><a href="donation-report.php">Donation reports &rarr;</a><?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($collections): $recent = array_map(fn($r) => (float)$r['amount'], $collections); ?>
        <section class="wc-public" id="weekly-collection" aria-labelledby="wc-title">
            <div class="wc-public-head">
                <h2 id="wc-title"><?= don_icon('trend', 20) ?> Weekly Collection</h2>
                <p>What <?= e($c['church_name']) ?> has collected over the last <?= count($collections) === 1 ? 'week' : count($collections) . ' weeks' ?>. Thank you for your generosity.</p>
            </div>
            <div class="wc-summary">
                <div><span>Latest week</span><b><?= rm(end($recent)) ?></b><small><?= e(date('j M Y', strtotime(end($collections)['week_date']))) ?></small></div>
                <div><span>Weekly average</span><b><?= rm(array_sum($recent) / count($recent)) ?></b></div>
            </div>
            <?php render_collection_chart($collections, 'Weekly Collection for ' . $c['church_name']); ?>
        </section>
    <?php endif; ?>

    <?php if ($done): ?>
        <div class="don-thanks" role="status">
            <span class="don-thanks-icon"><?= don_icon('heart', 26) ?></span>
            <h2>Thank you for your generosity!</h2>
            <p>Your donation to <strong><?= e($done['church']) ?></strong> has been submitted<?= $done['ref'] ? ' (reference <strong>' . e($done['ref']) . '</strong>)' : '' ?>.
               The parish will check your receipt and email you once it is confirmed.</p>
            <a class="btn-new" href="donations.php?church=<?= $selectedId ?>">Make another donation</a>
        </div>
    <?php elseif (!$anyMethod): ?>
        <div class="don-thanks is-empty" role="status">
            <span class="don-thanks-icon"><?= don_icon('bank', 26) ?></span>
            <h2>Online giving is not set up yet</h2>
            <p><?= e($c['church_name']) ?> has not added its payment details yet. Please contact the parish office
               (see <a href="church-information.php?church=<?= $selectedId ?>">church information</a>) or choose another church above.</p>
        </div>
    <?php else: ?>
        <?php if (isset($errors['form'])): ?><div class="alert info-alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
        <?php if ($errors && !isset($errors['form'])): ?><div class="alert info-alert" role="alert">Please check the highlighted fields.</div><?php endif; ?>

        <form method="post" action="donations.php?church=<?= $selectedId ?>" enctype="multipart/form-data" class="don-form" id="don-form" novalidate>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="church" value="<?= $selectedId ?>">
            <input type="text" name="website" class="hp-field" tabindex="-1" autocomplete="off" aria-hidden="true">

            <section class="don-step">
                <h2><span class="step-num">1</span> Details</h2>
                <div class="don-grid">
                    <div class="<?= fc($errors, 'name') ?>">
                        <label for="d-name">Full Name</label>
                        <input type="text" id="d-name" name="name" maxlength="100" autocomplete="name" value="<?= e($old['name']) ?>" required>
                        <?php if (isset($errors['name'])): ?><span class="row-error"><?= e($errors['name']) ?></span><?php endif; ?>
                    </div>
                    <div class="<?= fc($errors, 'purpose') ?>">
                        <label for="d-purpose">Reference / Purpose</label>
                        <input type="text" id="d-purpose" name="purpose" maxlength="100" list="purpose-list" placeholder="e.g. Weekly Offering" value="<?= e($old['purpose']) ?>" required>
                        <datalist id="purpose-list"><?php foreach ($purposes as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?></datalist>
                        <?php if (isset($errors['purpose'])): ?><span class="row-error"><?= e($errors['purpose']) ?></span><?php endif; ?>
                    </div>
                    <div class="<?= fc($errors, 'phone') ?>">
                        <label for="d-phone">Contact Number</label>
                        <input type="tel" id="d-phone" name="phone" maxlength="30" autocomplete="tel" placeholder="+60 12-345 6789" value="<?= e($old['phone']) ?>" required>
                        <?php if (isset($errors['phone'])): ?><span class="row-error"><?= e($errors['phone']) ?></span><?php endif; ?>
                    </div>
                    <div class="<?= fc($errors, 'amount') ?>">
                        <label for="d-amount">Amount (RM)</label>
                        <div class="amount-input"><span aria-hidden="true">RM</span><input type="text" id="d-amount" name="amount" inputmode="decimal" placeholder="0.00" value="<?= e($old['amount']) ?>" required></div>
                        <?php if (isset($errors['amount'])): ?><span class="row-error"><?= e($errors['amount']) ?></span><?php endif; ?>
                    </div>
                    <div class="<?= fc($errors, 'email') ?>">
                        <label for="d-email">Email</label>
                        <input type="email" id="d-email" name="email" maxlength="100" autocomplete="email" value="<?= e($old['email']) ?>" required>
                        <?php if (isset($errors['email'])): ?><span class="row-error"><?= e($errors['email']) ?></span><?php endif; ?>
                    </div>
                    <div class="<?= fc($errors, 'message') ?>">
                        <label for="d-message">Message <span class="opt">(optional)</span></label>
                        <textarea id="d-message" name="message" rows="3" maxlength="500"><?= e($old['message']) ?></textarea>
                        <?php if (isset($errors['message'])): ?><span class="row-error"><?= e($errors['message']) ?></span><?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="don-step">
                <h2><span class="step-num">2</span> Payment Method</h2>
                <div class="pay-options" role="radiogroup" aria-label="Payment method">
                    <label class="pay-card<?= $methods['bank'] ? '' : ' is-off' ?>">
                        <input type="radio" name="method" value="bank" <?= $old['method'] === 'bank' ? 'checked' : '' ?> <?= $methods['bank'] ? '' : 'disabled' ?>>
                        <span class="pay-head">
                            <span class="pay-radio" aria-hidden="true"></span>
                            <span class="pay-title"><?= e(DONATION_METHODS['bank']) ?></span>
                            <span class="pay-ico"><?= don_icon('bank', 20) ?></span>
                        </span>
                        <?php if ($methods['bank']): ?>
                            <span class="pay-body">
                                <?php if (stripos($c['donation_account_name'], '(SAMPLE)') !== false): ?>
                                    <em class="pay-sample">Demo account for testing &ndash; please do not send real money.</em>
                                <?php endif; ?>
                                <dl>
                                    <dt>Bank</dt><dd><?= e($c['donation_bank_name']) ?></dd>
                                    <dt>Account Name</dt><dd><?= e($c['donation_account_name']) ?></dd>
                                    <dt>Account Number</dt><dd><span id="acct-no"><?= e($c['donation_account_number']) ?></span>
                                        <button type="button" class="copy-btn" data-copy="#acct-no" title="Copy account number"><?= don_icon('copy', 13) ?> <span>Copy</span></button></dd>
                                </dl>
                            </span>
                        <?php else: ?>
                            <span class="pay-note">Not available for this parish yet.</span>
                        <?php endif; ?>
                    </label>

                    <label class="pay-card<?= $methods['qr'] ? '' : ' is-off' ?>">
                        <input type="radio" name="method" value="qr" <?= $old['method'] === 'qr' ? 'checked' : '' ?> <?= $methods['qr'] ? '' : 'disabled' ?>>
                        <span class="pay-head">
                            <span class="pay-radio" aria-hidden="true"></span>
                            <span class="pay-title"><?= e(DONATION_METHODS['qr']) ?></span>
                            <span class="pay-ico"><?= don_icon('qr', 20) ?></span>
                        </span>
                        <?php if ($methods['qr']): ?>
                            <span class="pay-body pay-qr">
                                <img src="<?= e(qr_image_url($c['donation_qr_image'])) ?>" alt="DuitNow QR code for <?= e($c['church_name']) ?>" width="180" height="180">
                                <small>Scan with any DuitNow-enabled banking or e-wallet app, then upload your receipt below.</small>
                            </span>
                        <?php else: ?>
                            <span class="pay-note">Not available for this parish yet.</span>
                        <?php endif; ?>
                    </label>
                </div>
                <?php if (isset($errors['method'])): ?><span class="row-error"><?= e($errors['method']) ?></span><?php endif; ?>
            </section>

            <section class="don-step">
                <h2><span class="step-num">3</span> Upload Transaction Receipt</h2>
                <label class="dropzone<?= isset($errors['receipt']) ? ' has-error' : '' ?>" id="dropzone" for="d-receipt">
                    <span class="dz-icon"><?= don_icon('upload', 26) ?></span>
                    <strong>Click to upload or drag and drop</strong>
                    <small>Support images (JPG, PNG) or PDF documents &middot; up to 5 MB</small>
                    <span class="dz-file" id="dz-file" hidden></span>
                    <input type="file" id="d-receipt" name="receipt" accept="image/jpeg,image/png,application/pdf,.pdf" hidden>
                </label>
                <?php if (isset($errors['receipt'])): ?><span class="row-error"><?= e($errors['receipt']) ?></span><?php endif; ?>
            </section>

            <div class="don-actions">
                <a href="index.php#services" class="btn-cancel">Cancel</a>
                <button type="submit" class="btn-save">Submit</button>
            </div>
        </form>
    <?php endif; ?>
<?php endif; ?>
</main>
<script>
document.getElementById('church')?.addEventListener('change', function () {
    document.getElementById('church-form').submit();
});

// Copy the account number.
document.querySelectorAll('.copy-btn').forEach(function (btn) {
    btn.addEventListener('click', function (ev) {
        ev.preventDefault();
        var text = document.querySelector(btn.dataset.copy).textContent.trim();
        var label = btn.querySelector('span');
        (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () {
            label.textContent = 'Copied';
            setTimeout(function () { label.textContent = 'Copy'; }, 1800);
        }).catch(function () { label.textContent = 'Select and copy'; });
    });
});

// Receipt drop zone: click, or drag a file onto it.
(function () {
    var zone = document.getElementById('dropzone');
    var input = document.getElementById('d-receipt');
    var note = document.getElementById('dz-file');
    if (!zone) return;
    function show() {
        var f = input.files[0];
        if (!f) { note.hidden = true; return; }
        note.textContent = f.name + ' (' + (f.size / 1024 / 1024 < 0.1 ? Math.max(1, Math.round(f.size / 1024)) + ' KB' : (f.size / 1024 / 1024).toFixed(1) + ' MB') + ')';
        note.hidden = false;
        zone.classList.toggle('too-big', f.size > 5 * 1024 * 1024);
    }
    input.addEventListener('change', show);
    ['dragenter', 'dragover'].forEach(function (t) {
        zone.addEventListener(t, function (ev) { ev.preventDefault(); zone.classList.add('is-drag'); });
    });
    ['dragleave', 'drop'].forEach(function (t) {
        zone.addEventListener(t, function (ev) { ev.preventDefault(); zone.classList.remove('is-drag'); });
    });
    zone.addEventListener('drop', function (ev) {
        if (ev.dataTransfer.files.length) { input.files = ev.dataTransfer.files; show(); }
    });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
