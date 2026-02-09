const CACHE_NAME = 'static-v30'; // Version Jump
const DYNAMIC_CACHE = 'dynamic-v30';

// Use relative paths
const STATIC_ASSETS = [
  './',
  './index.php',
  './offline.html',
  './assets/css/style.css',  // CRITICAL
  './assets/js/app.js',
  './assets/js/offline-db.js' // If this fails, others must still load
];

self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      // FAIL-SAFE LOGIC:
      // Try to cache each file individually. If one fails, log it but KEEP GOING.
      return Promise.all(
        STATIC_ASSETS.map(url => {
          return cache.add(url).catch(err => {
            console.warn(`[SW] Failed to cache ${url}, but continuing...`, err);
          });
        })
      );
    })
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then(keys => Promise.all(
      keys.map(key => {
        if (![CACHE_NAME, DYNAMIC_CACHE].includes(key)) return caches.delete(key);
      })
    ))
  );
  return self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const url = event.request.url;

  // 1. Navigation (HTML)
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request)
        .then((response) => {
          // Cache Student Pages Only
          // Check if response is valid before caching
          if (response && response.status === 200 && (url.includes('/student/') || url.includes('dashboard'))) {
            const clone = response.clone();
            caches.open(DYNAMIC_CACHE).then(cache => cache.put(event.request, clone));
          }
          return response;
        })
        .catch(() => {
          // Offline Fallback
          // Try to match the request in cache first, then fallback to offline.html
          return caches.match(event.request)
            .then(resp => resp || caches.match('./offline.html'));
        })
    );
    return;
  }

  // 2. Assets (CSS/JS) - Cache First
  event.respondWith(
    caches.match(event.request).then((cached) => {
      return cached || fetch(event.request).then(resp => {
        return resp;
      }).catch(() => {
        // Return nothing if offline and missing - browser handles 404/failed request
        return new Response('', { status: 408, statusText: 'Request timed out' });
      });
    })
  );
});
