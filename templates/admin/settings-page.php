<?php
/**
 * Settings page template for Aperture Pro Admin UI
 *
 * Expects $opts = get_option(AdminUI::OPTION_KEY, []);
 */
$opts = $opts ?? [];
$storage_driver = $opts['storage_driver'] ?? 'local';
$local_storage_path = $opts['local_storage_path'] ?? '';
$cloud_provider = $opts['cloud_provider'] ?? 'none';
$cloud_api_key = $opts['cloud_api_key'] ?? '';
$email_sender = $opts['email_sender'] ?? get_option('admin_email');
$webhook_secret = $opts['webhook_secret'] ?? '';
$require_otp = !empty($opts['require_otp']);
$theme_overrides = !empty($opts['theme_overrides']);
$nonce = wp_create_nonce(\AperturePro\Admin\AdminUI::NONCE_ACTION);
?>
<div class="wrap ap-admin-wrap">
    <h1>Aperture Pro Settings</h1>

    <form method="post" action="options.php" id="ap-settings-form">
        <?php settings_fields('aperture_pro_group'); ?>

        <div class="ap-grid">
            <div class="ap-col ap-col-main">
                <div class="ap-card">
                    <h2>Storage & Delivery</h2>
                    <p class="ap-muted">Choose where your images and final ZIPs are stored and how they are delivered to clients.</p>

                    <table class="form-table ap-form-table">
                        <tr>
                            <th scope="row">
                                <label for="storage_driver">Storage Driver</label>
                                <span class="ap-tooltip" title="Choose where files are stored. Local stores on your server; Cloudinary and ImageKit use cloud providers.">?</span>
                            </th>
                            <td>
                                <select id="storage_driver" name="<?php echo esc_attr(\AperturePro\Admin\AdminUI::OPTION_KEY); ?>[storage_driver]">
                                    <option value="local" <?php selected($storage_driver, 'local'); ?>>Local (server)</option>
                                    <option value="cloudinary" <?php selected($storage_driver, 'cloudinary'); ?>>Cloudinary</option>
                                    <option value="imagekit" <?php selected($storage_driver, 'imagekit'); ?>>ImageKit</option>
                                </select>
                                <p class="description">Local is simplest; cloud providers scale better and offload bandwidth.</p>
                            </td>
                        </tr>

                        <tr class="ap-local-path-row" <?php if ($storage_driver !== 'local') echo 'style="display:none"'; ?>>
                            <th scope="row">
                                <label for="local_storage_path">Local storage path</label>
                                <span class="ap-tooltip" title="Relative to WP uploads directory. Leave blank to use default uploads/aperture/">?</span>
                            </th>
                            <td>
                                <input type="text" id="local_storage_path" name="<?php echo esc_attr(\AperturePro\Admin\AdminUI::OPTION_KEY); ?>[local_storage_path]" value="<?php echo esc_attr($local_storage_path); ?>" class="regular-text" />
                                <p class="description">Example: <code>aperture-files</code>. Ensure the webserver can write to this directory.</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="cloud_provider">Cloud Provider</label>
                                <span class="ap-tooltip" title="If using a cloud provider, select it here and provide the API key below.">?</span>
                            </th>
                            <td>
                                <select id="cloud_provider" name="<?php echo esc_attr(\AperturePro\Admin\AdminUI::OPTION_KEY); ?>[cloud_provider]">
                                    <option value="none" <?php selected($cloud_provider, 'none'); ?>>None</option>
                                    <option value="cloudinary" <?php selected($cloud_provider, 'cloudinary'); ?>>Cloudinary</option>
                                    <option value="imagekit" <?php selected($cloud_provider, 'imagekit'); ?>>ImageKit</option>
                                </select>
                                <p class="description">Cloud providers can generate signed URLs and handle heavy bandwidth.</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="cloud_api_key">Cloud API Key</label>
                                <span class="ap-tooltip" title="Enter the API key for your cloud provider. Click Test to verify.">?</span>
                            </th>
                            <td>
                                <input type="password" id="cloud_api_key" name="<?php echo esc_attr(\AperturePro\Admin\AdminUI::OPTION_KEY); ?>[cloud_api_key]" value="<?php echo esc_attr($cloud_api_key); ?>" class="regular-text" autocomplete="new-password" />
                                <button type="button" class="button" id="ap-test-api-key" data-provider="<?php echo esc_attr($cloud_provider); ?>">Test</button>
                                <span id="ap-test-api-key-result" class="ap-test-result"></span>
                                <p class="description">We do not store API keys in plaintext in logs. For production, consider storing encrypted keys.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="ap-card">
                    <h2>Email & Notifications</h2>
                    <table class="form-table ap-form-table">
                        <tr>
                            <th scope="row">
                                <label for="email_sender">Email Sender</label>
                                <span class="ap-tooltip" title="The 'From' address used for transactional emails (OTP, download links).">?</span>
                            </th>
                            <td>
                                <input type="email" id="email_sender" name="<?php echo esc_attr(\AperturePro\Admin\AdminUI::OPTION_KEY); ?>[email_sender]" value="<?php echo esc_attr($email_sender); ?>" class="regular-text" />
                                <p class="description">Use a verified sender address for better deliverability.</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="webhook_secret">Payment Webhook Secret</label>
                                <span class="ap-tooltip" title="Secret used to verify payment provider webhooks. Click Validate to check format.">?</span>
                            </th>
                            <td>
                                <input type="password" id="webhook_secret" name="<?php echo esc_attr(\AperturePro\Admin\AdminUI::OPTION_KEY); ?>[webhook_secret]" value="<?php echo esc_attr($webhook_secret); ?>" class="regular-text" />
                                <button type="button" class="button" id="ap-validate-webhook">Validate</button>
                                <span id="ap-validate-webhook-result" class="ap-test-result"></span>
                                <p class="description">This secret is used to verify incoming payment webhooks. Keep it private.</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="require_otp">Require OTP for Downloads</label>
                                <span class="ap-tooltip" title="When enabled, clients must verify a one-time code sent to their email before downloading final files.">?</span>
                            </th>
                            <td>
                                <label><input type="checkbox" id="require_otp" name="<?php echo esc_attr(\AperturePro\Admin\AdminUI::OPTION_KEY); ?>[require_otp]" value="1" <?php checked($require_otp); ?> /> Enable OTP verification</label>
                                <p class="description">Recommended for sensitive deliveries or when download links are emailed.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="ap-card">
                    <h2>Appearance & Theme</h2>
                    <table class="form-table ap-form-table">
                        <tr>
                            <th scope="row">
                                <label for="theme_overrides">Enable Theme Overrides</label>
                                <span class="ap-tooltip" title="Allow the portal theme variables to be overridden from the admin UI.">?</span>
                            </th>
                            <td>
                                <label><input type="checkbox" id="theme_overrides" name="<?php echo esc_attr(\AperturePro\Admin\AdminUI::OPTION_KEY); ?>[theme_overrides]" value="1" <?php checked($theme_overrides); ?> /> Enable</label>
                                <p class="description">When enabled, you can customize colors and spacing for the client portal from Settings → Aperture Portal Theme.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="ap-card ap-performance-checklist">
                    <h2>Performance & UX Checklist</h2>
                    <ul>
                        <li>Use a cloud storage provider for large galleries to reduce server bandwidth.</li>
                        <li>Enable OTP for sensitive deliveries to reduce unauthorized downloads.</li>
                        <li>Ensure your server has sufficient disk space for temporary ZIP assembly (or use cloud pre-generation).</li>
                        <li>Test API keys and webhook secrets after entering them.</li>
                    </ul>
                </div>

                <?php submit_button('Save Settings'); ?>
            </div>

            <aside class="ap-col ap-col-side">
                <div class="ap-card">
                    <h3>Quick Help</h3>
                    <p>Hover the question marks for inline help. If you need assistance, reply to the client email or contact support.</p>
                    <p><strong>Tip:</strong> Use Cloudinary or ImageKit for large galleries to offload bandwidth and enable signed URLs.</p>
                </div>

                <div class="ap-card">
                    <h3>API Key Security</h3>
                    <p>API keys are sensitive. Rotate keys periodically and restrict them to required scopes. Do not share keys publicly.</p>
                </div>

                <div class="ap-card">
                    <h3>Audit</h3>
                    <p>Changes to settings are logged for audit. If a test fails, check logs under the plugin Health page.</p>
                </div>
            </aside>
        </div>
    </form>
</div>
