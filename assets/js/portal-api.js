window.AperturePortalAPI = (function () {
    const base = window.AperturePortal.rest;
    const nonce = window.AperturePortal.nonce;

    function request(method, endpoint, data = {}) {
        return fetch(`${base}${endpoint}`, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce
            },
            body: method === 'GET' ? undefined : JSON.stringify(data)
        }).then(r => r.json());
    }

    return {
        session: () => request('GET', '/auth/session'),
        proofs: projectId => request('GET', `/projects/${projectId}/proofs`),
        select: (galleryId, imageId, selected) =>
            request('POST', `/proofs/${galleryId}/select`, { image_id: imageId, selected }),
        comment: (galleryId, imageId, comment) =>
            request('POST', `/proofs/${galleryId}/comment`, { image_id: imageId, comment }),
        approve: galleryId =>
            request('POST', `/proofs/${galleryId}/approve`)
    };
})();
