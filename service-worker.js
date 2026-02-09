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

self.addEventListener('fetch', (event) => {
  // 1. Navigation Requests (HTML Pages)
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request).catch(() => {
        // Network Failed. Check Cache.
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

  // 1.5 Special Case: Dashboard & Student Pages (Stale-while-revalidate)
  // We want to show these pages immediately for speed/offline,
  // but update them in the background if network is available.
  const url = new URL(event.request.url);
  const isStudentPage = url.pathname.includes('views/student/dashboard.php') ||
    url.pathname.includes('views/student/history.php') ||
    url.pathname.includes('views/student/report_new.php');

  if (isStudentPage) {
    event.respondWith(
      caches.open('v1').then(cache => {
        return cache.match(event.request).then(cachedResponse => {
          const fetchPromise = fetch(event.request).then(networkResponse => {
            cache.put(event.request, networkResponse.clone());
            return networkResponse;
          });
          // Return cached response if available, otherwise wait for network
          return cachedResponse || fetchPromise;
        });
      })
    );
    return;
  }

  // 2. Other Requests (Images, CSS, JS)
  event.respondWith(
    caches.match(event.request).then((response) => {
      return response || fetch(event.request);
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
