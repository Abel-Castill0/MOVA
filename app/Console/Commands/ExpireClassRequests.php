<?php

namespace App\Console\Commands;

use App\Models\ClassEvent;
use App\Models\ClassRequest;
use App\Notifications\ClassRequestExpiredNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * §14 — Cierra las solicitudes abiertas que nadie respondió.
 *
 * EL PROBLEMA: una `ClassRequest` en estado `open` no tenía salida. Si ningún
 * profesor la aceptaba ni la rechazaba, se quedaba abierta para siempre
 * (docs/MOVA_SYSTEM_MAP.md R-12). Consecuencias reales:
 *
 *   - La bandeja del profesor (`teacher.requests`) acumulaba solicitudes de
 *     hace meses mezcladas con las de hoy.
 *   - `open_requests` en el panel de admin dejaba de medir demanda viva.
 *   - El padre esperaba indefinidamente por algo que ya nadie iba a atender,
 *     sin ninguna señal de que debía intentar otra cosa.
 *
 * QUÉ NO HACE: no toca dinero. Una solicitud `open` nunca tuvo créditos
 * reservados —la reserva ocurre al ACEPTAR, en `LessonController::store()`— así
 * que expirar es una transición puramente de estado. Por eso este comando puede
 * ser mucho más simple que `mova:settle-lessons`.
 */
class ExpireClassRequests extends Command
{
    protected $signature = 'mova:expire-class-requests
        {--dry-run : Muestra qué expiraría sin escribir nada}';

    protected $description = 'Expira solicitudes de clase abiertas que nadie respondió dentro del plazo';

    public function handle(): int
    {
        $hours = (int) config('class_requests.expiry_hours', 24);
        $cutoff = now()->subHours($hours);
        $dryRun = (bool) $this->option('dry-run');

        // SOLO 'open'. Nunca `accepted` (ya tiene clase y créditos reservados),
        // ni los terminales (`rejected`, `teacher_rejected`, `expired`), ni
        // `pending_parent_approval` — esa última está esperando al PADRE, no a
        // un profesor: expirarla castigaría al usuario por no haber revisado su
        // propia bandeja, y el control parental existe precisamente para que
        // decida sin prisa.
        $ids = ClassRequest::where('status', 'open')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->pluck('id');

        $this->info("Expiración de solicitudes (plazo: {$hours}h, corte: {$cutoff->toDateTimeString()}) — candidatas: {$ids->count()}");

        $expired = 0;
        $raced = 0;

        foreach ($ids as $id) {
            if ($dryRun) {
                $this->line("  [dry-run] expiraría ClassRequest {$id}");

                continue;
            }

            $outcome = $this->expire($id, $hours);

            match ($outcome) {
                'expired' => $expired++,
                'raced' => $raced++,
                default => null,
            };
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."Expiradas: {$expired}. Ya resueltas por otra vía: {$raced}.");

        return self::SUCCESS;
    }

    /**
     * CARRERA ACEPTACIÓN vs EXPIRACIÓN.
     *
     * Un profesor puede estar aceptando la solicitud justo cuando el barrido
     * decide expirarla. Si ganara la expiración después de que
     * `LessonController::store()` ya reservó créditos y creó la clase, quedaría
     * una `Lesson` viva colgando de una solicitud `expired`: un estado que la
     * máquina de estados no contempla y que nadie sabría interpretar.
     *
     * El lock resuelve la carrera en los dos sentidos:
     *
     *   - Si `store()` llegó primero, su transacción ya movió la solicitud a
     *     `accepted` y aquí se relee bajo lock un estado que ya no es `open`:
     *     no se expira nada ('raced').
     *   - Si este comando llega primero, `store()` se queda esperando el lock y
     *     al entrar encuentra `expired`, que no es `open` — su propio re-chequeo
     *     bajo lock la rechaza con el mensaje de "ya no está disponible".
     *
     * Es el mismo patrón que `SettleLessons::escalateToReview()` usa para las
     * clases, y por eso es idempotente: correrlo dos veces seguidas no
     * reexpira nada.
     */
    private function expire(int $id, int $hours): string
    {
        try {
            $request = DB::transaction(function () use ($id) {
                $request = ClassRequest::with(['student.parent', 'subject'])
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->first();

                if (! $request || $request->status !== 'open') {
                    return null;
                }

                $request->update(['status' => 'expired']);

                ClassEvent::log(
                    'request_expired',
                    null,
                    null,
                    $request->id,
                    'Sin respuesta de ningún profesor dentro del plazo'
                );

                return $request;
            });

            if ($request === null) {
                return 'raced';
            }

            // AVISO SOLO AL PADRE, y solo aquí.
            //
            // Es quien pierde algo: estaba esperando una clase que ya no va a
            // llegar y necesita saber que debe volver a intentarlo. A los
            // profesores NO se les avisa: para ellos es una solicitud que
            // decidieron no atender, y notificar cada expiración a todos los
            // profesores elegibles de la materia sería ruido puro.
            $request->student?->parent?->notify(new ClassRequestExpiredNotification($request));

            $this->line("  ClassRequest {$id}: expirada tras {$hours}h sin respuesta.");

            return 'expired';
        } catch (\Throwable $e) {
            Log::error('CLASS_REQUEST_EXPIRY_FAILED', [
                'class_request_id' => $id,
                'error' => $e->getMessage(),
            ]);
            report($e);

            $this->error("  ClassRequest {$id}: {$e->getMessage()}");

            return 'error';
        }
    }
}
