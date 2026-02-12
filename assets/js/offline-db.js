const DB_NAME = 'campuscare_db';
const DB_VERSION = 1;
const STORE_NAME = 'offline_reports';

function openDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);
        request.onupgradeneeded = (e) => {
            const db = e.target.result;
            if (!db.objectStoreNames.contains(STORE_NAME)) {
                db.createObjectStore(STORE_NAME, { autoIncrement: true });
            }
        };
        request.onsuccess = (e) => resolve(e.target.result);
        request.onerror = (e) => reject(e.target.errorCode);
    });
}

function saveReportLocally(data) {
    return openDB().then(db => {
        return new Promise((resolve, reject) => {
            const tx = db.transaction([STORE_NAME], 'readwrite');
            // Use unique timestamp+random key to prevent overwrites
            const uniqueKey = Date.now() + Math.random();
            const req = tx.objectStore(STORE_NAME).add(data, uniqueKey);
            req.onsuccess = () => resolve(req.result);
            req.onerror = (e) => reject(e.target.error);
        });
    });
}

function getAllReports() {
    return openDB().then(db => {
        return new Promise((resolve, reject) => {
            const tx = db.transaction([STORE_NAME], 'readonly');
            const store = tx.objectStore(STORE_NAME);
            const request = store.openCursor();
            const reports = [];

            request.onsuccess = (event) => {
                const cursor = event.target.result;
                if (cursor) {
                    reports.push({
                        id: cursor.key,
                        data: cursor.value
                    });
                    cursor.continue();
                } else {
                    resolve(reports);
                }
            };

            request.onerror = (event) => {
                reject(`Error fetching reports: ${event.target.error}`);
            };
        });
    });
}

function deleteReport(id) {
    return openDB().then(db => {
        return new Promise((resolve, reject) => {
            const tx = db.transaction([STORE_NAME], 'readwrite');
            const store = tx.objectStore(STORE_NAME);
            const request = store.delete(id);

            request.onsuccess = () => {
                resolve();
            };

            request.onerror = (event) => {
                reject(`Error deleting report: ${event.target.error}`);
            };
        });
    });
}
