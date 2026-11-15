<?php
// "Our Services" section (one-page site). Needs: $signedIn (bool).
// Each service links to its page once that page exists; until then it shows "Coming soon".
$serviceRoot = dirname(__DIR__, 2);

// Illustrated two-tone icons, drawn on a 48x48 grid in the site colours:
// purple #4a0d6e, lavender #e4d6f1 (soft fill), muted lavender #b9a3d1 (text lines) and gold #d4a72c.
$services = [
    ['title' => 'Church Information', 'text' => 'Find parishes, Mass times and contact details.', 'page' => 'church-information.php',
     // church with a cross, round window and arched door, plus an "i" badge
     'icon' => '<ellipse cx="24" cy="43.5" rx="17" ry="2" fill="#e4d6f1"/>'
             . '<rect x="12" y="21" width="24" height="21" rx="1.5" fill="#fff" stroke="#4a0d6e" stroke-width="2"/>'
             . '<path d="M8.5 23 24 10.5 39.5 23Z" fill="#4a0d6e" stroke="#4a0d6e" stroke-width="2"/>'
             . '<path d="M24 2.5v8M20.5 5.5h7" stroke="#d4a72c" stroke-width="2.4"/>'
             . '<circle cx="24" cy="18" r="2.2" fill="#d4a72c"/>'
             . '<path d="M19.5 42v-6.5a4.5 4.5 0 0 1 9 0V42Z" fill="#4a0d6e"/>'
             . '<rect x="14.5" y="26" width="3.5" height="5" rx="1.75" fill="#e4d6f1"/><rect x="30" y="26" width="3.5" height="5" rx="1.75" fill="#e4d6f1"/>'
             . '<circle cx="37.5" cy="35.5" r="7" fill="#d4a72c" stroke="#fff" stroke-width="2"/>'
             . '<path d="M37.5 34.5v4.5" stroke="#4a0d6e" stroke-width="2.2"/><circle cx="37.5" cy="31.6" r="1.25" fill="#4a0d6e"/>'],
    ['title' => 'Announcement & Bulletin', 'text' => 'Stay up to date with news and weekly bulletins.', 'page' => 'announcements.php',
     // folded newspaper with a photo, headline and columns of text
     'icon' => '<rect x="13" y="6" width="29" height="35" rx="3" fill="#e4d6f1"/>'
             . '<rect x="6.5" y="10" width="29" height="33" rx="3" fill="#fff" stroke="#4a0d6e" stroke-width="2"/>'
             . '<path d="M11 15.5h20" stroke="#4a0d6e" stroke-width="2.6"/>'
             . '<rect x="11" y="19.5" width="9.5" height="8.5" rx="1.5" fill="#d4a72c"/>'
             . '<path d="m12.5 26.5 2.6-3 2 2 1.4-1.5 1.5 2.5" stroke="#fff" stroke-width="1.3" fill="none"/>'
             . '<path d="M23.5 20.5h7.5M23.5 24h7.5M23.5 27.5h5M11 32h20M11 35.5h20M11 39h13" stroke="#b9a3d1" stroke-width="1.8"/>'],
    ['title' => 'Donation & Offerings', 'text' => 'Give to your parish and support the Church\'s mission.', 'page' => 'donations.php',
     // open hand offering a heart, with a coin
     'icon' => '<path d="M24 23.5s-9-5.2-9-11.2a4.8 4.8 0 0 1 9-2.3 4.8 4.8 0 0 1 9 2.3c0 6-9 11.2-9 11.2Z" fill="#d4a72c" stroke="#4a0d6e" stroke-width="2"/>'
             . '<path d="M19.5 11.8a2.4 2.4 0 0 1 2.2-1.6" stroke="#fff" stroke-width="1.6" fill="none"/>'
             . '<circle cx="38" cy="9" r="4.2" fill="#fff" stroke="#d4a72c" stroke-width="2"/><path d="M38 7v4" stroke="#d4a72c" stroke-width="1.6"/>'
             . '<path d="M10 30.5h3.5c2 0 3.5-1.5 5.5-1.5h8a2.6 2.6 0 0 1 0 5.2h-1l7.6-6.4a2.7 2.7 0 0 1 3.7 3.9l-8.6 7.6a6 6 0 0 1-4 1.5H16l-6 2.5Z" fill="#e4d6f1"/>'
             . '<path d="M10 30.5h3.5c2 0 3.5-1.5 5.5-1.5h8a2.6 2.6 0 0 1 0 5.2h-6.5" stroke="#4a0d6e" stroke-width="2.2" fill="none"/>'
             . '<path d="M16 41.3h10.2a6 6 0 0 0 4-1.5l8.6-7.6a2.7 2.7 0 0 0-3.7-3.9l-7.4 6.3" stroke="#4a0d6e" stroke-width="2.2" fill="none"/>'
             . '<rect x="3" y="28" width="7" height="16" rx="1.8" fill="#4a0d6e"/>'],
    ['title' => 'Church Calendar', 'text' => 'Browse liturgies, events and parish activities.', 'page' => 'events.php',
     // wall calendar with binder rings and a highlighted feast day
     'icon' => '<rect x="6" y="9" width="36" height="34" rx="4.5" fill="#fff" stroke="#4a0d6e" stroke-width="2"/>'
             . '<path d="M6 13.5A4.5 4.5 0 0 1 10.5 9h27a4.5 4.5 0 0 1 4.5 4.5V19H6Z" fill="#4a0d6e" stroke="#4a0d6e" stroke-width="2"/>'
             . '<rect x="14" y="4.5" width="3.6" height="9" rx="1.8" fill="#d4a72c"/><rect x="30.4" y="4.5" width="3.6" height="9" rx="1.8" fill="#d4a72c"/>'
             . '<g fill="#e4d6f1"><rect x="10.5" y="23" width="5" height="4.5" rx="1.2"/><rect x="18" y="23" width="5" height="4.5" rx="1.2"/><rect x="25.5" y="23" width="5" height="4.5" rx="1.2"/><rect x="33" y="23" width="5" height="4.5" rx="1.2"/>'
             . '<rect x="10.5" y="30" width="5" height="4.5" rx="1.2"/><rect x="18" y="30" width="5" height="4.5" rx="1.2"/><rect x="33" y="30" width="5" height="4.5" rx="1.2"/>'
             . '<rect x="10.5" y="37" width="5" height="3.5" rx="1.2"/><rect x="18" y="37" width="5" height="3.5" rx="1.2"/></g>'
             . '<rect x="24.5" y="29" width="7" height="6.5" rx="1.6" fill="#d4a72c"/><path d="M28 30.4v3.6M26.2 32h3.6" stroke="#fff" stroke-width="1.4"/>'],
    ['title' => 'Sacrament', 'text' => 'Request and prepare for the sacraments.', 'page' => 'sacraments.php',
     // golden chalice with the host above it
     'icon' => '<path d="M24 1.5v2.5M14.5 5l1.6 2M33.5 5l-1.6 2M11 12h2.4M34.6 12H37" stroke="#d4a72c" stroke-width="1.8"/>'
             . '<circle cx="24" cy="12" r="7" fill="#fff" stroke="#d4a72c" stroke-width="2"/>'
             . '<path d="M24 8.3v7.4M20.3 12h7.4" stroke="#d4a72c" stroke-width="1.8"/>'
             . '<path d="M10.5 21h27c0 7.5-5.8 12-13.5 12s-13.5-4.5-13.5-12Z" fill="#d4a72c" stroke="#4a0d6e" stroke-width="2"/>'
             . '<path d="M15 24.5c.6 2.4 2.2 4.4 4.4 5.5" stroke="#fff" stroke-width="1.7" fill="none" opacity=".75"/>'
             . '<path d="M22 33h4v5h-4Z" fill="#4a0d6e"/>'
             . '<path d="M14.5 44c0-3.4 4.2-6 9.5-6s9.5 2.6 9.5 6Z" fill="#4a0d6e"/>'],
    ['title' => 'Catechesis Programs', 'text' => 'Faith formation for children, youth and adults.', 'page' => 'catechesis.php',
     // open Bible with a cross, glowing
     'icon' => '<path d="M24 3.5v4M16.5 5.5l1.6 3M31.5 5.5l-1.6 3" stroke="#d4a72c" stroke-width="1.8"/>'
             . '<path d="M3.5 15v28c8-1.6 15 0 20.5 2.6C29.5 43 36.5 41.4 44.5 43V15Z" fill="#e4d6f1"/>'
             . '<path d="M24 15.5c-4.2-3-10.5-4-17-3v26.5c6.5-1 12.8 0 17 3Z" fill="#fff" stroke="#4a0d6e" stroke-width="2"/>'
             . '<path d="M24 15.5c4.2-3 10.5-4 17-3v26.5c-6.5-1-12.8 0-17 3Z" fill="#fff" stroke="#4a0d6e" stroke-width="2"/>'
             . '<path d="M10.5 18.5c3.3-.3 6.4.2 9 1.4M10.5 23c3.3-.3 6.4.2 9 1.4M10.5 27.5c3.3-.3 6.4.2 9 1.4M10.5 32c3.3-.3 6.4.2 9 1.4" stroke="#b9a3d1" stroke-width="1.6" fill="none"/>'
             . '<path d="M32.5 18v13M28 22.5h9" stroke="#d4a72c" stroke-width="2.6"/>'
             . '<path d="M24 15.5v26.5" stroke="#4a0d6e" stroke-width="2"/>'],
    ['title' => 'Pastoral & Spiritual Care', 'text' => 'Reach out for guidance, counselling and prayer.', 'page' => 'pastoral-care.php',
     // two hands gently holding a heart
     'icon' => '<path d="M24 5v3M17 7.5l1.4 2.2M31 7.5l-1.4 2.2" stroke="#d4a72c" stroke-width="1.8"/>'
             . '<path d="M24 31s-8.5-4.9-8.5-10.6a4.6 4.6 0 0 1 8.5-2.4 4.6 4.6 0 0 1 8.5 2.4c0 5.7-8.5 10.6-8.5 10.6Z" fill="#d4a72c" stroke="#4a0d6e" stroke-width="2"/>'
             . '<path d="M19.3 19.6a2.3 2.3 0 0 1 2-1.5" stroke="#fff" stroke-width="1.5" fill="none"/>'
             . '<path d="M5.5 21.5c0 6.5 2 10.5 6 13.6l5.8 4.9H23v-6.3l-4.8-4c-1.5-1.2-2.4-2.6-2.9-4.5l-1.7-5.7a2.2 2.2 0 0 0-4.3.9l.6 4" fill="#e4d6f1" stroke="#4a0d6e" stroke-width="2"/>'
             . '<path d="M42.5 21.5c0 6.5-2 10.5-6 13.6L30.7 40H25v-6.3l4.8-4c1.5-1.2 2.4-2.6 2.9-4.5l1.7-5.7a2.2 2.2 0 0 1 4.3.9l-.6 4" fill="#e4d6f1" stroke="#4a0d6e" stroke-width="2"/>'
             . '<rect x="15.5" y="40" width="9" height="5" rx="1.5" fill="#4a0d6e"/><rect x="23.5" y="40" width="9" height="5" rx="1.5" fill="#4a0d6e"/>'],
    ['title' => 'Intelligent Church Assistant', 'text' => 'Ask questions and get help at any time.', 'page' => 'assistant.php',
     // friendly robot with a chat bubble
     'icon' => '<path d="M28.5 3.5h13a3 3 0 0 1 3 3v7a3 3 0 0 1-3 3h-7.5l-4 3.2v-3.2h-1.5a3 3 0 0 1-3-3v-7a3 3 0 0 1 3-3Z" fill="#d4a72c"/>'
             . '<circle cx="30.5" cy="10" r="1.3" fill="#4a0d6e"/><circle cx="35" cy="10" r="1.3" fill="#4a0d6e"/><circle cx="39.5" cy="10" r="1.3" fill="#4a0d6e"/>'
             . '<path d="M19 19.5v-5" stroke="#4a0d6e" stroke-width="2"/><circle cx="19" cy="12.5" r="2.4" fill="#d4a72c" stroke="#4a0d6e" stroke-width="1.6"/>'
             . '<rect x="3" y="26" width="4" height="9" rx="2" fill="#4a0d6e"/><rect x="31" y="26" width="4" height="9" rx="2" fill="#4a0d6e"/>'
             . '<rect x="6" y="19.5" width="26" height="22" rx="6.5" fill="#fff" stroke="#4a0d6e" stroke-width="2"/>'
             . '<rect x="10" y="24" width="18" height="11.5" rx="4" fill="#4a0d6e"/>'
             . '<circle cx="15" cy="29" r="2.1" fill="#d4a72c"/><circle cx="23" cy="29" r="2.1" fill="#d4a72c"/>'
             . '<path d="M16.5 32.6c1.5 1 3.5 1 5 0" stroke="#e4d6f1" stroke-width="1.4" fill="none"/>'
             . '<ellipse cx="19" cy="45" rx="12" ry="1.6" fill="#e4d6f1"/>'],
];
?>
<div class="services-page">
    <header class="page-heading svc-heading">
        <span class="dir-eyebrow">What we offer</span>
        <h2>Our Services</h2>
        <p>Everything the Archdiocese offers its faithful, in one place.</p>
    </header>

