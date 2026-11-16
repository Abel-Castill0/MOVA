<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        // La lista se limita a 20 para evitar cargas excesivas. El contador
        // debe sumar TODAS las no leídas (también las anteriores a esas 20).
        return response()->json($user->notifications()->latest()->take(20)->get())
            ->header('X-Mova-Unread-Count', (string) $user->unreadNotifications()->count());
    }

    public function markRead(string $id)
    {
        auth()->user()->notifications()->findOrFail($id)->markAsRead();
        return response()->json(['ok' => true]);
    }

    public function markAllRead()
    {
        auth()->user()->unreadNotifications()->update(['read_at' => now()]);
        return response()->json(['ok' => true]);
    }
}
