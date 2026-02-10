const CACHE_NAME = 'static-v46';
const DYNAMIC_CACHE = 'dynamic-v46';

// Removed offline-db.js from here because we are embedding it
const STATIC_ASSETS = [
  './',
  './index.php',
  './offline.html',
  './assets/css/style.css',
  './assets/js/app.js'
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

  // --- STRATEGY 1: ADMIN & FACULTY (Network Only -> Offline Page Fallback) ---
  if (url.includes('/views/admin/') || url.includes('/views/faculty/')) {
    event.respondWith(
      fetch(event.request)
        .then((response) => {
          // Return network response directly. DO NOT CACHE for security.
          return response;
        })
        .catch(() => {
          // Network failed? Show Offline Page instead of White Screen.
          return caches.match('./offline.html');
        })
    );
    return;
  }

  // --- STRATEGY 2: STUDENT (Network First -> Cache -> Offline Page) ---
  // A. Navigation (HTML)
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request)
        .then((response) => {
          // Only cache Student pages
          if (response.status === 200 && (url.includes('/student/') || url.includes('dashboard'))) {
            const clone = response.clone();
            caches.open(DYNAMIC_CACHE).then(cache => cache.put(event.request, clone));
          }
          return response;
        })
        .catch(() => {
          return caches.match(event.request)
            .then(resp => resp || caches.match('./offline.html'));
        })
    );
    return;
  }

  // B. Assets (CSS/JS) - Cache First
  event.respondWith(
    caches.match(event.request).then(cached => {
      return cached || fetch(event.request).catch(() => { });
    })
  );
});

// 4. SYNC (Using the embedded functions)
self.addEventListener('sync', (event) => {
  if (event.tag === 'sync-new-reports') {
    event.waitUntil(
      getAllReports().then((reports) => { // Now this function is guaranteed to exist
        const syncPromises = reports.map((report) => {
          const formData = new FormData();
          for (const key in report.data) {
            formData.append(key, report.data[key]);
          }

          return fetch('./views/student/report_new.php', {
            method: 'POST',
            body: formData,
            credentials: 'include'
          }).then(res => {
            if (res.ok) return deleteReport(report.id);
          });
        });
        return Promise.all(syncPromises);
      })
    );
  }
});

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
      const req = tx.objectStore(STORE_NAME).add(data);
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
