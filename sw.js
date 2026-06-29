// MVP Backoffice - Service Worker v2
// Installability only. No offline caching.
// API requests always go straight to network, never cached.
const VERSION = 'v2';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(
  caches.keys()
    .then(keys => Promise.all(keys.map(k => caches.delete(k))))
    .then(() => self.clients.claim())
));

self.addEventListener('fetch', e => {
  // Always bypass service worker for API calls and PDF responses
  const url = e.request.url;
  if (
    url.includes('/api/') ||
    url.includes('report-pdf') ||
    url.includes('.pdf')
  ) {
    e.respondWith(fetch(e.request));
    return;
  }
  // Everything else - straight to network, no caching
  e.respondWith(fetch(e.request));
});
