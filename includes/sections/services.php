<?php
// "Our Services" section (one-page site). Needs: $signedIn (bool).
// Each service links to its page once that page exists; until then it shows "Coming soon".
$serviceRoot = dirname(__DIR__, 2);
$services = [
    ['title' => 'Church Information', 'text' => 'Find parishes, Mass times and contact details.', 'page' => 'church-information.php',
     'icon' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-5"/><path d="M12 8h.01"/>'],
    ['title' => 'Announcement & Bulletin', 'text' => 'Stay up to date with news and weekly bulletins.', 'page' => 'announcements.php',
     'icon' => '<path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/><path d="M18 14h-8"/><path d="M15 18h-5"/><path d="M10 6h8v4h-8V6Z"/>'],
    ['title' => 'Donation & Offerings', 'text' => 'Give to your parish and support the Church\'s mission.', 'page' => 'donations.php',
     'icon' => '<path d="M11 14h2a2 2 0 1 0 0-4h-3c-.6 0-1.1.2-1.4.6L3 16"/><path d="m7 20 1.6-1.4c.3-.4.8-.6 1.4-.6h4c1.1 0 2.1-.4 2.8-1.2l4.6-4.4a2 2 0 0 0-2.75-2.91l-4.2 3.9"/><path d="m2 15 6 6"/><path d="M19.5 8.5c.7-.7 1.5-1.6 1.5-2.7A2.73 2.73 0 0 0 16 4a2.78 2.78 0 0 0-5 1.8c0 1.2.8 2 1.5 2.8L16 12Z"/>'],
    ['title' => 'Church Calendar', 'text' => 'Browse liturgies, events and parish activities.', 'page' => 'events.php',
     'icon' => '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 18h.01"/><path d="M12 18h.01"/>'],
    ['title' => 'Sacrament', 'text' => 'Request and prepare for the sacraments.', 'page' => 'sacraments.php',
     'icon' => '<path d="M5 10a7 7 0 0 1 14 0"/><path d="M4 10h16c0 4-3 6.5-8 6.5S4 14 4 10Z"/><path d="M12 16.5V20"/><path d="M8 21h8"/><path d="M12 4.5V9"/><path d="M10.2 6.5h3.6"/>'],
    ['title' => 'Catechesis Programs', 'text' => 'Faith formation for children, youth and adults.', 'page' => 'catechesis.php',
     'icon' => '<path d="M2 3h20"/><path d="M21 3v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V3"/><path d="m7 21 5-5 5 5"/>'],
    ['title' => 'Pastoral & Spiritual Care', 'text' => 'Reach out for guidance, counselling and prayer.', 'page' => 'pastoral-care.php',
     'icon' => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/><path d="M12 5 9.04 7.96a2.17 2.17 0 0 0 0 3.08c.82.82 2.13.85 3 .07l2.07-1.9a2.82 2.82 0 0 1 3.79 0l2.96 2.66"/><path d="m18 15-2-2"/><path d="m15 18-2-2"/>'],
    ['title' => 'Intelligent Church Assistant', 'text' => 'Ask questions and get help at any time.', 'page' => 'assistant.php',
     'icon' => '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>'],
];
?>
<div class="services-page">
    <header class="page-heading">
        <h2>Our Services</h2>
        <p>Everything the Archdiocese offers its faithful, in one place.</p>
    </header>

<?php if (!$signedIn): ?>
    <?php render_login_gate('index.php#services'); ?>
<?php else: ?>
    <div class="service-grid">
        <?php foreach ($services as $s):
            $ready = is_file($serviceRoot . '/' . $s['page']);
            $tag   = $ready ? 'a' : 'div';
            $attrs = $ready ? 'href="' . htmlspecialchars($s['page']) . '"' : 'aria-disabled="true"';
        ?>
            <<?= $tag ?> class="service-card<?= $ready ? '' : ' is-soon' ?>" <?= $attrs ?>>
                <span class="service-icon">
                    <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $s['icon'] ?></svg>
                </span>
                <h3><?= htmlspecialchars($s['title']) ?></h3>
                <p><?= htmlspecialchars($s['text']) ?></p>
                <?php if ($ready): ?>
                    <span class="service-go">Open <span aria-hidden="true">&rarr;</span></span>
                <?php else: ?>
                    <span class="service-soon">Coming soon</span>
                <?php endif; ?>
            </<?= $tag ?>>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>
