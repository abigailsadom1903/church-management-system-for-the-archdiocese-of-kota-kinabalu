<?php
// Plain-language pages linked from the footer (Terms, Privacy, Cookies).

/**
 * @param array<int, array{0:string, 1:string}> $sections  [heading, paragraph] pairs (paragraphs are plain text)
 */
function render_legal_page(string $title, string $intro, array $sections): void
{
    global $pageTitle, $activeNav;
    $pageTitle = $title;
    $activeNav = '';
    include __DIR__ . '/header.php';
    ?>
    <main class="legal-page">
        <?php back_button('index.php', 'Back to Home'); ?>
        <header class="page-heading">
            <h1><?= htmlspecialchars($title) ?></h1>
            <p><?= htmlspecialchars($intro) ?></p>
        </header>
        <article class="legal-card">
            <?php foreach ($sections as $i => [$heading, $text]): ?>
                <section>
                    <h2><span><?= $i + 1 ?></span><?= htmlspecialchars($heading) ?></h2>
                    <p><?= nl2br(htmlspecialchars($text)) ?></p>
                </section>
            <?php endforeach; ?>
            <p class="legal-contact">Questions? Write to <a href="mailto:adkkadmin@catholicadkk.org">adkkadmin@catholicadkk.org</a> or call 088-203300.</p>
        </article>
    </main>
    <?php
    include __DIR__ . '/footer.php';
}
