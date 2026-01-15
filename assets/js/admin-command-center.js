(function ($) {
    const api = ApertureAdmin.rest;
    const nonce = ApertureAdmin.nonce;

    function request(endpoint) {
        return $.ajax({
            url: `${api}${endpoint}`,
            method: 'GET',
            beforeSend: xhr => xhr.setRequestHeader('X-WP-Nonce', nonce)
        });
    }

    function loadHealthCard() {
        request('/admin/health-check')
            .done(res => {
                const status = res.data.overall_status;
                const checks = res.data.checks;

                const statusEl = $('#ap-health-card .ap-health-status');
                const listEl = $('#ap-health-card .ap-health-list');

                statusEl
                    .removeClass('ap-loading')
                    .addClass(`ap-status-${status}`)
                    .text(`Overall Status: ${status.toUpperCase()}`);

                listEl.empty();

                Object.entries(checks.tables).forEach(([table, ok]) => {
                    listEl.append(`<li>${table}: ${ok ? 'OK' : 'Missing'}</li>`);
                });

                listEl.append(`<li>Config Loaded: ${checks.config_loaded ? 'Yes' : 'No'}</li>`);
                listEl.append(`<li>Storage Driver: ${checks.storage_driver}</li>`);
                listEl.append(`<li>Logging: ${checks.logging ? 'Operational' : 'Issue detected'}</li>`);
            })
            .fail(() => {
                $('#ap-health-card .ap-health-status')
                    .removeClass('ap-loading')
                    .addClass('ap-status-error')
                    .text('Health check failed');
            });
    }

    $(document).ready(function () {
        loadHealthCard();
    });

})(jQuery);
