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

    /**
     * F-18 — Antes hacía `$student->delete()` sin comprobar nada, lo que con
     * historial académico daba un error 500 (FK RESTRICT en MySQL) o destruía
     * las clases del alumno (CASCADE en SQLite/tests).
     *
     * Ahora se aplica el mismo criterio que al dar de baja una cuenta
     * (ProfileController::destroy): si hay historial, se anonimiza en vez de
     * borrar. El alumno desaparece de la vista del padre y sus datos
     * personales se sustituyen, pero las clases dictadas —que respaldan
     * movimientos del ledger— siguen siendo coherentes.
     */
    public function destroy(Student $student)
    {
        $this->authorize('delete', $student);

        // Con historial: se anonimizan los datos personales del menor ANTES del
        // soft delete, para que la fila que sobrevive no siga conteniendo su
        // nombre, fecha de nacimiento ni colegio.
        if ($student->hasAcademicHistory()) {
            $student->anonymize();
        }

        // Soft delete: desaparece de los listados del padre, pero la fila
        // permanece y las clases que la referencian siguen siendo coherentes.
        $student->delete();

        return redirect()->route('students.index')->with('success', 'Estudiante eliminado.');
    }
}
