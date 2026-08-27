<?php

namespace App\Support;

use App\Models\WhatsAppMessage;
use App\WhatsApp\WhatsAppMessageStatus;
use Illuminate\Support\Carbon;

/**
 * Observabilidad de entregas de WhatsApp — mismo espíritu que
 * App\Support\LedgerReconciliation: SOLO LECTURA, nunca corrige nada, solo
 * señala qué necesita revisión humana.
 *
 * No hay nada que "cuadrar" contra un segundo sistema (a diferencia del
 * ledger, que se compara contra classes/teacher_profiles) — Meta es la
 * única fuente de verdad externa y MOVA no la consulta activamente todavía
 * (eso requeriría credenciales reales y una API de consulta de estado por
 * wamid, fuera de alcance sin cuenta Meta). Lo que SÍ se puede hacer sin
 * eso: encontrar mensajes en un estado que no debería durar.
 *
 * Política explícita para 'unknown' (antes ausente — un mensaje 'unknown'
 * se señalaba de inmediato, sin darle tiempo a que el webhook real de Meta
 * lo resuelva, lo que hubiera hecho ruidoso el reporte con mensajes
 * enviados hace 10 segundos):
 *   - unknown, edad < threshold  → normal, todavía "esperando webhook".
 *     Aparece en el reporte pero NO cuenta para 'needs_attention'.
 *   - unknown, edad >= threshold → señalado para revisión humana.
 * Nunca se transiciona automáticamente 'unknown' → 'failed': MOVA no sabe
 * si Meta lo aceptó, y no va a fingir que sí lo sabe.
 */
class WhatsAppReconciliation
{
    public function __construct(private readonly int $stuckSentMinutes = 60)
    {
    }

    public function run(): array
    {
        $counts = WhatsAppMessage::selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();
        // Los valores de 'status' vienen del enum cast como el objeto en
        // memoria pero de la query cruda como string — normalizamos a
        // string para que el reporte sea consistente sin importar el driver.
        $counts = collect($counts)->mapWithKeys(fn ($total, $status) => [
            ($status instanceof WhatsAppMessageStatus ? $status->value : (string) $status) => $total,
        ])->toArray();

        $threshold = Carbon::now()->subMinutes($this->stuckSentMinutes);

        $stuckInSent = WhatsAppMessage::where('status', WhatsAppMessageStatus::Sent->value)
            ->where('created_at', '<', $threshold)
            ->orderBy('created_at')
            ->get(['id', 'to', 'template_key', 'provider_message_id', 'client_reference', 'created_at'])
            ->map(fn ($m) => $this->summarize($m, 'stuck_in_sent'))
            ->all();

        $unknownOld = WhatsAppMessage::where('status', WhatsAppMessageStatus::Unknown->value)
            ->where('created_at', '<', $threshold)
            ->orderBy('created_at')
            ->get(['id', 'to', 'template_key', 'provider_message_id', 'client_reference', 'created_at'])
            ->map(fn ($m) => $this->summarize($m, 'unknown_delivery'))
            ->all();

        $unknownWaitingCount = WhatsAppMessage::where('status', WhatsAppMessageStatus::Unknown->value)
            ->where('created_at', '>=', $threshold)
            ->count();

        $needsAttention = array_merge($stuckInSent, $unknownOld);

        return [
            'total_messages' => array_sum($counts),
            'counts' => $counts,
            'stuck_sent_threshold_minutes' => $this->stuckSentMinutes,
            'unknown_still_waiting' => $unknownWaitingCount,
            'needs_attention' => $needsAttention,
            'healthy' => $needsAttention === [],
        ];
    }

    private function summarize(WhatsAppMessage $message, string $reason): array
    {
        return [
            'id' => $message->id,
            'to' => $message->to,
            'template_key' => $message->template_key,
            'provider_message_id' => $message->provider_message_id,
            'client_reference' => $message->client_reference,
            'created_at' => $message->created_at->toIso8601String(),
            'age_minutes' => (int) $message->created_at->diffInMinutes(now()),
            'reason' => $reason,
        ];
    }
}
