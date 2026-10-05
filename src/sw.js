/* TaskPrioritizer install-only service worker.
 * Exists to satisfy PWA installability (Chrome requires a worker with a
 * fetch handler). Intentionally does NOT cache: all task data lives in
 * MySQL via PHP, so caching could serve stale tasks. Network passthrough.
 */
const SW_VERSION = 'tp-v1';

self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      // Clean up any legacy caches from future experiments.
      const keys = await caches.keys();
      await Promise.all(keys.map((key) => caches.delete(key)));
      await self.clients.claim();
    })()
  );
});

// Network passthrough: never intercept, never cache.
self.addEventListener('fetch', () => {
  // Intentionally empty: browser performs the default network fetch.
});
