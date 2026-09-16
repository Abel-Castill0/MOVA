<?php

namespace App\Http\Controllers;

use App\Events\ClassRequestCreated;
use App\Models\ClassEvent;
use App\Models\ClassOffer;
use App\Models\ClassRequest;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Notifications\ClassRequestRejectedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ClassRequestController extends Controller
{
    public function create(Request $request)
    {
        // Encontrado durante el cierre de la familia P0 de exposición
        // pública (docs/MOVA_DESIGN_AUDIT_FINAL.md): esta ruta exige
        // `role:parent`, no es pública — pero CUALQUIER padre autenticado
        // puede pasar `?offer_id=N` (IDs secuenciales, fáciles de
        // enumerar) y antes recibía el `ClassOffer` completo con
        // `teacherProfile.user` SIN ninguna restricción de columnas.
        // Verificado con json_encode() antes de corregir: filtraba
        // yape_number/plin_number/referral_code del profesor (además de
        // lo que TeacherProfile::$hidden ya cubre) y el `User` completo
        // del profesor — email, phone, phone_verification_code_hash,
        // suspension_reason, etc. — a un padre que no tiene ninguna
        // relación todavía con ese profesor. Mismo patrón de raíz que
        // MarketplaceController/WelcomeController/AdminController: una
        // relación cargada sin proyección explícita. Create.vue solo lee
        // subject.name, teacher_profile.hourly_rate y
        // teacher_profile.user.name (verificado leyendo la plantilla).
        $offer = $request->offer_id
            ? ClassOffer::select(['id', 'teacher_profile_id', 'subject_id', 'specific_rate'])
                ->with(['subject:id,name', 'teacherProfile:id,user_id,hourly_rate', 'teacherProfile.user:id,name'])
                ->findOrFail($request->offer_id)
            : null;

        // ?code=ABC123 desde "Solicitar clase" en el marketplace — prellena
        // el input, pero el valor real sigue resolviéndose en store() contra
        // la BD; esto es solo conveniencia de UI.
        $prefillCode = $request->filled('code') ? strtoupper(trim((string) $request->query('code'))) : null;

        // ?subject_id=N desde las tarjetas de "Materias disponibles" de la
        // landing (Welcome.vue) — antes enlazaban a un marketplace con
        // filtro por materia que ya no existe; ahora abren directo el
        // formulario con la materia elegida, sin preseleccionar profesor.
        $prefillSubjectId = $request->filled('subject_id') && ! $offer
            ? Subject::where('id', $request->query('subject_id'))->value('id')
            : null;

        return Inertia::render('ClassRequests/Create', [
            'subjects' => Subject::orderBy('name')->get(),
            'students' => auth()->user()->students()->get(),
            'offer' => $offer,
            'isMentorship' => $request->boolean('is_mentorship'),
            'prefillReferralCode' => $prefillCode,
            'prefillSubjectId' => $prefillSubjectId,
        ]);
    }

    /**
     * Búsqueda en vivo para el input "Código del profesor" — el padre ve a
     * quién le llegaría la solicitud ANTES de enviarla. Solo lectura, sin
     * side effects; la resolución real (la que de verdad importa) vuelve a
     * pasar por aquí mismo dentro de store(), nunca confía en lo que el
     * frontend ya validó.
     */
    public function lookupTeacherByCode(Request $request)
    {
        $code = strtoupper(trim((string) $request->query('code', '')));

        $profile = $code !== '' ? TeacherProfile::where('referral_code', $code)
            ->where('is_verified', true)
            ->with('user')
            ->first() : null;

        if (! $profile) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found' => true,
            'name' => $profile->user->name,
        ]);
    }

    /**
     * Ventana de deduplicación: un reintento de red (no un doble-click —
     * eso ya lo cubre `form.processing` en Create.vue) que reenvía
     * exactamente la misma intención debe colapsar en UNA sola solicitud,
     * no crear una segunda. 30s cubre con margen cualquier timeout/retry
     * de red real; no bloquea que el mismo padre pida la misma materia
     * otra vez minutos después, que es una intención distinta y legítima.
     */
    private const DUPLICATE_SUBMISSION_WINDOW_SECONDS = 30;

    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'subject_id' => 'required|exists:subjects,id',
            'class_offer_id' => 'nullable|exists:class_offers,id',
            'teacher_referral_code' => 'nullable|string|max:6',
            'is_mentorship' => 'sometimes|boolean',
            'help_needed' => 'required|string|max:2000',
            'preferred_times' => 'nullable|array',
        ]);

        // Ensure the student belongs to the authenticated parent
        $student = auth()->user()->students()->findOrFail($data['student_id']);

        $status = auth()->user()->parental_control ? 'pending_parent_approval' : 'open';
        $data['is_mentorship'] = (bool) ($data['is_mentorship'] ?? false);

        // Resolución real del código — nunca confía en lo que devolvió
        // lookupTeacherByCode() al frontend, esto es la garantía.
        //
        // Encontrado al preparar el rediseño de Create.vue, verificado en
        // vivo antes de asumir nada: este `abort_unless(..., 422, $msg)`
        // (y los dos de abajo) NUNCA llegaban al frontend como un error de
        // formulario. `abort()` lanza una HttpException genérica — sin
        // `ValidationException`, Inertia no la reconoce como error de
        // formulario y Laravel devuelve su página de error HTML genérica
        // ("Oops! An Error Occurred"), no una respuesta Inertia. Confirmado
        // con una request real: `session('errors')` quedaba `null`, el
        // mensaje en español se perdía por completo — `form.errors.
        // teacher_referral_code` en Create.vue era código muerto. Mismo
        // bug en los tres casos, mismo archivo, mismo formulario que se
        // está rediseñando — se corrige aquí. `ValidationException::
        // withMessages()` es el mecanismo real que Inertia sí traduce a
        // `form.errors` — ya usado correctamente para `start_time` en
        // `LessonController::store()`.
        $teacherProfileId = null;
        $rawCode = trim((string) ($data['teacher_referral_code'] ?? ''));
        if ($rawCode !== '') {
            $code = strtoupper($rawCode);
            $teacherProfile = TeacherProfile::where('referral_code', $code)
                ->where('is_verified', true)
                ->first();

            if (! $teacherProfile) {
                throw ValidationException::withMessages([
                    'teacher_referral_code' => 'Código de profesor no encontrado.',
                ]);
            }
            $teacherProfileId = $teacherProfile->id;
        }

        // P2 → RESOLVED (docs/MOVA_DESIGN_AUDIT_FINAL.md): antes solo se
        // validaba `is_active`/`is_verified` de la oferta en el camino de
        // mentoría — una solicitud normal podía quedar vinculada a una
        // oferta inactiva o de un profesor no verificado y nacer ya
        // muerta (nadie autorizado podría aceptarla nunca). Se valida
        // temprano para las DOS rutas, no solo mentoría, y se reutiliza
        // el mismo offer/teacherProfile ya cargado para el check de cupos.
        $offerTeacherProfile = null;
        if (! empty($data['class_offer_id'])) {
            $offer = ClassOffer::with('teacherProfile')->findOrFail($data['class_offer_id']);
            $offerTeacherProfile = $offer->teacherProfile;

            if (! ($offer->is_active && $offerTeacherProfile?->is_verified)) {
                throw ValidationException::withMessages([
                    'class_offer_id' => 'Esta oferta ya no está disponible.',
                ]);
            }

            if ($data['is_mentorship'] && ! $offerTeacherProfile->hasAvailableMentorshipSlots()) {
                throw ValidationException::withMessages([
                    'is_mentorship' => 'Este profesor tiene la agenda llena para acompañamiento continuo.',
                ]);
            }
        }

        // P2 → RESOLVED: dedup de intención repetida (reintento de red,
        // no doble-click — Create.vue ya deshabilita el botón mientras
        // `form.processing`, eso no cubre un timeout/reconexión real que
        // reenvía el mismo POST). Se serializa por el Student — es el
        // recurso más específico ya validado como propio del padre, y
        // ninguna solicitud legítima distinta puede compartir exactamente
        // los mismos 6 campos de intención para el mismo alumno en 30s.
        // No se añade columna/idempotency-key nueva: se reutiliza el
        // propio contenido de la solicitud como huella de intención.
        $classRequest = DB::transaction(function () use ($student, $data, $status, $teacherProfileId, $rawCode) {
            Student::whereKey($student->id)->lockForUpdate()->first();

            $duplicate = ClassRequest::where('student_id', $data['student_id'])
                ->where('subject_id', $data['subject_id'])
                ->where('help_needed', $data['help_needed'])
                ->where('is_mentorship', $data['is_mentorship'])
                ->where('class_offer_id', $data['class_offer_id'] ?? null)
                ->where('teacher_profile_id', $teacherProfileId)
                ->where('created_at', '>=', now()->subSeconds(self::DUPLICATE_SUBMISSION_WINDOW_SECONDS))
                ->first();

            if ($duplicate) {
                return $duplicate;
            }

            $classRequest = new ClassRequest($data);
            $classRequest->status = $status;
            // teacher_profile_id/teacher_referral_code quedan fuera de
            // $fillable a propósito (ver ClassRequest.php) — asignación
            // directa, solo tras la validación de arriba.
            $classRequest->teacher_profile_id = $teacherProfileId;
            $classRequest->teacher_referral_code = $teacherProfileId ? strtoupper($rawCode) : null;
            $classRequest->save();

            event(new ClassRequestCreated($classRequest));

            return $classRequest;
        });

        return redirect()->route('class-requests.index')->with('success', 'Solicitud enviada.');
    }

    public function index()
    {
        $studentIds = auth()->user()->students()->pluck('id');
        return Inertia::render('ClassRequests/Index', [
            'requests' => ClassRequest::whereIn('student_id', $studentIds)
                ->with(['student', 'subject', 'classOffer.teacherProfile.user', 'teacherProfile.user'])
                ->latest()->get(),
        ]);
    }

    public function approve(ClassRequest $classRequest)
    {
        $this->authorize('view', $classRequest);

        DB::transaction(function () use ($classRequest) {
            $classRequest = ClassRequest::whereKey($classRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorize('view', $classRequest);
            abort_unless(
                $classRequest->status === 'pending_parent_approval',
                422,
                'Solo se pueden aprobar solicitudes pendientes de aprobación.'
            );

            $classRequest->update(['status' => 'open']);
        });

        return back()->with('success', 'Solicitud aprobada.');
    }

    public function reject(ClassRequest $classRequest)
    {
        $this->authorize('view', $classRequest);

        DB::transaction(function () use ($classRequest) {
            $classRequest = ClassRequest::whereKey($classRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorize('view', $classRequest);
            abort_unless(
                $classRequest->status === 'pending_parent_approval',
                422,
                'Solo se pueden rechazar solicitudes pendientes de aprobación.'
            );

            $classRequest->update(['status' => 'rejected']);
        });

        return back()->with('success', 'Solicitud rechazada.');
    }

    public function teacherIndex()
    {
        $profile    = auth()->user()->teacherProfile;
        $offerIds   = $profile->classOffers()->pluck('id');
        $subjectIds = $profile->subjects()->pluck('subjects.id');

        $open = ClassRequest::where('status', 'open')
            ->visibleToTeacher($profile->id, $offerIds, $subjectIds)
            ->with(['student', 'subject', 'classOffer'])
            ->latest()->get();

        // Solicitudes que ESTE profesor ya contraofreció y están esperando
        // respuesta del padre. Solo se muestran las propias del profesor
        // logueado (counteroffer_teacher_profile_id === $profile->id).
        $counteroffered = ClassRequest::where('status', 'counteroffered')
            ->where('counteroffer_teacher_profile_id', $profile->id)
            ->with(['student', 'subject'])
            ->latest()
            ->get();

        $rejected = ClassRequest::where('status', 'teacher_rejected')
            ->visibleToTeacher($profile->id, $offerIds, $subjectIds)
            ->with(['student', 'subject'])
            ->latest('teacher_rejected_at')
            ->take(10)
            ->get();

        return Inertia::render('ClassRequests/TeacherIndex', [
            'requests'             => $open,
            'counterofdRequests'   => $counteroffered,
            'rejectedRequests'     => $rejected,
        ]);
    }

    public function teacherReject(ClassRequest $classRequest)
    {
        $this->authorize('reject', $classRequest);
        abort_unless($classRequest->status === 'open', 422, 'Solo se pueden rechazar solicitudes abiertas.');

        $data = request()->validate([
            'reason' => 'required|string|min:10|max:500',
        ]);

        $classRequest->load(['student.parent', 'subject']);
        $classRequest->update([
            'status'                     => 'teacher_rejected',
            'teacher_rejected_at'        => now(),
            'teacher_rejection_reason'   => $data['reason'],
        ]);

        ClassEvent::log('request_rejected', auth()->id(), null, $classRequest->id, $data['reason']);

        if ($classRequest->student?->parent) {
            $classRequest->student->parent->notify(new ClassRequestRejectedNotification($classRequest));
        }

        return back()->with('success', 'Solicitud rechazada. El padre ha sido notificado.');
    }

    public function accept(ClassRequest $classRequest)
    {
        $this->authorize('accept', $classRequest);

        // Hallazgo de revisión, distinto del anterior (proyección de
        // columnas): `ClassRequestPolicy::accept()` solo comprueba
        // ELEGIBILIDAD (verificado, materia/oferta, código de referido) —
        // nunca el `status` de la solicitud. Antes de este chequeo, un
        // profesor podía cargar este GET para una solicitud YA aceptada
        // (por él mismo o por otro) y recibir el payload completo del
        // alumno igual que si siguiera abierta; el POST ya bloqueaba
        // aceptar de nuevo, pero el ciclo de vida del recurso importa tanto
        // como la autorización de la acción — un profesor que ya no puede
        // (ni debe poder) actuar sobre esta solicitud tampoco necesita
        // seguir viendo los datos del alumno. Confirmado en vivo antes de
        // este fix: GET sobre una solicitud ya `accepted` devolvía 200 con
        // el nombre/apellido/grado del alumno intactos.
        if ($classRequest->status !== 'open') {
            return redirect()->route('teacher.requests')
                ->with('error', 'Esta solicitud ya no está disponible — probablemente otro profesor la aceptó primero.');
        }

        $profile = auth()->user()->teacherProfile;

        // Proyección explícita: `Accept.vue` solo lee subject.name y
        // student.first_name/last_name (verificado leyendo el archivo, no
        // supuesto). `Student` tiene `birth_date` y `school` — datos reales
        // de un menor que este profesor todavía ni siquiera aceptó — sin
        // ninguna columna elegida aquí, `->load(['student','subject'])`
        // serializaba la fila completa al cliente igual que el hallazgo ya
        // corregido en ClassRequestController::create() (?offer_id=), mismo
        // patrón de proyección implícita, encontrado ahora en un endpoint
        // distinto. `parent_user_id` se mantiene (es solo un id numérico,
        // sin nombre/contacto, y no carga la relación `parent`).
        $classRequest->load([
            'student:id,parent_user_id,first_name,last_name,grade_level',
            'subject:id,name',
        ]);

        return Inertia::render('ClassRequests/Accept', [
            'classRequest' => $classRequest,
            'creditsAvailable' => $profile->credits_available ?? 0,
            'hourlyRate' => (float) ($profile->hourly_rate ?? 0),
        ]);
    }
}
