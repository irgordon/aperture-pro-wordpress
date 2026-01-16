/**
 * Aperture Pro – Client Portal App
 *
 * PERFORMANCE ENHANCEMENTS:
 *  - Service Worker registration
 *  - Responsive image sizes attribute
 *  - AVIF / WebP detection + fallback
 *  - Skeleton loaders
 *  - Lazy loading + async decoding
 */

(function () {
    'use strict';

    const portal = document.getElementById('ap-portal');
    if (!portal) return;

    const gallery = portal.querySelector('.ap-proof-gallery');
    const apiBase = portal.dataset.apiBase || '';
    const nonce = portal.dataset.nonce || '';

    /* ---------------------------------------------------------
     * Service Worker Registration
     * --------------------------------------------------------- */

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/wp-content/plugins/aperture-pro/assets/js/sw.js')
                .catch(() => {
                    // Fail-soft: SW is an enhancement, not a requirement
                });
        });
    }

    /* ---------------------------------------------------------
     * Feature Detection (cached)
     * --------------------------------------------------------- */

    const supports = { avif: false, webp: false };

    function testImageFormat(dataUri) {
        return new Promise(resolve => {
            const img = new Image();
            img.onload = () => resolve(img.width > 0);
            img.onerror = () => resolve(false);
            img.src = dataUri;
        });
    }

    function detectImageFormats() {
        return Promise.all([
            testImageFormat(
                'data:image/avif;base64,AAAAIGZ0eXBhdmlmAAAAAG1pZjFhdmlmAAACAGF2MDEAAAAAAQAA'
            ).then(ok => supports.avif = ok),
            testImageFormat(
                'data:image/webp;base64,UklGRiIAAABXRUJQVlA4TAYAAAAvAAAAAAfQ//73v/+BiOh/AAA='
            ).then(ok => supports.webp = ok)
        ]);
    }

    /* ---------------------------------------------------------
     * Utilities
     * --------------------------------------------------------- */

    function apiFetch(endpoint, options = {}) {
        return fetch(apiBase + endpoint, {
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce
            },
            ...options
        }).then(res => {
            if (!res.ok) throw new Error('Network error');
            return res.json();
        });
    }

    /* ---------------------------------------------------------
     * Skeleton Loaders
     * --------------------------------------------------------- */

    function renderSkeletons(count = 12) {
        gallery.innerHTML = '';
        const fragment = document.createDocumentFragment();

        for (let i = 0; i < count; i++) {
            const card = document.createElement('div');
            card.className = 'ap-proof-card ap-skeleton';

            card.innerHTML = `
                <div class="ap-skeleton-image"></div>
                <div class="ap-skeleton-meta"></div>
            `;

            fragment.appendChild(card);
        }

        gallery.appendChild(fragment);
    }

    /* ---------------------------------------------------------
     * Image Creation (Responsive + Fallback)
     * --------------------------------------------------------- */

    function getOptimizedUrl(originalUrl) {
        if (supports.avif) return originalUrl + '?format=avif';
        if (supports.webp) return originalUrl + '?format=webp';
        return originalUrl;
    }

    function createImage(originalUrl, alt) {
        const img = document.createElement('img');

        img.alt = alt || '';
        img.loading = 'lazy';
        img.decoding = 'async';
        img.className = 'ap-proof-image';

        // Responsive sizing based on grid layout
        img.sizes = `
            (max-width: 600px) 100vw,
            (max-width: 1024px) 50vw,
            33vw
        `.trim();

        const optimizedUrl = getOptimizedUrl(originalUrl);
        let triedFallback = false;

        img.src = optimizedUrl;

        img.addEventListener('error', () => {
            if (!triedFallback && optimizedUrl !== originalUrl) {
                triedFallback = true;
                img.src = originalUrl;
                return;
            }
            img.classList.add('is-error');
        });

        return img;
    }

    /* ---------------------------------------------------------
     * Render Gallery
     * --------------------------------------------------------- */

    function renderGallery(proofs) {
        gallery.innerHTML = '';
        const fragment = document.createDocumentFragment();

        proofs.forEach(proof => {
            const card = document.createElement('div');
            card.className = 'ap-proof-card';
            card.dataset.imageId = proof.id;

            const img = createImage(proof.proof_url, proof.filename);
            card.appendChild(img);

            const meta = document.createElement('div');
            meta.className = 'ap-proof-meta';

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.checked = proof.is_selected;
            checkbox.className = 'ap-proof-select';

            meta.appendChild(checkbox);
            meta.appendChild(document.createTextNode(' Select'));

            card.appendChild(meta);
            fragment.appendChild(card);
        });

        gallery.appendChild(fragment);
    }

    /* ---------------------------------------------------------
     * Initial Load
     * --------------------------------------------------------- */

    function loadProofs() {
        renderSkeletons();

        detectImageFormats().finally(() => {
            apiFetch('/projects/current/proofs')
                .then(data => renderGallery(data.proofs || []))
                .catch(() => {
                    gallery.innerHTML = '<p class="ap-error">Failed to load proofs.</p>';
                });
        });
    }

    loadProofs();

})();
