<?php
/**
 * Photographer notes component
 */
?>
<div class="ap-card ap-photographer-notes">
    <h3>Notes from your photographer</h3>
    <?php if (!empty($notes)): ?>
        <div class="ap-notes"><?php echo nl2br(esc_html($notes)); ?></div>
    <?php else: ?>
        <div class="ap-notes-empty">No notes yet. Your photographer will share details here when available.</div>
    <?php endif; ?>
</div>
