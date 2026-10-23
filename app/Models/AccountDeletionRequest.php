<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountDeletionRequest extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'array', 'events' => 'array', 'responded_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function receipt(): array
    {
        return $this->only(['reference', 'status', 'created_at', 'responded_at', 'response']);
    }
}
