<?php

namespace App\Http\Controllers;

use App\Models\ClassOffer;
use App\Models\Subject;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClassOfferController extends Controller
{
    public function index()
    {
        $profile = auth()->user()->teacherProfile;
        return Inertia::render('ClassOffers/Index', [
            'offers' => $profile->classOffers()->with('subject')->latest()->get(),
        ]);
    }

    public function create()
    {
        return Inertia::render('ClassOffers/Create', [
            'subjects' => Subject::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'subject_id' => 'required|exists:subjects,id',
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
            'availability_schedule' => 'nullable|array',
            'specific_rate' => 'nullable|numeric|min:0',
        ]);

        auth()->user()->teacherProfile->classOffers()->create($data);

        return redirect()->route('class-offers.index')->with('success', 'Oferta creada.');
    }

    public function edit(ClassOffer $classOffer)
    {
        abort_unless($classOffer->teacher_profile_id === auth()->user()->teacherProfile->id, 403);
        return Inertia::render('ClassOffers/Edit', [
            'offer' => $classOffer,
            'subjects' => Subject::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, ClassOffer $classOffer)
    {
        abort_unless($classOffer->teacher_profile_id === auth()->user()->teacherProfile->id, 403);

        $data = $request->validate([
            'subject_id' => 'required|exists:subjects,id',
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
            'availability_schedule' => 'nullable|array',
            'specific_rate' => 'nullable|numeric|min:0',
        ]);

        $classOffer->update($data);

        return redirect()->route('class-offers.index')->with('success', 'Oferta actualizada.');
    }

    public function toggleActive(ClassOffer $classOffer)
    {
        abort_unless($classOffer->teacher_profile_id === auth()->user()->teacherProfile->id, 403);
        $classOffer->update(['is_active' => !$classOffer->is_active]);
        return back();
    }

    public function destroy(ClassOffer $classOffer)
    {
        abort_unless($classOffer->teacher_profile_id === auth()->user()->teacherProfile->id, 403);
        $classOffer->delete();
        return redirect()->route('class-offers.index')->with('success', 'Oferta eliminada.');
    }
}
