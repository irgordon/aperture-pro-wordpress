/**
 * Aperture Pro Service Worker
 *
 * CACHING STRATEGY:
 *  - Static assets: cache-first
 *  - Images: stale-while-revalidate
 *  - API requests: network-only
 */

const CACHE_VERSION = 'ap-v1';
const STATIC_CACHE = `ap-static-${CACHE_VERSION}`;
const IMAGE_CACHE = `ap-images-${CACHE_VERSION}`;

const STATIC_ASSETS = [
    '/wp-content/plugins/aperture-pro/assets/js/portal-app.js',
    '/wp-content/plugins/aperture-pro/assets/css/client-portal.css'
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(STATIC_CACHE).then(cache => cache.addAll(STATIC_ASSETS))
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(
                keys
                    .filter(k => !k.includes(CACHE_VERSION))
                    .map(k => caches.delete(k))
            )
        )
    );
});

self.addEventListener('fetch', event => {
    const req = event.request;
    const url = new URL(req.url);

    // Never cache API calls
    if (url.pathname.includes('/wp-json/')) {
        return;
    }

    // Images: stale-while-revalidate
    if (req.destination === 'image') {
        event.respondWith(
            caches.open(IMAGE_CACHE).then(cache =>
                cache.match(req).then(cached => {
                    const fetchPromise = fetch(req).then(res => {
                        if (res.ok) cache.put(req, res.clone());
                        return res;
                    }).catch(() => cached);

                    return cached || fetchPromise;
                })
            )
        );
        return;
    }

    // Static assets: cache-first
    event.respondWith(
        caches.match(req).then(cached => cached || fetch(req))
    );
});
