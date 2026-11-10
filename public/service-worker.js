const CACHE_NAME = 'multi-ai-agent-static-v1';
const STATIC_PREFIX = '/build/assets/';

self.addEventListener('install', (event) => {
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const cacheNames = await caches.keys();
        await Promise.all(cacheNames
            .filter((name) => name.startsWith('multi-ai-agent-static-') && name !== CACHE_NAME)
            .map((name) => caches.delete(name)));
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const requestUrl = new URL(event.request.url);
    if (event.request.method !== 'GET'
        || requestUrl.origin !== self.location.origin
        || !requestUrl.pathname.startsWith(STATIC_PREFIX)) {
        return;
    }

    event.respondWith((async () => {
        const cache = await caches.open(CACHE_NAME);
        const cached = await cache.match(event.request);
        if (cached) {
            return cached;
        }

        const response = await fetch(event.request);
        if (response.ok && response.type === 'basic') {
            await cache.put(event.request, response.clone());
        }
        return response;
    })());
});

self.addEventListener('push', (event) => {
    event.waitUntil(self.registration.showNotification('Multi-AI-Agent', {
        body: 'আপনার workspace-এ একটি আপডেট আছে। বিস্তারিত দেখতে app খুলুন।',
        icon: '/icons/app-icon.svg',
        badge: '/icons/app-icon.svg',
        data: { path: '/' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const windowClient of windows) {
            if (new URL(windowClient.url).origin === self.location.origin) {
                await windowClient.focus();
                return;
            }
        }
        await self.clients.openWindow('/');
    })());
});
