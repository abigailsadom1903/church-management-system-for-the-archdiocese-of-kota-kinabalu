<?php
// Shows the email a donor receives after a review, exactly as it is built (XAMPP has no mail server,
// so on localhost this is the way to see it). Only the admins of the donation's church may open it.
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/donation_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT d.*, c.church_name, c.church_email, c.church_contact_details
       FROM donations d JOIN church_info c ON c.church_info_id = d.church_id WHERE d.donation_id = ?'
);
$stmt->execute([$id]);
$d = $stmt->fetch();

if (!$d || !can_manage_church($pdo, (int)$d['church_id'])) {
    header('Location: donations.php');
    exit;
}

// A pending donation has no result yet: show what the donor would get on approval.
$pending = $d['status'] === 'pending';
if ($pending) $d['status'] = 'approved';

$pageTitle = 'Email preview';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="dm-page mail-page">
    <header class="page-heading">
        <h1>Email to the donor</h1>
        <p><?= $pending ? 'This is what the donor receives when you approve the donation.' : 'This is the email the donor received.' ?></p>
    </header>

    <div class="mail-frame">
        <dl class="mail-head">
            <dt>To:</dt><dd><a href="mailto:<?= e($d['donor_email']) ?>"><?= e($d['donor_email']) ?></a></dd>
            <dt>From:</dt><dd><?= e(donation_email_from($d)) ?></dd>
            <dt>Subject:</dt><dd><strong><?= e(donation_email_subject($d, $d)) ?></strong></dd>
        </dl>
        <?= donation_email_html($d, $d) ?>
    </div>

    <p class="dr-back"><a href="donation-review.php?id=<?= $id ?>">&larr; Back to the review</a></p>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
