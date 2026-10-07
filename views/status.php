<?php // views/status.php - the aggregate status board. The route hands this
// template counters and booleans only: a host name is a customer slug, and
// this page is public, so hosts_down never gets this far. A missing or
// unreadable aggregate renders the friendly no-data state at HTTP 200.
/** @var array{ok_pct: float, checks: int, last_incident: ?string, checked_at: ?string, degraded: bool}|null $status */
?>
<h1><?= htmlspecialchars($L['status_heading']) ?></h1>
<?php if ($status === null): ?>
<p><?= htmlspecialchars($L['status_no_data']) ?></p>
<?php else: ?>
<p><?= htmlspecialchars($status['degraded'] ? $L['status_state_down'] : $L['status_state_ok']) ?></p>
<p><?= htmlspecialchars(sprintf($L['status_uptime_label'], number_format($status['ok_pct'], 2))) ?></p>
<p><?= htmlspecialchars(sprintf($L['status_checks_label'], (string) $status['checks'])) ?></p>
<?php if ($status['checked_at'] !== null): ?>
<p><?= htmlspecialchars(sprintf($L['status_checked_label'], $status['checked_at'])) ?></p>
<?php endif; ?>
<?php if ($status['last_incident'] !== null): ?>
<p><?= htmlspecialchars(sprintf($L['status_last_incident'], $status['last_incident'])) ?></p>
<?php endif; ?>
<?php endif; ?>
<p><?= htmlspecialchars($L['status_external']) ?></p>
