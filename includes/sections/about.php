<?php
// "About Us" section (one-page site). The text, photo and contact details are edited by the Super Admin
// on superadmin/about.php (see includes/site_content.php).
require_once __DIR__ . '/../site_content.php';
$about = site_content();
$aboutE = fn(?string $s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$aboutMap = urlencode($about['map_query'] !== '' ? $about['map_query'] : str_replace("
", ', ', $about['contact_address']));
?>
<div class="about-page">
    <header class="page-heading">
        <h2>About Us</h2>
        <p>The Archdiocese of Kota Kinabalu</p>
    </header>

    <?php if (!empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'super_admin'): ?>
        <?php if (!empty($_GET['about_saved'])): ?><div class="alert success about-alert" role="status">About Us updated.</div><?php endif; ?>
        <p class="about-edit"><a class="btn-new" href="superadmin/about.php"><?= dash_icon('pencil', 15) ?> Edit About Us</a></p>
    <?php endif; ?>

    <section class="about-intro">
        <img src="<?= $aboutE(site_about_photo()) ?>" alt="<?= $aboutE($about['about_title']) ?>" loading="lazy">
        <div class="about-text">
            <?php if ($about['about_eyebrow'] !== ''): ?><span class="eyebrow"><?= $aboutE($about['about_eyebrow']) ?></span><?php endif; ?>
            <h3><?= $aboutE($about['about_title']) ?></h3>
            <?php foreach (preg_split('/
\s*
/', trim($about['about_body'])) as $para): ?>
                <p><?= nl2br($aboutE(trim($para))) ?></p>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="about-contact">
        <div class="contact-card">
            <h3>Contact</h3>
            <ul class="contact-list">
                <li>
                    <span class="contact-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></span>
                    <div><b>Visit us</b><span class="val"><?= nl2br($aboutE($about['contact_address'])) ?></span></div>
                </li>
                <?php if ($about['contact_phone'] !== ''): ?>
                <li>
                    <span class="contact-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/></svg></span>
                    <div><b>Telephone</b><span class="val"><?= $aboutE($about['contact_phone']) ?></span></div>
                </li>
                <?php endif; ?>
                <?php if ($about['contact_fax'] !== ''): ?>
                <li>
                    <span class="contact-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg></span>
                    <div><b>Fax</b><span class="val"><?= $aboutE($about['contact_fax']) ?></span></div>
                </li>
                <?php endif; ?>
                <?php if ($about['contact_email'] !== ''): ?>
                <li>
                    <span class="contact-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg></span>
                    <div><b>Email</b><span class="val"><a href="mailto:<?= $aboutE($about['contact_email']) ?>"><?= $aboutE($about['contact_email']) ?></a></span></div>
                </li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="map-card">
            <iframe
                class="about-map"
                title="Map showing <?= $aboutE($about['about_title']) ?>"
                src="https://www.google.com/maps?q=<?= $aboutMap ?>&amp;z=16&amp;output=embed"
                loading="lazy"
                allowfullscreen></iframe>
            <a class="map-link" href="https://www.google.com/maps/search/?api=1&amp;query=<?= $aboutMap ?>" target="_blank" rel="noopener">Open in Google Maps</a>
        </div>
    </section>
</div>
