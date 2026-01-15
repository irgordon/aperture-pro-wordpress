<?php
/**
 * Small system health indicator for clients (friendly).
 * Reads transient 'ap_health_items' and shows a simple status.
 */
$healthItems = $health;
$overall = 'ok';
foreach ($healthItems as $k => $v) {
    if (!empty($v['overall_status']) && $v['overall_status'] === 'error') {
        $overall = 'error';
        break;
    }
    if (!empty($v['overall_status']) && $v['overall_status'] === 'warning') {
        $overall = 'warning';
    }
}
?>
<div class="ap-card ap-system-health">
    <h4>System status</h4>
    <?php if ($overall === 'ok'): ?>
        <div class="ap-health-ok">All systems operational</div>
    <?php elseif ($overall === 'warning'): ?>
        <div class="ap-health-warning">Some features may be degraded. If you experience issues, contact support.</div>
    <?php else: ?>
        <div class="ap-health-error">We are experiencing technical issues. Some features may be unavailable.</div>
    <?php endif; ?>
</div>
