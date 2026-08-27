<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentOrder extends Model
{
    protected $fillable = [
        'recharge_request_id',
        'provider',
        'provider_order_id',
        'status',
        'amount_minor',
        'currency',
        'expires_at',
        'paid_at',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function rechargeRequest()
    {
        return $this->belongsTo(RechargeRequest::class);
    }
}
