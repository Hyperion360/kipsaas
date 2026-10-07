<?php // views/doc_body.php - shared body of the static trust pages: $doc names the
// key prefix; the heading is the h1, the body renders as blank-line-separated
// paragraphs (single newlines inside a paragraph become <br>). terms, privacy,
// aup, refund, and migration set $doc and require this file.
/** @var string $doc */
/** @var array<string, string> $L */
?>
<h1><?= htmlspecialchars($L[$doc . '_heading']) ?></h1>
<?php foreach (preg_split('/\n\s*\n/', trim($L[$doc . '_body'])) as $para): ?>
<p><?= nl2br(htmlspecialchars($para)) ?></p>
<?php endforeach; ?>
