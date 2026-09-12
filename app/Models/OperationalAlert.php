<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una incidencia operativa abierta o ya resuelta.
 *
 * Se escribe SIEMPRE a través de App\Services\OperationalAlertService — nunca
 * directamente desde un controller o un comando. Ese servicio es el que
 * garantiza la deduplicación y el aviso exactamente-una-vez; crear filas a mano
 * saltándoselo reintroduce el spam que esta tabla existe para evitar.
 */
class OperationalAlert extends Model
{
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    // Tipos conocidos. No es un enum de base de datos a propósito: aparecerán
    // tipos nuevos según crezca la operación, y un enum obligaría a una
    // migración por cada uno. La columna es un string indexado.
    public const TYPE_PAYMENT_REVIEW = 'payment_review';
    public const TYPE_WEBHOOK_REVIEW = 'webhook_review';
    public const TYPE_LESSON_NEEDS_REVIEW = 'lesson_needs_review';
    public const TYPE_LEDGER_ANOMALY = 'ledger_anomaly';
    public const TYPE_RECONCILIATION_FAILURE = 'reconciliation_failure';
    public const TYPE_HEALTH_CHECK = 'health_check';

    /**
     * Categorías del Centro de Operaciones.
     *
     * Se derivan de los productores que EXISTEN hoy, no de un catálogo
     * aspiracional. Por eso no hay categoría de "entregas" (mail/WhatsApp):
     * ningún productor levanta todavía una incidencia de ese tipo, y una
     * categoría que siempre está vacía enseña al admin a ignorar el panel.
     * Cuando exista el productor, se añade aquí.
     */
    public const CATEGORY_FINANCE = 'finance';
    public const CATEGORY_LESSON = 'lesson';
    public const CATEGORY_SYSTEM = 'system';

    /**
     * Qué categoría le corresponde a cada tipo.
     *
     * El criterio es "¿sobre qué tiene que decidir el admin?", no de qué
     * subsistema técnico salió. `reconciliation_failure` lo levanta
     * ProcessMercadoPagoWebhook al agotar reintentos: técnicamente es un fallo
     * de cola, pero lo que queda en el aire es un pago, así que vive en
     * finanzas junto al resto de decisiones sobre dinero.
     */
    private const TYPE_CATEGORIES = [
        self::TYPE_PAYMENT_REVIEW => self::CATEGORY_FINANCE,
        self::TYPE_WEBHOOK_REVIEW => self::CATEGORY_FINANCE,
        self::TYPE_LEDGER_ANOMALY => self::CATEGORY_FINANCE,
        self::TYPE_RECONCILIATION_FAILURE => self::CATEGORY_FINANCE,
        self::TYPE_LESSON_NEEDS_REVIEW => self::CATEGORY_LESSON,
        self::TYPE_HEALTH_CHECK => self::CATEGORY_SYSTEM,
    ];

    /**
     * Qué campos de `context` puede ver un admin, POR TIPO DE INCIDENCIA.
     *
     * ES UNA ALLOWLIST, NO UNA BLACKLIST, y la diferencia no es estilística.
     * La versión anterior descartaba claves cuyo nombre sonara peligroso
     * (`token`, `secret`, `payload`…). Eso falla con lo que no suena
     * peligroso: `ProcessMercadoPagoWebhook` mete
     * `'Error' => substr($exception->getMessage(), 0, 300)`, un mensaje de
     * excepción crudo que puede arrastrar una URL con query string o un trozo
     * de respuesta del proveedor, y la palabra "Error" no coincidía con ningún
     * patrón prohibido. Con una blacklist, cada productor nuevo es una fuga
     * potencial hasta que alguien se acuerda de ampliarla; con una allowlist,
     * un campo nuevo es invisible hasta que alguien decide que es seguro.
     *
     * Cada entrada se corresponde con un productor real (auditado uno a uno).
     * Lo que NO aparece aquí no sale a la UI, aunque exista en la fila.
     */
    private const CONTEXT_ALLOWLIST = [
        // HealthCheck.php — solo el entorno donde saltó el aviso.
        self::TYPE_HEALTH_CHECK => ['Entorno'],

        // ReconcileLedger.php (recuentos agregados) +
        // Admin\RechargeController.php (reversión manual bloqueada).
        self::TYPE_LEDGER_ANOMALY => [
            'Lecciones examinadas', 'Lecciones anómalas', 'Profesores descuadrados',
            'Recarga', 'Profesor (perfil)', 'Créditos de la recarga',
            'Saldo disponible', 'Motivo indicado',
        ],

        // SettleLessons.php — identificador de la clase y el motivo del escalado.
        self::TYPE_LESSON_NEEDS_REVIEW => ['Clase', 'Motivo'],

        // MercadoPagoPaymentReconciliationService.php. `Estado en el proveedor`
        // y `Detalle del proveedor` son enums de Mercado Pago
        // (p. ej. `cc_rejected_insufficient_amount`), no cuerpos de respuesta.
        self::TYPE_PAYMENT_REVIEW => [
            'Motivo', 'PaymentOrder', 'Recarga', 'Intento', 'PaymentWebhook',
            'Estado en el proveedor', 'Detalle del proveedor',
        ],
        self::TYPE_WEBHOOK_REVIEW => [
            'Motivo', 'PaymentOrder', 'Recarga', 'Intento', 'PaymentWebhook',
            'Estado en el proveedor', 'Detalle del proveedor',
        ],

        // ProcessMercadoPagoWebhook.php. `Error` queda DELIBERADAMENTE FUERA:
        // es el mensaje de la excepción y no hay forma barata de garantizar que
        // no arrastre datos del proveedor. El admin ya sabe qué webhook falló y
        // el detalle técnico vive en el log y en Sentry, que es donde debe estar.
        self::TYPE_RECONCILIATION_FAILURE => ['PaymentWebhook'],
    ];

