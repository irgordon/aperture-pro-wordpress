<script>
    // Ensures SW scope is correct for WordPress installs
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('<?php echo esc_url(plugins_url('assets/js/sw.js', APERTURE_PRO_FILE)); ?>');
    }
</script>
