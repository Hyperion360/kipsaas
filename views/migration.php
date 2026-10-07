<?php // views/migration.php - the migration offer: the doc body plus the call
// to action back into signup
$doc = 'migration';
require __DIR__ . '/doc_body.php';
?>
<p><a href="/start"><?= htmlspecialchars($L['migration_cta']) ?></a></p>