    /**
     * Incidencias que un admin PUEDE cerrar a mano.
     *
     * Allowlist por clave, con denegación por defecto. El criterio es único y
     * verificable: **solo se puede cerrar a mano lo que nadie cierra solo**.
     *
     * Si la fuente de verdad puede demostrar que el problema desapareció, debe
     * cerrarlo ella. Permitir el cierre manual ahí sería dar un botón para
     * silenciar temporalmente algo que sigue roto.
     *
     * Por qué por CLAVE y no por tipo: `ledger_anomaly` es mixto.
     * `ledger:anomaly` lo resuelve `mova:reconcile-ledger` cuando el ledger
     * vuelve a cuadrar, pero `recharge:{id}:manual_reversal_blocked` no lo
     * resuelve nadie. Una allowlist por tipo tendría que elegir entre dejar la
     * segunda abierta para siempre o abrir la mano con la primera.
     *
     * Verificado recorriendo todas las llamadas a resolve() del código:
     * ninguna alcanza estas tres claves.
     */
    private const MANUALLY_CLOSABLE_KEY_PATTERNS = [
        '/^payment_webhook:\d+:review$/',
        '/^payment_webhook:\d+:failed$/',
        '/^recharge:\d+:manual_reversal_blocked$/',
    ];

    protected $fillable = [
        'alert_key',
        'type',
        'severity',
        'title',
        'message',
        'context',
        'first_detected_at',
        'last_detected_at',
        'occurrences',
        'notified_at',
        'resolved_at',
        // El cierre manual se escribe con el query builder (claim atómico), no
        // por asignación masiva, así que estos tres no eran estrictamente
        // necesarios aquí. Se añaden porque su ausencia los descartaba EN
        // SILENCIO en cualquier otro camino —un seeder, un fixture de QA— y un
        // cierre sin autor ni motivo es exactamente lo que la Fase 3A.1 quiso
        // evitar.
        'resolved_by',
        'resolved_by_name',
        'resolution_note',
    ];

