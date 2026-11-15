<?php
// "Home" section (one-page site). Needs $pdo. Greets by first name once someone is signed in.
$homeStats = $pdo->query('SELECT stat_key, stat_label, stat_value FROM site_stats ORDER BY sort_order')->fetchAll();

$homeFirstName = '';
if (!empty($_SESSION['user_id']) && !empty($_SESSION['user_name'])) {
    // Parishioners by first name; admin accounts ("Super Admin", "Admin Sacred Heart") by their full account name.
    $homeFirstName = in_array($_SESSION['role'] ?? '', ['super_admin', 'church_admin'], true)
        ? trim($_SESSION['user_name'])
        : strtok(trim($_SESSION['user_name']), ' ');
}
$homeIsSuper = !empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'super_admin';
$homeE = fn(?string $s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

require_once __DIR__ . '/../news_feed.php';
$homeNews = catholic_sabah_news(6);   // latest posts of catholicsabah.com (kept for 30 minutes)
?>
<div class="home-page">
    <section class="home-hero">
        <img src="assets/images/logo.png" alt="Archdiocese of Kota Kinabalu logo">
        <h2>Church Management System<br>The Archdiocese of Kota Kinabalu</h2>
        <p>United in Faith, Connected in Service</p>
        <a class="home-mass-btn" href="mass-finder.php"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg> Find a Mass near me</a>
    </section>

    <div class="home-body">
        <?php if ($homeFirstName !== ''):
            $homeHour = (int)date('G');
            $homeGreet = $homeHour < 12 ? 'Good morning' : ($homeHour < 18 ? 'Good afternoon' : 'Good evening');
        ?>
            <section class="home-greet">
                <div class="home-greet-text">
                    <span class="home-greet-date">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>
                        <?= $homeE(date('l, j F Y')) ?>
                    </span>
                    <h1 class="home-welcome"><?= $homeE($homeGreet) ?>, <span><?= $homeE($homeFirstName) ?></span>!</h1>
                </div>
            </section>
        <?php else: ?>
            <h1 class="home-welcome"><a href="register.php">Register</a> or <a href="login.php">login</a> to get started</h1>
        <?php endif; ?>

        <?php
        // Parishioners get a small "My account" panel on the left of the news; admins and visitors get the news alone.
        require_once __DIR__ . '/user_overview.php';
        ob_start();
        render_user_overview($pdo);
        $homeAccount = ob_get_clean();
        ?>
        <?php if ($homeAccount !== ''): ?><div class="home-split"><?= $homeAccount ?><?php endif; ?>

        <section class="news-show" id="news" aria-roledescription="carousel" aria-labelledby="news-title">
            <div class="news-head">
                <h2 id="news-title">Catholic Sabah News</h2>
                <a href="<?= $homeE(NEWS_SITE) ?>" target="_blank" rel="noopener">Visit catholicsabah.com &rarr;</a>
            </div>
            <div class="news-stage">
                <?php if (!$homeNews): // the news site has not been reached yet: one card that still takes people there ?>
                    <a class="news-slide is-active is-plain" href="<?= $homeE(NEWS_SITE) ?>" target="_blank" rel="noopener">
                        <span class="news-text"><span class="news-title">Read the latest news of the Church in Sabah</span><span class="news-more">Open Catholic Sabah <span aria-hidden="true">&rarr;</span></span></span>
                    </a>
                <?php endif; ?>
                <?php foreach ($homeNews as $i => $n): ?>
                    <a class="news-slide<?= $i === 0 ? ' is-active' : '' ?><?= $n['image'] ? '' : ' is-plain' ?>" href="<?= $homeE($n['link']) ?>" target="_blank" rel="noopener"
                       aria-roledescription="slide" aria-label="<?= $i + 1 ?> of <?= count($homeNews) ?>: <?= $homeE($n['title']) ?> (opens catholicsabah.com)"<?= $i === 0 ? '' : ' tabindex="-1" aria-hidden="true"' ?>>
                        <?php if ($n['image']): ?><img src="<?= $homeE($n['image']) ?>" alt="" loading="<?= $i === 0 ? 'eager' : 'lazy' ?>" referrerpolicy="no-referrer"><?php endif; ?>
                        <span class="news-text">
                            <?php if ($n['date']): ?><span class="news-date"><?= $homeE(date('j F Y', strtotime($n['date']))) ?></span><?php endif; ?>
                            <span class="news-title"><?= $homeE($n['title']) ?></span>
                            <span class="news-more">Read on Catholic Sabah <span aria-hidden="true">&rarr;</span></span>
                        </span>
                    </a>
                <?php endforeach; ?>
                <?php if (count($homeNews) > 1): ?>
                    <button type="button" class="news-nav is-prev" aria-label="Previous news"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg></button>
                    <button type="button" class="news-nav is-next" aria-label="Next news"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></button>
                <?php endif; ?>
            </div>
            <?php if (count($homeNews) > 1): ?>
                <div class="news-dots" role="group" aria-label="Choose a news item">
                    <?php foreach ($homeNews as $i => $n): ?><button type="button" class="news-dot<?= $i === 0 ? ' is-active' : '' ?>" aria-label="News <?= $i + 1 ?>"<?= $i === 0 ? ' aria-current="true"' : '' ?>></button><?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <script>
        // Catholic Sabah News: one item at a time, moving on every 5 seconds. It waits while someone is pointing at it or
        // has it focused, and does not move by itself for people who asked their device for less motion.
        (function () {
            var box = document.getElementById('news');
            var slides = box ? box.querySelectorAll('.news-slide') : [];
            if (slides.length < 2) return;
            var dots = box.querySelectorAll('.news-dot'), at = 0, timer = null;
            var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            function show(i) {
                at = (i + slides.length) % slides.length;
                slides.forEach(function (s, n) {
                    var on = n === at;
                    s.classList.toggle('is-active', on);
                    if (on) { s.removeAttribute('tabindex'); s.removeAttribute('aria-hidden'); } else { s.setAttribute('tabindex', '-1'); s.setAttribute('aria-hidden', 'true'); }
                });
                dots.forEach(function (d, n) { d.classList.toggle('is-active', n === at); if (n === at) d.setAttribute('aria-current', 'true'); else d.removeAttribute('aria-current'); });
            }
            function play() { stop(); if (!still) timer = setInterval(function () { show(at + 1); }, 5000); }
            function stop() { if (timer) { clearInterval(timer); timer = null; } }
            box.querySelector('.is-prev').addEventListener('click', function () { show(at - 1); play(); });
            box.querySelector('.is-next').addEventListener('click', function () { show(at + 1); play(); });
            dots.forEach(function (d, n) { d.addEventListener('click', function () { show(n); play(); }); });
            ['mouseenter', 'focusin'].forEach(function (t) { box.addEventListener(t, stop); });
            ['mouseleave', 'focusout'].forEach(function (t) { box.addEventListener(t, play); });
            document.addEventListener('visibilitychange', function () { if (document.hidden) stop(); else play(); });
            play();
        })();
        </script>
        <?php if ($homeAccount !== ''): ?></div><?php endif; ?>

        <h2 class="home-lead">The Archdiocese at a glance</h2>
        <?php
        // icon for each statistic (by its key); any other statistic gets a chart icon
        $homeStatIcons = [
            'population'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'parishes'    => '<path d="m18 7 4 2v11a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9l4-2"/><path d="M14 22v-4a2 2 0 0 0-4 0v4"/><path d="M18 22V5l-6-3-6 3v17"/><path d="M12 7v5"/><path d="M10 9h4"/>',
            'outstations' => '<path d="M21 10c0 7-9 12-9 12s-9-5-9-12a9 9 0 0 1 18 0Z"/><path d="M12 7v6"/><path d="M9.5 9.5h5"/>',
            'priests'     => '<circle cx="12" cy="7" r="4"/><path d="M5.5 21a6.5 6.5 0 0 1 13 0"/><path d="M11 14.5h2v3h-2Z"/>',
        ];
        ?>

        <?php if (!empty($_GET['saved'])): ?>
            <div class="alert success home-alert" role="status">Statistics updated.</div>
        <?php endif; ?>

        <section class="home-stats" aria-label="Archdiocese statistics">
            <?php foreach ($homeStats as $s): ?>
                <div class="home-stat">
                    <?php if ($homeIsSuper): ?>
                        <a class="home-stat-edit" href="superadmin/statistics.php?focus=<?= $homeE(urlencode($s['stat_key'])) ?>" title="Edit <?= $homeE($s['stat_label']) ?>" aria-label="Edit <?= $homeE($s['stat_label']) ?>"><?= dash_icon('pencil', 15) ?></a>
                    <?php endif; ?>
                    <span class="home-stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $homeStatIcons[$s['stat_key']] ?? '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>' ?></svg></span>
                    <span class="home-stat-num" data-count="<?= (int)$s['stat_value'] ?>"><?= number_format((int)$s['stat_value']) ?></span>
                    <span class="home-stat-label"><?= $homeE($s['stat_label']) ?></span>
                </div>
            <?php endforeach; ?>
        </section>
        <script>
        // Statistics: each number counts up from 0 the first time it scrolls into view. The real figure is already in
        // the page, so it still shows without JavaScript, and it does not move for people who asked for less motion.
        (function () {
            var nums = document.querySelectorAll('.home-stat-num[data-count]');
            if (!nums.length || !('IntersectionObserver' in window)) return;
            if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            function run(el) {
                var end = parseInt(el.getAttribute('data-count'), 10) || 0, start = null, time = 1600;
                function step(now) {
                    if (start === null) start = now;
                    var p = Math.min((now - start) / time, 1);
                    el.textContent = Math.round(end * (1 - Math.pow(1 - p, 3))).toLocaleString('en-US');   // fast, then easing to a stop
                    if (p < 1) requestAnimationFrame(step);
                }
                requestAnimationFrame(step);
            }
            var seen = new IntersectionObserver(function (entries) {
                entries.forEach(function (en) {
                    if (!en.isIntersecting) return;
                    seen.unobserve(en.target);
                    run(en.target);
                });
            }, { threshold: 0.4 });
            nums.forEach(function (el) { seen.observe(el); });
        })();
        </script>

        <?php
        require_once __DIR__ . '/admin_overview.php';
        render_admin_overview($pdo);   // admins only; prints nothing for parishioners and visitors
        ?>

        <a class="scroll-cue" href="#services" aria-label="Scroll to the services">
            <span>Explore our services</span>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </a>
    </div>
</div>
