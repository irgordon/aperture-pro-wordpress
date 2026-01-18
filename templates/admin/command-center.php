<?php
$projectId = intval($_GET['project_id'] ?? 0);
?>

<div class="wrap aperture-command-center" data-project-id="<?php echo esc_attr($projectId); ?>">
    <h1>Project Command Center</h1>

    <div id="aperture-project-summary" class="ap-section">
        <h2>Project Summary</h2>
        <div class="ap-loader">Loading…</div>
    </div>

    <!-- Payment Summary (SPA Component) -->
    <div class="ap-section">
        <div data-spa-component="payment-card" data-project-id="<?php echo esc_attr($projectId); ?>"></div>
    </div>

    <div id="aperture-workflow" class="ap-section">
        <h2>Workflow</h2>
        <div class="ap-workflow-steps"></div>
        <button id="ap-start-editing" class="button button-primary">Start Editing</button>
        <button id="ap-generate-download" class="button">Generate Download Link</button>
    </div>

    <div id="aperture-logs" class="ap-section">
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
