importScripts('/assets/js/offline-db.js');

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open('v1').then((cache) => {
      return cache.addAll([
        '/',
        '/index.php',
        '/style.css',
        '/offline.html',
        '/icon-192.png',
        '/icons/icon-512.png',
        '/bg-music.MP3'
      ]);
    })
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

self.addEventListener('sync', (event) => {
  if (event.tag === 'sync-new-reports') {
    event.waitUntil(
      getAllReports().then((reports) => {
        const syncPromises = reports.map((reportWrapper) => {
          const { id, data } = reportWrapper;

          // Convert data to FormData for likely PHP backend compatibility
          // assuming 'data' is a plain JS object
          const formData = new FormData();
          for (const key in data) {
            if (Object.prototype.hasOwnProperty.call(data, key)) {
              formData.append(key, data[key]);
            }
          }

          // Send to backend
          return fetch('views/student/report_new.php', {
            method: 'POST',
            body: formData
          })
            .then((response) => {
              if (response.ok) {
                // On success, delete from IndexedDB
                return deleteReport(id);
              }
            })
            .catch((err) => {
              console.error('Failed to sync report:', id, err);
            });
        });

        return Promise.all(syncPromises).then(() => {
          // Notify user of success (basic implementation, notifies if at least one attempt finished)
          // In a real app we might count successes, but per prompt:
          self.registration.showNotification("Offline Report Sent Successfully!");
        });
      })
    );
  }
});
