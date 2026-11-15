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

$pageTitle = 'Donation & Offering';
$activeNav = 'services';
$presets = [5, 10, 20, 50, 100];
$oldAmt = str_replace([',', ' '], '', preg_replace('/^\s*RM\s*/i', '', $old['amount']));
include __DIR__ . '/includes/header.php';
?>
<main class="dn-page">
    <section class="dn-hero">
        <div class="dn-wrap">
            <?php back_button('index.php#services', 'Back to Services'); ?>
            <div class="dn-hero-row<?= $c ? '' : ' no-photo' ?>">
                <div class="dn-hero-text">
                    <span class="dn-eyebrow">Stewardship &middot; Give to your parish</span>
                    <h1>Donation &amp; Offering</h1>
                    <p>Give to your parish securely and let us know it&rsquo;s on its way. Every offering supports the life and mission of the Church.</p>
                    <?php if ($c): ?>
                        <form method="get" action="donations.php" class="dn-church" id="church-form">
                            <label for="church">Give to</label>
                            <div class="dn-church-select">
                                <?= don_icon('church', 16) ?>
                                <select id="church" name="church" data-church-picker>
                                    <?php foreach ($churches as $ch): ?>
                                        <option value="<?= (int)$ch['church_info_id'] ?>" data-name="<?= e($ch['church_name']) ?>" data-loc="<?= e($ch['church_location']) ?>" data-photo="<?= e((string)church_photo_url($ch['church_photo'])) ?>" <?= (int)$ch['church_info_id'] === $selectedId ? 'selected' : '' ?>><?= e($ch['church_name'] . ($ch['church_location'] ? ', ' . $ch['church_location'] : '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <noscript><button type="submit" class="btn-primary">Show</button></noscript>
                        </form>
                    <?php endif; ?>
                </div>
                <?php if ($c): ?>
                    <figure class="dn-hero-photo">
                        <?php if ($photoUrl): ?>
                            <img src="<?= e($photoUrl) ?>" alt="<?= e($c['church_name']) ?>">
                        <?php else: ?>
                            <span class="dn-hero-ph"><?= don_icon('church', 54) ?></span>
                        <?php endif; ?>
                        <figcaption>
                            <span class="dn-hero-pin"><?= don_icon('pin', 16) ?></span>
                            <span><b><?= e($c['church_name']) ?></b><?php if ($c['church_location']): ?><small><?= e($c['church_location']) ?></small><?php endif; ?></span>
                        </figcaption>
                    </figure>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="dn-band">
    <div class="dn-wrap">
<?php if (!$c): ?>
    <p class="dir-empty">No churches have been added yet.</p>
<?php else: ?>
    <?php if ($canManage): ?>
        <div class="don-admin-bar dn-admin">
            <span>You manage this church.</span>
            <a href="donation-manage.php?church=<?= $selectedId ?>">Manage donations &rarr;</a>
            <a href="collection-manage.php?church=<?= $selectedId ?>">Weekly collection &rarr;</a>
            <?php if (($_SESSION['role'] ?? '') === 'super_admin'): ?><a href="donation-report.php">Donation reports &rarr;</a><?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="dn-grid<?= $collections || $anyMethod ? '' : ' is-single' ?>">
        <div class="dn-main">
    <?php if ($done): ?>
        <div class="don-thanks dn-card" role="status">
            <span class="don-thanks-icon"><?= don_icon('heart', 26) ?></span>
            <h2>Thank you for your generosity!</h2>
            <p>Your donation to <strong><?= e($done['church']) ?></strong> has been submitted<?= $done['ref'] ? ' (reference <strong>' . e($done['ref']) . '</strong>)' : '' ?>.
               The parish will check your receipt and email you once it is confirmed.</p>
            <a class="btn-new" href="donations.php?church=<?= $selectedId ?>">Make another donation</a>
        </div>
    <?php elseif (!$anyMethod): ?>
        <div class="don-thanks is-empty dn-card" role="status">
            <span class="don-thanks-icon"><?= don_icon('bank', 26) ?></span>
            <h2>Online giving is not set up yet</h2>
            <p><?= e($c['church_name']) ?> has not added its payment details yet. Please contact the parish office
               (see <a href="church-information.php?church=<?= $selectedId ?>">church information</a>) or choose another church above.</p>
        </div>
    <?php else: ?>
        <?php if (isset($errors['form'])): ?><div class="alert info-alert" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
        <?php if ($errors && !isset($errors['form'])): ?><div class="alert info-alert" role="alert">Please check the highlighted fields.</div><?php endif; ?>

        <form method="post" action="donations.php?church=<?= $selectedId ?>" enctype="multipart/form-data" class="dn-form" id="don-form" novalidate>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="church" value="<?= $selectedId ?>">
            <input type="text" name="website" class="hp-field" tabindex="-1" autocomplete="off" aria-hidden="true">

            <section class="dn-card">
                <h2><span class="dn-num">1</span> Your gift</h2>
                <div class="dn-field<?= isset($errors['amount']) ? ' has-error' : '' ?>">
                    <label for="d-amount">Amount (RM)</label>
                    <div class="dn-amounts">
                        <?php foreach ($presets as $p): ?>
                            <button type="button" class="dn-chip<?= $oldAmt !== '' && (float)$oldAmt === (float)$p ? ' is-on' : '' ?>" data-amt="<?= $p ?>">RM <?= $p ?></button>
                        <?php endforeach; ?>
                        <div class="dn-other"><span aria-hidden="true">RM</span><input type="text" id="d-amount" name="amount" inputmode="decimal" placeholder="Other amount" value="<?= e($old['amount']) ?>" required></div>
                    </div>
                    <?php if (isset($errors['amount'])): ?><span class="row-error"><?= e($errors['amount']) ?></span><?php endif; ?>
                </div>
                <div class="dn-fields">
                    <div class="dn-field<?= isset($errors['purpose']) ? ' has-error' : '' ?>">
                        <label for="d-purpose">Reference / purpose</label>
                        <input type="text" id="d-purpose" name="purpose" maxlength="100" list="purpose-list" placeholder="e.g. Weekly Offering" value="<?= e($old['purpose']) ?>" required>
                        <datalist id="purpose-list"><?php foreach ($purposes as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?></datalist>
                        <?php if (isset($errors['purpose'])): ?><span class="row-error"><?= e($errors['purpose']) ?></span><?php endif; ?>
                    </div>
                    <div class="dn-field<?= isset($errors['name']) ? ' has-error' : '' ?>">
                        <label for="d-name">Full name</label>
                        <input type="text" id="d-name" name="name" maxlength="100" autocomplete="name" value="<?= e($old['name']) ?>" required>
                        <?php if (isset($errors['name'])): ?><span class="row-error"><?= e($errors['name']) ?></span><?php endif; ?>
                    </div>
                    <div class="dn-field<?= isset($errors['phone']) ? ' has-error' : '' ?>">
                        <label for="d-phone">Contact number</label>
                        <input type="tel" id="d-phone" name="phone" maxlength="30" autocomplete="tel" placeholder="+60 12-345 6789" value="<?= e($old['phone']) ?>" required>
                        <?php if (isset($errors['phone'])): ?><span class="row-error"><?= e($errors['phone']) ?></span><?php endif; ?>
                    </div>
                    <div class="dn-field<?= isset($errors['email']) ? ' has-error' : '' ?>">
                        <label for="d-email">Email</label>
                        <input type="email" id="d-email" name="email" maxlength="100" autocomplete="email" value="<?= e($old['email']) ?>" required>
                        <?php if (isset($errors['email'])): ?><span class="row-error"><?= e($errors['email']) ?></span><?php endif; ?>
                    </div>
                    <div class="dn-field is-wide<?= isset($errors['message']) ? ' has-error' : '' ?>">
                        <label for="d-message">Message <span class="opt">(optional)</span></label>
                        <textarea id="d-message" name="message" rows="3" maxlength="500" placeholder="A prayer intention or a note for the parish office"><?= e($old['message']) ?></textarea>
                        <?php if (isset($errors['message'])): ?><span class="row-error"><?= e($errors['message']) ?></span><?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="dn-card">
                <h2><span class="dn-num">2</span> Payment method</h2>
                <div class="dn-methods" role="radiogroup" aria-label="Payment method">
                    <?php foreach (['bank' => ['bank', 'Online banking or ATM'], 'qr' => ['qr', 'Scan with any banking or e-wallet app']] as $key => [$ico, $sub]): $on = $methods[$key]; ?>
                        <label class="dn-method<?= $on ? '' : ' is-off' ?>">
                            <input type="radio" name="method" value="<?= $key ?>" <?= $old['method'] === $key ? 'checked' : '' ?> <?= $on ? '' : 'disabled' ?>>
                            <span class="dn-method-ico"><?= don_icon($ico, 18) ?></span>
                            <span class="dn-method-t"><b><?= e(DONATION_METHODS[$key]) ?></b><small><?= $on ? $sub : 'Not available for this parish yet' ?></small></span>
                            <?php if ($on): ?><span class="dn-method-tick"><?= don_icon('tick', 12) ?></span><?php else: ?><span class="dn-soon">Soon</span><?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php if (isset($errors['method'])): ?><span class="row-error"><?= e($errors['method']) ?></span><?php endif; ?>

                <?php if ($methods['bank']): ?>
                    <div class="dn-panel" data-method="bank"<?= $old['method'] === 'bank' ? '' : ' hidden' ?>>
                        <span class="dn-panel-k">Transfer to</span>
                        <?php if (stripos($c['donation_account_name'], '(SAMPLE)') !== false): ?>
                            <em class="dn-sample">Demo account for testing &ndash; please do not send real money.</em>
                        <?php endif; ?>
                        <div class="dn-panel-row">
                            <div><small>Bank</small><b><?= e($c['donation_bank_name']) ?></b></div>
                            <div><small>Account name</small><b><?= e($c['donation_account_name']) ?></b></div>
                        </div>
                        <div class="dn-acct">
                            <div><small>Account number</small><b id="acct-no"><?= e($c['donation_account_number']) ?></b></div>
                            <button type="button" class="dn-copy copy-btn" data-copy="#acct-no" title="Copy account number"><?= don_icon('copy', 13) ?> <span>Copy</span></button>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($methods['qr']): ?>
                    <div class="dn-panel is-qr" data-method="qr"<?= $old['method'] === 'qr' ? '' : ' hidden' ?>>
                        <img src="<?= e(qr_image_url($c['donation_qr_image'])) ?>" alt="DuitNow QR code for <?= e($c['church_name']) ?>" width="170" height="170">
                        <div>
                            <span class="dn-panel-k">Scan to pay</span>
                            <p>Scan with any DuitNow-enabled banking or e-wallet app, then upload your receipt below.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <section class="dn-card">
                <h2><span class="dn-num">3</span> Upload your receipt</h2>
                <label class="dropzone dn-drop<?= isset($errors['receipt']) ? ' has-error' : '' ?>" id="dropzone" for="d-receipt">
                    <span class="dz-icon"><?= don_icon('upload', 22) ?></span>
                    <strong>Click to upload or drag and drop</strong>
                    <small>JPG, PNG or PDF &middot; up to 5 MB</small>
                    <span class="dz-file" id="dz-file" hidden></span>
                    <input type="file" id="d-receipt" name="receipt" accept="image/jpeg,image/png,application/pdf,.pdf" hidden>
                </label>
                <?php if (isset($errors['receipt'])): ?><span class="row-error"><?= e($errors['receipt']) ?></span><?php endif; ?>
            </section>

            <div class="dn-actions">
                <a href="index.php#services" class="dn-cancel">Cancel</a>
                <button type="submit" class="dn-give"><?= don_icon('heart', 15) ?> <span id="dn-give-label">Give<?= $oldAmt !== '' && is_numeric($oldAmt) && (float)$oldAmt > 0 ? ' RM ' . e(number_format((float)$oldAmt, 2)) : '' ?></span></button>
            </div>
        </form>
    <?php endif; ?>
        </div>

        <?php if ($collections || $anyMethod): ?>
            <aside class="dn-side">
                <?php if ($collections): $recent = array_map(fn($r) => (float)$r['amount'], $collections); ?>
                    <section class="dn-card dn-wc" id="weekly-collection" aria-labelledby="wc-title">
                        <span class="dn-panel-k is-gold">Weekly collection</span>
                        <h2 id="wc-title">Thank you for your generosity</h2>
                        <p class="dn-wc-sub"><?= e($c['church_name']) ?> &middot; last <?= count($collections) === 1 ? 'week' : count($collections) . ' weeks' ?></p>
                        <div class="dn-wc-stats">
                            <div class="is-dark"><span>Latest week</span><b><?= rm(end($recent)) ?></b><small><?= e(date('j M Y', strtotime(end($collections)['week_date']))) ?></small></div>
                            <div><span>Weekly average</span><b><?= rm(array_sum($recent) / count($recent)) ?></b><small><?= count($recent) ?> week<?= count($recent) === 1 ? '' : 's' ?></small></div>
                        </div>
                        <?php render_collection_mini($collections, 'Weekly Collection for ' . $c['church_name']); ?>
                    </section>
                <?php endif; ?>
                <?php if ($anyMethod && !$done): ?>
                    <div class="dn-how">
                        <b><?= don_icon('shield', 16) ?> How it works</b>
                        <p>Send your gift with the payment method you chose, then upload the receipt so the parish office can match it to your name.</p>
                    </div>
                <?php endif; ?>
            </aside>
        <?php endif; ?>
    </div>
<?php endif; ?>
    </div>
    </div>
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

// Amount: the RM chips fill the amount box; the Give button shows the amount.
(function () {
    var input = document.getElementById('d-amount');
    var label = document.getElementById('dn-give-label');
    if (!input) return;
    var chips = document.querySelectorAll('.dn-chip');
    function sync() {
        var v = parseFloat(input.value.replace(/^\s*RM\s*/i, '').replace(/[,\s]/g, ''));
        chips.forEach(function (c) { c.classList.toggle('is-on', v === parseFloat(c.dataset.amt)); });
        label.textContent = v > 0 ? 'Give RM ' + v.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : 'Give';
    }
    chips.forEach(function (c) {
        c.addEventListener('click', function () { input.value = c.dataset.amt; sync(); });
    });
    input.addEventListener('input', sync);
})();

// Payment method: show the details of the one chosen.
(function () {
    var radios = document.querySelectorAll('.dn-method input');
    function show() {
        var on = document.querySelector('.dn-method input:checked');
        document.querySelectorAll('.dn-panel').forEach(function (p) { p.hidden = !on || p.dataset.method !== on.value; });
    }
    radios.forEach(function (r) { r.addEventListener('change', show); });
})();

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
