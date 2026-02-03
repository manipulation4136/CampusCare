const CACHE_NAME = 'campuscare-offline-v4'; // Version 4 (To force update)
// Use relative path './' to ensure it works even inside subfolders like localhost/myproject/
const OFFLINE_URL = './offline.html';

self.addEventListener('install', (event) => {
  self.skipWaiting(); // Force activation immediately
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      console.log('Service Worker: Caching Offline Page');
      // We ONLY cache the offline.html strictly.
      // Even if style.css or logo.png is missing, this MUST succeed.
      return cache.add(OFFLINE_URL);
    }).catch(err => {
      console.error('CRITICAL: Failed to cache offline page. Check file path!', err);
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
              console.log('Service Worker: Clearing Old Cache');
              return caches.delete(cacheName);
            }
          })
        );
      })
    ])
  );
});

self.addEventListener('fetch', (event) => {
  // Only handle HTML navigation requests (pages)
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request)
        .catch(() => {
          // IF NETWORK FAILS -> SHOW OFFLINE PAGE
          return caches.match(OFFLINE_URL);
        })
    );
  }
  // For images/css/js, we just try network. No caching needed for now.
});
