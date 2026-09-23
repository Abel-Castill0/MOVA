<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    // phone_verified_normalized NO está aquí a propósito: es la garantía
    // UNIQUE contra teléfono duplicado (hallazgo CRÍTICO, 2026-08-22). Solo
    // PhoneVerificationController::verify() debe escribirla, con asignación
    // directa — igual que credits_settled_at en Lesson.
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar_url',
        'email_verified_at',
        'parental_control',
        'phone_verified_at',
        'phone_verification_code_hash',
        'phone_verification_expires_at',
        'phone_verification_attempts',
        'welcome_notification_sent_at',
        'suspended_at',
        'suspension_reason',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected $casts = [
        'email_verified_at'            => 'datetime',
        'phone_verified_at'            => 'datetime',
        'password'                     => 'hashed',
        'parental_control'             => 'boolean',
        'phone_verification_attempts'  => 'integer',
        'phone_verification_expires_at'   => 'datetime',
        'welcome_notification_sent_at'    => 'datetime',
        'suspended_at'                    => 'datetime',
        'whatsapp_opt_in_at'              => 'datetime',
        'whatsapp_opt_out_at'             => 'datetime',
        // P0-C — MFA admin. Secret y hashes de recovery codes cifrados en
        // reposo con APP_KEY; nunca en $fillable (ver AdminMfaService).
        'two_factor_secret'               => 'encrypted',
        'two_factor_recovery_codes'       => 'encrypted:array',
        'two_factor_confirmed_at'         => 'datetime',
    ];

    /**
     * F-01 — Guard de borrado independiente del motor de base de datos.
     *
     * En MySQL, la migración 2026_07_10_000002_protect_monetization_history
     * ya convierte a RESTRICT las FK que protegen el ledger
     * (credit_transactions / recharge_requests / classes -> teacher_profiles,
     * classes / class_requests -> students), así que un DELETE sobre `users`
     * que arrastraría historial financiero falla a nivel de motor. VERIFICADO
     * contra el esquema real, no solo leyendo la migración.
     *
     * Pero esa migración hace early-return si el driver no es MySQL, y los
     * tests corren sobre SQLite: allí las FK conservan el CASCADE original.
     * Es decir, la protección de producción NO era observable por ningún test,
     * y un `$user->delete()` en la suite sí destruía el ledger sin que nada
     * lo señalara.
     *
     * Este guard cierra ese hueco: se aplica en todos los drivers, convierte
     * un error crudo de constraint en un mensaje accionable, y hace la
     * protección testeable. NO sustituye a las FK — las FK siguen siendo la
     * última línea, porque un DELETE por SQL directo no pasa por Eloquent.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $user) {
            if ($user->hasProtectedHistory()) {
                throw new \RuntimeException(
                    "No se puede eliminar al usuario {$user->id}: tiene historial financiero o académico "
                    .'(ledger de créditos, recargas, clases o solicitudes). Ese historial debe preservarse. '
                    .'Usa la anonimización de ProfileController::destroy() en su lugar.'
                );
            }
        });
    }

    /**
     * ¿Este usuario arrastra historial que debe sobrevivir a la baja de la
     * cuenta? Vive aquí —y no solo en ProfileController— para que cualquier
     * camino de borrado lo respete, no únicamente el del formulario de perfil.
     */
    public function hasProtectedHistory(): bool
    {
        $teacherProfile = $this->teacherProfile;

        if ($teacherProfile && (
            $teacherProfile->creditTransactions()->exists()
            || $teacherProfile->rechargeRequests()->exists()
            || $teacherProfile->classes()->exists()
        )) {
            return true;
        }

        $studentIds = $this->students()->pluck('id');

        return $studentIds->isNotEmpty() && (
            ClassRequest::whereIn('student_id', $studentIds)->exists()
            || Lesson::whereIn('student_id', $studentIds)->exists()
        );
    }

    /**
     * ¿Acepta este usuario recibir NOTIFICACIONES por WhatsApp?
     *
     * Distinción deliberada, y es el motivo de que exista este método:
     *
     *   - `phone_verified_at` responde «controla este número».
     *   - `whatsapp_opt_in_at` responde «aceptó que le escribamos».
     *
     * No son lo mismo. Antes WhatsAppChannel solo miraba la primera, así que
     * MOVA no podía justificar por qué enviaba un mensaje a alguien concreto.
     *
     * NO aplica al OTP de verificación: ese mensaje no pasa por
     * WhatsAppChannel (lo envía PhoneVerificationController directamente) y lo
     * pide el propio usuario al pulsar «enviar código». Bloquearlo por falta de
     * opt-in impediría precisamente el paso donde se obtiene el opt-in.
     */
    public function wantsWhatsAppNotifications(): bool
    {
        if ($this->whatsapp_opt_out_at !== null) {
            // La baja gana siempre, aunque exista un opt-in anterior: es la
            // última voluntad expresada.
            return false;
        }

        return $this->whatsapp_opt_in_at !== null;
    }

    public function optInToWhatsApp(): void
    {
        $this->forceFill([
            'whatsapp_opt_in_at' => now(),
            'whatsapp_opt_out_at' => null,
        ])->save();
    }

    public function optOutOfWhatsApp(): void
    {
        $this->forceFill(['whatsapp_opt_out_at' => now()])->save();
    }

    public function students()
    {
        return $this->hasMany(Student::class, 'parent_user_id');
    }

    public function teacherProfile()
    {
        return $this->hasOne(TeacherProfile::class);
    }

    public function routeNotificationForWhatsApp(): ?string
    {
        return $this->normalizePhone($this->phone);
    }

    public function routeNotificationForMail(): string
    {
        return $this->email;
    }

    public static function normalizePhone(?string $phone): ?string
    {
        if (!$phone) return null;

        $phone = preg_replace('/[\s\-\(\)]/', '', $phone);

        if (str_starts_with($phone, '+')) {
            return strlen($phone) >= 10 ? $phone : null;
        }

        // Peruvian 9-digit mobile (starts with 9)
        if (preg_match('/^9\d{8}$/', $phone)) {
            return '+51' . $phone;
        }

        // 11-digit starting with 51 (without +)
        if (preg_match('/^51\d{9}$/', $phone)) {
            return '+' . $phone;
        }

        return null;
    }
}
