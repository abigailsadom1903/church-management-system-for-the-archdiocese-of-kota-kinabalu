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

$firstName = trim(explode(' ', trim((string)($_SESSION['user_name'] ?? '')))[0]);
$initials = '';
foreach (array_slice(preg_split('/\s+/', trim((string)($_SESSION['user_name'] ?? ''))) ?: [], 0, 2) as $w) $initials .= mb_strtoupper(mb_substr($w, 0, 1));

// The suggestion cards on the welcome screen: an icon and a line under each quick question (anything else gets the chat icon).
$chipIcons = [
    'calendar' => '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
    'heart'    => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
    'drop'     => '<path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5S5 13 5 15a7 7 0 0 0 7 7z"/>',
    'hand'     => '<path d="M11 14h2a2 2 0 1 0 0-4h-3c-.6 0-1.1.2-1.4.6L3 16"/><path d="m7 20 1.6-1.4c.3-.4.8-.6 1.4-.6h4c1.1 0 2.1-.4 2.8-1.2l4.6-4.4a2 2 0 0 0-2.75-2.91l-4.2 3.9"/><path d="m2 15 6 6"/>',
    'chat'     => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
];
$chipInfo = [
    'Find Mass Times'              => ['calendar', 'Weekend and weekday Masses near you'],
    'Request a Prayer'             => ['heart', 'Send a prayer intention to your parish'],
    'How to register for Baptism?' => ['drop', 'Steps, documents and dates'],
    'Support for Sick Visit'       => ['hand', 'Ask a priest to visit the sick'],
];
$svg = fn(string $paths, int $size = 18) => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
// the assistant's face: a gold robot on the purple badge
$botIcon = '<svg width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . '<path d="M12 4.6V7"/><circle cx="12" cy="3.4" r="1.2" fill="currentColor" stroke="none"/>'
         . '<rect x="4" y="7" width="16" height="12" rx="3.5"/><path d="M2 11.5v3.5"/><path d="M22 11.5v3.5"/>'
         . '<circle cx="9" cy="12.4" r="1.4" fill="currentColor" stroke="none"/><circle cx="15" cy="12.4" r="1.4" fill="currentColor" stroke="none"/>'
         . '<path d="M9.5 15.8h5"/></svg>';

$pageTitle = 'Intelligent Church Assistant';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="sx-page as-page">
    <?php back_button('index.php#services', 'Back to Services'); ?>

    <header class="page-heading">
        <h1>Intelligent Church Assistant</h1>
        <p>Quick answers about your parish, any time of day.</p>
    </header>

    <section class="as-chat" aria-label="Chat with the assistant">
        <header class="as-head">
            <span class="as-avatar is-head"><?= sprintf($botIcon, 24) ?></span>
            <div class="as-head-text">
                <h2>Church Assistant</h2>
                <p><i class="as-dot" aria-hidden="true"></i> Online &middot; parish help and general questions</p>
            </div>
            <button type="button" class="as-clear" id="as-clear" title="Clear this chat"><?= $svg('<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>', 15) ?><span>Clear</span></button>
        </header>

        <div class="as-log" id="as-log" role="log" aria-live="polite" tabindex="0">
            <div class="as-welcome" id="as-empty">
                <span class="as-avatar is-hero"><?= sprintf($botIcon, 42) ?></span>
                <h2>Peace be with you<?= $firstName !== '' ? ', ' . e($firstName) : '' ?>!</h2>
                <p>I can help with Mass times, sacraments, events, donations and your own requests, and answer questions about the faith or anything else you are curious about. Pick a topic or ask me anything.</p>
                <div class="as-suggest" role="group" aria-label="Suggested questions">
                    <?php foreach (ASSISTANT_CHIPS as $chip): [$ico, $hint] = $chipInfo[$chip] ?? ['chat', 'Ask the assistant']; ?>
                        <button type="button" class="as-card" data-ask="<?= e($chip) ?>">
                            <span class="as-card-ico"><?= $svg($chipIcons[$ico]) ?></span>
                            <span class="as-card-text"><b><?= e($chip) ?></b><small><?= e($hint) ?></small></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="as-quick" id="as-quick" role="group" aria-label="Quick questions" hidden>
            <?php foreach (ASSISTANT_CHIPS as $chip): ?><button type="button" class="as-chip" data-ask="<?= e($chip) ?>"><?= e($chip) ?></button><?php endforeach; ?>
        </div>

        <form class="as-form" id="as-form" autocomplete="off">
            <input type="text" id="as-input" maxlength="300" placeholder="Ask about Mass times, sacraments, events..." aria-label="Type your question here" required>
            <button type="submit" class="as-send" id="as-send" aria-label="Send"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3.4 20.4 21.5 12.6a.75.75 0 0 0 0-1.38L3.4 3.4a.75.75 0 0 0-1.05.86l1.7 6.1a.75.75 0 0 0 .6.54L13 12l-8.35 1.1a.75.75 0 0 0-.6.54l-1.7 6.1a.75.75 0 0 0 1.05.66Z"/></svg></button>
        </form>
    </section>
    <p class="as-note">Parish details come from this portal's own information; other answers are general information and may contain mistakes. For anything urgent, please call the parish office.</p>
