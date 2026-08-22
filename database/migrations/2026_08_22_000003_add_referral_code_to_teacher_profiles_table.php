<?php

use App\Models\TeacherProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Infraestructura para el "código de profesor" (Opción A del diseño de
 * solicitud-por-código, documentado en el chat — NO se cablea aquí el
 * enrutamiento de solicitudes por código, solo la columna y el backfill).
 *
 * Formato: 6 caracteres alfanuméricos MAYÚSCULA sin vocales ni 0/O/1/I/L
 * (ambiguos al leer/dictar en voz alta, y sin vocales evita deletrear
 * insultos por accidente). TeacherProfile::booted() lo genera solo para
 * perfiles NUEVOS a partir de aquí — ver ese archivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->string('referral_code', 6)->nullable()->unique()->after('id');
        });

        // Backfill de los perfiles ya existentes — uno por uno, nunca en
        // lote: cada código se genera y se comprueba único contra la BD real
        // antes del siguiente, no se puede paralelizar sin arriesgar colisión.
        TeacherProfile::whereNull('referral_code')->orderBy('id')->get(['id'])->each(function ($profile) {
            TeacherProfile::whereKey($profile->id)->update([
                'referral_code' => self::generateUniqueCode(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->dropColumn('referral_code');
        });
    }

    private static function generateUniqueCode(): string
    {
        // Mismo alfabeto que TeacherProfile::booted() — ver ese archivo para
        // el porqué exacto de cada exclusión.
        $alphabet = 'BCDFGHJKMNPQRSTVWXYZ23456789';

        do {
            $code = Str::upper(collect(range(1, 6))->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])->implode(''));
        } while (TeacherProfile::where('referral_code', $code)->exists());

        return $code;
    }
};
