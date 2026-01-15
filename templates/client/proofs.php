<?php
/**
 * Proofs gallery component
 */
?>
<div class="ap-card ap-proofs">
    <h3>Proofs</h3>

    <?php if (empty($images)): ?>
        <p>No proofs uploaded yet. Check back later.</p>
    <?php else: ?>
        <div class="ap-proofs-grid">
            <?php foreach ($images as $img): ?>
                <div class="ap-proof-item" data-image-id="<?php echo (int)$img['id']; ?>">
                    <img src="<?php echo esc_url($img['url']); ?>" alt="Proof <?php echo (int)$img['id']; ?>" />
                    <div class="ap-proof-meta">
                        <label>
                            <input type="checkbox" class="ap-select-checkbox" <?php echo $img['is_selected'] ? 'checked' : ''; ?> />
                            Select
                        </label>
                        <button class="ap-btn ap-btn-small ap-comment-btn">Comment</button>
                    </div>
                    <div class="ap-proof-comments">
                        <?php if (!empty($img['comments'])): ?>
                            <?php foreach ($img['comments'] as $c): ?>
                                <div class="ap-comment"><?php echo esc_html($c['comment']); ?> <span class="ap-comment-time"><?php echo esc_html($c['created_at'] ?? ''); ?></span></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="ap-proofs-actions">
            <button id="ap-approve-proofs" class="ap-btn ap-btn-success">Approve Selected Proofs</button>
        </div>
    <?php endif; ?>
</div>
