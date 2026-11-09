<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        return response()->json([
            // La campana muestra 20 entradas, pero el contador debe abarcar
            // TODAS las no leídas; si hay 21+, no puede ocultar las antiguas.
            'notifications' => $user->notifications()->latest()->limit(20)->get(),
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markRead(string $id)
    {
        auth()->user()->notifications()->findOrFail($id)->markAsRead();
        return response()->json(['ok' => true]);
    }

    public function markAllRead()
    {
        // UPDATE acotado al usuario, sin hidratar toda la colección en memoria.
        auth()->user()->unreadNotifications()->update(['read_at' => now()]);
        return response()->json(['ok' => true]);
    }
}
