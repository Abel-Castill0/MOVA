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
 *
 * Corrección AZ-2.13: el backfill original asumía "como mucho un usuario
 * verificado", que resultó falso al ensayar esta migración contra el
 * snapshot real rescatado de Railway (dos cuentas legacy — un teacher y un
 * parent, de 2026-06-24 — verificaron el mismo teléfono antes de que
 * existiera esta restricción). No hay ninguna señal capturada en su momento
 * que permita decidir de forma no arbitraria cuál era la dueña real, así
 * que la política es FAIL-CLOSED: se conservan ambas cuentas completas
 * (phone crudo, roles, historial académico) y se revoca la verificación
 * histórica de TODO el grupo en conflicto. El propio UNIQUE de abajo decide
 * de ahora en adelante quién demuestra control real primero al re-verificar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone_verified_normalized', 20)->nullable()->unique()->after('phone_verified_at');
        });

        $verified = DB::table('users')
            ->whereNotNull('phone_verified_at')
            ->whereNotNull('phone')
            ->orderBy('id')
            ->get(['id', 'phone']);

        $groups = [];
        foreach ($verified as $user) {
            $normalized = \App\Models\User::normalizePhone($user->phone);
            if ($normalized) {
                $groups[$normalized][] = $user->id;
            }
        }

        foreach ($groups as $normalized => $userIds) {
            if (count($userIds) === 1) {
                DB::table('users')->where('id', $userIds[0])->update([
                    'phone_verified_normalized' => $normalized,
                ]);

                continue;
            }

            // Conflicto legacy: 2+ cuentas verificaron el mismo teléfono
            // antes de que existiera esta restricción. NO se elige
            // ganador por email/dominio/ID/actividad — esa señal nunca se
            // capturó. Se preserva cada cuenta intacta y solo se revoca su
            // estado de verificación de teléfono; deberán volver a
            // verificar.
            DB::table('users')->whereIn('id', $userIds)->update([
                'phone_verified_at' => null,
                'phone_verified_normalized' => null,
                'phone_verification_code_hash' => null,
                'phone_verification_expires_at' => null,
                'phone_verification_attempts' => 0,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone_verified_normalized');
        });
    }
};
