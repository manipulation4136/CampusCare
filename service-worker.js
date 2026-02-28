const CACHE_NAME = 'static-v54'; // Final Version Bump

const DYNAMIC_CACHE = 'dynamic-v45';



// Removed offline-db.js from here because we are embedding it

const STATIC_ASSETS = [

  './',

  './index.php',

  './offline.php',

  './views/student/report_new.php',

  './assets/css/style.css',

  './assets/js/app.js',

  './assets/js/offline-db.js'

];



// 1. INSTALL (Fail-Safe)

self.addEventListener('install', (event) => {

  self.skipWaiting();

  event.waitUntil(

    caches.open(CACHE_NAME).then((cache) => {

      // FAIL-SAFE LOGIC:

      // Try to cache each file individually. If one fails, log it but KEEP GOING.

      return Promise.all(

        STATIC_ASSETS.map(url => {

          return cache.add(url).catch(err => console.warn('Failed to cache:', url));

        })

      );

    })

  );

});



// 2. ACTIVATE

self.addEventListener('activate', (event) => {

  event.waitUntil(

    caches.keys().then(keys => Promise.all(

      keys.map(key => {

        if (![CACHE_NAME, DYNAMIC_CACHE].includes(key)) return caches.delete(key);

      })

    ))

  );

  return self.clients.claim();

});



// 3. FETCH: Smart Strategy

self.addEventListener('fetch', (event) => {

  const url = event.request.url;

  // --- BYPASS LOCALHOST ---
  if (url.includes('localhost') || url.includes('127.0.0.1')) {
    return;
  }



  // --- STRATEGY 1: ADMIN & FACULTY (Network Only -> Offline Page) ---

  if (url.includes('/views/admin/') || url.includes('/views/faculty/')) {

    event.respondWith(

      fetch(event.request)

        .then((response) => {

          // Just return the network response. DO NOT CACHE IT.

          return response;

        })

        .catch(() => {

          // If Offline, show the Radar Page (No more white screen!)

          return caches.match('./offline.php');

        })

    );

    return; // Stop here for admins

  }



  // --- STRATEGY 2: STUDENT (Network First -> Cache -> Offline Page) ---

  // A. Navigation (HTML)

  if (event.request.mode === 'navigate') {

    event.respondWith(

      fetch(event.request)

        .then((response) => {

          // Cache Student Pages Only (Stale-While-Revalidate Logic base)

          if (response.status === 200 && (url.includes('/student/') || url.includes('dashboard'))) {

            const clone = response.clone();

            caches.open(DYNAMIC_CACHE).then(cache => cache.put(event.request, clone));

          }

          return response;

        })

        .catch(() => {

          // Network Failed. Try Cache.

          return caches.match(event.request)

            .then(cachedResponse => {

              if (cachedResponse) {

                return cachedResponse;

              }

              // If NOT in cache, fall back to Offline Landing Page

              // We prefer offline.php if it was cached (unlikely as it's dynamic but we can try), 

              // otherwise offline.html is the static fallback. 

              // Actually, our goal is to show the landing page which links to cached pages.

              return caches.match('./offline.php').then(match => match || caches.match('./offline.php'));

            });

        })

    );

    return;

  }



  // B. Assets (CSS/JS) - Cache First

  event.respondWith(

    caches.match(event.request).then(cached => {

      return cached || fetch(event.request).catch(() => {

        // Return nothing if missing

      });

    })

  );

});



// 4. SYNC (Using the embedded functions)

self.addEventListener('sync', (event) => {
  if (event.tag === 'sync-reports' || event.tag === 'sync-new-reports') {
    event.waitUntil(syncPendingReports());
  }
});

async function syncPendingReports() {
  const reports = await getAllReports();
  if (reports.length === 0) return;

  for (const report of reports) {
    const formData = new FormData();
    for (const key in report.data) {
      formData.append(key, report.data[key]);
    }
    formData.append('ajax', '1'); // Ensure JSON response
    formData.append('is_sync', '1');

    try {
      const response = await fetch('./views/student/report_new.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });

      if (response.ok) {
        const res = await response.json();
        if (res.success) {
          await deleteReport(report.id);
        } else {
          console.error('Server returned error for report:', report.id, res);
        }
      } else {
        console.error('Network response was not ok for report:', report.id);
      }
    } catch (error) {
      console.error('Sync failed for report:', report.id, error);
    }
  }
}



// --- EMBEDDED INDEXED-DB LOGIC (Merged from offline-db.js) ---

const DB_NAME = 'campuscare_db';

const DB_VERSION = 1;

const STORE_NAME = 'offline_reports';



function openDB() {

  return new Promise((resolve, reject) => {

    const request = indexedDB.open(DB_NAME, DB_VERSION);

    request.onupgradeneeded = (e) => {

      const db = e.target.result;

      if (!db.objectStoreNames.contains(STORE_NAME)) db.createObjectStore(STORE_NAME, { autoIncrement: true });

    };

    request.onsuccess = (e) => resolve(e.target.result);

    request.onerror = (e) => reject(e.target.errorCode);

  });

}



function saveReportLocally(data) {

  return openDB().then(db => {

    return new Promise((resolve, reject) => {

      const tx = db.transaction([STORE_NAME], 'readwrite');

      const uniqueKey = Date.now() + Math.random();
      const req = tx.objectStore(STORE_NAME).add(data, uniqueKey);

      req.onsuccess = () => resolve(req.result);

      req.onerror = (e) => reject(e.target.error);

    });

  });

}



function getAllReports() {

  return openDB().then(db => {

    return new Promise((resolve) => {

      const tx = db.transaction([STORE_NAME], 'readonly');

      // Check if store exists before accessing

      if (!db.objectStoreNames.contains(STORE_NAME)) {

        resolve([]);

        return;

      }

      const req = tx.objectStore(STORE_NAME).openCursor();

      const reports = [];

      req.onsuccess = (e) => {

        const cursor = e.target.result;

        if (cursor) { reports.push({ id: cursor.key, data: cursor.value }); cursor.continue(); }

        else resolve(reports);

      };

      req.onerror = () => resolve([]); // Fail gracefully

    });

  });

}



function deleteReport(id) {

  return openDB().then(db => {

    return new Promise((resolve, reject) => {

      const tx = db.transaction([STORE_NAME], 'readwrite');

      const req = tx.objectStore(STORE_NAME).delete(id);

      req.onsuccess = () => resolve();

      req.onerror = (e) => reject(e.target.error);

    });

  });

}
