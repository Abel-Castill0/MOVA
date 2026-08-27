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

    /**
     * Defensa en profundidad, no la primera línea de defensa — esa sigue
     * siendo el allow-list explícito de columnas en cada controller
     * (MarketplaceController::index()/TeacherPublicController::show(), los
     * únicos dos públicos, verificados sin fuga). `$hidden` solo protege
     * contra el día en que ALGÚN código nuevo pase el modelo completo a una
     * vista sin pensarlo (`return $teacherProfile` / Inertia prop directo) —
     * lo mismo que ya le pasó a `MarketplaceController` (ver el P0 corregido
     * en docs/MOVA_DESIGN_AUDIT_FINAL.md) pero por un motivo distinto (ahí el
     * allow-list existía y un detalle de Eloquent lo ignoraba en silencio;
     * esto cubre el caso de que el allow-list directamente no exista).
     *
     * Solo entran aquí los campos SIN NINGÚN lector real en todo el
     * frontend — verificado con grep en resources/js (nombre snake_case Y
     * camelCase) antes de añadir cada uno, no una lista intuida:
     *
     * - `user_id`: cada consumidor que necesita "a quién pertenece este
     *   perfil" usa la relación `user` ya cargada (`t.user?.name`, etc.),
     *   nunca el FK crudo.
     * - `credits_available`/`credits_reserved`: su único lector real
     *   (Teacher/Credits/Index.vue) los recibe de
     *   Teacher\CreditController::index(), que ya construye su propio
     *   array a mano (`'credits_available' => $teacherProfile->credits_available`)
     *   — nunca depende de la serialización del modelo, así que ocultarlos
     *   aquí no cambia nada ahí. `Teacher/Edit.vue`/`Setup.vue` (que sí
     *   reciben el modelo completo) jamás los leen.
     * - `completed_classes_count`/`is_experienced`: solo se leen dentro de
     *   `maxAllowedRate()` (este archivo) — acceso directo a propiedad,
     *   `$hidden` no lo afecta.
     * - `mentorship_slots_taken`: solo se lee dentro de
     *   `hasAvailableMentorshipSlots()` — `mentorship_slots_total` SÍ se
     *   usa en Teacher/Edit.vue/Setup.vue (el profesor fija su total), por
     *   eso ese campo se queda fuera de esta lista.
     * - `reviewed_at`: Admin/PendingTeachers.vue muestra `rejected_at`
     *   (verificado leyendo el archivo), nunca `reviewed_at`.
     *
     * Deliberadamente FUERA de esta lista pese a no tener lector obvio a
     * primera vista: `reviewed_by`. Cuando se carga la relación
     * `reviewedBy` (Admin/PendingTeachers.vue vía
     * AdminController::pendingTeachers()), Eloquent la serializa bajo la
     * misma clave snake_case `reviewed_by` que el FK crudo — ocultar esa
     * clave escondería también la relación ya cargada y rompería
     * "Revisado por: {{ t.reviewed_by?.name }}" en esa vista. `yape_number`/
     * `plin_number` tampoco entran: Teacher/Edit.vue los necesita para
     * precargar el formulario del propio profesor.
     */
    protected $hidden = [
        'user_id',
        'credits_available',
        'credits_reserved',
        'completed_classes_count',
        'is_experienced',
        'mentorship_slots_taken',
        'reviewed_at',
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
