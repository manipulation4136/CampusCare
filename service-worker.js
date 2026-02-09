const CACHE_NAME = 'static-v10'; // New version
const DYNAMIC_CACHE = 'dynamic-v10';
const ASSETS_TO_CACHE = [
  '/',
  '/index.php',
  '/offline.html',
  '/assets/css/style.css', // Critical
  '/assets/js/app.js'
];

self.addEventListener('install', (event) => {
  self.skipWaiting(); // Force immediate activation
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      // Cache files one by one. If one fails, ignore it and continue.
      return Promise.all(
        ASSETS_TO_CACHE.map(url => {
          return cache.add(url).catch(err => console.log('Failed to cache:', url));
        })
      );
    })
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.map((key) => {
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
          if (response.status === 200 && (url.includes('/student/') || url.includes('dashboard'))) {
            const clone = response.clone();
            caches.open(DYNAMIC_CACHE).then(cache => cache.put(event.request, clone));
          }
          return response;
        })
        .catch(() => {
          // Offline Fallback
          return caches.match(event.request)
            .then(resp => resp || caches.match('/offline.html'));
        })
    );
    return;
  }

  // 2. Assets (CSS/JS) - Cache First Strategy
  event.respondWith(
    caches.match(event.request).then((cached) => {
      return cached || fetch(event.request).then(resp => {
        return resp; // Optional: Dynamic cache logic here
      });
    })
  );
});
