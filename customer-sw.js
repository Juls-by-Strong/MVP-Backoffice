// MVP Customer - Service Worker
// Minimal SW required for PWA installability.
// Handles basic caching of the app shell only.
// No offline content caching - requires live connection per product spec.

const CACHE_NAME = 'mvp-customer-v1';
const SHELL_ASSETS = [
  '/customer.html',
  '/customer.webmanifest',
];

// Install: cache shell assets
self.addEventListener('install', event => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache =>
      cache.addAll(SHELL_ASSETS).catch(() => {})
    )
  );
});

// Activate: clean up old caches
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

// Fetch: network-first for API calls, cache-first for shell
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);

  // Always network-first for API requests
  if (url.pathname.includes('/api/')) {
    return; // Let browser handle normally
  }

  // Cache-first for shell assets
  event.respondWith(
    caches.match(event.request).then(cached => {
      if (cached) return cached;
      return fetch(event.request).then(response => {
        if (response && response.status === 200 && event.request.method === 'GET') {
          const clone = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
        }
        return response;
      }).catch(() => cached || new Response('Offline', { status: 503 }));
    })
  );
});
