/**
 * sw.js — Service Worker for the FEER volunteer panel PWA.
 * Strategy: cache-first for static assets, network-first for PHP pages.
 */

const CACHE_NAME = 'feer-panel-v2';

const PRECACHE_URLS = [
    '/',
    '/panel/index.php',
    '/panel/messages.php',
];

// Static asset extensions that use cache-first strategy
const STATIC_EXTENSIONS = /\.(css|js|woff2?|ttf|eot|otf|svg|png|jpg|jpeg|gif|ico|webp)(\?.*)?$/i;

// ---- Install: pre-cache shell pages ----
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            // Use individual adds so one 404 doesn't abort the whole precache
            return Promise.allSettled(
                PRECACHE_URLS.map(url => cache.add(url).catch(() => {}))
            );
        }).then(() => self.skipWaiting())
    );
});

// ---- Activate: delete stale caches ----
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(
                keys
                    .filter(key => key !== CACHE_NAME)
                    .map(key => caches.delete(key))
            )
        ).then(() => self.clients.claim())
    );
});

// ---- Fetch ----
self.addEventListener('fetch', event => {
    const { request } = event;

    // Only handle GET requests
    if (request.method !== 'GET') return;

    // Only handle http/https
    const url = new URL(request.url);
    if (!['http:', 'https:'].includes(url.protocol)) return;

    if (STATIC_EXTENSIONS.test(url.pathname)) {
        // Cache-first for static assets
        event.respondWith(
            caches.match(request).then(cached => {
                if (cached) return cached;
                return fetch(request).then(response => {
                    if (!response || response.status !== 200 || response.type === 'opaque') {
                        return response;
                    }
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then(cache => cache.put(request, clone));
                    return response;
                });
            })
        );
    } else {
        // Network-first for PHP pages and everything else
        event.respondWith(
            fetch(request)
                .then(response => {
                    if (!response || response.status !== 200 || response.type === 'opaque') {
                        return response;
                    }
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then(cache => cache.put(request, clone));
                    return response;
                })
                .catch(() =>
                    caches.match(request).then(cached =>
                        cached || new Response('Brak połączenia z serwerem.', {
                            status: 503,
                            statusText: 'Service Unavailable',
                            headers: { 'Content-Type': 'text/plain; charset=utf-8' },
                        })
                    )
                )
        );
    }
});
