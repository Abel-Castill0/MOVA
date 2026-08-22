<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hallazgo CRÍTICO de auditoría (2026-08-22): users.phone nunca tuvo
 * restricción de unicidad. Un profesor podía registrar N cuentas con el
 * mismo número y cobrar el bono de bienvenida (5 créditos) N veces —
 * probado empíricamente: 3 cuentas, un solo teléfono, 15 créditos gratis.
 *
 * NO se hace UNIQUE sobre `phone` directamente: es texto libre sin
 * normalizar (" 999 111 222", "+51999111222", "999111222" son la misma
 * persona pero filas distintas), y forzar unicidad sobre un número apenas
 * tecleado —nunca verificado— dejaría un typo o un número ajeno "ocupando"
 * ese teléfono para siempre, bloqueando a su dueño real de registrarse.
 *
 * La columna nueva, `phone_verified_normalized`, solo se escribe en el
 * mismo momento en que se confirma el código (PhoneVerificationController::
 * verify()) — ver ese archivo para el guard real, que usa
 * UniqueConstraintViolationException, mismo patrón que idempotency_key en
 * el ledger. NULL mientras no esté verificado: MySQL permite múltiples NULL
 * en un índice UNIQUE, así que no bloquea a nadie que no haya llegado a
 * verificar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone_verified_normalized', 20)->nullable()->unique()->after('phone_verified_at');
        });

        // Backfill: el único usuario local con phone_verified_at ya
        // confirmado (verificado antes de este fix) — un solo UPDATE
        // determinista, sin ambigüedad posible porque ya se comprobó que no
        // hay duplicados.
        DB::table('users')
            ->whereNotNull('phone_verified_at')
            ->whereNotNull('phone')
            ->orderBy('id')
            ->get(['id', 'phone'])
            ->each(function ($user) {
                $normalized = \App\Models\User::normalizePhone($user->phone);
                if ($normalized) {
                    DB::table('users')->where('id', $user->id)->update(['phone_verified_normalized' => $normalized]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone_verified_normalized');
        });
    }
};
