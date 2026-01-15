<?php
/**
 * Upload component: shows upload UI and progress bar.
 *
 * Client-side JS will handle chunking and call REST endpoints:
 *  - POST /aperture/v1/uploads/start
 *  - POST /aperture/v1/uploads/{upload_id}/chunk
 *  - GET  /aperture/v1/uploads/{upload_id}/progress
 *
 * The JS should use ApertureClient.restBase and ApertureClient.nonce localized by PortalController.
 */
?>
<div class="ap-card ap-upload">
    <h3>Upload Files</h3>
    <p>Upload additional images for this project. Files are uploaded in chunks and can be resumed if your connection is interrupted.</p>

    <div class="ap-upload-controls">
        <input type="file" id="ap-upload-input" multiple accept="image/*" />
        <button id="ap-start-upload" class="ap-btn ap-btn-primary">Start Upload</button>
    </div>

    <div id="ap-upload-list" class="ap-upload-list"></div>

    <template id="ap-upload-item-template">
        <div class="ap-upload-item">
            <div class="ap-upload-filename"></div>
            <div class="ap-upload-progress">
                <div class="ap-progress-bar"><div class="ap-progress-fill" style="width:0%"></div></div>
                <div class="ap-upload-status"></div>
            </div>
            <div class="ap-upload-actions">
                <button class="ap-btn ap-btn-small ap-resume">Resume</button>
                <button class="ap-btn ap-btn-small ap-cancel">Cancel</button>
            </div>
        </div>
    </template>
</div>
