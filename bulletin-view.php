<?php
// One bulletin made with the Bulletin Builder, as a clean A4 page: read it on screen, print it, or use
// "Download PDF" (the browser's "Save as PDF"). Uploaded PDF bulletins are simply opened as their file.
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/login_gate.php';
require_signed_in('Digital Bulletin');   // like the Bulletins tab: for signed-in parishioners and admins
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/bulletin_helpers.php';

$stmt = $pdo->prepare('SELECT * FROM bulletins WHERE bulletin_id = ?');
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$row = $stmt->fetch();
if (!$row) {
    header('Location: announcements.php?tab=bulletins');
    exit;
}
$snap = bulletin_snapshot_of($row);
if (!$snap) {   // an uploaded PDF
    $file = $row['file_name'] && preg_match('/^[\w.-]+$/', $row['file_name']) && is_file(__DIR__ . '/uploads/bulletins/' . $row['file_name']) ? 'uploads/bulletins/' . $row['file_name'] : null;
    header('Location: ' . ($file ?? 'announcements.php?church=' . (int)$row['church_id'] . '&tab=bulletins'));
    exit;
}

$canManage = can_manage_church($pdo, (int)$row['church_id']);
$flash = !empty($_GET['saved']) ? ($_SESSION['admin_flash'] ?? null) : null;
if ($flash) unset($_SESSION['admin_flash']);
$back = 'announcements.php?church=' . (int)$row['church_id'] . '&tab=bulletins';
// The browser offers the page title as the PDF's file name.
$fileTitle = preg_replace('/[^\w\- ]+/u', '', $snap['church']['name'] . ' - ' . ($snap['title'] ?: 'Bulletin')) . ' - ' . $snap['date'];
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= $h($snap['language'] === 'kzj' ? 'en' : $snap['language']) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $h($fileTitle) ?></title>
    <link rel="icon" href="assets/images/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Noto+Serif:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root { color-scheme: light; }
        html, body { margin: 0; }
        body { min-height: 100vh; font-family: 'Inter', Arial, sans-serif; background: #ece8f2; }
        .bv-bar { position: sticky; top: 0; z-index: 2; display: flex; align-items: center; flex-wrap: wrap; gap: 10px; padding: 10px 16px; background: #fff; border-bottom: 1px solid #e0d8ea; box-shadow: 0 2px 10px rgba(60, 30, 90, .08); }
        .bv-bar a, .bv-bar button { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; font: 600 13px 'Inter', Arial, sans-serif; color: #4a0d6e; text-decoration: none; background: #f3ecf9; border: 1px solid #d9c9e8; border-radius: 8px; cursor: pointer; }
        .bv-bar .is-main { color: #fff; background: #4a0d6e; border-color: #4a0d6e; }
        .bv-bar a:hover, .bv-bar button:hover { filter: brightness(.96); }
        .bv-bar a:focus-visible, .bv-bar button:focus-visible { outline: 2px solid #d4a72c; outline-offset: 2px; }
        .bv-spacer { flex: 1; }
        .bv-hint { width: 100%; margin: 0; font-size: 12px; color: #6b6b76; }
        .bv-flash { max-width: 210mm; margin: 14px auto 0; padding: 10px 14px; font-size: 14px; color: #1b5e20; background: #e3f6ea; border: 1px solid #b9dfc5; border-radius: 8px; }
        .bv-sheet { padding: 20px 12px 40px; }
        .bv-sheet .blt { box-shadow: 0 10px 30px rgba(60, 30, 90, .15); border-radius: 2px; }
        <?= bulletin_css() ?>
        @media (max-width: 640px) { .bv-sheet { padding: 12px 0 24px; } .blt { padding: 18px 16px; } .blt-head { flex-wrap: wrap; } .blt-head-side { align-items: flex-start; text-align: left; } }
        @media print { body { background: #fff; } .bv-bar, .bv-flash { display: none; } .bv-sheet { padding: 0; } .bv-sheet .blt { box-shadow: none; } }
    </style>
</head>
<body>
    <div class="bv-bar">
        <a href="<?= $h($back) ?>">&larr; Bulletins</a>
        <span class="bv-spacer"></span>
        <?php if ($canManage): ?><a href="bulletin-builder.php?id=<?= (int)$row['bulletin_id'] ?>">Edit</a><?php endif; ?>
        <button type="button" class="is-main" id="bv-pdf">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/></svg>
            Download PDF
        </button>
        <p class="bv-hint">In the print window, choose <b>Save as PDF</b> as the printer (or pick a printer to print it). For a clean page, turn off <b>Headers and footers</b> under More settings.</p>
    </div>
    <?php if ($flash): ?><div class="bv-flash" role="status"><?= $h($flash) ?></div><?php endif; ?>
    <div class="bv-sheet"><?= bulletin_render($snap) ?></div>
    <script>
    document.getElementById('bv-pdf').addEventListener('click', function () { window.print(); });
    <?php if (!empty($_GET['print'])): ?>
    // Opened with the Download button on the bulletins list: go straight to "Save as PDF" once the fonts are in.
    (document.fonts ? document.fonts.ready : Promise.resolve()).then(function () { setTimeout(function () { window.print(); }, 150); });
    <?php endif; ?>
    </script>
</body>
</html>
