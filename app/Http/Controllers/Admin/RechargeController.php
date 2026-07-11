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
            'recharges' => RechargeRequest::with(['teacherProfile.user', 'reviewer'])
                ->latest()
                ->paginate(30)
                ->withQueryString(),
        ]);
    }

    public function approve(RechargeRequest $recharge)
    {
        $reviewerId = auth()->id();

        $result = DB::transaction(function () use ($recharge, $reviewerId) {
            $recharge = RechargeRequest::whereKey($recharge->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($recharge->status === 'approved') {
                return ['recharge' => $recharge->load('teacherProfile.user'), 'changed' => false];
            }

            abort_if($recharge->status === 'rejected', 422, 'Una recarga rechazada no puede aprobarse.');

            $teacherProfile = TeacherProfile::whereKey($recharge->teacher_profile_id)
                ->lockForUpdate()
                ->firstOrFail();

            $transaction = $teacherProfile->creditTransactions()->firstOrCreate([
                'idempotency_key' => "recharge:{$recharge->id}:deposit",
            ], [
                'recharge_request_id' => $recharge->id,
                'type' => 'deposit',
                'amount' => $recharge->credits,
                'description' => 'Recarga de paquete: ' . $recharge->package_name,
            ]);

            if ($transaction->wasRecentlyCreated) {
                $teacherProfile->update([
                    'credits_available' => $teacherProfile->credits_available + $recharge->credits,
                ]);
            }

            $recharge->update([
                'status' => 'approved',
                'reviewed_at' => now(),
                'reviewed_by' => $reviewerId,
                'approved_at' => now(),
                'rejected_at' => null,
                'rejection_reason' => null,
            ]);

            return ['recharge' => $recharge->load('teacherProfile.user'), 'changed' => true];
        });

        if ($result['changed']) {
            $result['recharge']->teacherProfile?->user?->notify(
                new RechargeApprovedNotification($result['recharge'])
            );
        }

        return back()->with(
            'success',
            $result['changed']
                ? 'Recarga aprobada y créditos abonados correctamente.'
                : 'La recarga ya estaba aprobada; no se abonaron créditos adicionales.'
        );
    }

    public function reject(Request $request, RechargeRequest $recharge)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $reviewerId = $request->user()->id;

        $result = DB::transaction(function () use ($recharge, $data, $reviewerId) {
            $recharge = RechargeRequest::whereKey($recharge->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($recharge->status === 'rejected') {
                return ['recharge' => $recharge->load('teacherProfile.user'), 'changed' => false];
            }

            abort_if($recharge->status === 'approved', 422, 'Una recarga aprobada no puede rechazarse.');

            $recharge->update([
                'status' => 'rejected',
                'reviewed_at' => now(),
                'reviewed_by' => $reviewerId,
                'approved_at' => null,
                'rejected_at' => now(),
                'rejection_reason' => $data['reason'],
            ]);

            return ['recharge' => $recharge->load('teacherProfile.user'), 'changed' => true];
        });

        if ($result['changed']) {
            $result['recharge']->teacherProfile?->user?->notify(
                new RechargeRejectedNotification($result['recharge'], $data['reason'])
            );
        }

        return back()->with(
            'success',
            $result['changed'] ? 'Recarga rechazada correctamente.' : 'La recarga ya estaba rechazada.'
        );
    }
}
