importScripts('/assets/js/offline-db.js');

const STATIC_ASSETS = [
  '/',
  '/index.php',
  '/offline.html',
  '/offline-game.html',
  '/assets/css/style.css',
  '/assets/js/app.js',
  '/assets/js/offline-db.js',
  '/bg-music.mp3'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open('v1').then((cache) => {
      // CRITICAL FIX: Only include files that ACTUALLY exist in your folder.
      return cache.addAll(STATIC_ASSETS);
    })
  );
});

const DYNAMIC_CACHE = 'dynamic-v1';

self.addEventListener('fetch', (event) => {
  // 1. Navigation Requests (HTML Pages)
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request)
        .then((networkResponse) => {
          // Check if valid response
          if (!networkResponse || networkResponse.status !== 200 || networkResponse.type !== 'basic') {
            return networkResponse;
          }

          // Clone and Cache Dynamic Pages
          const responseToCache = networkResponse.clone();
          caches.open(DYNAMIC_CACHE).then((cache) => {
            cache.put(event.request, responseToCache);
          });

          return networkResponse;
        })
        .catch(() => {
          // Network Failed. Check Cache (Static OR Dynamic).
          return caches.match(event.request).then((cachedResponse) => {
            if (cachedResponse) {
              return cachedResponse;
            }
            return caches.match('/offline.html');
          });
        })
    );
    return;
  }

  // 2. Dashboard Specific Strategy (Stale-while-revalidate)
  const url = new URL(event.request.url);
  if (url.pathname.includes('views/student/dashboard.php')) {
    event.respondWith(
      caches.open(DYNAMIC_CACHE).then(cache => {
        return cache.match(event.request).then(cachedResponse => {
          const fetchPromise = fetch(event.request).then(networkResponse => {
            cache.put(event.request, networkResponse.clone());
            return networkResponse;
          });
          return cachedResponse || fetchPromise;
        });
      })
    );
    return;
  }

  // 3. Other Requests (Images, CSS, JS)
  event.respondWith(
    caches.match(event.request).then((response) => {
      // Return cached response OR fetch from network
      return response || fetch(event.request).then(networkResponse => {
        // Optional: Cache new static assets dynamically too?
        // For now, let's stick to just returning network for non-static
        return networkResponse;
      });
    })
  );
});

self.addEventListener('sync', (event) => {
  if (event.tag === 'sync-new-reports') {
    event.waitUntil(
      getAllReports().then((reports) => {
        const syncPromises = reports.map((reportWrapper) => {
          const { id, data } = reportWrapper;
          const formData = new FormData();
          for (const key in data) {
            if (Object.prototype.hasOwnProperty.call(data, key)) {
              formData.append(key, data[key]);
            }
          }
          return fetch('views/student/report_new.php', {
            method: 'POST',
            body: formData,
            credentials: 'include' // IMPORTANT: Send cookies/session
          })
            .then((response) => {
              if (response.ok) {
                return deleteReport(id);
              } else {
                return response.text().then(text => {
                  throw new Error(`Server rejected: ${response.status} ${text}`);
                });
              }
            })
            .catch((err) => {
              console.error('Failed to sync report:', id, err);
            });
        });

        return Promise.all(syncPromises).then(() => {
          self.registration.showNotification("Offline Report Sent Successfully!");
        });
      })
    );
  }
});
