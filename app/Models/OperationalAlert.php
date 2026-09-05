<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
}
