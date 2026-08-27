<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppWebhookEvent extends Model
{
    // Mismo detalle que WhatsAppMessage: Eloquent adivina "whats_app_..."
    // en vez de "whatsapp_...". Ver el comentario en ese modelo — ya nos
    // costó un bug real la primera vez, así que ahora se fija explícito en
    // todo modelo nuevo que empiece con "WhatsApp".
    protected $table = 'whatsapp_webhook_events';

    protected $fillable = [
        'event_key',
        'provider_message_id',
        'status',
        'received_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];
}
