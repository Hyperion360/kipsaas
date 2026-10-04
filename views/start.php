<?php // views/start.php - pricing table parameterized from the plan catalog + signup form
/** @var array<string, array<string, mixed>> $plans */
?>
<h1><?= htmlspecialchars($L['start_heading']) ?></h1>
<?php if (isset($error) && $error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<table class="plans">
<tr><th></th><?php foreach ($plans as $p): ?><th><?= htmlspecialchars((string) ($p['label'] ?? '')) ?></th><?php endforeach; ?></tr>
<tr><td><?= htmlspecialchars($L['plan_price']) ?></td><?php foreach ($plans as $p): ?><td>$<?= number_format(((int) ($p['amount_month'] ?? 0)) / 100) ?>/mo</td><?php endforeach; ?></tr>
<tr><td><?= htmlspecialchars($L['plan_storage']) ?></td><?php foreach ($plans as $p): ?><td><?= (int) ($p['storage_gb'] ?? 0) ?> GB</td><?php endforeach; ?></tr>
<tr><td><?= htmlspecialchars($L['plan_attribution']) ?></td><?php foreach ($plans as $p): ?><td><?= htmlspecialchars(!empty($p['powered_by']) ? $L['attribution_shown'] : $L['attribution_removed']) ?></td><?php endforeach; ?></tr>
</table>
<form method="post" action="/start">
  <?= \KipSaaS\Csrf::field() ?>
  <label><?= htmlspecialchars($L['label_email']) ?> <input type="email" name="email" required></label>
  <label><?= htmlspecialchars($L['label_title']) ?> <input name="title" required pattern="[A-Za-z0-9 -]{3,63}" title="<?= htmlspecialchars($L['start_subdomain_note']) ?>"></label>
  <label><?= htmlspecialchars($L['label_plan']) ?>
    <select name="plan">
<?php foreach ($plans as $id => $p): ?>
      <option value="<?= htmlspecialchars((string) $id) ?>"><?= htmlspecialchars((string) ($p['label'] ?? $id)) ?>, $<?= number_format(((int) ($p['amount_month'] ?? 0)) / 100) ?>/mo</option>
<?php endforeach; ?>
    </select>
  </label>
  <button type="submit"><?= htmlspecialchars($L['submit_signup']) ?></button>
</form>
<p><?= htmlspecialchars($L['signup_note']) ?></p>
