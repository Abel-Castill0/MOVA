<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): string|null
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            // AZ-3G: config PÚBLICA de realtime, nunca las VITE_PUSHER_* que
            // el build de Docker no inyecta (son server-runtime, no
            // build-time). Solo lo que un cliente WebSocket necesita para
            // conectar -- nunca 'secret' ni 'app_id'. enabled=false (y el
            // resto de campos en null) cuando BROADCAST_DRIVER no es
            // 'pusher' o falta la key pública; AppLayout/initEcho() deben
            // seguir funcionando sin romperse en ese caso.
            'realtime' => fn() => $this->publicRealtimeConfig(),
            'auth' => [
                'user' => $request->user() ? [
                    'id'               => $request->user()->id,
                    'name'             => $request->user()->name,
                    'email'            => $request->user()->email,
                    'phone'            => $request->user()->phone,
                    'avatar_url'       => $request->user()->avatar_url,
                    'parental_control' => $request->user()->parental_control,
                    'roles'            => $request->user()->getRoleNames(),
                    'email_verified'   => (bool) $request->user()->email_verified_at,
                    'phone_verified'   => (bool) $request->user()->phone_verified_at,
                    // Consentimiento de WhatsApp: distinto de phone_verified.
                    'whatsapp_opt_in'  => $request->user()->wantsWhatsAppNotifications(),
                ] : null,
            ],
            'flash' => [
                'success'   => fn() => $request->session()->get('success'),
                'error'     => fn() => $request->session()->get('error'),
                'status'    => fn() => $request->session()->get('status'),
                'debugCode' => fn() => $request->session()->get('debugCode'),
            ],
            'ziggy' => fn() => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ];
    }

    /** @return array{enabled: bool, key: string|null, cluster: string|null, host: string|null, port: int|null, scheme: string|null} */
    private function publicRealtimeConfig(): array
    {
        $key = config('broadcasting.connections.pusher.key');
        $enabled = config('broadcasting.default') === 'pusher' && !empty($key);

        if (!$enabled) {
            return ['enabled' => false, 'key' => null, 'cluster' => null, 'host' => null, 'port' => null, 'scheme' => null];
        }

        return [
            'enabled' => true,
            'key' => $key,
            'cluster' => config('broadcasting.connections.pusher.options.cluster'),
            'host' => config('broadcasting.connections.pusher.options.host'),
            'port' => (int) config('broadcasting.connections.pusher.options.port'),
            'scheme' => config('broadcasting.connections.pusher.options.scheme'),
        ];
    }
}
