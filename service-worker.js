importScripts('/assets/js/offline-db.js'); // പാത്ത് കൃത്യമാണെന്ന് ഉറപ്പുവരുത്തുക

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open('v1').then((cache) => {
      // പ്രധാനം: ഇതിൽ ലിസ്റ്റ് ചെയ്ത എല്ലാ ഫയലുകളും ഫോൾഡറിൽ ഉണ്ടെന്ന് ഉറപ്പുവരുത്തുക.
      // ഒരെണ്ണം ഇല്ലെങ്കിൽ പോലും Service Worker വർക്ക് ചെയ്യില്ല.
      return cache.addAll([
        '/',
        '/index.php',
        '/offline.html',
        '/assets/css/style.css', 
        '/assets/js/app.js',
        '/assets/js/offline-db.js',
        '/bg-music.MP3'
      ]);
    })
  );
});

self.addEventListener('fetch', (event) => {
  // Navigation Requests (HTML Pages)
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request).catch(() => {
        // നെറ്റ് കിട്ടിയില്ലെങ്കിൽ മാത്രം ഇവിടെ വരും.
        
        // 1. ആദ്യം നോക്കുക, നമ്മൾ പോകാൻ ശ്രമിച്ച പേജ് (ഉദാ: Dashboard or Game) കാഷെയിൽ ഉണ്ടോ എന്ന്.
        return caches.match(event.request).then((cachedResponse) => {
          if (cachedResponse) {
            return cachedResponse; // ഉണ്ടെങ്കിൽ അത് കാണിക്കുക (ഗെയിം ലോഡ് ആകും).
          }
          // 2. ഇല്ലെങ്കിൽ മാത്രം offline.html കാണിക്കുക.
          return caches.match('/offline.html');
        });
      })
    );
    return;
  }

  // Other Requests (Images, CSS, JS) - Cache First Strategy
  event.respondWith(
    caches.match(event.request).then((response) => {
      return response || fetch(event.request);
    })
  );
});

// Sync Event (ഇതിൽ മാറ്റമില്ല)
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
