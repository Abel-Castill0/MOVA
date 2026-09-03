<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RechargeRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'teacher_profile_id',
        'package_code',
        'package_name',
        'credits',
        'amount_pen',
        'payment_method',
        'operation_number',
        'operation_number_normalized',
        'status',
        'reviewed_at',
        'reviewed_by',
        'approved_at',
        'rejected_at',
        'rejection_reason',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
    ];

    protected $casts = [
        'credits' => 'integer',
        'amount_pen' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function teacherProfile()
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function creditTransaction()
    {
        return $this->hasOne(CreditTransaction::class);
    }

    public function reversedBy()
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /**
     * Todos los intentos de pago (ver PaymentOrder::$attempt_number) — ya
     * no es 1:1: un intento rechazado no impide un segundo intento legítimo
     * sobre la misma RechargeRequest.
     */
    public function paymentOrders()
    {
        return $this->hasMany(PaymentOrder::class);
    }

    /**
     * El intento más reciente — lo que casi siempre se quiere al preguntar
     * "¿en qué quedó el pago de esta recarga?".
     */
    public function latestPaymentOrder()
    {
        return $this->hasOne(PaymentOrder::class)->latestOfMany('attempt_number');
    }
}
