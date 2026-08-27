<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentWebhook extends Model
{
    protected $fillable = [
        'provider',
        'event_id',
        'event_type',
        'payload',
        'payload_hash',
        'received_at',
        'processed_at',
        'status',
        'error',
    ];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
