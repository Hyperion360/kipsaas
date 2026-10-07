<?php // views/start.php - pricing table parameterized from the plan catalog + signup form
/** @var array<string, array<string, mixed>> $plans */
// Manual-only plans (no price id) are not self-serve purchasable: they render
// nowhere here, and Signup refuses a forged form value naming one.
$buyable = array_filter($plans, fn($p) => !empty($p['price_id']));
?>
<h1><?= htmlspecialchars($L['start_heading']) ?></h1>
<p><?= htmlspecialchars($L['start_sub']) ?></p>
<ul>
  <li><?= htmlspecialchars($L['start_pain_1']) ?></li>
  <li><?= htmlspecialchars($L['start_pain_2']) ?></li>
  <li><?= htmlspecialchars($L['start_pain_3']) ?></li>
</ul>
<?php if (isset($error) && $error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<table class="plans">
<tr><th></th><?php foreach ($buyable as $p): ?><th><?= htmlspecialchars((string) ($p['label'] ?? '')) ?></th><?php endforeach; ?></tr>
<tr><td><?= htmlspecialchars($L['plan_price']) ?></td><?php foreach ($buyable as $p): ?><td>$<?= number_format(((int) ($p['amount_month'] ?? 0)) / 100) ?>/mo</td><?php endforeach; ?></tr>
<tr><td><?= htmlspecialchars($L['plan_storage']) ?></td><?php foreach ($buyable as $p): ?><td><?= (int) ($p['storage_gb'] ?? 0) ?> GB</td><?php endforeach; ?></tr>
<tr><td><?= htmlspecialchars($L['plan_attribution']) ?></td><?php foreach ($buyable as $p): ?><td><?= htmlspecialchars(!empty($p['powered_by']) ? $L['attribution_shown'] : $L['attribution_removed']) ?></td><?php endforeach; ?></tr>
<tr><td><?= htmlspecialchars($L['plan_custom_domain']) ?></td><?php foreach ($buyable as $p): ?><td><?= htmlspecialchars(!empty($p['custom_domains']) ? $L['custom_domain_yes'] : $L['custom_domain_no']) ?></td><?php endforeach; ?></tr>
</table>
<p><?= htmlspecialchars($L['plan_refund_note']) ?></p>
<p><?= htmlspecialchars($L['start_features']) ?></p>
<form method="post" action="/start">
  <?= \KipSaaS\Csrf::field() ?>
  <label><?= htmlspecialchars($L['label_email']) ?> <input type="email" name="email" required></label>
  <label><?= htmlspecialchars($L['label_title']) ?> <input name="title" required pattern="[A-Za-z0-9 -]{3,63}" title="<?= htmlspecialchars($L['start_subdomain_note']) ?>"></label>
  <label><?= htmlspecialchars($L['label_plan']) ?>
    <select name="plan">
<?php foreach ($buyable as $id => $p): ?>
      <option value="<?= htmlspecialchars((string) $id) ?>"><?= htmlspecialchars((string) ($p['label'] ?? $id)) ?>, $<?= number_format(((int) ($p['amount_month'] ?? 0)) / 100) ?>/mo</option>
<?php endforeach; ?>
    </select>
  </label>
  <button type="submit"><?= htmlspecialchars($L['submit_signup']) ?></button>
</form>
<p><?= htmlspecialchars($L['signup_note']) ?></p>
<p><?= htmlspecialchars($L['start_export_note']) ?></p>
<p class="callout"><a href="/migration"><?= htmlspecialchars($L['start_migration_callout']) ?></a></p>
