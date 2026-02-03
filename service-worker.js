const CACHE_NAME = 'campuscare-offline-v3';
const ASSETS_TO_CACHE = [
  '/',
  '/index.php',
  '/assets/css/style.css',
  '/offline.html',
  '/img/logo.png',
  '/img/icon.png'
];

self.addEventListener('install', (event) => {
  self.skipWaiting(); // Force this new service worker to become the active one
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      console.log('Opened cache');
      return cache.addAll(ASSETS_TO_CACHE);
    }).catch(err => {
      console.error('Failed to cache assets:', err);
    })
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    Promise.all([
      self.clients.claim(), // Take control of all open clients immediately
      caches.keys().then((cacheNames) => {
        return Promise.all(
          cacheNames.map((cacheName) => {
            if (cacheName !== CACHE_NAME) {
              console.log('Deleting old cache:', cacheName);
              return caches.delete(cacheName);
            }
          })
        );
      })
    ])
  );
});

self.addEventListener('fetch', (event) => {
  // Handle navigation requests (HTML pages)
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request).catch(() => {
        // If network fails, return the offline page
        return caches.match('/offline.html');
      })
    );
    return;
  }

  // Handle other requests (Cache First, fallback to Network)
  event.respondWith(
    caches.match(event.request).then((response) => {
      return response || fetch(event.request);
    })
  );
});
