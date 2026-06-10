<?php

namespace App\Http\Controllers;

use App\Events\ClassRequestCreated;
use App\Models\ClassOffer;
use App\Models\ClassRequest;
use App\Models\Subject;
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
        $subjectIds = auth()->user()->teacherProfile->subjects()->pluck('subjects.id');
        return Inertia::render('ClassRequests/TeacherIndex', [
            'requests' => ClassRequest::whereIn('subject_id', $subjectIds)
                ->where('status', 'open')
                ->with(['student', 'subject'])
                ->latest()->get(),
        ]);
    }

    public function accept(ClassRequest $classRequest)
    {
        return Inertia::render('ClassRequests/Accept', [
            'classRequest' => $classRequest->load(['student', 'subject']),
        ]);
    }
}
