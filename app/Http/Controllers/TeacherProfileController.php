<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use App\Services\SubjectNormalizer;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TeacherProfileController extends Controller
{
    public function setup()
    {
        return Inertia::render('Teacher/Setup', [
            'subjects' => Subject::orderBy('name')->get(),
            'profile' => auth()->user()->teacherProfile,
        ]);
    }

    public function storeSetup(Request $request)
    {
        $profile = auth()->user()->teacherProfile;

        $data = $request->validate([
            'bio' => 'nullable|string|max:1000',
            'mentorship_slots_total' => 'required|integer|min:0|max:50',
            'subject_ids' => 'nullable|array',
            'subject_ids.*' => 'exists:subjects,id',
            'subject_names' => 'nullable|array',
            'subject_names.*' => 'nullable|string|max:100',
        ]);

        $profile->update([
            'bio' => $data['bio'] ?? null,
            'mentorship_slots_total' => $data['mentorship_slots_total'],
        ]);

        $subjectIds = $this->resolveSubjectIds($data);
        abort_if($subjectIds->isEmpty(), 422, 'Debe registrar al menos una materia.');

        $sync = $subjectIds->mapWithKeys(fn($id) => [$id => ['specific_rate' => null]]);
        $profile->subjects()->sync($sync);

        return redirect()->route('dashboard')->with('success', 'Perfil configurado.');
    }

    public function edit()
    {
        $profile = auth()->user()->teacherProfile()->with('subjects')->first();
        return Inertia::render('Teacher/Edit', [
            'profile' => $profile,
            'subjects' => Subject::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request)
    {
        $profile = auth()->user()->teacherProfile;

        $data = $request->validate([
            'bio' => 'nullable|string|max:1000',
            'yape_number' => 'nullable|string|max:20',
            'plin_number' => 'nullable|string|max:20',
            'mentorship_slots_total' => 'required|integer|min:0|max:50',
            'subject_ids' => 'nullable|array',
            'subject_ids.*' => 'exists:subjects,id',
            'subject_names' => 'nullable|array',
            'subject_names.*' => 'nullable|string|max:100',
        ]);

        $profile->update([
            'bio' => $data['bio'] ?? null,
            'yape_number' => $data['yape_number'] ?? null,
            'plin_number' => $data['plin_number'] ?? null,
            'mentorship_slots_total' => $data['mentorship_slots_total'],
        ]);

        $subjectIds = $this->resolveSubjectIds($data);
        abort_if($subjectIds->isEmpty(), 422, 'Debe registrar al menos una materia.');

        $sync = $subjectIds->mapWithKeys(fn($id) => [$id => ['specific_rate' => null]]);
        $profile->subjects()->sync($sync);

        return redirect()->route('teacher.profile')->with('success', 'Perfil actualizado.');
    }

    private function resolveSubjectIds(array $data)
    {
        $existingIds = collect($data['subject_ids'] ?? [])->filter()->values();
        $createdIds = collect($data['subject_names'] ?? [])
            ->map(fn($name) => trim((string) $name))
            ->filter()
            ->unique(fn($name) => SubjectNormalizer::normalize($name))
            ->map(fn($name) => Subject::firstOrCreateByName($name)->id);

        return $existingIds->merge($createdIds)->unique()->values();
    }
}
