<?php

namespace App\Http\Controllers;

use App\Events\ClassRequestCreated;
use App\Models\ClassEvent;
use App\Models\ClassOffer;
use App\Models\ClassRequest;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Notifications\ClassRequestRejectedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ClassRequestController extends Controller
{
    public function create(Request $request)
    {
        $offer = $request->offer_id ? ClassOffer::with(['subject', 'teacherProfile.user'])->findOrFail($request->offer_id) : null;

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
        auth()->user()->students()->findOrFail($data['student_id']);

        $status = auth()->user()->parental_control ? 'pending_parent_approval' : 'open';
        $data['is_mentorship'] = (bool) ($data['is_mentorship'] ?? false);

        // Resolución real del código — nunca confía en lo que devolvió
        // lookupTeacherByCode() al frontend, esto es la garantía.
        $teacherProfileId = null;
        $rawCode = trim((string) ($data['teacher_referral_code'] ?? ''));
        if ($rawCode !== '') {
            $code = strtoupper($rawCode);
            $teacherProfile = TeacherProfile::where('referral_code', $code)
                ->where('is_verified', true)
                ->first();

            abort_unless($teacherProfile, 422, 'Código de profesor no encontrado.');
            $teacherProfileId = $teacherProfile->id;
        }

        if ($data['is_mentorship'] && !empty($data['class_offer_id'])) {
            $teacherProfile = ClassOffer::with('teacherProfile')
                ->findOrFail($data['class_offer_id'])
                ->teacherProfile;

            abort_unless(
                $teacherProfile?->hasAvailableMentorshipSlots(),
                422,
                'Este profesor tiene la agenda llena para acompañamiento continuo.'
            );
        }

        $classRequest = new ClassRequest($data);
        $classRequest->status = $status;
        // teacher_profile_id/teacher_referral_code quedan fuera de $fillable
        // a propósito (ver ClassRequest.php) — asignación directa, solo tras
        // la validación de arriba.
        $classRequest->teacher_profile_id = $teacherProfileId;
        $classRequest->teacher_referral_code = $teacherProfileId ? strtoupper($rawCode) : null;
        $classRequest->save();

        event(new ClassRequestCreated($classRequest));

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

        $rejected = ClassRequest::where('status', 'teacher_rejected')
            ->visibleToTeacher($profile->id, $offerIds, $subjectIds)
            ->with(['student', 'subject'])
            ->latest('teacher_rejected_at')
            ->take(10)
            ->get();

        return Inertia::render('ClassRequests/TeacherIndex', [
            'requests'         => $open,
            'rejectedRequests' => $rejected,
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

        $profile = auth()->user()->teacherProfile;

        return Inertia::render('ClassRequests/Accept', [
            'classRequest' => $classRequest->load(['student', 'subject']),
            'creditsAvailable' => $profile->credits_available ?? 0,
            'hourlyRate' => (float) ($profile->hourly_rate ?? 0),
        ]);
    }
}
