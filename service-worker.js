importScripts('./assets/js/offline-db.js');

const CACHE_NAME = 'campuscare-v3'; // Increment to force update
const STATIC_ASSETS = [
  './',
  './index.php',
  './offline.html',
  './offline-game.html',
  './manifest.json',
  './assets/css/style.css',
  './assets/js/app.js',
  './assets/js/offline-db.js',
  './bg-music.mp3'
];

// --- 1. INSTALL: Cache Static Assets with Logging ---
self.addEventListener('install', (event) => {
  console.log('[SW] Installing Service Worker...', new Date().toISOString());
  self.skipWaiting(); // Force replacement of old SW

  event.waitUntil(
    caches.open(CACHE_NAME).then(async (cache) => {
      console.log(`[SW] Opened cache: ${CACHE_NAME}`);

      // Cache files individually to identify failures
      for (const asset of STATIC_ASSETS) {
        try {
          const response = await fetch(asset);
          if (!response.ok) {
            throw new Error(`Failed to fetch ${asset} - Status: ${response.status}`);
          }
          await cache.put(asset, response);
          console.log(`[SW] Caching success: ${asset}`);
        } catch (error) {
          console.error(`[SW] Caching FAILED: ${asset}`, error);
          // We generally want to ensure critical assets are cached. 
          // If offline.html fails, offline mode is broken.
        }
      }
    })
  );
});

// --- 2. ACTIVATE: Clean Old Caches ---
self.addEventListener('activate', (event) => {
  console.log('[SW] Activating...');
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cache) => {
          if (cache !== CACHE_NAME) {
            console.log('[SW] Deleting old cache:', cache);
            return caches.delete(cache);
          }
        })
      );
    }).then(() => {
      console.log('[SW] Claiming clients immediately');
      return self.clients.claim();
    })
  );
});

// --- 3. FETCH: Strategies ---
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // STRATEGY A: HTML / Navigation -> Network First, Fallback to Cache
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request)
        .then((networkResponse) => {
          // Verify valid response
          if (!networkResponse || networkResponse.status !== 200 || networkResponse.type !== 'basic') {
            // If network returns 404/500, we might want to return it OR fallback to cache.
            // Usually we return the network error so user knows.
            return networkResponse;
          }

          // Clone & Update Cache (Dynamic Caching for visited pages)
          const responseToCache = networkResponse.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(event.request, responseToCache);
          });

          return networkResponse;
        })
        .catch((err) => {
          console.log(`[SW] Network failed for ${url.pathname}. Checking cache...`);
          // Network failed (Offline). Try Cache.
          return caches.match(event.request).then((cachedResponse) => {
            if (cachedResponse) {
              return cachedResponse;
            }
            // Cache failed too. Show Offline Page.
            console.log('[SW] Serving offline.html');
            return caches.match('./offline.html');
          });
        })
    );
    return;
  }

  // STRATEGY B: Static Assets (CSS, JS, Images) -> Stale-While-Revalidate
  // Improved regex to catch common assets
  const isStatic = /\.(css|js|png|jpg|jpeg|svg|json|mp3|woff|woff2)$/i.test(url.pathname);

  if (isStatic) {
    event.respondWith(
      caches.open(CACHE_NAME).then((cache) => {
        return cache.match(event.request).then((cachedResponse) => {

          // Network Fetch (Background)
          const fetchPromise = fetch(event.request).then((networkResponse) => {
            // Only cache valid responses
            if (networkResponse && networkResponse.status === 200 && networkResponse.type === 'basic') {
              cache.put(event.request, networkResponse.clone());
            }
            return networkResponse;
          }).catch(err => {
            // Network failed - just ignore for static assets, we hope we have cache
            // console.log('[SW] Background update failed', err);
          });

          // Return Cached First, else wait for Network
          return cachedResponse || fetchPromise;
        });
      })
    );
    return;
  }

  // STRATEGY C: Default (API, etc) -> Network Only (or Browser Default)
  // Leave as is.
});

// --- 4. BACKGROUND SYNC (Keep existing logic) ---
self.addEventListener('sync', (event) => {
  if (event.tag === 'sync-new-reports') {
    console.log('[SW] Syncing new reports...');
    event.waitUntil(
      getAllReports().then((reports) => { // Defined in offline-db.js
        const syncPromises = reports.map((reportWrapper) => {
          const { id, data } = reportWrapper;
          const formData = new FormData();
          for (const key in data) {
            if (Object.prototype.hasOwnProperty.call(data, key)) {
              formData.append(key, data[key]);
            }
          }
          // Use relative path for robustness
          return fetch('./views/student/report_new.php', {
            method: 'POST',
            body: formData,
            // 'include' credentials is vital for session auth
            credentials: 'include'
          })
            .then((response) => {
              if (response.ok) {
                return deleteReport(id); // Defined in offline-db.js
              } else {
                return response.text().then(text => {
                  throw new Error(`Server Sync Rejected: ${response.status}`);
                });
              }
            })
            .catch((err) => {
              console.error('[SW] Sync Failed for report', id, err);
            });
        });

        return Promise.all(syncPromises).then(() => {
          if (self.registration.showNotification) {
            self.registration.showNotification("Offline Reports Synced Successfully!");
          }
        });
      })
    );
  }
});
