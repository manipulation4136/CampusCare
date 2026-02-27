const DB_NAME = 'campuscare_db';
const DB_VERSION = 1;
const STORE_NAME = 'offline_reports';

/**
 * Opens the IndexedDB database.
 * @returns {Promise<IDBDatabase>}
 */
function openDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = (event) => {
            const db = event.target.result;
            if (!db.objectStoreNames.contains(STORE_NAME)) {
                db.createObjectStore(STORE_NAME, { autoIncrement: true });
            }
        };

        request.onsuccess = (event) => {
            resolve(event.target.result);
        };

        request.onerror = (event) => {
            reject(`IndexedDB error: ${event.target.errorCode}`);
        };
    });
}

/**
 * Save report data locally.
 * @param {Object} data - The form data to save.
 * @returns {Promise<any>}
 */
function saveReportLocally(data) {
    return openDB().then((db) => {
        return new Promise((resolve, reject) => {
            const transaction = db.transaction([STORE_NAME], 'readwrite');
            const store = transaction.objectStore(STORE_NAME);
            const uniqueKey = Date.now() + Math.random();
            const request = store.add(data, uniqueKey);

            request.onsuccess = () => {
                resolve(request.result); // Returns the new key
            };

            request.onerror = (event) => {
                reject(`Error saving report: ${event.target.error}`);
            };
        });
    });
}

/**
 * Get all locally saved reports.
 * @returns {Promise<Array>}
 */
function getAllReports() {
    return openDB().then((db) => {
        return new Promise((resolve, reject) => {
            const transaction = db.transaction([STORE_NAME], 'readonly');
            const store = transaction.objectStore(STORE_NAME);
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

/**
 * Delete a report by its ID (key).
 * @param {number} id - The key of the report to delete.
 * @returns {Promise<void>}
 */
function deleteReport(id) {
    return openDB().then((db) => {
        return new Promise((resolve, reject) => {
            const transaction = db.transaction([STORE_NAME], 'readwrite');
            const store = transaction.objectStore(STORE_NAME);
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
