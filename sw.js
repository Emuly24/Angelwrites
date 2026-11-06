// F204: network-first service worker.
// The previous worker answered every request from a cache named 'angelwrites-v1' that was
// never refreshed, so returning visitors kept the homepage, style.css and main.js they first
// saw, whatever was deployed later. This worker:
//  - always asks the network first and only falls back to the cache when offline;
//  - refreshes the cached copy of the site shell whenever the network answers;
//  - deletes every older cache when it activates, and takes over open pages at once.
// Bump CACHE_NAME whenever this file changes in a way that should drop old caches.
const CACHE_NAME = 'angelwrites-v2';
const SHELL = ['/', '/assets/css/style.css', '/assets/js/main.js'];

self.addEventListener('install', event => {
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => cache.addAll(SHELL)).catch(() => {})
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    const req = event.request;
    const url = new URL(req.url);
    // Only same-origin GETs; everything else (POST forms, CDNs, AJAX writes) goes straight through.
    if (req.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }
    const isShell = SHELL.includes(url.pathname) && url.search === '';
    event.respondWith(
        fetch(req)
            .then(response => {
                if (isShell && response.ok) {
                    const copy = response.clone();
                    caches.open(CACHE_NAME).then(cache => cache.put(req, copy));
                }
                return response;
            })
            .catch(() => caches.match(req).then(cached => cached || Response.error()))
    );
});