<?php if (!$signedIn): ?>
    <?php render_login_gate('index.php#services'); ?>
<?php else: ?>
    <div class="service-grid">
        <?php foreach ($services as $i => $s):
            $ready = is_file($serviceRoot . '/' . $s['page']);
            $tag   = $ready ? 'a' : 'div';
            $attrs = $ready ? 'href="' . htmlspecialchars($s['page']) . '"' : 'aria-disabled="true"';
        ?>
            <<?= $tag ?> class="service-card<?= $ready ? '' : ' is-soon' ?>" <?= $attrs ?>>
                <span class="service-num" aria-hidden="true"><?= sprintf('%02d', $i + 1) ?></span>
                <span class="service-icon">
                    <svg width="46" height="46" viewBox="0 0 48 48" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $s['icon'] ?></svg>
                </span>
                <h3><?= htmlspecialchars($s['title']) ?></h3>
                <p><?= htmlspecialchars($s['text']) ?></p>
                <span class="service-foot">
                    <?php if ($ready): ?>
                        <span class="service-go">Open service</span>
                        <span class="service-arrow" aria-hidden="true">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>
                        </span>
                    <?php else: ?>
                        <span class="service-soon">Coming soon</span>
                    <?php endif; ?>
                </span>
            </<?= $tag ?>>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>
