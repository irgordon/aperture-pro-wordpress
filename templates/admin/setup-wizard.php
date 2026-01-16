<?php
/**
 * Aperture Pro Setup Wizard
 */
?>
<div class="wrap ap-setup-wrap">
    <h1>Aperture Pro Setup</h1>

    <div class="ap-setup-progress">
        <div class="ap-setup-steps">
            <span data-step="1">Welcome</span>
            <span data-step="2">Branding</span>
            <span data-step="3">Storage</span>
            <span data-step="4">Proofing</span>
            <span data-step="5">Performance</span>
            <span data-step="6">Launch</span>
        </div>
        <div class="ap-progress-bar">
            <div class="ap-progress-fill"></div>
        </div>
    </div>

    <form id="ap-setup-form">
        <section class="ap-step" data-step="1">
            <h2>Welcome</h2>
            <p>Let’s configure Aperture Pro for your studio.</p>
        </section>

        <section class="ap-step" data-step="2">
            <h2>Branding</h2>
            <label>Studio Name</label>
            <input type="text" name="studio_name" required>
        </section>

        <section class="ap-step" data-step="3">
            <h2>Storage & Delivery</h2>
            <label>Storage Driver</label>
            <select name="storage_driver">
                <option value="local">Local</option>
                <option value="s3">Amazon S3</option>
                <option value="cloudinary">Cloudinary</option>
                <option value="imagekit">ImageKit</option>
            </select>
        </section>

        <section class="ap-step" data-step="4">
            <h2>Proofing Defaults</h2>
            <label>Max Proof Size (px)</label>
            <input type="number" name="proof_max_size" value="1600">
            <label>Proof Quality</label>
            <input type="number" name="proof_quality" value="65">
        </section>

        <section class="ap-step" data-step="5">
            <h2>Performance</h2>
            <label>
                <input type="checkbox" checked disabled>
                Enable IndexedDB caching
            </label>
            <label>
                <input type="checkbox" checked disabled>
                Enable Service Worker
            </label>
        </section>

        <section class="ap-step" data-step="6">
            <h2>Ready to Launch</h2>
            <p>Your studio is ready. Click Finish to activate Aperture Pro.</p>
        </section>

        <div class="ap-setup-actions">
            <button type="button" id="ap-prev">Back</button>
            <button type="button" id="ap-next">Next</button>
            <button type="submit" id="ap-finish">Finish</button>
        </div>
    </form>
</div>
