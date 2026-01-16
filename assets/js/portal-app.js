/**
 * Aperture Pro – Client Portal App
 *
 * PERFORMANCE ENHANCEMENTS:
 *  - Skeleton loaders for perceived speed
 *  - AVIF / WebP detection with graceful fallback
 *  - Native lazy loading + async decoding
 *  - IntersectionObserver progressive reveal
 *  - Event delegation + debounced network calls
 */

(function () {
    'use strict';

    const portal = document.getElementById('ap-portal');
    if (!portal) return;

    const gallery = portal.querySelector('.ap-proof-gallery');
    const apiBase = portal.dataset.apiBase || '';
    const nonce = portal.dataset.nonce || '';

    /* ---------------------------------------------------------
     * Feature Detection (cached)
     * --------------------------------------------------------- */

    const supports = {
        avif: false,
        webp: false
    };

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

    function testImageFormat(dataUri) {
        return new Promise(resolve => {
            const img = new Image();
            img.onload = () => resolve(img.width > 0);
            img.onerror = () => resolve(false);
            img.src = dataUri;
        });
    }

    /* ---------------------------------------------------------
     * Utilities
     * --------------------------------------------------------- */

    function debounce(fn, delay) {
        let timer;
        return function (...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), delay);
        };
    }

    function requestIdle(fn) {
        if ('requestIdleCallback' in window) {
            requestIdleCallback(fn, { timeout: 500 });
        } else {
            setTimeout(fn, 0);
        }
    }

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

            const img = document.createElement('div');
            img.className = 'ap-skeleton-image';

            const meta = document.createElement('div');
            meta.className = 'ap-skeleton-meta';

            card.appendChild(img);
            card.appendChild(meta);
            fragment.appendChild(card);
        }

        gallery.appendChild(fragment);
    }

    /* ---------------------------------------------------------
     * Image Creation with Format Fallback
     * --------------------------------------------------------- */

    const imageObserver = 'IntersectionObserver' in window
        ? new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    imageObserver.unobserve(entry.target);
                }
            });
        }, { rootMargin: '100px' })
        : null;

    function getOptimizedUrl(originalUrl) {
        if (supports.avif) {
            return originalUrl + '?format=avif';
        }
        if (supports.webp) {
            return originalUrl + '?format=webp';
        }
        return originalUrl;
    }

    function createImage(originalUrl, alt) {
        const img = document.createElement('img');
        img.alt = alt || '';
        img.loading = 'lazy';
        img.decoding = 'async';
        img.className = 'ap-proof-image';

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
            img.alt = 'Image unavailable';
        });

        if (imageObserver) {
            imageObserver.observe(img);
        } else {
            img.classList.add('is-visible');
        }

        return img;
    }

    /* ---------------------------------------------------------
     * Render Proof Gallery
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

            const label = document.createElement('label');
            label.textContent = 'Select';

            meta.appendChild(checkbox);
            meta.appendChild(label);

            card.appendChild(meta);
            fragment.appendChild(card);
        });

        gallery.appendChild(fragment);
    }

    /* ---------------------------------------------------------
     * Event Delegation
     * --------------------------------------------------------- */

    gallery.addEventListener('change', debounce(event => {
        const checkbox = event.target.closest('.ap-proof-select');
        if (!checkbox) return;

        const card = checkbox.closest('.ap-proof-card');
        if (!card) return;

        const imageId = card.dataset.imageId;
        const selected = checkbox.checked;

        apiFetch('/proofs/select', {
            method: 'POST',
            body: JSON.stringify({
                image_id: imageId,
                selected: selected
            })
        }).catch(() => {
            checkbox.checked = !selected;
        });
    }, 250));

    /* ---------------------------------------------------------
     * Initial Load
     * --------------------------------------------------------- */

    function loadProofs() {
        renderSkeletons();

        detectImageFormats().finally(() => {
            apiFetch('/projects/current/proofs')
                .then(data => {
                    requestIdle(() => renderGallery(data.proofs || []));
                })
                .catch(() => {
                    gallery.innerHTML = '<p class="ap-error">Failed to load proofs.</p>';
                });
        });
    }

    loadProofs();

})();
