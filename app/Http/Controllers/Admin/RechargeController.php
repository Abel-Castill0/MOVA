<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Notifications\RechargeApprovedNotification;
use App\Notifications\RechargeRejectedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class RechargeController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Recharges/Index', [
            'recharges' => RechargeRequest::with('teacherProfile.user')
                ->latest()
                ->paginate(30)
                ->withQueryString(),
        ]);
    }

    public function approve(RechargeRequest $recharge)
    {
        abort_if($recharge->status !== 'pending', 422, 'Esta recarga ya fue revisada.');

        $recharge = DB::transaction(function () use ($recharge) {
            $recharge = RechargeRequest::whereKey($recharge->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_if($recharge->status !== 'pending', 422, 'Esta recarga ya fue revisada.');

            $teacherProfile = TeacherProfile::whereKey($recharge->teacher_profile_id)
                ->lockForUpdate()
                ->firstOrFail();

            $teacherProfile->update([
                'credits_available' => $teacherProfile->credits_available + $recharge->credits,
            ]);

            $teacherProfile->creditTransactions()->create([
                'type' => 'deposit',
                'amount' => $recharge->credits,
                'description' => 'Recarga de paquete: ' . $recharge->package_name,
            ]);

            $recharge->update(['status' => 'approved']);

            return $recharge->load('teacherProfile.user');
        });

        $recharge->teacherProfile?->user?->notify(new RechargeApprovedNotification($recharge));

        return back()->with('success', 'Recarga aprobada y créditos abonados correctamente.');
    }

    public function reject(Request $request, RechargeRequest $recharge)
    {
        abort_if($recharge->status !== 'pending', 422, 'Esta recarga ya fue revisada.');

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $recharge = DB::transaction(function () use ($recharge) {
            $recharge = RechargeRequest::whereKey($recharge->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_if($recharge->status !== 'pending', 422, 'Esta recarga ya fue revisada.');

            $recharge->update(['status' => 'rejected']);

            return $recharge->load('teacherProfile.user');
        });

        $recharge->teacherProfile?->user?->notify(
            new RechargeRejectedNotification($recharge, $data['reason'] ?? null)
        );

        return back()->with('success', 'Recarga rechazada correctamente.');
    }
}
