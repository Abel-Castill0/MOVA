/**
 * Echo expone una API para suscribirse a canales y escuchar eventos
 * broadcast de Laravel. Cargado lazy (ver AppLayout.vue) para que las
 * páginas de invitado nunca descarguen el chunk de Pusher/Echo.
 *
 * AZ-3G: la config viene de props de Inertia (HandleInertiaRequests::share(),
 * clave `realtime`), NUNCA de import.meta.env.VITE_PUSHER_* -- el build de
 * Docker de producción no inyecta esas variables porque son server-runtime,
 * no build-time. Sin esto, `new Echo()` recibía `key: undefined` en
 * producción y el usuario autenticado veía un error de consola en cada
 * carga de AppLayout.
 */

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * @param {{enabled?: boolean, key?: string|null, cluster?: string|null, host?: string|null, port?: number|null, scheme?: string|null}} [config]
 * @returns {import('laravel-echo').default|null} null si realtime está deshabilitado o falta la key pública -- nunca lanza.
 */
export function initEcho(config) {
    if (window.Echo) return window.Echo;

    if (!config || !config.enabled || !config.key) {
        return null;
    }

    window.Pusher = Pusher;

    window.Echo = new Echo({
        broadcaster: 'pusher',
        key: config.key,
        cluster: config.cluster ?? 'mt1',
        wsHost: config.host ? config.host : `ws-${config.cluster}.pusher.com`,
        wsPort: config.port ?? 80,
        wssPort: config.port ?? 443,
        forceTLS: (config.scheme ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    return window.Echo;
}
