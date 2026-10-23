<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountDeletionRequest extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'array', 'events' => 'array', 'outcome' => 'array', 'responded_at' => 'datetime', 'erased_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function receipt(): array
    {
        $outcome = $this->outcome ?? [];

        return $this->only(['reference', 'status', 'created_at', 'erased_at', 'responded_at', 'response', 'cleanup_status'])
            + ['outcome' => array_intersect_key($outcome, array_flip([
                'classes_cancelled', 'requests_closed', 'requests_republished', 'requests_need_new_confirmation',
                'credits_forfeited', 'credits_refundable', 'students_anonymized',
            ]))];
    }
}
