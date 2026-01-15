<?php
$projectId = intval($_GET['project_id'] ?? 0);
?>

<div class="wrap aperture-command-center" data-project-id="<?php echo esc_attr($projectId); ?>">
    <h1>Project Command Center</h1>

    <div id="ap-health-card" class="ap-card">
        <h2>System Health</h2>
        <div class="ap-health-status ap-loading">Checking system health…</div>
        <ul class="ap-health-list"></ul>
    </div>

    <div id="aperture-project-summary" class="ap-card">
        <h2>Project Summary</h2>
        <div class="ap-loader">Loading…</div>
    </div>

    <div id="aperture-logs" class="ap-card">
        <h2>Recent Activity</h2>
        <table class="widefat">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Level</th>
                    <th>Context</th>
                    <th>Message</th>
                </tr>
            </thead>
            <tbody id="ap-log-rows">
                <tr><td colspan="4">Loading…</td></tr>
            </tbody>
        </table>
    </div>
</div>