    protected $casts = [
        'context' => 'array',
        'first_detected_at' => 'datetime',
        'last_detected_at' => 'datetime',
        'notified_at' => 'datetime',
        'resolved_at' => 'datetime',
        'occurrences' => 'integer',
    ];

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeCritical(Builder $query): Builder
    {
        return $query->where('severity', self::SEVERITY_CRITICAL);
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOfCategory(Builder $query, string $category): Builder
    {
        $types = array_keys(array_filter(
            self::TYPE_CATEGORIES,
            fn (string $c) => $c === $category
        ));

        // `system` es el cajón por defecto, así que su filtro debe incluir
        // también los tipos que TODAVÍA no están mapeados. Sin esto, una
        // incidencia de un productor nuevo se vería en "todas" y en category(),
        // pero desaparecería al filtrar por la categoría que ella misma dice
        // tener — un tipo nuevo no debe volverse invisible por un olvido.
        if ($category === self::CATEGORY_SYSTEM) {
            $mappedElsewhere = array_keys(array_filter(
                self::TYPE_CATEGORIES,
                fn (string $c) => $c !== self::CATEGORY_SYSTEM
            ));

            return $query->whereNotIn('type', $mappedElsewhere);
        }

        // Una categoría desconocida no debe devolver la tabla entera: eso
        // convertiría un filtro con una errata en una fuga de contexto.
        return $query->whereIn('type', $types ?: ['__none__']);
    }

    public function category(): string
    {
        // Un tipo no catalogado cae en `system` en vez de romper la pantalla:
        // un productor nuevo debe aparecer en el panel aunque nadie se haya
        // acordado de mapearlo aquí todavía.
        return self::TYPE_CATEGORIES[$this->type] ?? self::CATEGORY_SYSTEM;
    }

    /**
     * `context` listo para enviar al navegador.
     *
     * Denegación por defecto: solo salen los campos declarados en
     * CONTEXT_ALLOWLIST para ESTE tipo. Un tipo sin entrada no expone nada, así
     * que un productor nuevo es invisible hasta que alguien revise sus campos y
     * los declare — que es exactamente el momento en el que se piensa si son
     * seguros.
     *
     * Se sigue exigiendo además que el valor sea escalar: un array anidado es
     * casi siempre un payload de proveedor, y estar en la allowlist no debería
     * bastar para dejar pasar una estructura arbitraria.
     */
    public function safeContext(): array
    {
        $allowed = self::CONTEXT_ALLOWLIST[$this->type] ?? [];

        if ($allowed === []) {
            return [];
        }

        $safe = [];

        foreach ($this->context ?? [] as $label => $value) {
            if (! in_array($label, $allowed, true)) {
                continue;
            }

            if ($value === null || is_scalar($value)) {
                if (is_bool($value)) {
                    $safe[$label] = $value ? 'sí' : 'no';

                    continue;
                }

                // Tope de longitud para las cadenas.
                //
                // Dos de los campos permitidos NO los escribe MOVA:
                // `Estado en el proveedor` y `Detalle del proveedor` son
                // `status` y `status_detail` tal cual los devuelve Mercado
                // Pago. Son enums documentados y cortos, pero nada en MOVA lo
                // GARANTIZA: si un día llegara una respuesta larga o
                // inesperada, entraría entera en la pantalla. Truncar es la
                // defensa proporcionada — no hay XSS posible (Vue escapa y la
                // página no usa v-html) y el diagnóstico completo sigue en el
                // log y en Sentry.
                $safe[$label] = is_string($value) && mb_strlen($value) > 200
                    ? mb_substr($value, 0, 200).'…'
                    : $value;
            }
        }

        return $safe;
    }

    /**
     * ¿Puede un admin cerrar esta incidencia a mano? Ver
     * MANUALLY_CLOSABLE_KEY_PATTERNS para el criterio.
     */
    public function isManuallyClosable(): bool
    {
        foreach (self::MANUALLY_CLOSABLE_KEY_PATTERNS as $pattern) {
            if (preg_match($pattern, (string) $this->alert_key) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * A dónde debe ir el admin para actuar sobre esto.
     *
     * El Centro de Operaciones ORQUESTA navegación: no repite aquí ninguna
     * acción financiera ni de dominio. Cada destino es la pantalla que ya es
     * autoridad sobre ese recurso.
     *
     * Devuelve null cuando no hay ninguna pantalla que resuelva el problema
     * (p. ej. una incidencia de configuración, que se arregla en el entorno de
     * despliegue). Es preferible no ofrecer botón a ofrecer uno que no lleva a
     * ninguna parte.
     */
    public function actionRoute(): ?array
    {
        return match ($this->category()) {
            self::CATEGORY_LESSON => ['label' => 'Revisar clases', 'name' => 'admin.lessons'],
            self::CATEGORY_FINANCE => ['label' => 'Revisar recargas', 'name' => 'admin.recharges.index'],
            default => null,
        };
    }

    /**
     * Nombre legible del tipo, para no enseñarle `payment_review` a un humano.
     *
     * Vive aquí y no en el frontend por la misma razón que `category()`: es
     * parte del contrato y se puede probar. El fallback describe lo único que
     * se sabe con certeza de un productor nuevo —que requiere una mirada— sin
     * afirmar una causa que nadie ha demostrado.
     */
    public function typeLabel(): string
    {
        return [
            self::TYPE_PAYMENT_REVIEW => 'Pago pendiente de revisión',
            self::TYPE_WEBHOOK_REVIEW => 'Notificación de pago sin resolver',
            self::TYPE_RECONCILIATION_FAILURE => 'Notificación de pago que no pudo procesarse',
            self::TYPE_LEDGER_ANOMALY => 'Descuadre en los créditos',
            self::TYPE_LESSON_NEEDS_REVIEW => 'Clase que necesita intervención',
            self::TYPE_HEALTH_CHECK => 'Configuración que requiere atención',
        ][$this->type] ?? 'Incidencia operativa';
    }
}
