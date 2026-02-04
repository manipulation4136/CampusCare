self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open('v1').then((cache) => {
      
      // 1. അത്യാവശ്യമായ ഫയലുകൾ (Critical Files)
      // ഇവ നിർബന്ധമായും ലോഡ് ആകണം, ഇല്ലെങ്കിൽ ആപ്പ് വർക്ക് ചെയ്യില്ല.
      const criticalFiles = [
        '/',
        '/index.php',
        '/style.css',
        '/offline.html',
        '/icon-192.png',
        '/icons/icon-512.png'
      ];

      // 2. Critical ഫയലുകൾ ക്യാഷ് ചെയ്യുന്നു
      const cacheCritical = cache.addAll(criticalFiles);

      // 3. മ്യൂസിക് ഫയൽ മാത്രം പ്രത്യേകം ക്യാഷ് ചെയ്യുന്നു
      // .catch() ഉപയോഗിക്കുന്നത് കൊണ്ട്, ഇത് പരാജയപ്പെട്ടാലും കുഴപ്പമില്ല.
      const cacheMusic = cache.add('/bg-music.MP3').catch((err) => {
        console.warn('Music file failed to load, but ignoring error:', err);
      });

      // 4. രണ്ട് പ്രോസസ്സുകളും പൂർത്തിയാകാൻ കാത്തിരിക്കുന്നു
      return Promise.all([cacheCritical, cacheMusic]);
    })
  );
});
