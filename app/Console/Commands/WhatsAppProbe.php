<?php

namespace App\Console\Commands;

use App\Channels\WhatsAppChannel;
use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Models\RechargeRequest;
use App\Models\Subject;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Notifications\ClassCancelledNotification;
use App\Notifications\ClassConfirmedNotification;
use App\Notifications\ClassReminderNotification;
use App\Notifications\ClassRescheduledNotification;
use App\Notifications\CounterofferProposedNotification;
use App\Notifications\CounterofferRejectedNotification;
use App\Notifications\InsufficientCreditsNotification;
use App\Notifications\NewClassRequestNotification;
use App\Notifications\ParentApprovalRequestNotification;
use App\Notifications\RechargeApprovedNotification;
use App\WhatsApp\Waha\WahaClient;
use Illuminate\Console\Command;
use Illuminate\Notifications\Notification as BaseNotification;

/**
 * Prueba de extremo a extremo de las plantillas reales de WhatsApp antes de activar avisos para usuarios reales.
 *
 * Envía cada notificación de negocio con datos SINTÉTICOS en memoria (no crea ni modifica filas de clases, solicitudes ni
 * recargas) por el MISMO canal que usa MOVA (`WhatsAppChannel` → proveedor), así que respeta consentimiento, teléfono
 * verificado, cuenta suspendida/cerrada, lista autorizada y la política de WAHA (solo a quien escribió primero, calentamiento).
 *
 * Solo funciona con WhatsApp activado, proveedor `waha` y una lista autorizada (`WAHA_ALLOWED_RECIPIENTS`) que incluya
 * el teléfono del destinatario: nunca puede usarse para escribir a usuarios reales.
 */
class WhatsAppProbe extends Command
{
    protected $signature = 'mova:whatsapp-probe {user : id o correo del destinatario (debe estar en la lista autorizada)} {--event=* : eventos a probar (por defecto todos)} {--pause=8 : segundos entre envíos (respeta el límite global de WAHA, 8 por minuto)}';

    protected $description = 'Envía las plantillas reales de WhatsApp con datos sintéticos a un destinatario de la lista autorizada.';

    public const EVENTS = [
        'new_request', 'teacher_accepted', 'counteroffer', 'counteroffer_rejected', 'class_confirmed',
        'reminder_24h', 'reminder_2h', 'cancelled', 'rescheduled', 'recharge_approved', 'insufficient_credits',
    ];

    public function handle(): int
    {
        if (! config('services.whatsapp.enabled') || config('services.whatsapp.provider') !== 'waha') {
            $this->error('WhatsApp debe estar activado con el proveedor waha (WHATSAPP_ENABLED=true, WHATSAPP_PROVIDER=waha).');

            return self::FAILURE;
        }

        $allowed = array_filter(array_map(fn ($n) => WahaClient::digits((string) $n), explode(',', (string) config('services.waha.allowed_recipients', ''))));
        if ($allowed === []) {
            $this->error('La sonda exige una lista autorizada (WAHA_ALLOWED_RECIPIENTS): sin ella podría escribir a cualquiera.');

            return self::FAILURE;
        }

        $arg = (string) $this->argument('user');
        $user = ctype_digit($arg) ? User::find((int) $arg) : User::where('email', $arg)->first();
        if (! $user) {
            $this->error('No existe ese usuario.');

            return self::FAILURE;
        }

        $digits = WahaClient::digits((string) $user->phone);
        if ($digits === '' || ! in_array($digits, $allowed, true)) {
            $this->error('El teléfono de ese usuario no está en la lista autorizada.');

            return self::FAILURE;
        }

        $events = $this->option('event') ?: self::EVENTS;
        $unknown = array_diff($events, self::EVENTS);
        if ($unknown !== []) {
            $this->error('Eventos desconocidos: '.implode(', ', $unknown).'. Válidos: '.implode(', ', self::EVENTS));

            return self::FAILURE;
        }

        $this->info('Destinatario #'.$user->id.' · teléfono verificado: '.($user->phone_verified_at ? 'sí' : 'no').' · consentimiento: '.($user->wantsWhatsAppNotifications() ? 'sí' : 'no'));

        foreach (array_values($events) as $i => $event) {
            if ($i > 0) {
                sleep(max(0, (int) $this->option('pause')));
            }
            $notification = $this->notificationFor($event);
            $before = (int) WhatsAppMessage::max('id');
            // Directo al canal: se omite `shouldSend()` de cada notificación (exige filas reales, p. ej. una solicitud abierta),
            // pero se conservan TODAS las verificaciones del canal y del proveedor.
            app(WhatsAppChannel::class)->send($user, $notification);
            $row = WhatsAppMessage::where('id', '>', $before)->latest('id')->first();
            $status = $row ? $row->status->value.($row->skip_reason ? ' ('.$row->skip_reason->value.')' : '') : 'sin registro (canal desactivado o sin teléfono)';
            $this->line(str_pad($event, 22).' → '.$status);
        }

        $this->comment('Los ACK de entrega llegan después por el webhook de WAHA (estado delivered/read en whatsapp_messages).');

        return self::SUCCESS;
    }

    private function notificationFor(string $event): BaseNotification
    {
        $subject = new Subject(['name' => 'Matemáticas (prueba)']);
        $request = (new ClassRequest())->forceFill([
            'counteroffer_time' => now()->addDays(2)->setTime(16, 0),
            'counteroffer_duration_minutes' => 60,
        ]);
        $request->setRelation('subject', $subject);
        $request->setRelation('counterofferTeacherProfile', null);

        $lesson = (new Lesson())->forceFill(['start_time' => now()->addDay()->setTime(16, 0), 'duration_minutes' => 60]);

        return match ($event) {
            'new_request' => new NewClassRequestNotification($request),
            'teacher_accepted' => new ParentApprovalRequestNotification($request),
            'counteroffer' => new CounterofferProposedNotification($request),
            'counteroffer_rejected' => new CounterofferRejectedNotification(0, 'Matemáticas (prueba)'),
            'class_confirmed' => new ClassConfirmedNotification($lesson),
            'reminder_24h' => new ClassReminderNotification($lesson, '24h'),
            'reminder_2h' => new ClassReminderNotification($lesson, '2h'),
            'cancelled' => new ClassCancelledNotification($lesson),
            'rescheduled' => new ClassRescheduledNotification($lesson, 'MOVA (prueba)'),
            'recharge_approved' => new RechargeApprovedNotification((new RechargeRequest())->forceFill(['credits' => 5])),
            'insufficient_credits' => new InsufficientCreditsNotification(2.0, 1.0),
        };
    }
}
