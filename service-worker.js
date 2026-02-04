// ശ്രദ്ധിക്കുക: 'Self' അല്ല, 'self' (small letter) ആണ് ഉപയോഗിക്കേണ്ടത്.
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open('v1').then((cache) => {
      // ഈ ലിസ്റ്റിലുള്ള എല്ലാ ഫയലുകളും സെർവറിൽ ഉണ്ടെന്ന് ഉറപ്പുവരുത്തുക.
      // ഒരെണ്ണം ഇല്ലെങ്കിൽ പോലും (Error 404), സർവീസ് വർക്കർ ഇൻസ്റ്റാൾ ആകില്ല.
      return cache.addAll([
        '/',
        '/index.php',
        '/style.css',
        '/offline.html',
        '/icon-192.png',
        '/icons/icon-512.png'
      ]);
    })
  );
});

self.addEventListener('fetch', (event) => {
  // HTML പേജുകൾക്കുള്ള റിക്വസ്റ്റ്
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request).catch(() => {
        // ഇന്റർനെറ്റ് ഇല്ലെങ്കിൽ മാത്രം offline.html കാണിക്കുക
        return caches.match('/offline.html');
      })
    );
    return;
  }

  // ബാക്കിയുള്ള ഫയലുകൾ (ആദ്യം Cache-ൽ നോക്കും, ഇല്ലെങ്കിൽ മാത്രം Network-ൽ)
  event.respondWith(
    caches.match(event.request).then((response) => {
      return response || fetch(event.request);
    })
  );
});
