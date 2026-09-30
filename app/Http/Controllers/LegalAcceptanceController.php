<?php

namespace App\Http\Controllers;

use App\Models\LegalAcceptance;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** C-P1-LEGAL-REACCEPTANCE — aceptar las versiones vigentes de Términos/Privacidad. */
class LegalAcceptanceController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $missing = LegalAcceptance::missingCurrentDocuments($request->user());

        if ($missing === [] || $request->user()->hasRole('admin')) {
            return redirect()->intended(route('dashboard'));
        }

        return Inertia::render('Legal/Accept', [
            'documents' => $missing,
            'versions'  => config('legal.versions'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(
            ['accepted' => ['accepted']],
            ['accepted.accepted' => 'Debes aceptar los Términos y la Política de Privacidad vigentes para continuar.'],
        );

        // Lock de la fila del usuario: un doble envío no duplica aceptaciones.
        DB::transaction(function () use ($request) {
            User::whereKey($request->user()->id)->lockForUpdate()->first();
            LegalAcceptance::recordMissingCurrent($request->user(), $request);
        });

        return redirect()->intended(route('dashboard'));
    }
}
