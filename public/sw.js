self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch (error) {
        payload = { title: 'Multi-AI-Agent notification', body: 'Open your workspace to review.' };
    }

    event.waitUntil(self.registration.showNotification(payload.title || 'Multi-AI-Agent', {
        body: payload.body || 'Open your workspace to review.',
        data: { url: payload.url || '/' },
        tag: 'multi-ai-agent-notification',
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = event.notification.data?.url || '/';
    event.waitUntil(clients.openWindow(url));
});
