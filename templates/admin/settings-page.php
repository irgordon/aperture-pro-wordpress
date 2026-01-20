<?php
/**
 * Settings page template for Aperture Pro Admin UI
 *
 * Expects:
 *   $opts = get_option(AdminUI::OPTION_KEY, []);
 */

use AperturePro\Admin\AdminUI;

$opts = $opts ?? [];

$storage_driver       = $opts['storage_driver']       ?? 'local';
$local_storage_path   = $opts['local_storage_path']   ?? '';
$cloud_provider       = $opts['cloud_provider']       ?? 'none';
$email_sender         = $opts['email_sender']         ?? get_option('admin_email');
$require_otp          = !empty($opts['require_otp']);
$theme_overrides      = !empty($opts['theme_overrides']);

// S3 fields
$s3_bucket            = $opts['s3_bucket']            ?? '';
$s3_region            = $opts['s3_region']            ?? '';
$has_s3_access_key    = !empty($opts['s3_access_key']);
$has_s3_secret_key    = !empty($opts['s3_secret_key']);
$cloudfront_domain    = $opts['cloudfront_domain']    ?? '';
$cloudfront_key_pair  = $opts['cloudfront_key_pair_id'] ?? '';
$has_cf_private_key   = !empty($opts['cloudfront_private_key']);

