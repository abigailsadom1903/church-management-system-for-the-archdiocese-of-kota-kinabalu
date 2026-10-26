<?php
require_once __DIR__ . '/dash_shell.php';
if (dash_use_shell()) echo "</div>
";   // closes the dashboard content area opened by the header
// Shared footer for every page (public pages and the dashboards).
// Dashboards live in a sub-folder and set $footerRoot = '../' first.
$footerRoot = $footerRoot ?? '';
$footBase = !empty($onePage) ? '' : $footerRoot . 'index.php';   // on the one-page site the links just scroll
$footSignedIn = !empty($_SESSION['user_id']);
?>
<?php require_once __DIR__ . '/site_content.php'; $footC = site_content(); $footE = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); ?>
<footer class="site-footer">
    <div class="foot-top">
        <a class="foot-brand" href="<?= $footBase ?>#home">
            <img src="<?= $footerRoot ?>assets/images/logo.png" alt="" width="34" height="37">
            <span>Archdiocese of Kota Kinabalu</span>
        </a>
        <nav class="foot-links" aria-label="Footer">
            <a href="<?= $footBase ?>#home">Home</a>
            <a href="<?= $footBase ?>#services">Services</a>
            <a href="<?= $footBase ?>#directory">Directory</a>
            <a href="<?= $footBase ?>#about">About Us</a>
            <?php if (!$footSignedIn): ?><a href="<?= $footerRoot ?>login.php">Login / Register</a><?php endif; ?>
        </nav>
    </div>

    <div class="foot-bottom">
        <p>&copy; <?= date('Y') ?> Archdiocese of Kota Kinabalu</p>
        <?php if ($footC['contact_phone'] !== '' || $footC['contact_email'] !== ''): ?>
            <p class="foot-contact">
                <?php if ($footC['contact_phone'] !== ''): ?>
                    <span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/></svg><span class="sr-only">Telephone: </span><?= $footE($footC['contact_phone']) ?></span>
                <?php endif; ?>
                <?php if ($footC['contact_email'] !== ''): ?>
                    <a href="mailto:<?= $footE($footC['contact_email']) ?>"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg><?= $footE($footC['contact_email']) ?></a>
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <nav aria-label="Legal">
            <a href="<?= $footerRoot ?>terms.php">Terms</a>
            <a href="<?= $footerRoot ?>privacy.php">Privacy</a>
            <a href="<?= $footerRoot ?>cookies.php">Cookies</a>
        </nav>
    </div>
</footer>

<div class="cfm-overlay" id="cfm-overlay" hidden>
    <div class="cfm-box is-danger" id="cfm-box" role="alertdialog" aria-modal="true" aria-labelledby="cfm-title" aria-describedby="cfm-msg">
        <span class="cfm-ico" aria-hidden="true">
            <svg class="for-danger" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>
            <svg class="for-approve" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            <svg class="for-reject" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </span>
        <h2 class="cfm-title" id="cfm-title">Are you sure?</h2>
        <p class="cfm-msg" id="cfm-msg"></p>
        <div class="cfm-actions">
            <button type="button" class="cfm-cancel" id="cfm-cancel">Cancel</button>
            <button type="button" class="cfm-ok" id="cfm-ok">Yes, Continue</button>
        </div>
    </div>
</div>
<script>
// One enhanced confirm dialog for every "This cannot be undone" action site-wide, instead of the plain
// browser confirm(). Works the same way for every page: put data-confirm="..." on a submit button, or
// data-confirm-form="..." on a <form>, exactly as before — nothing else needs to change.
// Optional on the same element: data-confirm-tone="approve|reject" (default: the red warning),
// data-confirm-title="Approve application?" and data-confirm-ok="Approve" (the confirm button's text).
(function () {
    var overlay = document.getElementById('cfm-overlay');
    if (!overlay) return;
    var box = document.getElementById('cfm-box');
    var titleEl = document.getElementById('cfm-title');
    var msgEl = document.getElementById('cfm-msg');
    var okBtn = document.getElementById('cfm-ok');
    var cancelBtn = document.getElementById('cfm-cancel');
    var pending = null;
    var lastFocus = null;

    function open(message, run, el) {
        var d = (el && el.dataset) || {};
        var tone = ['approve', 'reject'].indexOf(d.confirmTone) >= 0 ? d.confirmTone : 'danger';
        box.className = 'cfm-box is-' + tone;
        titleEl.textContent = d.confirmTitle || 'Are you sure?';
        okBtn.textContent = d.confirmOk || 'Yes, Continue';
        msgEl.textContent = message;
        pending = run;
        overlay.hidden = false;
        lastFocus = document.activeElement;
        cancelBtn.focus();
        document.body.style.overflow = 'hidden';
    }
    function close() {
        overlay.hidden = true;
        pending = null;
        document.body.style.overflow = '';
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }
    okBtn.addEventListener('click', function () { var run = pending; close(); if (run) run(); });
    cancelBtn.addEventListener('click', close);
    overlay.addEventListener('click', function (ev) { if (ev.target === overlay) close(); });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !overlay.hidden) close(); });

    document.querySelectorAll('[data-confirm]').forEach(function (btn) {
        btn.addEventListener('click', function (ev) {
            ev.preventDefault();
            open(btn.dataset.confirm, function () { if (btn.form) btn.form.requestSubmit(btn); }, btn);
        });
    });
    document.querySelectorAll('[data-confirm-form]').forEach(function (f) {
        f.addEventListener('submit', function (ev) {
            ev.preventDefault();
            open(f.dataset.confirmForm, function () { f.submit(); }, f);
        });
    });
})();
</script>
<script src="<?= $footerRoot ?>assets/js/photo-adjust.js?v=<?= @filemtime(__DIR__ . '/../assets/js/photo-adjust.js') ?: 1 ?>" defer></script>
<script src="<?= $footerRoot ?>assets/js/photo-view.js?v=<?= @filemtime(__DIR__ . '/../assets/js/photo-view.js') ?: 1 ?>" defer></script>
</body>
</html>
