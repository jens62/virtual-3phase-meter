// Simple service worker for the Virtual Three-Phase Meter PWA
// - Pre-caches the app shell (HTML, CSS, JS, icons)
// - Serves navigation requests (pages) from cache when offline
// - Uses network-first for other requests (live meter data, SVG, etc.)

const CACHE_NAME = 'virtual-3phase-meter-v1';

// List the core resources needed to load the dashboard UI
const APP_SHELL = [
  './',
  './index.php',
  './manifest.json',
  './assets/css/settings.css',
  './assets/js/settings.js',
  './assets/icons/icon-192.png',
  './assets/icons/icon-512.png',
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(APP_SHELL))
  );
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys
          .filter(key => key !== CACHE_NAME)
          .map(key => caches.delete(key))
      )
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', event => {
  const request = event.request;

  // For navigation requests (HTML pages), try cache first then network
  if (request.mode === 'navigate') {
    event.respondWith(
      caches.match('./index.php').then(cached => {
        return (
          cached ||
          fetch(request).catch(() => caches.match('./index.php'))
        );
      })
    );
    return;
  }

  // For everything else, use a network-first strategy with cache fallback
  event.respondWith(
    fetch(request)
      .then(response => {
        const respClone = response.clone();
        caches.open(CACHE_NAME).then(cache => cache.put(request, respClone));
        return response;
      })
      .catch(() => caches.match(request))
  );
});

