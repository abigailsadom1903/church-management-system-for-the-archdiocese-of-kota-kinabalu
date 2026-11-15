// Service worker of the installable app (see manifest.json and includes/pwa.php).
//  - Pages are always loaded fresh from the server (they hold personal details and form tokens, so they are never
//    stored on the phone). With no connection, a friendly offline page is shown instead.
//  - The design files (styles, scripts, images in /assets/) are kept on the phone so the app opens quickly.
//  - Uploads (receipts, sacrament documents, photos) and everything else are never stored.
// Bump VERSION when this file changes so old copies are cleared.
const VERSION = 'cms-v1';
const CORE = [
    'offline.html',
    'assets/css/style.css',
    'assets/css/dark.css',
    'assets/images/logo.png',
    'assets/images/app/icon-192.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(VERSION).then((c) => c.addAll(CORE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;              // fonts, maps etc.: the browser handles them

    // Pages: network only, offline page as the fallback.
    if (req.mode === 'navigate') {
        event.respondWith(fetch(req).catch(() => caches.match('offline.html')));
        return;
    }

    // Design files: show the kept copy at once and refresh it in the background.
    const scope = new URL(self.registration.scope);
    if (url.pathname.startsWith(scope.pathname + 'assets/')) {
        event.respondWith(
            caches.open(VERSION).then((cache) =>
                cache.match(req).then((kept) => {
                    const fresh = fetch(req).then((res) => {
                        if (res.ok) cache.put(req, res.clone());
                        return res;
                    }).catch(() => kept);
                    return kept || fresh;
                })
            )
        );
    }
    // Anything else (PHP, uploads, API calls): straight to the network, never stored.
});
