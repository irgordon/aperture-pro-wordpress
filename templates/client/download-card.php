<?php
/**
 * Download card: shows final gallery download link and expiration
 */
?>
<div class="ap-card ap-download-card">
    <h3>Final Gallery</h3>

    <?php if (!empty($download) && (empty($paymentStatus) || strtolower($paymentStatus) === 'paid')): ?>
        <p>Your final gallery is ready for download.</p>
        <a class="ap-btn ap-btn-success" href="<?php echo esc_url($download['url']); ?>">Download Now</a>
        <?php if (!empty($download['expires_at'])): ?>
            <div class="ap-download-expires">Link expires: <?php echo esc_html($download['expires_at']); ?></div>
        <?php endif; ?>
    <?php elseif (!empty($download)): ?>
        <p>Download is available but payment is required before delivery.</p>
    <?php else: ?>
        <p>No download link is available yet. If you believe this is an error, contact your photographer.</p>
    <?php endif; ?>
</div>
