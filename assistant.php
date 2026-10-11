<?php
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/login_gate.php';
require_signed_in('Intelligent Church Assistant');   // visitors get "please login or register" instead of this page
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/assistant_engine.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$pageTitle = 'Intelligent Church Assistant';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="sx-page as-page">
    <?php back_button('index.php#services', 'Back to Services'); ?>

    <header class="page-heading">
        <h1>Welcome back!</h1>
        <p>I am your Intelligent Church Assistant. How can I help you today?</p>
    </header>

    <div class="as-chips" role="group" aria-label="Quick questions">
        <?php foreach (ASSISTANT_CHIPS as $chip): ?><button type="button" class="as-chip" data-ask="<?= e($chip) ?>"><?= e($chip) ?></button><?php endforeach; ?>
    </div>

    <section class="as-chat" aria-label="Chat with the assistant">
        <div class="as-log" id="as-log" role="log" aria-live="polite" tabindex="0">
            <p class="as-empty" id="as-empty">Type a question below, or tap one of the buttons above.</p>
        </div>
        <form class="as-form" id="as-form" autocomplete="off">
            <span class="as-form-ico" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></span>
            <input type="text" id="as-input" maxlength="300" placeholder="Type your question here..." aria-label="Type your question here" required>
            <button type="submit" class="as-send" id="as-send" aria-label="Send"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3.4 20.4 21.5 12.6a.75.75 0 0 0 0-1.38L3.4 3.4a.75.75 0 0 0-1.05.86l1.7 6.1a.75.75 0 0 0 .6.54L13 12l-8.35 1.1a.75.75 0 0 0-.6.54l-1.7 6.1a.75.75 0 0 0 1.05.66Z"/></svg></button>
        </form>
    </section>
    <p class="as-note">I answer from this portal's own information. For anything urgent, please call the parish office. <button type="button" class="as-clear" id="as-clear">Clear chat</button></p>
</main>
<script>
(function () {
    var log = document.getElementById('as-log'), empty = document.getElementById('as-empty');
    var form = document.getElementById('as-form'), input = document.getElementById('as-input'), send = document.getElementById('as-send');
    var csrf = <?= json_encode($_SESSION['csrf']) ?>;
    var KEY = 'as-chat-<?= (int)$_SESSION['user_id'] ?>-<?= e($_SESSION['role'] ?? '') ?>';
    var history = [];
    try { history = JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch (e) { history = []; }
    var SAFE_LINK = /^((superadmin|churchadmin)\/)?[a-z0-9\-]+\.php(\?[\w=&\-]*)?(#[\w\-]*)?$/i;   // only pages of this site

    function save() { try { sessionStorage.setItem(KEY, JSON.stringify(history.slice(-40))); } catch (e) {} }
    function scroll() { log.scrollTop = log.scrollHeight; }

    // Text is put on the page as text (never as HTML); **bold** becomes <b>.
    function richText(target, text) {
        text.split('\n').forEach(function (line, i) {
            var p = document.createElement('p');
            line.split('**').forEach(function (chunk, j) {
                if (!chunk) return;
                var node = document.createTextNode(chunk);
                if (j % 2 === 1) { var b = document.createElement('b'); b.appendChild(node); p.appendChild(b); } else p.appendChild(node);
            });
            target.appendChild(p);
        });
    }

    function bubble(who, text, links) {
        if (empty) { empty.remove(); empty = null; }
        var row = document.createElement('div');
        row.className = 'as-row is-' + who;
        var ico = document.createElement('span');
        ico.className = 'as-ico';
        ico.innerHTML = who === 'me'
            ? '<svg width="26" height="26" viewBox="0 0 40 40" aria-hidden="true"><circle cx="20" cy="20" r="19" fill="#6b6b76"/><circle cx="20" cy="15" r="6.5" fill="#fff"/><path d="M6.5 32.5c2.5-6 7.5-8.5 13.5-8.5s11 2.5 13.5 8.5A19 19 0 0 1 20 39a19 19 0 0 1-13.500-6.500z" fill="#fff"/></svg>'
            : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>';
        var b = document.createElement('div');
        b.className = 'as-bubble';
        richText(b, text);
        (links || []).forEach(function (l) {
            if (!Array.isArray(l) || !SAFE_LINK.test(l[1])) return;
            var a = document.createElement('a');
            a.className = 'as-link';
            a.href = l[1];
            a.textContent = l[0];
            b.appendChild(a);
        });
        row.appendChild(ico); row.appendChild(b);
        log.appendChild(row);
        scroll();
        return row;
    }

    history.forEach(function (m) { bubble(m.who, m.text, m.links); });

    function ask(text) {
        text = (text || '').trim();
        if (!text) return;
        var past = history.slice(-6).map(function (m) { return { who: m.who, text: String(m.text || '').slice(0, 600) }; });   // context for follow-up questions
        bubble('me', text);
        history.push({ who: 'me', text: text }); save();
        input.value = '';
        send.disabled = true;
        var typing = bubble('bot', '…');
        typing.classList.add('is-typing');
        fetch('assistant-api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ message: text, history: past }), credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                typing.remove();
                var msg = res.ok ? res.j.text : (res.j.error || 'Sorry, something went wrong.');
                var links = res.ok ? res.j.links : [];
                bubble('bot', msg, links);
                history.push({ who: 'bot', text: msg, links: links }); save();
            })
            .catch(function () { typing.remove(); bubble('bot', 'Sorry, I could not reach the server. Please try again.'); })
            .then(function () { send.disabled = false; input.focus(); });
    }

    form.addEventListener('submit', function (ev) { ev.preventDefault(); ask(input.value); });
    document.querySelectorAll('.as-chip').forEach(function (c) { c.addEventListener('click', function () { ask(c.dataset.ask); }); });
    document.getElementById('as-clear').addEventListener('click', function () {
        if (!history.length || !confirm('Clear this chat? This cannot be undone.')) return;
        history = []; save(); log.innerHTML = '<p class="as-empty" id="as-empty">Type a question below, or tap one of the buttons above.</p>'; empty = document.getElementById('as-empty');
    });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
