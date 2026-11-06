// F204: network-first service worker.
// The previous worker answered every request from a cache named 'angelwrites-v1' that was
// never refreshed, so returning visitors kept the homepage, style.css and main.js they first
// saw, whatever was deployed later. This worker:
//  - always asks the network first and only falls back to the cache when offline;
//  - refreshes the cached copy of the site shell whenever the network answers;
//  - deletes every older cache when it activates, and takes over open pages at once.
// Bump CACHE_NAME whenever this file changes in a way that should drop old caches.
const CACHE_NAME = 'angelwrites-v2';
// R10: books a member has opened stay readable offline. The reader page carries the whole book,
// so the last BOOKS_MAX reader pages are kept (network first, as above) and dropped on logout.
const BOOKS_CACHE = 'angelwrites-books-v1';
const BOOKS_MAX = 10;
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
            .then(keys => Promise.all(keys.filter(k => k !== CACHE_NAME && k !== BOOKS_CACHE).map(k => caches.delete(k))))
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
    if (url.pathname === '/logout.php') {
        caches.delete(BOOKS_CACHE); // the next person on this device must not read this member's books
        return;
    }
    // Only a plain /reader/reader.php?id=N (no admin preview) is kept for offline reading.
    const isBook = url.pathname === '/reader/reader.php' && /^\?id=\d+$/.test(url.search);
    if (isBook) {
        event.respondWith(
            fetch(req)
                .then(response => {
                    if (response.ok && !response.redirected) {
                        const copy = response.clone();
                        caches.open(BOOKS_CACHE).then(cache => cache.delete(req).then(() => cache.put(req, copy)).then(() => cache.keys())
                            .then(keys => Promise.all(keys.slice(0, Math.max(0, keys.length - BOOKS_MAX)).map(k => cache.delete(k)))));
                    }
                    return response;
                })
                .catch(() => caches.open(BOOKS_CACHE).then(cache => cache.match(req)).then(cached => cached || Response.error()))
        );
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
