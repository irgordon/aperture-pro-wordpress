/**
 * Aperture Pro – Client Portal App
 *
 * PERFORMANCE ENHANCEMENTS:
 *  - IndexedDB metadata caching (proof list) for instant repeat visits
 *  - Service Worker registration (if present)
 *  - Responsive image sizes attribute
 *  - AVIF / WebP detection + fallback
 *  - Skeleton loaders
 *  - Lazy loading + async decoding
 *
 * INDEXEDDB STRATEGY:
 *  - Cache proof metadata per project (stale-while-revalidate)
 *  - TTL-based expiration to avoid stale data lingering
 *  - Schema versioning for forward compatibility
 */

(function () {
    'use strict';

    const portal = document.getElementById('ap-portal');
    if (!portal) return;

    const gallery = portal.querySelector('.ap-proof-gallery');
    if (!gallery) return;

    const apiBase = portal.dataset.apiBase || '';
    const nonce = portal.dataset.nonce || '';

    // If your portal can provide a stable project identifier, use it.
    // Fallback keeps cache scoped but may reduce reuse if your endpoint is "current".
    const projectCacheKey =
        portal.dataset.projectId ||
        portal.dataset.projectKey ||
        'current';

    /* ---------------------------------------------------------
     * Service Worker Registration (best-effort)
     * --------------------------------------------------------- */

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            // Path kept explicit to match prior implementation; use template-based registration if available.
            navigator.serviceWorker.register('/wp-content/plugins/aperture-pro/assets/js/sw.js')
                .catch(() => {
                    // Fail-soft: SW is an enhancement, not a requirement.
                });
        });
    }

    /* ---------------------------------------------------------
     * IndexedDB helper (minimal, dependency-free)
     * --------------------------------------------------------- */

    const IDB = (function () {
        const DB_NAME = 'aperture_pro_portal';
        const DB_VERSION = 1;

        // Store for proof list metadata
        const STORE_PROOFS = 'proofs';

        function open() {
            return new Promise((resolve, reject) => {
                if (!('indexedDB' in window)) {
                    reject(new Error('IndexedDB not supported'));
                    return;
                }

                const req = indexedDB.open(DB_NAME, DB_VERSION);

                req.onupgradeneeded = function (event) {
                    const db = event.target.result;

                    if (!db.objectStoreNames.contains(STORE_PROOFS)) {
                        const store = db.createObjectStore(STORE_PROOFS, { keyPath: 'key' });
                        store.createIndex('updatedAt', 'updatedAt', { unique: false });
                    }
                };

                req.onsuccess = function () {
                    resolve(req.result);
                };

                req.onerror = function () {
                    reject(req.error || new Error('Failed to open IndexedDB'));
                };
            });
        }

        function get(db, storeName, key) {
            return new Promise((resolve, reject) => {
                const tx = db.transaction(storeName, 'readonly');
                const store = tx.objectStore(storeName);
                const req = store.get(key);

                req.onsuccess = () => resolve(req.result || null);
                req.onerror = () => reject(req.error || new Error('IDB get failed'));
            });
        }

        function put(db, storeName, value) {
            return new Promise((resolve, reject) => {
                const tx = db.transaction(storeName, 'readwrite');
                const store = tx.objectStore(storeName);
                const req = store.put(value);

                req.onsuccess = () => resolve(true);
                req.onerror = () => reject(req.error || new Error('IDB put failed'));
            });
        }

        return {
            DB_NAME,
            DB_VERSION,
            STORE_PROOFS,
            open,
            get,
            put
        };
    })();

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

    function safeJsonStringify(obj) {
        try {
            return JSON.stringify(obj);
        } catch (e) {
            return '';
        }
    }

    function hashPayload(payload) {
        // Lightweight hash: stable string compare is good enough for our use here.
        // Avoids crypto overhead and keeps logic simple.
        return safeJsonStringify(payload);
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
     * Image Creation (Responsive + Format Fallback)
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

        // Responsive sizing based on typical masonry/grid behavior.
        // Adjust if your CSS uses a different column system.
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
            img.alt = 'Image unavailable';
        });

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
            checkbox.checked = !!proof.is_selected;
            checkbox.className = 'ap-proof-select';

            meta.appendChild(checkbox);
            meta.appendChild(document.createTextNode(' Select'));

            card.appendChild(meta);
            fragment.appendChild(card);
        });

        gallery.appendChild(fragment);
    }

    /* ---------------------------------------------------------
     * IndexedDB cache: proofs list (stale-while-revalidate)
     * --------------------------------------------------------- */

    const PROOFS_CACHE_TTL_MS = 10 * 60 * 1000; // 10 minutes
    const PROOFS_CACHE_SCHEMA = 'v1';

    function buildProofsCacheKey() {
        // Scope by apiBase + project key + schema so environments and versions don’t collide.
        return [
            'proofs',
            PROOFS_CACHE_SCHEMA,
            apiBase || 'no-api-base',
            projectCacheKey
        ].join('|');
    }

    async function loadProofsFromCache() {
        const key = buildProofsCacheKey();

        try {
            const db = await IDB.open();
            const cached = await IDB.get(db, IDB.STORE_PROOFS, key);

            if (!cached || !cached.payload || !cached.updatedAt) {
                return null;
            }

            const age = Date.now() - cached.updatedAt;
            if (age > PROOFS_CACHE_TTL_MS) {
                return null;
            }

            return cached.payload;
        } catch (e) {
            // Fail-soft: no cache if IDB is unavailable or errors.
            return null;
        }
    }

    async function saveProofsToCache(payload) {
        const key = buildProofsCacheKey();

        try {
            const db = await IDB.open();
            await IDB.put(db, IDB.STORE_PROOFS, {
                key,
                updatedAt: Date.now(),
                payload
            });
        } catch (e) {
            // Fail-soft: caching is an enhancement only.
        }
    }

    /* ---------------------------------------------------------
     * Initial Load (cache-first render, then refresh)
     * --------------------------------------------------------- */

    async function loadProofs() {
        renderSkeletons();

        // Detect formats in parallel with cache load.
        const detectPromise = detectImageFormats();

        // Try to render cached proofs ASAP for perceived speed.
        const cachedPayload = await loadProofsFromCache();
        if (cachedPayload && Array.isArray(cachedPayload.proofs)) {
            // Render from cache immediately (stale).
            renderGallery(cachedPayload.proofs);
        }

        // Ensure format detection completes before we render any *fresh* response.
        // Cached render may have already happened; that's OK. Fresh render will replace it.
        await Promise.race([
            detectPromise,
            new Promise(resolve => setTimeout(resolve, 600))
        ]);

        // Fetch fresh proofs. If it matches cached, avoid unnecessary re-render.
        apiFetch('/projects/current/proofs')
            .then(async data => {
                const proofs = (data && data.proofs) ? data.proofs : [];
                const freshPayload = { proofs };

                const freshHash = hashPayload(freshPayload);
                const cachedHash = cachedPayload ? hashPayload(cachedPayload) : '';

                // If we didn't render cache, or the content changed, render fresh.
                if (!cachedPayload || freshHash !== cachedHash) {
                    renderGallery(proofs);
                }

                // Persist fresh payload for next visit.
                await saveProofsToCache(freshPayload);
            })
            .catch(() => {
                // If cache rendered, keep it; otherwise show error.
                if (!cachedPayload) {
                    gallery.innerHTML = '<p class="ap-error">Failed to load proofs.</p>';
                }
            });
    }

    loadProofs();

})();
