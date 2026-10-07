<?php

namespace App\Http\Controllers;

use App\Services\ChatbotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatbotController extends Controller
{
    /**
     * Un único mensaje neutral para cualquier fallo: ni el texto ni el cuerpo
     * permiten distinguir "apagado", "sin clave" o "error del proveedor" —
     * nunca se revela configuración, variables de entorno ni al proveedor.
     */
    private const UNAVAILABLE_MESSAGE = 'Movi no está disponible en este momento. Puedes escribirnos a :email o volver a intentarlo más tarde.';

    private const DAILY_LIMIT_MESSAGE = 'Movi recibió muchas consultas hoy. Vuelve a intentarlo más tarde o escríbenos a :email.';

    public function message(Request $request, ChatbotService $chatbotService): JsonResponse
    {
        $maxChars = (int) config('chatbot.max_message_chars', 1000);

        $validated = $request->validate([
            'message'          => ['required', 'string', 'max:'.$maxChars],
            'history'          => ['nullable', 'array', 'max:'.(int) config('chatbot.max_history_items', 10)],
            'history.*.sender' => ['required_with:history', 'string', 'in:user,bot,model'],
            'history.*.text'   => ['required_with:history', 'string', 'max:'.$maxChars],
        ]);

        $user = $request->user();
        $role = $user?->hasRole('teacher') ? 'teacher' : ($user ? 'parent' : null);

        $result = $chatbotService->reply($validated['message'], $validated['history'] ?? [], $role);

        if ($result['ok']) {
            return response()->json(['ok' => true, 'reply' => $result['reply']]);
        }

        if ($result['reason'] === ChatbotService::DAILY_LIMIT) {
            return response()->json(['ok' => false, 'message' => $this->withSupportEmail(self::DAILY_LIMIT_MESSAGE)], 429);
        }

        // 503 idéntico para apagado, sin clave o error del proveedor.
        return response()->json(['ok' => false, 'message' => $this->withSupportEmail(self::UNAVAILABLE_MESSAGE)], 503);
    }

    private function withSupportEmail(string $message): string
    {
        return str_replace(':email', (string) config('legal.support_email'), $message);
    }
}
