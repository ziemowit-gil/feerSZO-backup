/**
 * crm/mobile/sw.js — service worker mobilnego dialera CRM.
 *
 * Cache'ujemy WYŁĄCZNIE powłokę aplikacji (CSS/JS/ikony). Dane kontaktów —
 * imiona i numery telefonów — nigdy nie trafiają do Cache Storage: to dane
 * osobowe, a CRM jest bramkowany weryfikacją IKA. Podręczna kopia listy żyje
 * w sessionStorage i ginie razem z zamknięciem aplikacji (patrz assets/app.js).
 */

const CACHE = 'crm-dialer-v1';

// Ścieżki względne — działa niezależnie od tego, w jakim podkatalogu stoi aplikacja.
const SHELL = ['assets/app.css', 'assets/app.js', 'offline.html'];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE)
            .then(cache => Promise.allSettled(
                SHELL.map(url => cache.add(new URL(url, self.registration.scope)).catch(() => {}))
            ))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    const req = event.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    // Ścieżki liczymy względem scope — aplikacja stoi zarówno pod /crm/mobile/,
    // jak i pod krótkim aliasem /mobilna/ (przepisanie w .htaccess).
    const scope = new URL(self.registration.scope);
    if (!url.pathname.startsWith(scope.pathname)) return;
    const rel = url.pathname.slice(scope.pathname.length);

    // Dane kontaktów i logowanie rozmów — zawsze z sieci, nigdy z cache.
    if (rel.startsWith('api/')) return;

    // Powłoka: cache-first (statyczne assety zmieniają się razem z ?v=mtime).
    if (/\.(css|js|svg|png|woff2?)$/i.test(url.pathname)) {
        event.respondWith(
            caches.match(req, { ignoreSearch: false }).then(hit => hit || fetch(req).then(res => {
                if (res.ok) {
                    const copy = res.clone();
                    caches.open(CACHE).then(c => c.put(req, copy));
                }
                return res;
            }))
        );
        return;
    }

    // Wejście na stronę aplikacji: sieć, a przy jej braku ekran offline.
    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req).catch(() => caches.match(new URL('offline.html', self.registration.scope)))
        );
    }
});
