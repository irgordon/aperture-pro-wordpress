<?php
/**
 * Main portal template
 *
 * Expects $context array with keys:
 *  - project, client, gallery, images, photographer_notes, payment_status, session, health, messages
 */
$project = $context['project'] ?? null;
$client = $context['client'] ?? null;
$gallery = $context['gallery'] ?? null;
$images = $context['images'] ?? [];
$notes = $context['photographer_notes'] ?? null;
$paymentStatus = $context['payment_status'] ?? null;
$session = $context['session'] ?? null;
$health = $context['health'] ?? [];
$messages = $context['messages'] ?? [];
$download = $context['download'] ?? null;
?>

<div class="ap-portal">
    <header class="ap-portal-header">
        <h1 class="ap-portal-title"><?php echo $project ? $project['title'] : 'Client Portal'; ?></h1>
        <div class="ap-portal-meta">
            <?php if ($client): ?>
                <div class="ap-client-name">Hello, <strong><?php echo $client['name']; ?></strong></div>
            <?php endif; ?>
            <div class="ap-project-status">Status: <strong><?php echo $project ? ucfirst($project['status']) : 'Unknown'; ?></strong></div>
            <div class="ap-last-updated">Last updated: <?php echo $project ? $project['updated_at'] : ''; ?></div>
        </div>
    </header>

    <?php if (!empty($messages)): ?>
        <div class="ap-portal-messages">
            <?php foreach ($messages as $m): ?>
                <div class="ap-message ap-message-info"><?php echo esc_html($m); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php include __DIR__ . '/payment-alert.php'; ?>

    <main class="ap-portal-main">
        <section class="ap-portal-left">
            <?php include __DIR__ . '/dashboard.php'; ?>
            <?php include __DIR__ . '/photographer-notes.php'; ?>
            <?php include __DIR__ . '/upload.php'; ?>
        </section>

        <aside class="ap-portal-right">
            <?php include __DIR__ . '/proofs.php'; ?>
            <?php include __DIR__ . '/download-card.php'; ?>
            <?php include __DIR__ . '/system-health.php'; ?>
        </aside>
    </main>

    <footer class="ap-portal-footer">
        <div class="ap-contact-support"><?php echo esc_html($context['messages'][0] ?? ''); ?></div>
    </footer>
</div>
