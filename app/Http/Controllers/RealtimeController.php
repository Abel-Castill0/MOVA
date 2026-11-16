<?php

namespace App\Http\Controllers;

use App\Realtime\SignalRClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RealtimeController extends Controller
{
    /**
     * El navegador autenticado pide aquí su token de conexión. El id del usuario sale de la SESIÓN, nunca del cuerpo de la
     * petición: nadie puede pedir el token de otra persona. Sin tiempo real configurado responde 204 y la interfaz sigue
     * con su refresco normal.
     */
    public function negotiate(Request $request, SignalRClient $client): JsonResponse
    {
        if (! SignalRClient::enabled()) {
            return response()->json(null, 204)->header('Cache-Control', 'no-store, private');
        }

        return response()->json($client->clientConnection((int) $request->user()->id))
            ->header('Cache-Control', 'no-store, private');
    }
}
