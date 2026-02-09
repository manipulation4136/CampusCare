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
        // Check if valid response logic could be added here, but usually for assets it's less critical unless we cache errors
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
