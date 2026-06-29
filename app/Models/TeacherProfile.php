<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TeacherProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'bio', 'hourly_rate', 'zoom_account_id', 'is_verified',
        'rejected_at', 'rejection_reason', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'is_verified'  => 'boolean',
        'hourly_rate'  => 'decimal:2',
        'rejected_at'  => 'datetime',
        'reviewed_at'  => 'datetime',
    ];

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
