/**
 * VELO VRMS — Offline-First Service Worker
 * Pre-caches local vehicle images, stylesheet, and API fallback.
 */
const CACHE_NAME = 'velo-vrms-offline-v1';
const CORE_ASSETS = [
  '/',
  '/vehicles.php',
  '/database-clusters.html',
  '/assets/style.css',
  '/assets/offline-catalog.js',
  '/assets/img/vehicle-placeholder.svg',
  '/assets/vehicles/toyota-vios.jpg',
  '/assets/vehicles/mitsubishi-montero-sport.jpg',
  '/assets/vehicles/ford-ranger.jpg',
  '/assets/vehicles/honda-city.jpg',
  '/assets/vehicles/mitsubishi-xpander.jpg',
  '/assets/vehicles/toyota-fortuner.jpg',
  '/assets/vehicles/nissan-navara.jpg'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(CORE_ASSETS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (event.request.method !== 'GET') return;

  // Network-first for JSON API with offline cache fallback
  if (url.pathname.startsWith('/api/')) {
    event.respondWith(
      fetch(event.request)
        .then((res) => {
          const clone = res.clone();
          caches.open(CACHE_NAME).then((c) => c.put(event.request, clone));
          return res;
        })
        .catch(() => caches.match(event.request))
    );
    return;
  }

  // Cache-first for local vehicle images & static assets
  if (url.pathname.startsWith('/assets/')) {
    event.respondWith(
      caches.match(event.request).then((cached) =>
        cached || fetch(event.request).catch(() => caches.match('/assets/img/vehicle-placeholder.svg'))
      )
    );
  }
});
