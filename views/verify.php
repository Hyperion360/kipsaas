<?php // views/verify.php - the mail-scanner-safe interstitial: GET shows, POST claims
/** @var string $token */
?>
<h1><?= htmlspecialchars($L['verify_heading']) ?></h1>
<p><?= htmlspecialchars($L['verify_body']) ?></p>
<form method="post" action="/verify/claim">
  <?= \KipSaaS\Csrf::field() ?>
  <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
  <button type="submit"><?= htmlspecialchars($L['verify_submit']) ?></button>
</form>
