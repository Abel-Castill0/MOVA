<?php

namespace App\Models;

use App\WhatsApp\WhatsAppMessageStatus;
use App\WhatsApp\WhatsAppSkipReason;
use Illuminate\Database\Eloquent\Model;

class WhatsAppMessage extends Model
{
    // Sin esto, Laravel adivina la tabla como "whats_app_messages"
    // (WhatsApp se parte en "Whats"+"App" al convertir a snake_case) en vez
    // de "whatsapp_messages", que es como la nombra la migración.
    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'to',
        'template_key',
        'client_reference',
        'provider',
        'provider_message_id',
        'status',
        'error',
        'skip_reason',
        'status_updated_at',
        'delivered_at',
        'read_at',
    ];

    protected $casts = [
        'status' => WhatsAppMessageStatus::class,
        // Nullable por diseño: solo un mensaje `skipped` lo tiene; `sent`,
        // `delivered`, `read`, `failed` y `unknown` siempre lo dejan null.
        'skip_reason' => WhatsAppSkipReason::class,
        'status_updated_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];
}
