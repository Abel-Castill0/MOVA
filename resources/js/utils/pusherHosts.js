/**
 * El host HTTP REST de Pusher NO es el host WebSocket.
 * Laravel configura por defecto api-<cluster>.pusher.com para publicar,
 * mientras el navegador debe conectar a ws-<cluster>.pusher.com.
 * Un PUSHER_HOST personalizado sí se conserva (Reverb/proxy privado).
 */
export function resolvePusherWsHost(config = {}) {
    const cluster = (typeof config.cluster === 'string' && config.cluster.trim()) || 'mt1';
    const raw = typeof config.host === 'string' ? config.host.trim() : '';
    const host = raw.replace(/^https?:\/\//i, '').replace(/\/.*$/, '').replace(/:\d+$/, '');
    if (!host || host.toLowerCase() === `api-${cluster}.pusher.com`.toLowerCase()) {
        return `ws-${cluster}.pusher.com`;
    }
    return host;
}
