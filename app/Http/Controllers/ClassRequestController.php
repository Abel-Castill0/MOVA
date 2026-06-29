<?php

namespace App\Http\Controllers;

use App\Events\ClassRequestCreated;
use App\Models\ClassEvent;
use App\Models\ClassOffer;
use App\Models\ClassRequest;
use App\Models\Subject;
use App\Notifications\ClassRequestRejectedNotification;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClassRequestController extends Controller
{
    public function create(Request $request)
    {
        $offer = $request->offer_id ? ClassOffer::with(['subject', 'teacherProfile.user'])->findOrFail($request->offer_id) : null;
        return Inertia::render('ClassRequests/Create', [
            'subjects' => Subject::orderBy('name')->get(),
            'students' => auth()->user()->students()->get(),
            'offer' => $offer,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'subject_id' => 'required|exists:subjects,id',
            'class_offer_id' => 'nullable|exists:class_offers,id',
            'help_needed' => 'required|string|max:2000',
            'preferred_times' => 'nullable|array',
        ]);

        // Ensure the student belongs to the authenticated parent
        auth()->user()->students()->findOrFail($data['student_id']);

        $status = auth()->user()->parental_control ? 'pending_parent_approval' : 'open';

        $classRequest = ClassRequest::create(array_merge($data, ['status' => $status]));

        event(new ClassRequestCreated($classRequest));

        return redirect()->route('class-requests.index')->with('success', 'Solicitud enviada.');
    }

    public function index()
    {
        $studentIds = auth()->user()->students()->pluck('id');
        return Inertia::render('ClassRequests/Index', [
            'requests' => ClassRequest::whereIn('student_id', $studentIds)
                ->with(['student', 'subject', 'classOffer.teacherProfile.user'])
                ->latest()->get(),
        ]);
    }

    public function approve(ClassRequest $classRequest)
    {
        $studentIds = auth()->user()->students()->pluck('id');
        abort_unless($studentIds->contains($classRequest->student_id), 403);
        $classRequest->update(['status' => 'open']);
        return back()->with('success', 'Solicitud aprobada.');
    }

    public function reject(ClassRequest $classRequest)
    {
        $studentIds = auth()->user()->students()->pluck('id');
        abort_unless($studentIds->contains($classRequest->student_id), 403);
        $classRequest->update(['status' => 'rejected']);
        return back()->with('success', 'Solicitud rechazada.');
    }

    public function teacherIndex()
    {
        $profile    = auth()->user()->teacherProfile;
        $offerIds   = $profile->classOffers()->pluck('id');
        $subjectIds = $profile->subjects()->pluck('subjects.id');

        $open = ClassRequest::where('status', 'open')
            ->where(function ($q) use ($offerIds, $subjectIds) {
                $q->whereIn('class_offer_id', $offerIds)
                  ->orWhere(function ($inner) use ($subjectIds) {
                      $inner->whereNull('class_offer_id')
                            ->whereIn('subject_id', $subjectIds);
                  });
            })
            ->with(['student', 'subject', 'classOffer'])
            ->latest()->get();

        $rejected = ClassRequest::where('status', 'teacher_rejected')
            ->where(function ($q) use ($offerIds, $subjectIds) {
                $q->whereIn('class_offer_id', $offerIds)
                  ->orWhere(function ($inner) use ($subjectIds) {
                      $inner->whereNull('class_offer_id')
                            ->whereIn('subject_id', $subjectIds);
                  });
            })
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
        $profile = auth()->user()->teacherProfile;
        abort_unless($profile, 403);

        // Only requests linked to this teacher's offers or matching subjects
        $offerIds   = $profile->classOffers()->pluck('id');
        $subjectIds = $profile->subjects()->pluck('subjects.id');
        $ownedViaOffer   = $classRequest->class_offer_id && $offerIds->contains($classRequest->class_offer_id);
        $ownedViaSubject = !$classRequest->class_offer_id && $subjectIds->contains($classRequest->subject_id);
        abort_unless($ownedViaOffer || $ownedViaSubject, 403);

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
        return Inertia::render('ClassRequests/Accept', [
            'classRequest' => $classRequest->load(['student', 'subject']),
        ]);
    }
}
