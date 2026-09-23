<?php

namespace App\Http\Controllers;

use App\Models\Complaint;
use App\Notifications\ComplaintFiledNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * P0-K — Libro de Reclamaciones virtual: formulario público (con o sin
 * sesión) y gestión administrativa. El texto legal y los datos del
 * proveedor vienen de config/legal.php; aquí solo hay soporte técnico.
 */
class ComplaintController extends Controller
{
    public function create(Request $request)
    {
        return Inertia::render('Legal/Complaints', [
            'provider'      => $this->provider(),
            'responseDays'  => (int) config('legal.complaint_response_days'),
            'prefill'       => $request->user() ? [
                'consumer_name' => $request->user()->name,
                'email'         => $request->user()->email,
                'phone'         => $request->user()->phone,
            ] : null,
            'filedCode'     => $request->session()->get('complaint_code'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type'             => ['required', Rule::in(Complaint::TYPES)],
            'consumer_name'    => ['required', 'string', 'max:150'],
            'document_type'    => ['required', Rule::in(Complaint::DOCUMENT_TYPES)],
            'document_number'  => ['required', 'string', 'regex:/^[A-Za-z0-9-]{6,20}$/'],
            'address'          => ['required', 'string', 'max:255'],
            'phone'            => ['nullable', 'string', 'max:20'],
            'email'            => ['required', 'email', 'max:150'],
            'is_minor'         => ['boolean'],
            'guardian_name'    => ['nullable', 'required_if:is_minor,true', 'string', 'max:150'],
            'good_type'        => ['required', Rule::in(Complaint::GOOD_TYPES)],
            'amount'           => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'good_description' => ['required', 'string', 'max:255'],
            'detail'           => ['required', 'string', 'min:10', 'max:5000'],
            'consumer_request' => ['required', 'string', 'min:5', 'max:2000'],
            'accepted'         => ['accepted'],
        ], [
            'guardian_name.required_if' => 'Indica el nombre del padre, madre o apoderado.',
            'accepted.accepted'         => 'Debes declarar que la información es verídica.',
        ]);

        unset($data['accepted']);
        $complaint = Complaint::file([
            ...$data,
            'is_minor' => (bool) ($data['is_minor'] ?? false),
            'user_id'  => $request->user()?->id,
            'ip'       => $request->ip(),
        ]);

        Log::info('complaint.filed', ['complaint_id' => $complaint->id, 'code' => $complaint->code]);

        // Constancia al consumidor (copia de la hoja). SafeMailChannel: un
        // fallo de correo no invalida el registro ya guardado.
        Notification::route('mail', $complaint->email)->notify(new ComplaintFiledNotification($complaint));

        return redirect()->route('complaints.create')->with('complaint_code', $complaint->code);
    }

    // ── Admin ────────────────────────────────────────────────────────────

    public function adminIndex(Request $request)
    {
        $status = $request->query('status');

        return Inertia::render('Admin/Complaints', [
            'complaints' => Complaint::query()
                ->when(in_array($status, ['open', 'answered'], true), fn ($q) => $q->where('status', $status))
                ->with('responder:id,name')
                ->latest()
                ->paginate(20)
                ->withQueryString(),
            'status'       => $status,
            'responseDays' => (int) config('legal.complaint_response_days'),
        ]);
    }

    public function respond(Request $request, Complaint $complaint)
    {
        $data = $request->validate(['response' => ['required', 'string', 'min:10', 'max:5000']]);

        abort_if($complaint->status === 'answered', 409, 'Este reclamo ya fue respondido.');

        $complaint->forceFill([
            'status'       => 'answered',
            'response'     => $data['response'],
            'responded_at' => now(),
            'responded_by' => $request->user()->id,
        ])->save();

        Notification::route('mail', $complaint->email)->notify(new ComplaintFiledNotification($complaint, answered: true));

        return back()->with('success', "Respuesta registrada para {$complaint->code}.");
    }

    private function provider(): array
    {
        return [
            'business_name' => config('legal.provider.business_name'),
            'ruc'           => config('legal.provider.ruc'),
            'address'       => config('legal.provider.address'),
            'support_email' => config('legal.support_email'),
        ];
    }
}
