<?php
/**
 * Dashboard summary: project details, session date, quick actions
 */
?>
<div class="ap-card ap-dashboard">
    <h2>Project Summary</h2>

    <?php if ($project): ?>
        <dl class="ap-project-details">
            <dt>Title</dt><dd><?php echo $project['title']; ?></dd>
            <dt>Status</dt><dd><?php echo ucfirst($project['status']); ?></dd>
            <dt>Session Date</dt><dd><?php echo $project['session_date'] ?: 'TBD'; ?></dd>
            <dt>Last Updated</dt><dd><?php echo $project['updated_at']; ?></dd>
        </dl>

        <div class="ap-quick-actions">
            <?php if ($gallery && $gallery['status'] === 'proofing'): ?>
                <button class="ap-btn ap-btn-primary" id="ap-open-proofs">View Proofs</button>
            <?php endif; ?>

            <?php if (!empty($download) && (empty($paymentStatus) || strtolower($paymentStatus) === 'paid')): ?>
                <a class="ap-btn ap-btn-success" href="<?php echo esc_url($download['url']); ?>">Download Final Gallery</a>
            <?php elseif (!empty($download)): ?>
                <button class="ap-btn ap-btn-disabled" disabled>Download Final Gallery (Payment required)</button>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p>No project information available.</p>
    <?php endif; ?>
</div>
