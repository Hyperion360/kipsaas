<?php // views/portal.php
/** @var bool $sent */
?>
<h1><?= htmlspecialchars($L['portal_heading']) ?></h1>
<?php if (!empty($sent)): ?>
<p><?= htmlspecialchars($L['portal_sent']) ?></p>
<?php else: ?>
<form method="post" action="/billing/portal">
  <?= \KipSaaS\Csrf::field() ?>
  <label><?= htmlspecialchars($L['portal_label']) ?> <input type="email" name="email" required></label>
  <button type="submit"><?= htmlspecialchars($L['portal_submit']) ?></button>
</form>
<?php endif; ?>
