<?php

namespace App\Http\Controllers;

use App\Models\Student;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StudentController extends Controller
{
    public function index()
    {
        return Inertia::render('Students/Index', [
            'students' => auth()->user()->students()->get(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Students/Create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'birth_date' => 'nullable|date',
            'grade_level' => 'required|in:primaria,secundaria,universidad',
            'school' => 'nullable|string|max:200',
        ]);

        auth()->user()->students()->create($data);

        return redirect()->route('dashboard')->with('success', 'Estudiante añadido.');
    }

    public function edit(Student $student)
    {
        $this->authorize('update', $student);
        return Inertia::render('Students/Edit', ['student' => $student]);
    }

    public function update(Request $request, Student $student)
    {
        $this->authorize('update', $student);

        $data = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'birth_date' => 'nullable|date',
            'grade_level' => 'required|in:primaria,secundaria,universidad',
            'school' => 'nullable|string|max:200',
        ]);

        $student->update($data);

        return redirect()->route('students.index')->with('success', 'Estudiante actualizado.');
    }

    public function destroy(Student $student)
    {
        $this->authorize('delete', $student);
        $student->delete();
        return redirect()->route('students.index')->with('success', 'Estudiante eliminado.');
    }
}
