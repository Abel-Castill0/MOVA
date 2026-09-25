<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OperationalAlert;
use App\Services\OperationalAlertService;
use App\Support\Heartbeat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Centro de Operaciones del admin.
 *
 * Responde a una sola pregunta: "¿hay algo que necesite mi atención ahora?".
 *
 * NO es una segunda fuente de verdad. PaymentOrder sigue mandando sobre el
 * pago, Lesson sobre la clase y el ledger sobre el dinero. Una OperationalAlert
 * solo dice "esto necesita atención"; para actuar, esta pantalla NAVEGA al
 * recurso canónico en vez de reimplementar su lógica aquí.
 */
class OperationsController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): Response
    {
        $filters = [
            'status' => in_array($request->query('status'), ['open', 'resolved', 'all'], true)
                ? $request->query('status')
                : 'open',
            'severity' => in_array($request->query('severity'), [
                OperationalAlert::SEVERITY_CRITICAL,
                OperationalAlert::SEVERITY_WARNING,
            ], true) ? $request->query('severity') : null,
            'category' => in_array($request->query('category'), [
                OperationalAlert::CATEGORY_FINANCE,
                OperationalAlert::CATEGORY_LESSON,
                OperationalAlert::CATEGORY_SYSTEM,
            ], true) ? $request->query('category') : null,
        ];

        return Inertia::render('Admin/Operations/Index', [
            'summary' => $this->summary(),
            'alerts' => $this->alerts($filters),
            'capabilities' => $this->capabilities(),
            'filters' => $filters,
        ]);
    }

    /**
     * Cierre manual de una incidencia.
     *
     * SE RECHAZA POR DEFECTO. Solo pasan las incidencias que ningún camino del
     * código cierra automáticamente (ver
     * OperationalAlert::MANUALLY_CLOSABLE_KEY_PATTERNS). Si la fuente de verdad
     * puede demostrar que el problema desapareció, debe cerrarla ella: dar un
     * botón manual ahí sería ofrecer una forma de silenciar algo que sigue roto.
     *
     * El guard vive aquí, en el backend, no en si la UI pinta el botón.
     */
    public function close(Request $request, OperationalAlert $alert, OperationalAlertService $alerts)
    {
        abort_unless(
            $alert->isManuallyClosable(),
            422,
            'Esta incidencia se cierra sola cuando el problema desaparece. Corrige el recurso afectado en su propia pantalla.'
        );

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ], [
            'reason.required' => 'Explica por qué se cierra esta incidencia.',
            'reason.min' => 'El motivo debe tener al menos 10 caracteres.',
            'reason.max' => 'El motivo no puede superar los 500 caracteres.',
        ]);

        $closed = $alerts->closeManually($alert, $request->user(), $data['reason']);

        if (! $closed) {
            return back()->with('error', 'Esa incidencia ya estaba cerrada.');
        }

        return back()->with('success', 'Incidencia cerrada. El recurso afectado no ha cambiado.');
    }

    /**
     * Contadores de atención. Una sola consulta agregada, no tres COUNT.
     */
    private function summary(): array
    {
        $rows = OperationalAlert::query()
            ->open()
            ->selectRaw('severity, COUNT(*) as total')
            ->groupBy('severity')
            ->pluck('total', 'severity');

        $critical = (int) $rows->get(OperationalAlert::SEVERITY_CRITICAL, 0);
        $warning = (int) $rows->get(OperationalAlert::SEVERITY_WARNING, 0);

        return [
            'critical_open' => $critical,
            'warning_open' => $warning,
            'total_open' => $critical + $warning,
        ];
    }

    private function alerts(array $filters)
    {
        $query = OperationalAlert::query()
            ->when($filters['status'] === 'open', fn ($q) => $q->whereNull('resolved_at'))
            ->when($filters['status'] === 'resolved', fn ($q) => $q->whereNotNull('resolved_at'))
            ->when($filters['severity'], fn ($q, $s) => $q->where('severity', $s))
            ->when($filters['category'], fn ($q, $c) => $q->ofCategory($c))
            // Sin resolver primero y, dentro de cada grupo, lo más reciente
            // arriba: es el orden en el que un admin quiere leerlo.
            ->orderByRaw('CASE WHEN resolved_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('last_detected_at')
            // Sólo la relación que la pantalla pinta, y sólo sus columnas:
            // evita el N+1 al mostrar quién cerró cada incidencia.
            ->with('resolvedBy:id,name');

        return $query->paginate(self::PER_PAGE)->withQueryString()->through(function (OperationalAlert $alert) {
            $action = $alert->actionRoute();

            return [
                'id' => $alert->id,
                // `type_label` es lo que ve el admin; `type` NO se envía: es una
                // clave interna y la pantalla no debe tener siquiera la
                // tentación de pintarla.
                'type_label' => $alert->typeLabel(),
                'category' => $alert->category(),
                'severity' => $alert->severity,
                'title' => $alert->title,
                'message' => $alert->message,
                'context' => $alert->safeContext(),
                'occurrences' => $alert->occurrences,
                'first_detected_at' => $alert->first_detected_at?->toIso8601String(),
                'last_detected_at' => $alert->last_detected_at?->toIso8601String(),
                'resolved_at' => $alert->resolved_at?->toIso8601String(),
                // Fallback al snapshot: el usuario puede haberse borrado.
                'resolved_by' => $alert->resolvedBy?->name ?? $alert->resolved_by_name,
                'can_close' => $alert->isManuallyClosable(),
                'resolution_note' => $alert->resolution_note,
                'action' => $action === null ? null : [
                    'label' => $action['label'],
                    'url' => route($action['name']),
                ],
            ];
        });
    }

    /**
     * Estado por capacidad — SIN VERDES FALSOS.
     *
     * La regla es que `healthy` exige una señal POSITIVA, no la ausencia de
     * malas noticias. "No hay alertas de WhatsApp" no significa que WhatsApp
     * funcione: significa que MOVA no está mirando. En ese caso el estado es
     * `unknown`, que es información útil (nadie vigila esto) en vez de una
     * mentira tranquilizadora.
     *
     * Hoy la única capacidad con señal positiva real es la cola: failed_jobs
     * se puede contar y un cero significa algo. El scheduler no tiene
     * heartbeat, y correo/WhatsApp/pagos no tienen sonda: si no hay incidencia
     * abierta, su estado honesto es `unknown`.
     */
    private function capabilities(): array
    {
        $openByCategory = OperationalAlert::query()
            ->open()
            ->selectRaw('type, COUNT(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $anyOpen = fn (array $types) => collect($types)->sum(fn ($t) => (int) $openByCategory->get($t, 0)) > 0;

        $capabilities = [];

        // Cola: señal positiva real y barata.
        try {
            $failed = DB::table('failed_jobs')->count();
            $capabilities[] = [
                'key' => 'queue',
                'label' => 'Cola de trabajos',
                'status' => $failed > 0 ? 'attention' : 'healthy',
                'detail' => $failed > 0
                    ? "{$failed} job(s) fallidos sin procesar"
                    : 'Sin trabajos fallidos pendientes',
            ];
        } catch (\Throwable $e) {
            // No poder contar no es lo mismo que estar sano.
            Log::warning('[Operations] No se pudo leer failed_jobs.', ['error' => $e->getMessage()]);
            $capabilities[] = [
                'key' => 'queue',
                'label' => 'Cola de trabajos',
                'status' => 'unknown',
                'detail' => 'No se pudo consultar failed_jobs',
            ];
        }

        // P0-J — scheduler y worker tienen ahora señal POSITIVA (latido).
        foreach ([Heartbeat::SCHEDULER => 'Scheduler', Heartbeat::WORKER => 'Worker de cola'] as $name => $label) {
            try {
                $age = Heartbeat::age($name);
                $status = Heartbeat::status($name);
            } catch (\Throwable $e) {
                Log::warning('[Operations] No se pudo leer system_heartbeats.', ['error' => $e->getMessage()]);
                [$age, $status] = [null, 'unknown'];
            }
            // En producción "nunca latió" = el proceso no corre: atención, no
            // un gris neutro. Fuera de producción es normal no tener worker.
            $neverInProduction = $status === 'unknown' && app()->environment('production');
            $capabilities[] = [
                'key' => $name,
                'label' => $label,
                'status' => ($status === 'stale' || $neverInProduction) ? 'attention' : $status,
                'detail' => match ($status) {
                    'healthy' => "Último latido hace {$age} s",
                    'stale' => 'Sin latido hace '.intdiv((int) $age, 60).' min',
                    default => $neverInProduction ? 'Nunca ha registrado latido: el proceso no está corriendo' : 'Nunca ha registrado latido',
                },
            ];
        }

        // El detalle DEBE explicar el estado que se muestra. Antes decía
        // "Pagos deshabilitados por configuración" incluso cuando había una
        // incidencia abierta y el estado era `attention`: dos frases
        // contradictorias en la misma línea.
        $describe = fn (bool $attention, string $whenAttention, string $whenQuiet) => $attention
            ? $whenAttention
            : $whenQuiet;

        $paymentsAttention = $anyOpen([
            OperationalAlert::TYPE_PAYMENT_REVIEW,
            OperationalAlert::TYPE_WEBHOOK_REVIEW,
            OperationalAlert::TYPE_RECONCILIATION_FAILURE,
        ]);
        $capabilities[] = [
            'key' => 'payments',
            'label' => 'Pagos',
            'status' => $paymentsAttention ? 'attention' : 'unknown',
            'detail' => $describe(
                $paymentsAttention,
                'Hay pagos esperando una decisión manual',
                config('payments.enabled')
                    ? 'Sin sonda activa: solo se reportan incidencias detectadas'
                    : 'Deshabilitados por configuración; sin sonda activa'
            ),
        ];

        $ledgerAttention = $anyOpen([OperationalAlert::TYPE_LEDGER_ANOMALY]);
        $capabilities[] = [
            'key' => 'ledger',
            'label' => 'Integridad del ledger',
            'status' => $ledgerAttention ? 'attention' : 'unknown',
            'detail' => $describe(
                $ledgerAttention,
                'La conciliación encontró saldos que no cuadran',
                'Se verifica en el barrido diario de conciliación'
            ),
        ];

        $lessonsAttention = $anyOpen([OperationalAlert::TYPE_LESSON_NEEDS_REVIEW]);
        $capabilities[] = [
            'key' => 'lessons',
            'label' => 'Clases',
            'status' => $lessonsAttention ? 'attention' : 'unknown',
            'detail' => $describe(
                $lessonsAttention,
                'Hay clases que quedaron esperando una decisión',
                'Solo se reportan clases que quedaron esperando decisión'
            ),
        ];

        $systemAttention = $anyOpen([OperationalAlert::TYPE_HEALTH_CHECK]);
        $capabilities[] = [
            'key' => 'system',
            'label' => 'Configuración y entorno',
            'status' => $systemAttention ? 'attention' : 'unknown',
            'detail' => $describe(
                $systemAttention,
                'El chequeo encontró ajustes que conviene revisar',
                'Depende de que mova:health-check esté ejecutándose'
            ),
        ];

        return $capabilities;
    }
}
