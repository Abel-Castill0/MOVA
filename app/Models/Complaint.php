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
     * Crea la hoja con correlativo anual "MOVA-YYYY-NNNNNN".
     *
     * P1-01: el número sale de complaint_sequences, una fila por año:
     *  1. la fila del año se garantiza de forma idempotente (INSERT IGNORE,
     *     fuera de la transacción: la primera alta del año no compite por
     *     rangos vacíos);
     *  2. dentro de la transacción se bloquea ESA fila (FOR UPDATE), se
     *     incrementa y se inserta la hoja. Los envíos concurrentes se
     *     serializan sobre una sola fila en vez de sobre gap locks.
     * DB::transaction(..., 3) reintenta ante deadlock como defensa
     * secundaria; el UNIQUE de complaints.code es la última red.
     */
    public static function file(array $data): self
    {
        $year = now()->year;

        DB::table('complaint_sequences')->insertOrIgnore([
            'year' => $year, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::transaction(function () use ($data, $year) {
            $last = DB::table('complaint_sequences')->where('year', $year)->lockForUpdate()->value('last_number');
            $next = (int) $last + 1;
            DB::table('complaint_sequences')->where('year', $year)->update(['last_number' => $next, 'updated_at' => now()]);

            $complaint = new self($data);
            $complaint->code = 'MOVA-'.$year.'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
            $complaint->status = 'open';
            $complaint->save();

            return $complaint;
        }, 3);
    }
}
