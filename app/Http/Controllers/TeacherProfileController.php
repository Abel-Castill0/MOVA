<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TeacherProfileController extends Controller
{
    public function setup()
    {
        return Inertia::render('Teacher/Setup', [
            'subjects' => Subject::forPicker(),
            'profile' => auth()->user()->teacherProfile,
            'selectedSubjectIds' => auth()->user()->teacherProfile?->subjects()->pluck('subjects.id') ?? [],
        ]);
    }

    public function storeSetup(Request $request)
    {
        $profile = auth()->user()->teacherProfile;

        $data = $request->validate([
            'bio' => 'nullable|string|max:1000',
            'mentorship_slots_total' => 'required|integer|min:0|max:50',
            ...self::subjectRules(),
        ], self::SUBJECT_MESSAGES);

        DB::transaction(function () use ($profile, $data) {
            $profile->update([
                'bio' => $data['bio'] ?? null,
                'mentorship_slots_total' => $data['mentorship_slots_total'],
            ]);
            $this->syncSubjects($profile, $data['subject_ids']);
        });

        return redirect()->route('dashboard')->with('success', 'Perfil configurado.');
    }

    public function edit()
    {
        $profile = auth()->user()->teacherProfile()->with('subjects')->first();
        return Inertia::render('Teacher/Edit', [
            'profile' => $profile,
            'subjects' => Subject::forPicker(),
            // Franjas semanales declaradas (hora de Lima), en HH:MM.
            'availability' => $profile?->availabilitySlots->map(fn ($slot) => [
                'day_of_week' => $slot->day_of_week,
                'start_time' => substr($slot->start_time, 0, 5),
                'end_time' => substr($slot->end_time, 0, 5),
            ])->values() ?? [],
            'availabilityMaxSlots' => \App\Models\TeacherAvailabilitySlot::MAX_SLOTS,
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
            ...self::subjectRules(),
        ], self::SUBJECT_MESSAGES);

        DB::transaction(function () use ($profile, $data) {
            $profile->update([
                'bio' => $data['bio'] ?? null,
                'yape_number' => $data['yape_number'] ?? null,
                'plin_number' => $data['plin_number'] ?? null,
                'mentorship_slots_total' => $data['mentorship_slots_total'],
            ]);
            $this->syncSubjects($profile, $data['subject_ids']);
        });

        return redirect()->route('teacher.profile')->with('success', 'Perfil actualizado.');
    }

    /** Máximo de materias por profesor (el catálogo es corto; evita perfiles que lo marcan todo). */
    public const MAX_SUBJECTS = 15;

    public const SUBJECT_MESSAGES = [
        'subject_ids.required' => 'Elige al menos una materia del catálogo.',
        'subject_ids.min' => 'Elige al menos una materia del catálogo.',
        'subject_ids.max' => 'Puedes elegir hasta 15 materias.',
        'subject_ids.*.integer' => 'Elige las materias de la lista.',
        'subject_ids.*.distinct' => 'Elegiste la misma materia dos veces.',
        'subject_ids.*.exists' => 'Una de las materias elegidas no existe en el catálogo.',
        'subject_names.prohibited' => 'Las materias se eligen de la lista; ya no se pueden escribir.',
        'teacher_subject_names.prohibited' => 'Las materias se eligen de la lista; ya no se pueden escribir.',
    ];

    /**
     * Catálogo cerrado: solo ids de materias que ya existen. Cualquier texto libre (en subject_ids o en los antiguos
     * campos de nombres) se rechaza en la validación, antes de escribir nada.
     *
     * @return array<string, mixed>
     */
    public static function subjectRules(string $requiredRule = 'required', string $legacyField = 'subject_names'): array
    {
        return [
            'subject_ids' => [$requiredRule, 'array', 'min:1', 'max:'.self::MAX_SUBJECTS],
            'subject_ids.*' => ['integer', 'distinct', self::catalogSubjectExists()],
            $legacyField => ['prohibited'],
        ];
    }

    /** Existe Y pertenece al catálogo maestro (las filas antiguas de texto libre, sin categoría, no se pueden elegir). */
    public static function catalogSubjectExists(): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('subjects', 'id')->whereNotNull('category');
    }

    private function syncSubjects($profile, array $subjectIds): void
    {
        $profile->subjects()->sync(
            collect($subjectIds)->map(fn ($id) => (int) $id)->unique()
                ->mapWithKeys(fn (int $id) => [$id => ['specific_rate' => null]])
                ->all()
        );
    }
}
