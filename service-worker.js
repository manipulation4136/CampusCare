importScripts('./assets/js/offline-db.js');

const CACHE_NAME = 'campuscare-v4'; // Increment version
const OFFLINE_URL = './offline.html';

const STATIC_ASSETS = [
  './',
  './index.php',
  OFFLINE_URL, // Critical
  './offline-game.html',
  './manifest.json',
  './assets/css/style.css',
  './assets/js/app.js',
  './assets/js/offline-db.js',
  './bg-music.mp3'
];

// --- 1. INSTALL: Cache Static Assets (Strict) ---
self.addEventListener('install', (event) => {
  console.log('[SW] Installing Service Worker...', new Date().toISOString());
  self.skipWaiting();

  event.waitUntil(
    caches.open(CACHE_NAME).then(async (cache) => {
      console.log(`[SW] Opened cache: ${CACHE_NAME}`);

      // 1. Force Cache 'offline.html' FIRST. If this fails, abort install.
      try {
        await cache.add(OFFLINE_URL);
        console.log(`[SW] CRITICAL: ${OFFLINE_URL} cached successfully.`);
      } catch (err) {
        console.error(`[SW] CRITICAL FAILURE: Could not cache ${OFFLINE_URL}. Aborting.`, err);
        throw err; // This stops the SW from installing.
      }

      // 2. Cache other assets (Best Effort)
      const otherAssets = STATIC_ASSETS.filter(url => url !== OFFLINE_URL);
      for (const asset of otherAssets) {
        try {
          const response = await fetch(asset);
          if (response.ok) {
            await cache.put(asset, response);
            console.log(`[SW] Cached: ${asset}`);
          } else {
            console.warn(`[SW] Failed to fetch ${asset}: ${response.status}`);
          }
        } catch (error) {
          console.warn(`[SW] Failed to cache ${asset}`, error);
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
      console.log('[SW] Claiming clients');
      return self.clients.claim();
    })
  );
});

// --- 3. FETCH: Robust Strategies ---
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // STRATEGY A: HTML / Navigation -> Network First, Fallback to Cache, Fallback to Offline Page
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request)
        .then((networkResponse) => {
          // Check if valid reference
          if (!networkResponse || networkResponse.status !== 200 || networkResponse.type !== 'basic') {
            return networkResponse;
          }
          // Clone & Update Cache
          const responseToCache = networkResponse.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(event.request, responseToCache);
          });
          return networkResponse;
        })
        .catch((err) => {
          console.log(`[SW] Network failed for ${url.pathname}. Checking cache...`);

          return caches.match(event.request).then((cachedResponse) => {
            if (cachedResponse) {
              return cachedResponse;
            }
            // CRITICAL FALLBACK
            console.log('[SW] Page not in cache. Serving offline.html...');
            return caches.match(OFFLINE_URL).then(offlineResp => {
              if (offlineResp) return offlineResp;

              // Last Resort (Should not happen if install succeeded)
              console.error('[SW] OUCH! offline.html is missing from cache!');
              return new Response("You are offline and the offline page is missing.", {
                status: 503,
                headers: { 'Content-Type': 'text/plain' }
              });
            });
          });
        })
    );
    return;
  }

  // STRATEGY B: Static Assets -> Stale-While-Revalidate
  const isStatic = /\.(css|js|png|jpg|jpeg|svg|json|mp3|woff|woff2)$/i.test(url.pathname);
  if (isStatic) {
    event.respondWith(
      caches.open(CACHE_NAME).then((cache) => {
        return cache.match(event.request).then((cachedResponse) => {
          const fetchPromise = fetch(event.request).then((networkResponse) => {
            if (networkResponse && networkResponse.status === 200) {
              cache.put(event.request, networkResponse.clone());
            }
            return networkResponse;
          }).catch(() => { /* mute errors for bg updates */ });

          return cachedResponse || fetchPromise;
        });
      })
    );
    return;
  }
});

// --- 4. BACKGROUND SYNC ---
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
          return fetch('./views/student/report_new.php', {
            method: 'POST',
            body: formData,
            credentials: 'include'
          })
            .then((response) => {
              if (response.ok) {
                return deleteReport(id);
              }
            });
        });
        return Promise.all(syncPromises);
      })
    );
  }
});
