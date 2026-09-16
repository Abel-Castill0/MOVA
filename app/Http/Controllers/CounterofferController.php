<?php

namespace App\Http\Controllers;

use App\Models\ClassEvent;
use App\Models\ClassRequest;
use App\Services\WhatsAppNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gestiona las contraofertas de horario que hace un profesor sobre una
 * solicitud abierta. Ciclo:
 *
 *   ClassRequestController::teacherIndex()  →  TeacherIndex.vue
 *   TeacherIndex.vue (modal "Proponer otra hora")
 *   POST /class-requests/{classRequest}/counteroffer  ←  aquí
 *   WhatsAppNotificationService::notifyParentOfCounteroffer()
 *   [padre responde SI/NO vía CounterofferWebhookController]
 *
 * Solo el controlador escribe counteroffer_teacher_profile_id; el modelo
 * lo tiene en $fillable pero no en ningún request-body validado genérico,
 * así que no puede llegar desde el exterior por mass-assignment accidental.
 */
class CounterofferController extends Controller
{
    public function __construct(
        private readonly WhatsAppNotificationService $whatsapp,
    ) {}

    /**
     * El profesor propone una hora alternativa para una solicitud abierta.
     *
     * Autorización: ClassRequestPolicy::accept() — el mismo guard que usa
     * ClassRequestController::accept(). Si el profesor puede aceptar la
     * solicitud (está verificado, la solicitud es de su materia/oferta o
     * le llega por código de referido), también puede hacerle una
     * contraoferta.
     *
     * Transición de estado: open → counteroffered.
     * Solo se permite si la solicitud está en 'open' — una solicitud ya
     * en 'counteroffered' (otro profesor propuso antes) requeriría que
     * el padre rechace primero la propuesta existente.
     */
    public function store(Request $request, ClassRequest $classRequest)
    {
        $this->authorize('accept', $classRequest);

        if ($classRequest->status !== 'open') {
            throw ValidationException::withMessages([
                'counteroffer_time' => 'Solo se puede hacer una contraoferta a solicitudes abiertas.',
            ]);
        }

        $data = $request->validate([
            'counteroffer_time' => [
                'required',
                'date',
                'after:now',
            ],
        ], [
            'counteroffer_time.required' => 'La hora propuesta es obligatoria.',
            'counteroffer_time.after'    => 'La hora propuesta debe ser en el futuro.',
        ]);

        $profile = auth()->user()->teacherProfile;

        DB::transaction(function () use ($classRequest, $data, $profile) {
            // Lock para evitar que dos profesores hagan contraoferta simultáneamente
            ClassRequest::whereKey($classRequest->id)->lockForUpdate()->first();

            // Re-verificar estado dentro del lock
            $fresh = ClassRequest::findOrFail($classRequest->id);
            if ($fresh->status !== 'open') {
                throw ValidationException::withMessages([
                    'counteroffer_time' => 'Esta solicitud ya no está disponible.',
                ]);
            }

            $fresh->update([
                'status'                        => 'counteroffered',
                'counteroffer_time'             => $data['counteroffer_time'],
                'counteroffer_teacher_profile_id' => $profile->id,
            ]);

            ClassEvent::log(
                'counteroffer_proposed',
                auth()->id(),
                null,
                $classRequest->id,
                "Hora propuesta: {$data['counteroffer_time']}"
            );
        });

        // Recargar con la hora y el perfil del profesor ya guardados
        $classRequest->refresh();

        // Notificar al padre — fuera de la transacción: si falla el envío
        // el estado ya quedó guardado correctamente, no se pierde nada.
        try {
            $this->whatsapp->notifyParentOfCounteroffer($classRequest, $profile);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[CounterofferController] Error al notificar al padre.', [
                'class_request_id' => $classRequest->id,
                'error'            => $e->getMessage(),
            ]);
        }

        return back()->with('success', '¡Contraoferta enviada! El padre recibirá una notificación para aceptar o rechazar.');
    }
}
