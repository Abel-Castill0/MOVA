<?php

namespace App\Http\Controllers;

use App\Models\ClassEvent;
use App\Models\ClassRequest;
use App\Services\WhatsAppNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Webhook simulado que procesa la respuesta del padre a la contraoferta.
 *
 * En producción, el padre respondería "SI" o "NO" directamente por WhatsApp
 * y Meta enviaría ese mensaje a este endpoint. Para el demo, cualquier cliente
 * (Postman, curl, el panel de admin) puede llamar este endpoint directamente.
 *
 * Ruta: POST /webhooks/counteroffer-response
 * Pública, sin auth de usuario (simula la llamada desde Meta). Rate-limited
 * por IP (ver routes/web.php) para evitar abuso.
 *
 * Es IDEMPOTENTE: si la solicitud ya está en 'accepted' o 'open' (ya se
 * procesó antes), responde success:true sin volver a escribir nada.
 *
 * El link de videollamada usa meet.jit.si (público, sin credenciales) para
 * el demo. En producción se reemplazaría por JaasService::generateLink().
 */
class CounterofferWebhookController extends Controller
{
    public function __construct(
        private readonly WhatsAppNotificationService $whatsapp,
    ) {}

    public function handle(Request $request)
    {
        $data = $request->validate([
            'class_request_id' => ['required', 'integer', 'exists:class_requests,id'],
            'response'         => ['required', 'string', 'in:SI,NO,si,no,Sí,sí'],
        ]);

        $accepted = in_array(strtoupper(trim($data['response'])), ['SI', 'SÍ']);

        $classRequest = ClassRequest::with([
            'student.parent',
            'subject',
            'counterofferTeacherProfile.user',
        ])->findOrFail($data['class_request_id']);

        // Idempotencia: si ya se procesó, no hacer nada.
        if (! in_array($classRequest->status, ['counteroffered'])) {
            Log::info('[CounterofferWebhook] Solicitud ya procesada o en estado incorrecto — ignorando.', [
                'class_request_id' => $classRequest->id,
                'current_status'   => $classRequest->status,
            ]);

            return response()->json(['success' => true, 'skipped' => true]);
        }

        DB::transaction(function () use ($classRequest, $accepted) {
            // Lock para procesar exactamente una vez aunque lleguen dos webhooks simultáneos
            $fresh = ClassRequest::whereKey($classRequest->id)->lockForUpdate()->first();

            if ($fresh->status !== 'counteroffered') {
                return; // Ya procesado dentro del lock, salir silenciosamente
            }

            if ($accepted) {
                // El padre aceptó: generar link y confirmar la clase.
                $meetingLink = 'https://meet.jit.si/MOVA-'.Str::random(12);

                $fresh->update([
                    'status'       => 'accepted',
                    'meeting_link' => $meetingLink,
                ]);

                $classRequest->meeting_link = $meetingLink;
                $classRequest->status       = 'accepted';

                ClassEvent::log(
                    'counteroffer_accepted',
                    null, // actor = sistema (respuesta de WhatsApp, sin sesión de usuario)
                    null,
                    $fresh->id,
                    'Padre aceptó la contraoferta vía WhatsApp',
                    ['meeting_link' => $meetingLink]
                );

                Log::info('[CounterofferWebhook] Padre aceptó la contraoferta.', [
                    'class_request_id' => $fresh->id,
                    'meeting_link'     => $meetingLink,
                ]);
            } else {
                // El padre rechazó: limpiar contraoferta y volver al marketplace.
                $fresh->update([
                    'status'                          => 'open',
                    'counteroffer_time'               => null,
                    'counteroffer_teacher_profile_id' => null,
                ]);

                $classRequest->status = 'open';

                ClassEvent::log(
                    'counteroffer_rejected',
                    null,
                    null,
                    $fresh->id,
                    'Padre rechazó la contraoferta vía WhatsApp'
                );

                Log::info('[CounterofferWebhook] Padre rechazó la contraoferta — solicitud vuelta a open.', [
                    'class_request_id' => $fresh->id,
                ]);
            }
        });

        // Notificaciones fuera de la transacción
        try {
            if ($accepted) {
                $this->whatsapp->notifyBothOfAcceptance($classRequest);
            } else {
                $this->whatsapp->notifyTeacherOfRejection($classRequest);
            }
        } catch (\Throwable $e) {
            Log::error('[CounterofferWebhook] Error al enviar notificaciones post-respuesta.', [
                'class_request_id' => $classRequest->id,
                'error'            => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success'      => true,
            'accepted'     => $accepted,
            'meeting_link' => $accepted ? $classRequest->meeting_link : null,
        ]);
    }
}
