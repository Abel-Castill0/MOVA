<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/** P0-K — hoja del Libro de Reclamaciones virtual. */
class Complaint extends Model
{
    public const TYPES = ['reclamo', 'queja'];

    public const DOCUMENT_TYPES = ['DNI', 'CE', 'PASAPORTE'];

    public const GOOD_TYPES = ['producto', 'servicio'];

    protected $guarded = ['id', 'code', 'status', 'response', 'responded_at', 'responded_by'];

    protected $casts = [
        'is_minor'     => 'boolean',
        'amount'       => 'decimal:2',
        'responded_at' => 'datetime',
    ];

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    /**
     * Crea la hoja con correlativo anual "MOVA-YYYY-NNNNNN" asignado dentro
     * de la transacción, con lock, para que dos envíos simultáneos no
     * compartan número (el índice unique es la última red).
     */
    public static function file(array $data): self
    {
        return DB::transaction(function () use ($data) {
            $prefix = 'MOVA-'.now()->year.'-';
            $last = self::where('code', 'like', $prefix.'%')->lockForUpdate()->orderByDesc('code')->value('code');
            $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

            $complaint = new self($data);
            $complaint->code = $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
            $complaint->status = 'open';
            $complaint->save();

            return $complaint;
        });
    }
}
