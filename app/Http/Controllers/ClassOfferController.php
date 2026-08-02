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
        $profile = auth()->user()->teacherProfile;
        $maxRate = $profile->maxAllowedRate();

        $data = $request->validate([
            'subject_id'                         => 'required|exists:subjects,id',
            'title'                              => 'required|string|max:200',
            'description'                        => 'nullable|string|max:2000',
            'availability_schedule'              => 'nullable|array',
            'availability_schedule.timezone'     => 'nullable|string|max:50',
            'availability_schedule.days'         => 'nullable|array',
            'availability_schedule.days.*'       => 'array',
            'availability_schedule.days.*.*.start' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'availability_schedule.days.*.*.end'   => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'specific_rate'                      => 'nullable|numeric|min:0|max:' . $maxRate,
        ], [
            'specific_rate.max' => "Por ahora puede ofertar hasta S/ {$maxRate} según su nivel actual (clases completadas y calificación promedio).",
        ]);

        $data = $this->normalizeAvailability($data);

        $profile->classOffers()->create($data);

        return redirect()->route('class-offers.index')->with('success', 'Oferta creada.');
    }

    public function edit(ClassOffer $classOffer)
    {
        $this->authorize('update', $classOffer);
        return Inertia::render('ClassOffers/Edit', [
            'offer' => $classOffer,
            'subjects' => Subject::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, ClassOffer $classOffer)
    {
        $this->authorize('update', $classOffer);
        $maxRate = auth()->user()->teacherProfile->maxAllowedRate();

        $data = $request->validate([
            'subject_id'                         => 'required|exists:subjects,id',
            'title'                              => 'required|string|max:200',
            'description'                        => 'nullable|string|max:2000',
            'availability_schedule'              => 'nullable|array',
            'availability_schedule.timezone'     => 'nullable|string|max:50',
            'availability_schedule.days'         => 'nullable|array',
            'availability_schedule.days.*'       => 'array',
            'availability_schedule.days.*.*.start' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'availability_schedule.days.*.*.end'   => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'specific_rate'                      => 'nullable|numeric|min:0|max:' . $maxRate,
        ], [
            'specific_rate.max' => "Por ahora puede ofertar hasta S/ {$maxRate} según su nivel actual (clases completadas y calificación promedio).",
        ]);

        $data = $this->normalizeAvailability($data);

        $classOffer->update($data);

        return redirect()->route('class-offers.index')->with('success', 'Oferta actualizada.');
    }

    public function toggleActive(ClassOffer $classOffer)
    {
        $this->authorize('update', $classOffer);
        $classOffer->update(['is_active' => !$classOffer->is_active]);
        return back();
    }

    /**
     * Ensure availability_schedule is null or has the canonical structure.
     * Strips slots where start >= end to prevent invalid data.
     */
    private function normalizeAvailability(array $data): array
    {
        $schedule = $data['availability_schedule'] ?? null;
        if (!$schedule || empty($schedule['days'])) {
            $data['availability_schedule'] = null;
            return $data;
        }

        $allDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $normalized = ['timezone' => $schedule['timezone'] ?? 'America/Lima', 'days' => []];

        foreach ($allDays as $day) {
            $slots = $schedule['days'][$day] ?? [];
            $validSlots = [];
            foreach ($slots as $slot) {
                $start = $slot['start'] ?? '';
                $end   = $slot['end']   ?? '';
                if ($start && $end && $start < $end) {
                    $validSlots[] = ['start' => $start, 'end' => $end];
                }
            }
            $normalized['days'][$day] = array_values($validSlots);
        }

        // If all days are empty, treat as no schedule
        $hasAnySlot = array_filter($normalized['days'], fn ($s) => !empty($s));
        $data['availability_schedule'] = $hasAnySlot ? $normalized : null;

        return $data;
    }

    public function destroy(ClassOffer $classOffer)
    {
        $this->authorize('delete', $classOffer);
        $classOffer->delete();
        return redirect()->route('class-offers.index')->with('success', 'Oferta eliminada.');
    }
}
