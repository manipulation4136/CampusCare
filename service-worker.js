importScripts('/assets/js/offline-db.js');

const CACHE_NAME = 'v2'; // പതിപ്പ് മാറ്റി, പഴയ കാഷെ കളയാൻ.
const STATIC_ASSETS = [
  '/',
  '/index.php',
  '/offline.html',
  '/assets/css/style.css',
  '/assets/js/app.js',
  '/assets/js/offline-db.js',
  '/bg-music.MP3' // ഫയൽ നെയിം കൃത്യമാണെന്ന് ഉറപ്പുവരുത്തുക
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      // ഇവിടെ ഡാഷ്‌ബോർഡ് ഇടരുത്. അസറ്റ്സ് മാത്രം മതി.
      return cache.addAll(STATIC_ASSETS);
    })
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  // പഴയ കാഷെ ക്ലിയർ ചെയ്യുന്നു (പ്രധാനമാണ്)
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((key) => {
          if (key !== CACHE_NAME) return caches.delete(key);
        })
      );
    })
  );
  self.clients.claim();
});

// The Magic: Network First, then Cache (ഇതാണ് പ്രധാനം)
self.addEventListener('fetch', (event) => {
  // 1. Navigation Requests (HTML Pages like dashboard, report)
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request)
        .then((networkResponse) => {
          // നെറ്റ് ഉണ്ടെങ്കിൽ: പേജ് ലോഡ് ചെയ്യുക + പുതിയ കോപ്പി Cache-ൽ സേവ് ചെയ്യുക
          return caches.open(CACHE_NAME).then((cache) => {
            cache.put(event.request, networkResponse.clone());
            return networkResponse;
          });
        })
        .catch(() => {
          // നെറ്റ് ഇല്ലെങ്കിൽ: Cache-ൽ നോക്കുക
          return caches.match(event.request).then((cachedResponse) => {
            if (cachedResponse) {
              return cachedResponse; // നേരത്തെ സേവ് ചെയ്ത ഡാഷ്‌ബോർഡ് കാണിക്കും
            }
            // അതും ഇല്ലെങ്കിൽ മാത്രം offline page
            return caches.match('/offline.html');
          });
        })
    );
    return;
  }

  // 2. Other Requests (Images, CSS, JS) - Cache First
  event.respondWith(
    caches.match(event.request).then((response) => {
      return response || fetch(event.request).then((networkResponse) => {
             return caches.open(CACHE_NAME).then((cache) => {
                 cache.put(event.request, networkResponse.clone());
                 return networkResponse;
             });
        });
    })
  );
});

// Sync Event (No Changes)
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
