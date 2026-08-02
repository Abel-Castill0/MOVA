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
}
