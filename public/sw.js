const CACHE_NAME = 'virtual-meter-v4';

const APP_SHELL = [
  './',
  './index.html',
  './manifest.json',

  // Stylesheets
  './assets/css/settings.css',
  './assets/css/dashboard.css',

  // Fonts
  './assets/fonts/digital-7 (mono).woff2',

  // Third-party libraries (non-module scripts)
  './assets/js/bwip-js-min.js',
  './assets/js/umd.js',
  './assets/js/loglevel.min.js',
  './assets/js/mqtt.min.js',

  // App modules
  './assets/js/app.js',
  './assets/js/config-state.js',
  './assets/js/storage.js',
  './assets/js/tasmota-api.js',
  './assets/js/ui-setup.js',
  './assets/js/ui-dashboard.js',
  './assets/js/dashboard-renderer.js',
  './assets/js/logger.js',

  // Icons
  './assets/icons/icon-192.png',
  './assets/icons/icon-512.png',
  './assets/icons/icons8-apple-settings.svg',

  // Meter SVG templates
  './assets/meter-templates/eBZ_DD3 2R06 DTA - SMZ1.svg',
];

// Pre-cache all app shell files on install
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL))
  );
  self.skipWaiting();
});

// Remove old caches on activate
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)))
    )
  );
  self.clients.claim();
});

// Cache-first: serve from cache, fall back to network
self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;

  event.respondWith(
    caches.match(event.request).then((cached) => {
      if (cached) return cached;
      return fetch(event.request).catch(() => {
        // For page navigations, serve the app shell so the app still opens
        if (event.request.mode === 'navigate') {
          return caches.match('./index.html');
        }
      });
    })
  );
});