</main>
<script>
(function () {
    var log = document.getElementById('as-log'), welcome = document.getElementById('as-empty'), quick = document.getElementById('as-quick');
    var form = document.getElementById('as-form'), input = document.getElementById('as-input'), send = document.getElementById('as-send');
    var csrf = <?= json_encode($_SESSION['csrf']) ?>;
    var KEY = 'as-chat-<?= (int)$_SESSION['user_id'] ?>-<?= e($_SESSION['role'] ?? '') ?>';
    var BOT_ICON = <?= json_encode(sprintf($botIcon, 20)) ?>;
    var INITIALS = <?= json_encode($initials ?: '?') ?>;
    var history = [];
    try { history = JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch (e) { history = []; }
    var SAFE_LINK = /^((superadmin|churchadmin)\/)?[a-z0-9\-]+\.php(\?[\w=&\-]*)?(#[\w\-]*)?$/i;   // only pages of this site

    function save() { try { sessionStorage.setItem(KEY, JSON.stringify(history.slice(-40))); } catch (e) {} }
    function scroll() { log.scrollTop = log.scrollHeight; }
    function clock(t) { var d = new Date(t); return isNaN(d) ? '' : d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); }

    // The welcome screen shows until the first message; after that the questions sit as small chips above the box.
    function started(on) {
        welcome.hidden = on;
        quick.hidden = !on;
    }

    // Text is put on the page as text (never as HTML); **bold** becomes <b>.
    function richText(target, text) {
        text.split('\n').forEach(function (line) {
            if (!line.trim()) return;
            var p = document.createElement('p');
            line.split('**').forEach(function (chunk, j) {
                if (!chunk) return;
                var node = document.createTextNode(chunk);
                if (j % 2 === 1) { var b = document.createElement('b'); b.appendChild(node); p.appendChild(b); } else p.appendChild(node);
            });
            target.appendChild(p);
        });
    }

    function bubble(who, text, links, time) {
        started(true);
        var row = document.createElement('div');
        row.className = 'as-row is-' + who;
        var ico = document.createElement('span');
        ico.className = 'as-avatar is-' + who;
        if (who === 'me') ico.textContent = INITIALS; else ico.innerHTML = BOT_ICON;
        var col = document.createElement('div');
        col.className = 'as-col';
        var b = document.createElement('div');
        b.className = 'as-bubble';
        if (text === null) {
            b.innerHTML = '<span class="as-dots" aria-label="The assistant is typing"><i></i><i></i><i></i></span>';
        } else {
            richText(b, text);
        }
        var shown = (links || []).filter(function (l) { return Array.isArray(l) && SAFE_LINK.test(l[1]); });
        if (shown.length) {
            var wrap = document.createElement('div');
            wrap.className = 'as-links';
            shown.forEach(function (l) {
                var a = document.createElement('a');
                a.className = 'as-link';
                a.href = l[1];
                a.textContent = l[0];
                wrap.appendChild(a);
            });
            b.appendChild(wrap);
        }
        col.appendChild(b);
        if (time) {
            var t = document.createElement('span');
            t.className = 'as-time';
            t.textContent = (who === 'me' ? 'You' : 'Assistant') + ' · ' + clock(time);
            col.appendChild(t);
        }
        row.appendChild(ico); row.appendChild(col);
        log.appendChild(row);
        scroll();
        return row;
    }

    history.forEach(function (m) { bubble(m.who, m.text, m.links, m.t); });
    started(history.length > 0);

    function ask(text) {
        text = (text || '').trim();
        if (!text || send.disabled) return;
        var past = history.slice(-6).map(function (m) { return { who: m.who, text: String(m.text || '').slice(0, 600) }; });   // context for follow-up questions
        var now = Date.now();
        bubble('me', text, null, now);
        history.push({ who: 'me', text: text, t: now }); save();
        input.value = '';
        send.disabled = true;
        var typing = bubble('bot', null);
        typing.classList.add('is-typing');
        fetch('assistant-api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ message: text, history: past }), credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                typing.remove();
                var msg = res.ok ? res.j.text : (res.j.error || 'Sorry, something went wrong.');
                var links = res.ok ? res.j.links : [];
                var at = Date.now();
                bubble('bot', msg, links, at);
                history.push({ who: 'bot', text: msg, links: links, t: at }); save();
            })
            .catch(function () { typing.remove(); bubble('bot', 'Sorry, I could not reach the server. Please try again.', null, Date.now()); })
            .then(function () { send.disabled = false; input.focus(); });
    }

    form.addEventListener('submit', function (ev) { ev.preventDefault(); ask(input.value); });
    document.querySelectorAll('[data-ask]').forEach(function (c) { c.addEventListener('click', function () { ask(c.dataset.ask); }); });
    document.getElementById('as-clear').addEventListener('click', function () {
        if (!history.length || !confirm('Clear this chat? This cannot be undone.')) return;
        history = []; save();
        log.querySelectorAll('.as-row').forEach(function (r) { r.remove(); });
        started(false);
        input.focus();
    });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
