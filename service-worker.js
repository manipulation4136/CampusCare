importScripts('/assets/js/offline-db.js');

const STATIC_ASSETS = [
  '/',
  '/index.php',
  '/offline.html',
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
            body: formData
          })
            .then((response) => {
              if (response.ok) {
                return deleteReport(id);
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
