<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class TeacherProfile extends Model
{
    use HasFactory;

    // Alfabeto del código de referido (ver migración
    // 2026_08_22_000003_add_referral_code_to_teacher_profiles_table):
    // - Sin vocales: un código de 6 consonantes/dígitos no puede deletrear
    //   una palabra ofensiva por accidente.
    // - Sin 0/O/1/I/L: ambiguos al leerlos o dictarlos en voz alta.
    private const REFERRAL_CODE_ALPHABET = 'BCDFGHJKMNPQRSTVWXYZ23456789';

    // referral_code NO está en $fillable: se genera SIEMPRE en booted(),
    // nunca por asignación externa — mismo motivo que credits_settled_at en
    // Lesson (una capa central es la única fuente, no cada caller).
    protected $fillable = [
        'user_id', 'bio', 'hourly_rate', 'yape_number', 'plin_number', 'is_verified',
        'credits_available', 'credits_reserved',
        'completed_classes_count', 'is_experienced',
        'mentorship_slots_total', 'mentorship_slots_taken',
        'rejected_at', 'rejection_reason', 'reviewed_by', 'reviewed_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (TeacherProfile $profile) {
            if (! $profile->referral_code) {
                $profile->referral_code = self::generateUniqueReferralCode();
            }
        });
    }

    private static function generateUniqueReferralCode(): string
    {
        do {
            $code = Str::upper(collect(range(1, 6))
                ->map(fn () => self::REFERRAL_CODE_ALPHABET[random_int(0, strlen(self::REFERRAL_CODE_ALPHABET) - 1)])
                ->implode(''));
        } while (self::where('referral_code', $code)->exists());

        return $code;
    }

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

    /**
     * Nivel de tarifa automático: Base (20) / Experto (25) / Élite (30).
     * También limita specific_rate en ofertas de clase (ver ClassOfferController).
     */
    public function maxAllowedRate(): int
    {
        $avgRating = $this->avgRating();

        if ($this->completed_classes_count >= 20 && $avgRating !== null && $avgRating >= 4.5) {
            return 30;
        }

        if ($this->completed_classes_count >= 5 && $avgRating !== null && $avgRating >= 4.0) {
            return 25;
        }

        return 20;
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
