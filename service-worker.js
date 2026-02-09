const STATIC_ASSETS = [
  '/',
  '/index.php',
  '/offline.html',
  '/assets/css/style.css',
  '/assets/js/app.js'
];

const STATIC_CACHE = 'static-v3';
const DYNAMIC_CACHE = 'dynamic-v3';

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE).then((cache) => {
      // Robust Caching: Handle each file individually
      // If one fails (e.g., missing file), others still get cached.
      const cachePromises = STATIC_ASSETS.map((asset) => {
        return cache.add(asset).catch((err) => {
          console.error(`[SW] Failed to cache ${asset}:`, err);
        });
      });
      return Promise.all(cachePromises);
    })
  );
  self.skipWaiting(); // Activate immediately
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((key) => {
          if (key !== STATIC_CACHE && key !== DYNAMIC_CACHE) {
            return caches.delete(key);
          }
        })
      );
    })
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const url = event.request.url;

  // 1. Navigation Requests (HTML Pages) - Network First
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request)
        .then((networkResponse) => {
          // Check if valid response
          if (!networkResponse || networkResponse.status !== 200 || networkResponse.type !== 'basic') {
            return networkResponse;
          }

          // STRICT FILTER: Only cache if it is a Student Page or a Common Asset
          // Exclude Admin and Faculty paths explicitly
          if (
            (url.includes('/views/student/') || url.includes('/assets/') || url.includes('index.php')) &&
            !url.includes('/views/admin/') &&
            !url.includes('/views/faculty/')
          ) {
            const responseToCache = networkResponse.clone();
            caches.open(DYNAMIC_CACHE).then((cache) => {
              cache.put(event.request, responseToCache);
            });
          }

          return networkResponse;
        })
        .catch(() => {
          // Network Failed. Check Cache.
          return caches.match(event.request).then((cachedResponse) => {
            if (cachedResponse) {
              return cachedResponse;
            }
            // Fallback for Admin/Faculty pages (not cached) -> Offline Page
            return caches.match('/offline.html');
          });
        })
    );
    return;
  }

  // 2. Static Assets & Other Requests - Cache First (falling back to Network)
  event.respondWith(
    caches.match(event.request).then((response) => {
      // Return cached response OR fetch from network
      return response || fetch(event.request).then((networkResponse) => {
        // Check if valid response logic could be added here
        if (!networkResponse || networkResponse.status !== 200 || networkResponse.type !== 'basic') {
          return networkResponse;
        }

        // Apply same filter for dynamic assets (if any need caching that aren't pre-cached)
        if (
          (url.includes('/views/student/') || url.includes('/assets/') || url.includes('index.php')) &&
          !url.includes('/views/admin/') &&
          !url.includes('/views/faculty/')
        ) {
          const responseToCache = networkResponse.clone();
          caches.open(DYNAMIC_CACHE).then((cache) => {
            cache.put(event.request, responseToCache);
          });
        }

        return networkResponse;
      });
    })
  );
});
