<?php
/**
 * Admin Health Check Template
 *
 * Variables available:
 * - $healthData (array) returned from HealthService::check()
 */
?>
<div class="wrap ap-settings-wrap">
    <h1>Aperture Pro Health Status</h1>

    <!-- Dashboard Cards (SPA Islands) -->
    <div class="ap-health-dashboard" style="display: flex; gap: 20px; margin-top: 20px; margin-bottom: 20px;">
        <?php if (!empty($cards)): ?>
            <?php foreach ($cards as $card): ?>
                <?php if (!empty($card['enabled']) && current_user_can($card['capability'])): ?>
                    <div data-spa-component="<?php echo esc_attr($card['spa_component']); ?>" class="ap-card-slot"></div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="card ap-card" style="max-width: 800px; margin-top: 20px;">
        <h2>System Status:
            <?php if ($healthData['overall_status'] === 'ok'): ?>
                <span style="color: green;">Operational</span>
            <?php elseif ($healthData['overall_status'] === 'warning'): ?>
                <span style="color: orange;">Warning</span>
            <?php else: ?>
                <span style="color: red;">Critical</span>
            <?php endif; ?>
        </h2>

        <p>Last check: <?php echo esc_html($healthData['timestamp']); ?></p>

        <hr>

        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th>Check</th>
                    <th>Status</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <!-- Database Tables -->
                <tr>
                    <td><strong>Database Tables</strong></td>
                    <td>
                        <?php
                        $allTablesOk = !in_array(false, $healthData['checks']['tables'], true);
                        if ($allTablesOk): ?>
                            <span class="dashicons dashicons-yes" style="color: green;"></span> OK
                        <?php else: ?>
                            <span class="dashicons dashicons-warning" style="color: orange;"></span> Missing
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$allTablesOk): ?>
                            Missing: <?php
                            $missing = array_keys(array_filter($healthData['checks']['tables'], function($v) { return !$v; }));
                            echo implode(', ', $missing);
                            ?>
                        <?php else: ?>
                            All required tables present.
                        <?php endif; ?>
                    </td>
                </tr>

                <!-- Configuration -->
                <tr>
                    <td><strong>Configuration</strong></td>
                    <td>
                        <?php if ($healthData['checks']['config_loaded']): ?>
                            <span class="dashicons dashicons-yes" style="color: green;"></span> OK
                        <?php else: ?>
                            <span class="dashicons dashicons-warning" style="color: orange;"></span> Warning
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo $healthData['checks']['config_loaded'] ? 'Configuration loaded successfully.' : 'Configuration missing or empty.'; ?>
                    </td>
                </tr>

                <!-- Storage Driver -->
                <tr>
                    <td><strong>Storage Driver</strong></td>
                    <td>
                        <?php if ($healthData['checks']['storage_driver'] !== 'unavailable'): ?>
                            <span class="dashicons dashicons-yes" style="color: green;"></span> OK
                        <?php else: ?>
                            <span class="dashicons dashicons-no" style="color: red;"></span> Error
                        <?php endif; ?>
                    </td>
                    <td>
                        Current driver: <?php echo esc_html($healthData['checks']['storage_driver']); ?>
                    </td>
                </tr>

                <!-- Logging -->
                <tr>
                    <td><strong>Logging System</strong></td>
                    <td>
                        <?php if ($healthData['checks']['logging']): ?>
                            <span class="dashicons dashicons-yes" style="color: green;"></span> OK
                        <?php else: ?>
                            <span class="dashicons dashicons-warning" style="color: orange;"></span> Warning
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo $healthData['checks']['logging'] ? 'Logging operational.' : 'Logging check failed.'; ?>
                    </td>
                </tr>

                <!-- Upload Watchdog -->
                <tr>
                    <td><strong>Upload Watchdog</strong></td>
                    <td>
                        <?php if (!empty($healthData['checks']['upload_watchdog']['ok'])): ?>
                            <span class="dashicons dashicons-yes" style="color: green;"></span> OK
                        <?php else: ?>
                            <span class="dashicons dashicons-warning" style="color: orange;"></span> Warning
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        if (!empty($healthData['checks']['upload_watchdog']['ok'])) {
                            echo 'Watchdog healthy.';
                        } else {
                            echo 'Watchdog issues detected.';
                        }
                        ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
