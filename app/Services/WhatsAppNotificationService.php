<?php

namespace App\Services;

use App\Models\ClassRequest;
use App\Models\TeacherProfile;
use App\WhatsApp\Contracts\WhatsAppProviderContract;
use Illuminate\Support\Facades\Log;

/**
 * Mensajes de WhatsApp específicos del ciclo de contraoferta de horario.
 *
 * Usa el WhatsAppProviderContract inyectado (FakeWhatsAppProvider en dev,
 * MetaCloudApiProvider en producción con credenciales configuradas) —
 * el mismo patrón que WhatsAppChannel. En dev todos los envíos caen en
 * Log::info() a través de FakeWhatsAppProvider.
 *
 * Este servicio se llama directamente desde CounterofferController y
 * CounterofferWebhookController — no usa el sistema de Notifications de
 * Laravel porque los mensajes de contraoferta son transaccionales puntuales,
 * no notificaciones persistentes que el usuario deba ver en su bandeja.
 */
class WhatsAppNotificationService
{
    public function __construct(
        private readonly WhatsAppProviderContract $provider,
    ) {}

    /**
     * Notifica al padre que el profesor propone otra hora.
     * Mensaje: "El profesor X propone la hora Y para la clase de Z.
     * Responde SI para aceptar o NO para rechazar."
     */
    public function notifyParentOfCounteroffer(ClassRequest $classRequest, TeacherProfile $teacherProfile): void
    {
        $classRequest->loadMissing(['student.parent', 'subject', 'counterofferTeacherProfile.user']);

        $parentPhone = $classRequest->student?->parent?->phone;
        $teacherName = $teacherProfile->user?->name ?? 'El profesor';
        $subjectName = $classRequest->subject?->name ?? 'la materia';
        $proposedTime = $classRequest->counteroffer_time
            ? $classRequest->counteroffer_time->format('d/m/Y \a \l\a\s H:i')
            : '(hora por confirmar)';

        Log::info('[WhatsApp/Contraoferta] Notificando al padre sobre contraoferta.', [
            'class_request_id'  => $classRequest->id,
            'teacher'           => $teacherName,
            'subject'           => $subjectName,
            'proposed_time'     => $proposedTime,
            'parent_phone'      => $parentPhone ? substr($parentPhone, 0, 4).'***' : 'N/A',
        ]);

        if (! $parentPhone) {
            Log::warning('[WhatsApp/Contraoferta] Padre sin teléfono registrado — no se puede notificar.', [
                'class_request_id' => $classRequest->id,
            ]);

            return;
        }

        // Mensaje simulado para dev. En producción esto enviaría una
        // plantilla aprobada por Meta con los params correspondientes.
        $message = "Hola! {$teacherName} propone la clase de {$subjectName} "
            ."el {$proposedTime}. "
            ."Responde SI para aceptar o NO para que la solicitud vuelva al marketplace.";

        Log::info("[WhatsApp/Contraoferta→Padre] Mensaje simulado: {$message}");

        // Intentar enviar por proveedor real (en dev siempre devuelve true sin enviar nada)
        $this->provider->sendTemplate(
            $parentPhone,
            'counteroffer_notification',
            [
                'teacher_name'  => $teacherName,
                'subject_name'  => $subjectName,
                'proposed_time' => $proposedTime,
                'request_id'    => (string) $classRequest->id,
            ],
            "CounterOfferNotification#{$classRequest->id}"
        );
    }

    /**
     * Notifica al padre y al profesor que la clase fue confirmada
     * (el padre aceptó la contraoferta). Incluye el link de la videollamada.
     */
    public function notifyBothOfAcceptance(ClassRequest $classRequest): void
    {
        $classRequest->loadMissing([
            'student.parent',
            'subject',
            'counterofferTeacherProfile.user',
        ]);

        $parentPhone  = $classRequest->student?->parent?->phone;
        $teacherPhone = $classRequest->counterofferTeacherProfile?->user?->phone;
        $teacherName  = $classRequest->counterofferTeacherProfile?->user?->name ?? 'El profesor';
        $subjectName  = $classRequest->subject?->name ?? 'la materia';
        $meetingLink  = $classRequest->meeting_link ?? '(link por generar)';
        $confirmedTime = $classRequest->counteroffer_time
            ? $classRequest->counteroffer_time->format('d/m/Y \a \l\a\s H:i')
            : '(hora confirmada)';

        Log::info('[WhatsApp/Contraoferta] Clase aceptada — notificando a ambos.', [
            'class_request_id' => $classRequest->id,
            'meeting_link'     => $meetingLink,
            'confirmed_time'   => $confirmedTime,
        ]);

        // Notificar al padre
        if ($parentPhone) {
            $msgPadre = "¡Clase confirmada! {$teacherName} te enseñará {$subjectName} "
                ."el {$confirmedTime}. Enlace: {$meetingLink}";
            Log::info("[WhatsApp/Contraoferta→Padre] {$msgPadre}");
            $this->provider->sendTemplate(
                $parentPhone,
                'class_confirmed_with_link',
                ['teacher_name' => $teacherName, 'time' => $confirmedTime, 'link' => $meetingLink],
                "CounterOfferAccepted#parent#{$classRequest->id}"
            );
        }

        // Notificar al profesor
        if ($teacherPhone) {
            $studentName = trim(
                ($classRequest->student?->first_name ?? '').' '.($classRequest->student?->last_name ?? '')
            );
            $msgProfe = "¡{$studentName} aceptó tu contraoferta! "
                ."Clase de {$subjectName} el {$confirmedTime}. Enlace: {$meetingLink}";
            Log::info("[WhatsApp/Contraoferta→Profesor] {$msgProfe}");
            $this->provider->sendTemplate(
                $teacherPhone,
                'class_confirmed_teacher',
                ['student_name' => $studentName, 'time' => $confirmedTime, 'link' => $meetingLink],
                "CounterOfferAccepted#teacher#{$classRequest->id}"
            );
        }
    }

    /**
     * Notifica al profesor que el padre rechazó la contraoferta.
     * La solicitud vuelve al marketplace como 'open'.
     */
    public function notifyTeacherOfRejection(ClassRequest $classRequest): void
    {
        $classRequest->loadMissing(['counterofferTeacherProfile.user', 'subject', 'student']);

        $teacherPhone = $classRequest->counterofferTeacherProfile?->user?->phone;
        $subjectName  = $classRequest->subject?->name ?? 'la materia';
        $studentName  = trim(
            ($classRequest->student?->first_name ?? '').' '.($classRequest->student?->last_name ?? '')
        );

        Log::info('[WhatsApp/Contraoferta] Padre rechazó contraoferta — notificando al profesor.', [
            'class_request_id' => $classRequest->id,
            'teacher_phone'    => $teacherPhone ? substr($teacherPhone, 0, 4).'***' : 'N/A',
        ]);

        if (! $teacherPhone) {
            return;
        }

        $msg = "{$studentName} no pudo con la hora que propusiste para {$subjectName}. "
            ."La solicitud volvió al marketplace — otro profesor puede aceptarla.";
        Log::info("[WhatsApp/Contraoferta→Profesor] {$msg}");

        $this->provider->sendTemplate(
            $teacherPhone,
            'counteroffer_rejected',
            ['student_name' => $studentName, 'subject_name' => $subjectName],
            "CounterOfferRejected#{$classRequest->id}"
        );
    }
}
