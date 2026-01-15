(function ($) {
    const api = ApertureAdmin.rest;
    const nonce = ApertureAdmin.nonce;

    const projectId = $('.aperture-command-center').data('project-id');

    function request(method, endpoint, data = {}) {
        return $.ajax({
            url: `${api}${endpoint}`,
            method,
            data: JSON.stringify(data),
            contentType: 'application/json',
            beforeSend: xhr => xhr.setRequestHeader('X-WP-Nonce', nonce)
        });
    }

    function loadProjectSummary() {
        request('GET', `/projects/${projectId}/status`)
            .done(res => {
                $('#aperture-project-summary').html(`
                    <p><strong>Status:</strong> ${res.data.status}</p>
                `);
            })
            .fail(() => {
                $('#aperture-project-summary').html(`<p>Error loading project.</p>`);
            });
    }

    function loadLogs() {
        request('GET', `/admin/logs?project_id=${projectId}`)
            .done(res => {
                const rows = res.data.map(log => `
                    <tr>
                        <td>${log.created_at}</td>
                        <td>${log.level}</td>
                        <td>${log.context}</td>
                        <td>${log.message}</td>
                    </tr>
                `).join('');

                $('#ap-log-rows').html(rows);
            })
            .fail(() => {
                $('#ap-log-rows').html(`<tr><td colspan="4">Error loading logs.</td></tr>`);
            });
    }

    $('#ap-start-editing').on('click', function () {
        request('POST', `/admin/projects/${projectId}/start-editing`)
            .done(() => {
                alert('Editing started');
                loadProjectSummary();
            })
            .fail(() => alert('Error starting editing'));
    });

    $('#ap-generate-download').on('click', function () {
        request('POST', `/admin/projects/${projectId}/generate-download-link`)
            .done(res => {
                prompt('Download link:', res.data.download_url);
            })
            .fail(() => alert('Error generating link'));
    });

    loadProjectSummary();
    loadLogs();

})(jQuery);
