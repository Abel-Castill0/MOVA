<?php

namespace App\Http\Controllers;

use App\Services\ChatbotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatbotController extends Controller
{
    /**
     * Procesa un mensaje del usuario hacia el chatbot Movi.
     */
    public function message(Request $request, ChatbotService $chatbotService): JsonResponse
    {
        $validated = $request->validate([
            'message'          => ['required', 'string', 'max:1000'],
            'history'          => ['nullable', 'array', 'max:10'],
            'history.*.sender' => ['required_with:history', 'string', 'in:user,bot,model'],
            'history.*.text'   => ['required_with:history', 'string', 'max:1000'],
        ]);

        $result = $chatbotService->reply(
            $validated['message'],
            $validated['history'] ?? []
        );

        return response()->json($result);
    }
}
