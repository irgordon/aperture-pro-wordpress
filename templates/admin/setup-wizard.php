<?php
/**
 * Aperture Pro Setup Wizard
 *
 * CONDITIONAL STEPS:
 *  - Storage credentials shown only when relevant driver is selected
 *  - Health check summary before launch
 */

use AperturePro\Admin\AdminUI;

$nonce = wp_create_nonce(AdminUI::NONCE_ACTION);
?>
<div class="wrap ap-setup-wrap">
    <h1>Aperture Pro Setup</h1>

    <!-- Stepper Header -->
    <div class="ap-setup-progress">
        <div class="ap-setup-steps">
            <span data-step="1">Welcome</span>
            <span data-step="2">Branding</span>
            <span data-step="3">Storage</span>
            <span data-step="4">Proofing</span>
            <span data-step="5">Performance</span>
            <span data-step="6">Review</span>
        </div>
        <div class="ap-progress-bar">
            <div class="ap-progress-fill"></div>
        </div>
    </div>

    <form id="ap-setup-form">
        <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">

        <!-- STEP 1 -->
        <section class="ap-step" data-step="1">
            <h2>Welcome</h2>
            <p>Let’s configure Aperture Pro for your studio.</p>
        </section>

        <!-- STEP 2 -->
        <section class="ap-step" data-step="2">
            <h2>Branding</h2>
            <label>Studio Name</label>
            <input type="text" name="studio_name" required>
        </section>

        <!-- STEP 3 -->
        <section class="ap-step" data-step="3">
            <h2>Storage & Delivery</h2>

            <label>Storage Driver</label>
            <select name="storage_driver" id="ap-storage-driver">
                <option value="local">Local (Server)</option>
                <option value="s3">Amazon S3</option>
                <option value="cloudinary">Cloudinary</option>
                <option value="imagekit">ImageKit</option>
            </select>

            <!-- S3 Credentials -->
            <div class="ap-conditional ap-storage-s3">
                <h3>S3 Credentials</h3>
                <label>Bucket</label>
                <input type="text" name="s3_bucket">
                <label>Region</label>
                <input type="text" name="s3_region">
                <label>Access Key</label>
                <input type="text" name="s3_access_key">
                <label>Secret Key</label>
                <input type="password" name="s3_secret_key">
            </div>

            <!-- Cloudinary -->
            <div class="ap-conditional ap-storage-cloudinary">
                <h3>Cloudinary</h3>
                <label>API Key</label>
                <input type="text" name="cloud_api_key">
            </div>

            <!-- ImageKit -->
            <div class="ap-conditional ap-storage-imagekit">
                <h3>ImageKit</h3>
                <label>Public Key</label>
                <input type="text" name="cloud_api_key"> <!-- Reusing cloud_api_key as per AdminUI limit -->
            </div>
        </section>

        <!-- STEP 4 -->
        <section class="ap-step" data-step="4">
            <h2>Proofing Defaults</h2>
            <label>Max Proof Size (px)</label>
            <input type="number" name="proof_max_size" value="1600" min="800" max="2400">
            <label>Proof Quality</label>
            <input type="number" name="proof_quality" value="65" min="40" max="85">
        </section>

        <!-- STEP 5 -->
        <section class="ap-step" data-step="5">
            <h2>Performance</h2>
            <p>These features are enabled automatically.</p>
            <ul>
                <li>✔ IndexedDB Metadata Caching</li>
                <li>✔ Service Worker Image Caching</li>
                <li>✔ Lazy Loading & Responsive Images</li>
            </ul>
        </section>

        <!-- STEP 6 -->
        <section class="ap-step" data-step="6">
            <h2>Health Check & Launch</h2>
            <div id="ap-health-summary"></div>
            <p>If everything looks good, click Finish to complete setup.</p>
        </section>

        <div class="ap-actions">
            <button type="button" class="button" id="ap-prev">Previous</button>
            <button type="button" class="button button-primary" id="ap-next">Next</button>
            <button type="button" class="button button-primary" id="ap-finish" style="display:none;">Finish & Launch</button>
        </div>
    </form>
</div>
<script>
    // Inline simplified logic or rely on external JS (we'll fix external JS)
    window.apAjaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
</script>
