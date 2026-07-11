<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\RechargeRequest;
use App\Models\User;
use App\Notifications\NewRechargeRequestNotification;
use App\Support\OperationNumberNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CreditController extends Controller
{
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
                ->get([
                    'id', 'package_code', 'package_name', 'credits', 'amount_pen',
                    'payment_method', 'operation_number', 'status', 'rejection_reason', 'created_at',
                ]),
            'packages' => collect(config('credits.packages'))
                ->map(fn (array $package, string $code) => ['code' => $code] + $package)
                ->values(),
            'paymentMethods' => config('credits.payment_methods'),
            'rechargesEnabled' => $this->rechargesEnabled(),
            'paymentDestination' => $this->rechargesEnabled()
                ? config('credits.recharges.payment_destination')
                : null,
        ]);
    }

    public function storeRecharge(Request $request)
    {
        $teacherProfile = $request->user()->teacherProfile;

        abort_unless($teacherProfile, 403, 'No tienes perfil de profesor.');
        abort_unless(
            $this->rechargesEnabled(),
            503,
            'Las recargas se habilitarán próximamente.'
        );

        $packages = config('credits.packages');
        $paymentMethods = config('credits.payment_methods');

        $data = $request->validate([
            'package_code' => ['required', 'string', Rule::in(array_keys($packages))],
            'payment_method' => ['required', 'string', Rule::in(array_keys($paymentMethods))],
            'operation_number' => ['required', 'string', 'min:4', 'max:80'],
        ]);

        $normalizedOperation = OperationNumberNormalizer::normalize($data['operation_number']);

        if (mb_strlen($normalizedOperation, 'UTF-8') < 4) {
            throw ValidationException::withMessages([
                'operation_number' => 'Ingrese un número de operación válido.',
            ]);
        }

        $duplicate = RechargeRequest::where('operation_number_normalized', $normalizedOperation)
            ->where(function ($query) use ($data) {
                $query->where('payment_method', $data['payment_method'])
                    ->orWhere('payment_method', 'legacy');
            })
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'operation_number' => 'Este número de operación ya fue registrado.',
            ]);
        }

        $package = $packages[$data['package_code']];

        try {
            $recharge = DB::transaction(function () use ($teacherProfile, $data, $package, $normalizedOperation) {
                return RechargeRequest::create([
                    'teacher_profile_id' => $teacherProfile->id,
                    'package_code' => $data['package_code'],
                    'package_name' => $package['name'],
                    'credits' => $package['credits'],
                    'amount_pen' => $package['amount_pen'],
                    'payment_method' => $data['payment_method'],
                    'operation_number' => trim($data['operation_number']),
                    'operation_number_normalized' => $normalizedOperation,
                    'status' => 'pending',
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'operation_number' => 'Este número de operación ya fue registrado.',
            ]);
        }

        $recharge->load('teacherProfile.user');
        User::role('admin')->get()->each(function (User $admin) use ($recharge) {
            $admin->notify(new NewRechargeRequestNotification($recharge));
        });

        return redirect()
            ->route('teacher.credits.index')
            ->with('success', 'Solicitud de recarga enviada. El administrador la validará pronto.');
    }

    private function rechargesEnabled(): bool
    {
        return (bool) config('credits.recharges.enabled')
            && filled(config('credits.recharges.payment_destination'));
    }
}
