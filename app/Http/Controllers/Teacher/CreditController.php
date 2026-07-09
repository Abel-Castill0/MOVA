<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\RechargeRequest;
use App\Models\User;
use App\Notifications\NewRechargeRequestNotification;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CreditController extends Controller
{
    private const PACKAGES = [
        'Inicio' => ['credits' => 5, 'amount_pen' => '10.00'],
        'Impulso' => ['credits' => 15, 'amount_pen' => '30.00'],
        'Pro' => ['credits' => 30, 'amount_pen' => '60.00'],
    ];

    public function index()
    {
        $teacherProfile = auth()->user()->teacherProfile;

        abort_unless($teacherProfile, 403, 'No tienes perfil de profesor.');

        return Inertia::render('Teacher/Credits/Index', [
            'teacherProfile' => [
                'id' => $teacherProfile->id,
                'credits_available' => $teacherProfile->credits_available,
                'credits_reserved' => $teacherProfile->credits_reserved,
            ],
            'creditTransactions' => $teacherProfile->creditTransactions()
                ->latest()
                ->limit(20)
                ->get(['id', 'type', 'amount', 'description', 'created_at']),
            'rechargeRequests' => $teacherProfile->rechargeRequests()
                ->latest()
                ->limit(20)
                ->get(['id', 'package_name', 'credits', 'amount_pen', 'operation_number', 'status', 'created_at']),
        ]);
    }

    public function storeRecharge(Request $request)
    {
        $teacherProfile = $request->user()->teacherProfile;

        abort_unless($teacherProfile, 403, 'No tienes perfil de profesor.');

        $data = $request->validate([
            'package_name' => ['required', 'string', Rule::in(array_keys(self::PACKAGES))],
            'credits' => ['required', 'integer', Rule::in([5, 15, 30])],
            'amount_pen' => ['required', 'numeric', Rule::in([10, 30, 60])],
            'operation_number' => ['required', 'string', 'min:4', 'max:80'],
        ]);

        $package = self::PACKAGES[$data['package_name']];

        abort_if(
            (int) $data['credits'] !== $package['credits'] || number_format((float) $data['amount_pen'], 2, '.', '') !== $package['amount_pen'],
            422,
            'El paquete seleccionado no coincide con el monto enviado.'
        );

        $recharge = RechargeRequest::create([
            'teacher_profile_id' => $teacherProfile->id,
            'package_name' => $data['package_name'],
            'credits' => $package['credits'],
            'amount_pen' => $package['amount_pen'],
            'operation_number' => $data['operation_number'],
            'status' => 'pending',
        ]);

        $recharge->load('teacherProfile.user');
        User::role('admin')->get()->each(function (User $admin) use ($recharge) {
            $admin->notify(new NewRechargeRequestNotification($recharge));
        });

        return redirect()
            ->route('teacher.credits.index')
            ->with('success', 'Solicitud de recarga enviada. El administrador la validará pronto.');
    }
}