$nonce = wp_create_nonce(AdminUI::NONCE_ACTION);
?>
<div class="wrap ap-admin-wrap">
    <h1>Aperture Pro Settings</h1>

    <form method="post" action="options.php" id="ap-settings-form">
        <?php settings_fields('aperture_pro_group'); ?>

        <div class="ap-grid">
            <div class="ap-col ap-col-main">

                <!-- ===========================
                     STORAGE & DELIVERY
                ============================ -->
                <div class="ap-card">
                    <h2>Storage & Delivery</h2>
                    <p class="ap-muted">Choose where your images and final ZIPs are stored and how they are delivered to clients.</p>

                    <table class="form-table ap-form-table">

                        <tr>
                            <th scope="row">
                                <label for="storage_driver">Storage Driver</label>
                            </th>
                            <td><?php AdminUI::field_storage_driver(); ?></td>
                        </tr>
                    </table>

                    <!-- LOCAL SETTINGS -->
                    <table class="form-table ap-form-table" id="ap-local-settings">
                        <tr class="ap-local-path-row">
                            <th scope="row">
                                <label for="local_storage_path">Local Storage Path</label>
                            </th>
                            <td><?php AdminUI::field_local_storage_path(); ?></td>
                        </tr>
                    </table>

                    <!-- CLOUD PROVIDER SETTINGS (Cloudinary / ImageKit) -->
                    <table class="form-table ap-form-table" id="ap-cloud-settings">
                        <tr>
                            <th scope="row">
                                <label for="cloud_provider">Cloud Provider</label>
                            </th>
                            <td><?php AdminUI::field_cloud_provider(); ?></td>
                        </tr>

                        <tr class="ap-cloudinary-field">
                            <th scope="row">
                                <label for="cloudinary_cloud_name">Cloud Name</label>
                            </th>
                            <td><?php AdminUI::field_cloudinary_cloud_name(); ?></td>
                        </tr>

                        <tr class="ap-cloudinary-field">
                            <th scope="row">
                                <label for="cloud_api_key">Cloud API Key</label>
                            </th>
                            <td><?php AdminUI::field_cloud_api_key(); ?></td>
                        </tr>

                        <tr class="ap-cloudinary-field">
                            <th scope="row">
                                <label for="cloudinary_api_secret">API Secret</label>
                            </th>
                            <td><?php AdminUI::field_cloudinary_api_secret(); ?></td>
                        </tr>

                        <tr class="ap-imagekit-field">
                            <th scope="row">
                                <label for="imagekit_public_key">Public Key</label>
                            </th>
                            <td><?php AdminUI::field_imagekit_public_key(); ?></td>
                        </tr>

                        <tr class="ap-imagekit-field">
                            <th scope="row">
                                <label for="imagekit_private_key">Private Key</label>
                            </th>
                            <td><?php AdminUI::field_imagekit_private_key(); ?></td>
                        </tr>

                        <tr class="ap-imagekit-field">
                            <th scope="row">
                                <label for="imagekit_url_endpoint">URL Endpoint</label>
                            </th>
                            <td><?php AdminUI::field_imagekit_url_endpoint(); ?></td>
                        </tr>
                    </table>
                </div>

                <!-- ===========================
                     AWS S3 + CLOUDFRONT
                ============================ -->
                <div class="ap-card" id="ap-s3-settings">
                    <h2>AWS S3 & CloudFront</h2>
                    <p class="ap-muted">Configure Amazon S3 for storage and CloudFront for fast global delivery.</p>

                    <table class="form-table ap-form-table">

                        <tr>
                            <th scope="row"><label for="s3_bucket">S3 Bucket</label></th>
                            <td><?php AdminUI::field_s3_bucket(); ?></td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="s3_region">S3 Region</label></th>
                            <td><?php AdminUI::field_s3_region(); ?></td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="s3_access_key">S3 Access Key</label></th>
                            <td><?php AdminUI::field_s3_access_key(); ?></td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="s3_secret_key">S3 Secret Key</label></th>
                            <td><?php AdminUI::field_s3_secret_key(); ?></td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="cloudfront_domain">CloudFront Domain</label></th>
                            <td><?php AdminUI::field_cloudfront_domain(); ?></td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="cloudfront_key_pair_id">CloudFront Key Pair ID</label></th>
                            <td><?php AdminUI::field_cloudfront_key_pair_id(); ?></td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="cloudfront_private_key">CloudFront Private Key</label></th>
                            <td><?php AdminUI::field_cloudfront_private_key(); ?></td>
                        </tr>

                    </table>
                </div>

                <!-- ===========================
                     EMAIL & NOTIFICATIONS
                ============================ -->
                <div class="ap-card">
                    <h2>Email & Notifications</h2>

                    <table class="form-table ap-form-table">

                        <tr>
                            <th scope="row"><label for="email_sender">Email Sender</label></th>
                            <td><?php AdminUI::field_email_sender(); ?></td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="webhook_secret">Payment Webhook Secret</label></th>
                            <td><?php AdminUI::field_webhook_secret(); ?></td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="require_otp">Require OTP for Downloads</label></th>
                            <td><?php AdminUI::field_require_otp(); ?></td>
                        </tr>

                    </table>
                </div>

                <!-- ===========================
                     APPEARANCE & THEME
                ============================ -->
                <div class="ap-card">
                    <h2>Appearance & Theme</h2>

                    <table class="form-table ap-form-table">
                        <tr>
                            <th scope="row"><label for="theme_overrides">Enable Theme Overrides</label></th>
                            <td><?php AdminUI::field_theme_overrides(); ?></td>
                        </tr>
                    </table>
                </div>

                <!-- ===========================
                     PERFORMANCE CHECKLIST
                ============================ -->
                <div class="ap-card ap-performance-checklist">
                    <h2>Performance & UX Checklist</h2>
                    <ul>
                        <li>Use a cloud storage provider for large galleries to reduce server bandwidth.</li>
                        <li>Enable OTP for sensitive deliveries to reduce unauthorized downloads.</li>
                        <li>Ensure your server has sufficient disk space for temporary ZIP assembly.</li>
                        <li>Test API keys and webhook secrets after entering them.</li>
                    </ul>
                </div>

                <?php submit_button('Save Settings'); ?>
            </div>

            <!-- ===========================
                 SIDEBAR HELP
            ============================ -->
            <aside class="ap-col ap-col-side">

                <div class="ap-card">
                    <h3>Quick Help</h3>
                    <p>Hover the question marks for inline help. If you need assistance, contact support or your developer.</p>
                    <p><strong>Tip:</strong> CloudFront dramatically improves global delivery speed.</p>
                </div>

                <div class="ap-card">
                    <h3>API Key Security</h3>
                    <p>API keys are encrypted before storage. Rotate keys periodically and restrict IAM permissions.</p>
                </div>

                <div class="ap-card">
                    <h3>Audit</h3>
                    <p>Changes to settings are logged for audit. Check the Health page for warnings or failures.</p>
                </div>

            </aside>
        </div>
    </form>
</div>
