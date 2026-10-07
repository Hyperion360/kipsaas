<?php // views/layout.php - one shared shell; every page is plain HTML, zero JS
$inner = basename($name);
// Per-template override-first resolution (the kit layout must keep working
// when the operator's view_dir overrides only some pages): view_dir wins,
// the kit's own views dir is the fallback.
$innerFile = is_file(($viewDir ?? '') . '/' . $inner)
    ? $viewDir . '/' . $inner
    : ($kitViews ?? __DIR__) . '/' . $inner;
ob_start();
require $innerFile;
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
