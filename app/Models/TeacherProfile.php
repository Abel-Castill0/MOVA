<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TeacherProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'bio', 'hourly_rate', 'zoom_account_id', 'is_verified',
        'credits_available', 'credits_reserved',
        'completed_classes_count', 'is_experienced',
        'mentorship_slots_total', 'mentorship_slots_taken',
        'rejected_at', 'rejection_reason', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'is_verified'  => 'boolean',
        'hourly_rate'  => 'decimal:2',
        'credits_available' => 'integer',
        'credits_reserved'  => 'integer',
        'completed_classes_count' => 'integer',
        'is_experienced' => 'boolean',
        'mentorship_slots_total' => 'integer',
        'mentorship_slots_taken' => 'integer',
        'rejected_at'  => 'datetime',
        'reviewed_at'  => 'datetime',
    ];

    public function maxAllowedRate(): int
    {
        return $this->completed_classes_count >= 5 || $this->is_experienced ? 25 : 20;
    }

    public function hasAvailableMentorshipSlots(): bool
    {
        return $this->mentorship_slots_taken < $this->mentorship_slots_total;
    }

    public function isRejected(): bool
    {
        return !$this->is_verified && $this->rejected_at !== null;
    }

    public function isPending(): bool
    {
        return !$this->is_verified && $this->rejected_at === null;
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subjects()
    {
        return $this->belongsToMany(Subject::class, 'teacher_subject')
            ->withPivot('specific_rate');
    }

    public function classOffers()
    {
        return $this->hasMany(ClassOffer::class);
    }

    public function classes()
    {
        return $this->hasMany(Lesson::class);
    }

    public function reviews()
    {
        return $this->hasMany(TeacherReview::class);
    }

    public function creditTransactions()
    {
        return $this->hasMany(CreditTransaction::class);
    }

    public function rechargeRequests()
    {
        return $this->hasMany(RechargeRequest::class);
    }

    public function visibleReviews()
    {
        return $this->hasMany(TeacherReview::class)->where('is_visible', true);
    }

    public function avgRating(): ?float
    {
        $avg = $this->visibleReviews()->avg('rating');
        return $avg ? round((float) $avg, 1) : null;
    }

    public function reviewCount(): int
    {
        return $this->visibleReviews()->count();
    }
}
