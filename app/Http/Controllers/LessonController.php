<?php

namespace App\Http\Controllers;

use App\Events\ClassConfirmed;
use App\Models\ClassEvent;
use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Models\TeacherProfile;
use App\Notifications\ClassCancelledNotification;
use App\Notifications\ClassRescheduledNotification;
use App\Notifications\PaymentConfirmedNotification;
use App\Services\JaasService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class LessonController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'class_request_id' => 'required|exists:class_requests,id',
            'start_time' => 'required|date|after:now',
            'duration_minutes' => 'required|integer|min:30|max:240',
        ]);
        $data['start_time'] = Carbon::parse($data['start_time'])->utc()->toIso8601String();

        $classRequest = ClassRequest::with(['student', 'subject'])->findOrFail($data['class_request_id']);
        $profile = auth()->user()->teacherProfile;

        // Clasificación explícita, no "todo abort() se convierte" — revisión
        // solicitada: ¿cuál de estos 5 es de verdad una autorización (403,
        // se queda como abort) y cuál es un estado de negocio recuperable
        // (ValidationException)?
        //
        // - Sin perfil de profesor: NO es recuperable reenviando este mismo
        //   formulario — es un estado de cuenta roto/imposible en la
        //   práctica (todo profesor obtiene su perfil atómicamente al
        //   registrarse; ver RegisteredUserController). Es más cercano a
        //   una autorización/precondición que a una validación de negocio,
        //   así que se queda como abort_unless(403) — un 403 crudo aquí es
        //   aceptable porque un usuario real jamás debería alcanzarlo
        //   legítimamente (y si alguna vez ocurre, un error ruidoso en logs
        //   es mejor que uno silenciosamente disfrazado de validación).
        abort_unless($profile, 403, 'No tienes perfil de profesor.');

        // - `status !== 'open'` SÍ es recuperable: la solicitud sigue
        //   existiendo, el profesor sigue siendo elegible para intentar con
        //   OTRA — es un conflicto de estado del recurso, no una falta de
        //   permiso. Mismo razonamiento para créditos insuficientes y cupo
        //   de mentoría lleno (ver dentro de la transacción, abajo): todos
        //   son "no puedes completar esta acción de negocio ahora mismo",
        //   no "no tienes permiso". Los 3 usan la misma clave de error de
        //   formulario — no field-level — ver nota más abajo sobre por qué
        //   NO se atan a `class_request_id` ni a `duration_minutes`.
        if ($classRequest->status !== 'open') {
            throw ValidationException::withMessages([
                'accept' => 'Esta solicitud ya no está disponible — probablemente otro profesor la aceptó primero.',
            ]);
        }
        $this->authorize('accept', $classRequest);

        if ($this->hasScheduleOverlap($profile->id, $data['start_time'], $data['duration_minutes'])) {
            return back()->withErrors(['start_time' => 'Ya tienes una clase en ese horario.']);
        }

        $lesson = DB::transaction(function () use ($classRequest, $data, $profile) {
            $classRequest = ClassRequest::with(['student', 'subject', 'classOffer'])
                ->whereKey($classRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($classRequest->status !== 'open') {
                // Re-chequeo bajo lock: si otra request ganó la carrera entre
                // el chequeo de arriba y este `lockForUpdate()`, esta es la
                // que realmente importa — el de arriba es solo fail-fast.
                throw ValidationException::withMessages([
                    'accept' => 'Esta solicitud ya no está disponible — probablemente otro profesor la aceptó primero.',
                ]);
            }

            if ($this->hasScheduleOverlap(
                $profile->id,
                $data['start_time'],
                $data['duration_minutes'],
                true
            )) {
                throw ValidationException::withMessages([
                    'start_time' => 'Ya tienes una clase en ese horario.',
                ]);
            }

            $teacherProfile = TeacherProfile::whereKey($profile->id)
                ->lockForUpdate()
                ->firstOrFail();

            $creditsNeeded = Lesson::creditCostForMinutes($data['duration_minutes']);

            if ($teacherProfile->credits_available < $creditsNeeded) {
                // Corregido en revisión: NO se ata a `duration_minutes`. La
                // primera versión de este fix lo hacía razonando que "es lo
                // único que el profesor puede cambiar en este formulario" —
                // pero eso implica visualmente que cambiar la duración es
                // LA solución, cuando la solución más directa (recargar
                // saldo) ni siquiera está en este formulario. Es un error de
                // negocio, no un dato de formulario inválido — usa la misma
                // clave `accept` que el resto de errores no ligados a un
                // campo concreto. El mensaje mismo ya menciona ambas
                // salidas (recargar o acortar), la UI no necesita
                // insinuarlo con la ubicación del error.
                throw ValidationException::withMessages([
                    'accept' => 'Créditos insuficientes para esta duración. Por favor, recargue su saldo o elija una clase más corta.',
                ]);
            }

            if ($classRequest->is_mentorship && ! $teacherProfile->hasAvailableMentorshipSlots()) {
                throw ValidationException::withMessages([
                    'accept' => 'Tienes la agenda llena para acompañamiento continuo — no puedes aceptar esta solicitud por ahora.',
                ]);
            }

            // BUG-4 (docs/MOVA_AUDIT_PHASE0.md, sección Q): `specific_rate` se
            // valida y persiste en ClassOfferController, pero hasta este fix
            // nunca se leía aquí — el profesor podía configurar una tarifa
            // distinta para una oferta concreta creyendo que era la que se
            // cobraría, y el sistema siempre congelaba la tarifa general del
            // perfil en su lugar. `specific_rate` ya fue validado contra
            // maxAllowedRate() al guardarse la oferta, así que no hace falta
            // volver a acotarlo aquí — solo usarlo si existe.
            $rate = $classRequest->classOffer?->specific_rate ?? $teacherProfile->hourly_rate;

            $lesson = Lesson::create([
                'teacher_profile_id' => $profile->id,
                'student_id' => $classRequest->student_id,
                'class_request_id' => $classRequest->id,
                'class_offer_id' => $classRequest->class_offer_id,
                'start_time' => $data['start_time'],
                'duration_minutes' => $data['duration_minutes'],
                'price_frozen_pen' => round($rate * $creditsNeeded, 2),
                'status' => 'scheduled',
            ]);

            // F-07: ya no se genera jitsi_password. JaaS autentica por JWT
            // firmado (con el `room` en el payload); la contraseña era un
            // residuo de meet.jit.si que se guardaba en claro sin que nadie
            // la leyera nunca.
            $lesson->update([
                'jitsi_room' => "mova-lesson-{$lesson->id}-".Str::random(32),
            ]);

            $profileUpdates = [
                'credits_available' => $teacherProfile->credits_available - $creditsNeeded,
                'credits_reserved' => $teacherProfile->credits_reserved + $creditsNeeded,
            ];

            if ($classRequest->is_mentorship) {
                $profileUpdates['mentorship_slots_taken'] = $teacherProfile->mentorship_slots_taken + 1;
            }

            $teacherProfile->update($profileUpdates);

            $teacherProfile->creditTransactions()->create([
                'idempotency_key' => "lesson:{$lesson->id}:reservation",
                'lesson_id' => $lesson->id,
                'type' => 'reservation',
                'amount' => $creditsNeeded,
                'description' => 'Reserva por aceptación de clase',
            ]);

            $classRequest->update(['status' => 'accepted']);

            return $lesson;
        });

        event(new ClassConfirmed($lesson));

        return redirect()->route('teacher.lessons')->with('success',
            '¡Clase programada! La sala virtual está disponible en "Mis clases".'
        );
    }

    public function parentIndex()
    {
        $studentIds = auth()->user()->students()->pluck('id');

        // Proyección explícita — mismo hallazgo, mismo root cause que
        // ClassRequestController::accept() (auditoría de Accept.vue):
        // `ParentLessonCard.vue` solo lee `teacher_profile.user.name`,
        // `teacher_profile.yape_number`/`plin_number` (legítimo — así paga
        // el padre) y `student.first_name`/`last_name` (verificado leyendo
        // el componente, no supuesto). Sin restricción, `teacherProfile.user`
        // serializaba el `User` completo del profesor (email, teléfono,
        // hash de verificación, motivo de suspensión...) a cada padre que
        // ve su lista de clases. `student` va menos restringido en severidad
        // real (es el propio hijo del padre, que ya ve completo en
        // `Students/Edit.vue`), pero se acota igual por disciplina de
        // minimización, no por necesidad de seguridad aquí.
        return Inertia::render('Lessons/ParentIndex', [
            'lessons' => Lesson::whereIn('student_id', $studentIds)
                ->with([
                    'teacherProfile:id,user_id,yape_number,plin_number',
                    'teacherProfile.user:id,name',
                    'student:id,parent_user_id,first_name,last_name,grade_level',
                    'classRequest.subject:id,name',
                    'teacherReview',
                ])
                ->orderBy('start_time', 'desc')
                ->get(),
        ]);
    }

    public function teacherIndex()
    {
        $profile = auth()->user()->teacherProfile;

        // Mismo hallazgo que ClassRequestController::accept(): `student`
        // completo (incluye `birth_date`/`school`, datos reales de un
        // menor) se serializaba sin restricción a cada profesor con
        // clases — `TeacherLessonCard.vue` solo lee `first_name`/
        // `last_name` (verificado, no supuesto).
        return Inertia::render('Lessons/TeacherIndex', [
            'lessons' => Lesson::where('teacher_profile_id', $profile->id)
                ->with([
                    'student:id,parent_user_id,first_name,last_name,grade_level',
                    'classRequest.subject:id,name',
                    'lessonReport',
                ])
                ->orderBy('start_time', 'desc')
                ->get(),
        ]);
    }

    // Único punto de la app que revela jitsi_room (oculto por defecto en el
    // modelo — ver Lesson::$hidden) y emite el JWT de JaaS. El frontend los
    // pide aquí, justo antes de abrir la sala, en vez de recibirlos en el
    // listado de clases; así reducimos la ventana de exposición y evitamos
    // que alguien con la URL/room adivinada pueda entrar sin haber pasado
    // por esta verificación de autorización + estado. El JWT se firma con
    // la private key de JaaS (nunca sale del backend) y su expiración queda
    // acotada a la ventana de acceso de la clase (F-06), no a 24h fijas.
    public function join(Lesson $lesson, JaasService $jaas)
    {
        $this->authorize('view', $lesson);

        abort_unless(
            in_array($lesson->status, ['scheduled', 'paid', 'pending_parent_confirmation'], true),
            403,
            'Esta clase no está disponible para unirse en este momento.'
        );

        abort_unless($lesson->jitsi_room, 404, 'Esta clase todavía no tiene una sala virtual asignada.');

        // F-06 — Ventana temporal AUTORITATIVA en el servidor.
        //
        // Antes esta comprobación no existía aquí: la regla "disponible 15
        // minutos antes" vivía solo en resources/js/utils/lessonJoin.js, así
        // que un POST directo a esta ruta devolvía un token válido días antes
        // de la clase. La UI comunicaba una restricción que el backend no
        // aplicaba — divergencia de autorización, no solo de UX.
        //
        // 'paid' se exceptúa a propósito: es el estado en que la clase ya
        // ocurrió y se confirmó el pago; el acceso posterior a la sala para
        // repasar/cerrar temas ya era el comportamiento esperado (ver
        // lessonJoin.js:13) y restringirlo aquí sería un cambio de producto,
        // no una corrección de seguridad.
        if ($lesson->status !== 'paid') {
            $opensAt  = $lesson->start_time->copy()->subMinutes((int) config('jaas.join_window_before_minutes', 15));
            $closesAt = $lesson->end_time->copy()->addMinutes((int) config('jaas.join_grace_after_minutes', 120));

            abort_if(
                now()->lt($opensAt),
                403,
                'La sala se abre '.config('jaas.join_window_before_minutes', 15).' minutos antes del inicio de la clase.'
            );
            abort_if(now()->gt($closesAt), 403, 'La sala de esta clase ya se cerró.');
        }

        $user = auth()->user();
        $isModerator = $lesson->teacherProfile?->user_id === $user->id;

        // El token no sobrevive a la ventana en que este mismo endpoint lo
        // habría concedido. Para 'paid' se mantiene una ventana corta desde
        // ahora, en lugar de las 24h fijas de antes.
        $tokenExpiresAt = $lesson->status === 'paid'
            ? now()->addMinutes((int) config('jaas.join_grace_after_minutes', 120))
            : $lesson->end_time->copy()->addMinutes((int) config('jaas.join_grace_after_minutes', 120));

        return response()->json([
            'jitsi_room' => $lesson->jitsi_room,
            'jitsi_token' => $jaas->generateToken($lesson->jitsi_room, $user->name, $isModerator, $tokenExpiresAt),
            // App ID de JaaS — no es secreto (aparece en cada URL/script tag
            // de la llamada), el frontend lo necesita para construir el room
            // name con prefijo de tenant y la URL de external_api.js.
            'jaas_app_id' => config('jaas.app_id'),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function confirmPayment(Lesson $lesson)
    {
        $user = auth()->user();
        $this->authorize('confirmPayment', $lesson);

        // Mismo hallazgo/clasificación que accept()/reschedule(): ninguno de
        // estos dos es una falla de autorización (`authorize()` ya corrió,
        // aparte, arriba) — son conflictos de estado del recurso, recuperables
        // (el padre puede entender por qué y esperar/reintentar). Antes eran
        // abort_unless()/abort_if() crudos — mismo patrón de fallo silencioso
        // ya corregido 4 veces antes en esta sesión. Este formulario no tiene
        // NINGÚN campo (ParentIndex.vue lo dispara con un POST de body vacío,
        // solo un botón), así que no hay ningún campo real al que atar el
        // error — usa su propia clave de negocio, `confirmPayment`, mismo
        // patrón que `accept`/`reschedule`.
        if ($lesson->status !== 'scheduled') {
            throw ValidationException::withMessages([
                'confirmPayment' => 'Solo se puede confirmar el pago de clases programadas.',
            ]);
        }
        // Sin bypass por query param ni por entorno: en PHPUnit, now() ya respeta
        // Carbon::setTestNow(); para E2E (Playwright), usar el comando de consola
        // `mova:testing-backdate-lesson` para sembrar la clase ya vencida en BD,
        // en vez de debilitar esta validación en runtime.
        if (now()->lt($lesson->end_time)) {
            throw ValidationException::withMessages([
                'confirmPayment' => 'La clase aún no ha finalizado.',
            ]);
        }

        $lesson = DB::transaction(function () use ($lesson, $user) {
            $lesson = Lesson::with(['teacherProfile.user'])
                ->whereKey($lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lesson->status !== 'scheduled') {
                throw ValidationException::withMessages([
                    'confirmPayment' => 'Solo se puede confirmar el pago de clases programadas.',
                ]);
            }
            if (now()->lt($lesson->end_time)) {
                throw ValidationException::withMessages([
                    'confirmPayment' => 'La clase aún no ha finalizado.',
                ]);
            }

            $lesson->update(['status' => 'paid']);

            ClassEvent::log('payment_confirmed', $user->id, $lesson->id, $lesson->class_request_id);

            return $lesson;
        });

        $lesson->teacherProfile?->user?->notify(new PaymentConfirmedNotification($lesson));

        return back()->with('success', 'Pago confirmado. El profesor podrá subir el reporte de la clase.');
    }

    public function cancel(Lesson $lesson)
    {
        $user = auth()->user();
        $profile = $user->teacherProfile;
        $this->authorize('cancel', $lesson);

        // Used below to route notifications to the other party.
        $isTeacher = $profile && $lesson->teacher_profile_id === $profile->id;
        $isParent = $user->hasRole('parent') && $user->students()->where('id', $lesson->student_id)->exists();
        $isAdmin = $user->hasRole('admin');

        // Mismo hallazgo/clasificación que accept()/reschedule()/confirmPayment():
        // ninguno de los 4 chequeos de abajo es autorización (esa ya corrió
        // aparte, arriba) — todos son conflictos de estado del recurso o de
        // integridad financiera, recuperables en el sentido de que el usuario
        // entiende qué pasó (aunque en el caso de la anomalía financiera la
        // "recuperación" real es contactar soporte, no reintentar). Este
        // formulario solo tiene `reason` (opcional) — ninguno de estos errores
        // es sobre ese campo, así que los 4 comparten la misma clave de
        // negocio, `cancel`, mismo patrón que `accept`/`reschedule`/
        // `confirmPayment`.
        if ($lesson->status !== 'scheduled') {
            throw ValidationException::withMessages([
                'cancel' => 'Solo se pueden cancelar clases programadas.',
            ]);
        }

        $data = request()->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $lesson = DB::transaction(function () use ($lesson, $user, $data) {
            $lesson = Lesson::with(['student.parent', 'teacherProfile.user', 'classRequest'])
                ->whereKey($lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lesson->status !== 'scheduled') {
                throw ValidationException::withMessages([
                    'cancel' => 'Solo se pueden cancelar clases programadas.',
                ]);
            }

            $teacherProfile = TeacherProfile::whereKey($lesson->teacher_profile_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Se devuelve exactamente lo reservado en su momento (ledger), no lo
            // que costaría hoy — protege contra una reprogramación posterior que
            // haya cambiado duration_minutes. Ver Lesson::reservedCreditAmount().
            //
            // reservedCreditAmount() lanza RuntimeException si el ledger no
            // respalda exactamente 1 reserva (anomalía real, ej. una clase
            // legacy NO_LEDGER) — se convierte en un error real de negocio en
            // vez de dejar un 500 crudo al padre/profesor que solo quería
            // cancelar.
            try {
                $creditsToRefund = $lesson->reservedCreditAmount();
            } catch (\RuntimeException $e) {
                report($e);
                throw ValidationException::withMessages([
                    'cancel' => 'Esta clase tiene una anomalía financiera y no se puede cancelar automáticamente. Contacta a soporte.',
                ]);
            }

            if ($teacherProfile->credits_reserved < $creditsToRefund) {
                throw ValidationException::withMessages([
                    'cancel' => 'No hay créditos reservados suficientes para devolver esta clase.',
                ]);
            }

            $profileUpdates = [
                'credits_available' => $teacherProfile->credits_available + $creditsToRefund,
                'credits_reserved' => $teacherProfile->credits_reserved - $creditsToRefund,
            ];

            if ($lesson->classRequest?->is_mentorship) {
                $profileUpdates['mentorship_slots_taken'] = max(
                    0,
                    $teacherProfile->mentorship_slots_taken - 1
                );
            }

            $teacherProfile->update($profileUpdates);

            $teacherProfile->creditTransactions()->create([
                'idempotency_key' => "lesson:{$lesson->id}:release",
                'lesson_id' => $lesson->id,
                'type' => 'refund',
                'amount' => $creditsToRefund,
                'description' => 'Devolución por clase cancelada',
            ]);

            $lesson->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => $data['reason'] ?? null,
            ]);

            ClassEvent::log('class_cancelled', $user->id, $lesson->id, $lesson->class_request_id, $data['reason'] ?? null);

            return $lesson;
        });

        $notification = new ClassCancelledNotification($lesson);

        // Notify the other party (not the one who cancelled)
        if (! $isTeacher && $lesson->teacherProfile?->user) {
            $lesson->teacherProfile->user->notify($notification);
        }
        if (! $isParent && $lesson->student?->parent) {
            $lesson->student->parent->notify($notification);
        }
        // Admin cancels → notify both
        if ($isAdmin) {
            $lesson->teacherProfile?->user?->notify($notification);
            $lesson->student?->parent?->notify($notification);
        }

        return back()->with('success', 'Clase cancelada correctamente.');
    }

    // C-2 v1: reschedule() solo mueve start_time. Antes también aceptaba
    // duration_minutes sin recalcular price_frozen_pen ni los créditos ya
    // reservados — un padre podía ampliar una clase de 30min a 4h pagando y
    // consumiendo lo de 30min. Cambiar la duración de forma segura requiere
    // recalcular costo, créditos y ledger con aceptación explícita de ambas
    // partes; ese flujo económico queda fuera de esta v1 (ver v2 futura).
    public function reschedule(Lesson $lesson)
    {
        $user = auth()->user();
        $profile = $user->teacherProfile;
        $this->authorize('reschedule', $lesson);

        // Used below to word the notification from the right party's perspective.
        $isTeacher = $profile && $lesson->teacher_profile_id === $profile->id;

        // Mismo hallazgo/clasificación que el Final Integrity Gate de
        // Accept.vue: el estado de la lección ya no es un problema de
        // ningún campo del formulario (start_time/reason) — es un
        // conflicto de estado del recurso, recuperable (el usuario puede
        // entender por qué y actuar en consecuencia), así que usa
        // `ValidationException` con una clave de negocio propia
        // (`reschedule`), no atada a `start_time` para no sugerir que la
        // hora elegida es el problema. Antes era `abort_unless(422)` — el
        // mismo patrón de fallo silencioso ya corregido 3 veces antes esta
        // sesión, confirmado aquí empíricamente (expectsJson()===false para
        // un POST normal, y los tests existentes solo comprobaban
        // assertStatus(422), nunca el mensaje real).
        if ($lesson->status !== 'scheduled') {
            throw ValidationException::withMessages([
                'reschedule' => 'Solo se pueden reprogramar clases programadas.',
            ]);
        }

        // Rechazo explícito, no silencioso: si el campo llega (con cualquier
        // valor, incluso igual al actual) se informa por qué no se aplicó, en
        // vez de ignorarlo y dejar que el cliente crea que sí tuvo efecto.
        // Este SÍ se ata a `duration_minutes` — a diferencia del caso de
        // arriba, el mensaje es genuinamente sobre ese campo concreto que el
        // cliente envió, no un error de negocio disfrazado de error de campo.
        if (request()->has('duration_minutes')) {
            throw ValidationException::withMessages([
                'duration_minutes' => 'No puedes cambiar la duración de una clase agendada. Contacta al profesor.',
            ]);
        }

        $data = request()->validate([
            'start_time' => 'required|date|after:now',
            'reason' => 'nullable|string|max:500',
        ]);
        $data['start_time'] = Carbon::parse($data['start_time'])->utc()->toIso8601String();

        $lesson = DB::transaction(function () use ($lesson, $user, $data) {
            // No confiamos en la instancia cargada antes de la transacción:
            // otra petición pudo cancelarla o reprogramarla mientras esta
            // request estaba en vuelo.
            $lesson = Lesson::with(['student.parent', 'teacherProfile.user'])
                ->whereKey($lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lesson->status !== 'scheduled') {
                // Re-chequeo bajo lock: mismo razonamiento que el de arriba.
                throw ValidationException::withMessages([
                    'reschedule' => 'Solo se pueden reprogramar clases programadas.',
                ]);
            }

            // Reutiliza el mismo chequeo de solapamiento (con lock) que usa
            // store() para aceptar clases nuevas, en vez de una segunda query
            // inline independiente — así ambos flujos protegen la agenda del
            // profesor con la misma estrategia. duration_minutes es el de la
            // propia lesson (inmutable en v1), no un valor del request.
            if ($this->hasScheduleOverlap(
                $lesson->teacher_profile_id,
                $data['start_time'],
                $lesson->duration_minutes,
                true,
                $lesson->id
            )) {
                throw ValidationException::withMessages([
                    'start_time' => 'El profesor ya tiene una clase en ese horario.',
                ]);
            }

            $originalStart = $lesson->original_start_time ?? $lesson->start_time;

            $lesson->update([
                'start_time' => $data['start_time'],
                'original_start_time' => $originalStart,
                'rescheduled_at' => now(),
                'rescheduled_by' => $user->id,
                'reschedule_reason' => $data['reason'] ?? null,
            ]);

            ClassEvent::log('class_rescheduled', $user->id, $lesson->id, $lesson->class_request_id, $data['reason'] ?? null, [
                'new_start_time' => $data['start_time'],
                'original_start' => $originalStart,
            ]);

            return $lesson;
        });

        $changedByName = $isTeacher ? 'el profesor' : 'el padre/tutor';
        $notification = new ClassRescheduledNotification($lesson, $changedByName);

        // Notify both parties
        $lesson->teacherProfile?->user?->notify($notification);
        $lesson->student?->parent?->notify($notification);

        return back()->with('success', 'Clase reprogramada correctamente.');
    }

    // $excludeLessonId: al reprogramar una clase, su propia fila candidatea
    // contra su horario ACTUAL (aún no actualizado dentro de la transacción),
    // así que debe excluirse o se detectaría como solapada consigo misma.
    // store() no lo necesita (la lesson todavía no existe al chequear).
    private function hasScheduleOverlap(
        int $teacherProfileId,
        string $startTime,
        int $durationMinutes,
        bool $lock = false,
        ?int $excludeLessonId = null
    ): bool {
        $requestedStart = Carbon::parse($startTime);
        $requestedEnd = $requestedStart->copy()->addMinutes($durationMinutes);
        $query = Lesson::where('teacher_profile_id', $teacherProfileId)
            ->where('status', 'scheduled')
            ->where('start_time', '<', $requestedEnd)
            ->when($excludeLessonId, fn ($q) => $q->where('id', '!=', $excludeLessonId));

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()->contains(
            fn (Lesson $lesson) => $lesson->end_time->gt($requestedStart)
        );
    }
}
