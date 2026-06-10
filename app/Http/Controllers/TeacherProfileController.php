<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TeacherProfileController extends Controller
{
    public function setup()
    {
        return Inertia::render('Teacher/Setup', [
            'subjects' => Subject::orderBy('name')->get(),
        ]);
    }

    public function storeSetup(Request $request)
    {
        $data = $request->validate([
            'bio' => 'nullable|string|max:1000',
            'hourly_rate' => 'required|numeric|min:0',
            'subject_ids' => 'required|array|min:1',
            'subject_ids.*' => 'exists:subjects,id',
        ]);

        $profile = auth()->user()->teacherProfile;
        $profile->update([
            'bio' => $data['bio'] ?? null,
            'hourly_rate' => $data['hourly_rate'],
        ]);

        $sync = collect($data['subject_ids'])->mapWithKeys(fn($id) => [$id => ['specific_rate' => null]]);
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
        $data = $request->validate([
            'bio' => 'nullable|string|max:1000',
            'hourly_rate' => 'required|numeric|min:0',
            'subject_ids' => 'required|array|min:1',
            'subject_ids.*' => 'exists:subjects,id',
        ]);

        $profile = auth()->user()->teacherProfile;
        $profile->update([
            'bio' => $data['bio'] ?? null,
            'hourly_rate' => $data['hourly_rate'],
        ]);

        $sync = collect($data['subject_ids'])->mapWithKeys(fn($id) => [$id => ['specific_rate' => null]]);
        $profile->subjects()->sync($sync);

        return redirect()->route('teacher.profile')->with('success', 'Perfil actualizado.');
    }
}
