<?php // views/layout.php - one shared shell; every page is plain HTML, zero JS
$inner = basename($name);
ob_start();
require __DIR__ . '/' . $inner;
$content = (string) ob_get_clean();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title ?? '') ?> | <?= htmlspecialchars($brand) ?></title>
<link rel="stylesheet" href="/style.css">
</head>
<body>
<header><a href="<?= htmlspecialchars($brandUrl) ?>"><strong><?= htmlspecialchars($brand) ?></strong></a></header>
<main><?= $content ?></main>
<footer><a href="/start"><?= htmlspecialchars($L['footer_start']) ?></a> &middot; <a href="/terms"><?= htmlspecialchars($L['footer_terms']) ?></a> &middot; <a href="/privacy"><?= htmlspecialchars($L['footer_privacy']) ?></a> &middot; <a href="/aup"><?= htmlspecialchars($L['footer_aup']) ?></a> &middot; <a href="/refund"><?= htmlspecialchars($L['footer_refund']) ?></a> &middot; <a href="/status"><?= htmlspecialchars($L['footer_status']) ?></a> &middot; <?= htmlspecialchars($L['footer_support']) ?></footer>
</body>
</html>
