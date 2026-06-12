// ============================================================
//  SW.JS – Service Worker for offline reading
// ============================================================
const CACHE_NAME = 'angelwrites-reader-v1';
const READER_URLS = [
    '/reader/reader.php',
    '/assets/css/style.css',
    '/assets/js/reader.js',
    // Add fonts, logos, etc.
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(READER_URLS);
        })
    );
});

self.addEventListener('fetch', (event) => {
    event.respondWith(
        caches.match(event.request).then((response) => {
            // If found in cache, return it; otherwise fetch from network.
            return response || fetch(event.request);
        })
    );
});

// Update service worker when new version is available
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cacheName) => {
                    if (cacheName !== CACHE_NAME) {
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
});