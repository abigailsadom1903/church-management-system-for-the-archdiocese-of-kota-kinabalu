<?php
// The whole public site on one scrolling page: Home, Services, Directory, About Us.
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/login_gate.php';

$signedIn = !empty($_SESSION['user_id']);   // signed-in people see the services; visitors see "login or register"

$pageTitle = 'Home';
$activeNav = '';
$onePage   = true;   // header: menu links scroll to the sections on this page
include __DIR__ . '/includes/header.php';
?>
<main class="onepage">
    <section id="home" class="one-section" aria-label="Home">
        <?php include __DIR__ . '/includes/sections/home.php'; ?>
    </section>

    <section id="services" class="one-section is-alt" aria-label="Services">
        <?php include __DIR__ . '/includes/sections/services.php'; ?>
    </section>

    <section id="directory" class="one-section" aria-label="Directory of churches">
        <?php include __DIR__ . '/includes/sections/directory.php'; ?>
    </section>

    <section id="about" class="one-section is-alt" aria-label="About us">
        <?php include __DIR__ . '/includes/sections/about.php'; ?>
    </section>
</main>

<?php if ($signedIn): ?>
    <a href="assistant.php" class="ai-fab" aria-label="Ask the Intelligent Church Assistant">
        <span class="ai-fab-badge" aria-hidden="true"></span>
        <span class="ai-fab-face" aria-hidden="true">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="7" x2="12" y2="3.3"/><circle cx="12" cy="2.4" r="1.1" fill="currentColor" stroke="none"/>
                <rect x="4.5" y="7" width="15" height="12" rx="4.5"/>
                <path d="M4.5 11.5H2.3"/><path d="M21.7 11.5h-2.2"/>
                <circle cx="9.3" cy="13.2" r="1.3" fill="currentColor" stroke="none"/>
                <circle cx="14.7" cy="13.2" r="1.3" fill="currentColor" stroke="none"/>
                <path d="M9.3 16.4c.9.9 4.5.9 5.4 0"/>
            </svg>
        </span>
        <span class="ai-fab-tip">Ask AI<small>Get help anytime</small></span>
    </a>
<?php endif; ?>
<button type="button" class="to-top" id="to-top" aria-label="Back to the top" hidden>
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m18 15-6-6-6 6"/></svg>
</button>

<script>
// Highlight the menu item of the section being read, and show a "back to top" button.
(function () {
    var links = document.querySelectorAll('.main-nav a[data-nav]');
    var sections = ['home', 'services', 'directory', 'about'].map(function (id) { return document.getElementById(id); });

    function setActive(id) {
        links.forEach(function (a) { a.classList.toggle('active', a.dataset.nav === id); });
    }

    if ('IntersectionObserver' in window) {
        var visible = {};
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) { visible[en.target.id] = en.intersectionRatio; });
            var best = null, bestRatio = 0;
            sections.forEach(function (s) { if ((visible[s.id] || 0) > bestRatio) { best = s.id; bestRatio = visible[s.id]; } });
            if (best) setActive(best);
        }, { rootMargin: '-90px 0px -35% 0px', threshold: [0, 0.1, 0.25, 0.5, 0.75, 1] });
        sections.forEach(function (s) { io.observe(s); });
    }

    var top = document.getElementById('to-top');
    function toggleTop() { top.hidden = window.scrollY < 600; }
    window.addEventListener('scroll', toggleTop, { passive: true });
    toggleTop();
    top.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
