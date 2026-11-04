import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.createTaskEcho = (token) => {
    const key = import.meta.env.VITE_REVERB_APP_KEY;
    if (!key || !token) {
        return null;
    }

    const host = import.meta.env.VITE_REVERB_HOST || window.location.hostname;
    const scheme = import.meta.env.VITE_REVERB_SCHEME || window.location.protocol.replace(':', '');
    const port = Number(import.meta.env.VITE_REVERB_PORT || (scheme === 'https' ? 443 : 80));

    return new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: host,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/api/v1/broadcasting/auth',
        auth: {
            headers: {
                Authorization: `Bearer ${token}`,
            },
        },
    });
};
