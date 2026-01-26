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
                    <img src="<?php echo esc_url($img['url']); ?>" alt="Proof image ID <?php echo (int)$img['id']; ?>" />
                    <div class="ap-proof-meta">
                        <label>
                            <input type="checkbox" class="ap-select-checkbox" <?php echo $img['is_selected'] ? 'checked' : ''; ?> aria-label="Select proof <?php echo (int)$img['id']; ?>" />
                            Select
                        </label>
                        <button class="ap-btn ap-btn-small ap-comment-btn" aria-label="Comment on proof <?php echo (int)$img['id']; ?>">Comment</button>
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

        <?php if (isset($context['pagination']) && $context['pagination']['total_pages'] > 1): ?>
            <div class="ap-pagination" style="margin: 20px 0; display: flex; justify-content: center; gap: 15px; align-items: center;">
                <?php
                $pg = $context['pagination'];
                $cur = $pg['current_page'];
                $tot = $pg['total_pages'];
                ?>

                <?php if ($cur > 1): ?>
                    <a href="<?php echo esc_url(add_query_arg('page', $cur - 1)); ?>" class="ap-btn ap-btn-small">Previous</a>
                <?php else: ?>
                    <button class="ap-btn ap-btn-small" disabled>Previous</button>
                <?php endif; ?>

                <span>Page <?php echo (int)$cur; ?> of <?php echo (int)$tot; ?></span>

                <?php if ($cur < $tot): ?>
                    <a href="<?php echo esc_url(add_query_arg('page', $cur + 1)); ?>" class="ap-btn ap-btn-small">Next</a>
                <?php else: ?>
                    <button class="ap-btn ap-btn-small" disabled>Next</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="ap-proofs-actions">
            <button id="ap-approve-proofs" class="ap-btn ap-btn-success" aria-label="Approve currently selected proofs">Approve Selected Proofs</button>
        </div>
    <?php endif; ?>
</div>
