<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C-1 post-mortem, Opción A del diseño de código de referido (HANDOFF_FINAL.md
 * §18): vía ADICIONAL de solicitud en paralelo al marketplace de ofertas
 * existente — ClassOffer no se toca, DiagnosticRecommendationService no se
 * toca.
 *
 * teacher_profile_id: vínculo REAL — cuando está presente, la solicitud es
 * exclusiva de ese profesor (ClassRequestPolicy::accept() lo exige). NULL
 * significa "abierta a quien matchee por oferta/materia", el comportamiento
 * de siempre.
 *
 * teacher_referral_code: solo trazabilidad de CON QUÉ código se hizo la
 * solicitud (útil si el código de un profesor se regenera alguna vez). NO es
 * la fuente de verdad para autorizar nada — esa es teacher_profile_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_requests', function (Blueprint $table) {
            $table->foreignId('teacher_profile_id')->nullable()->after('class_offer_id')
                ->constrained()->nullOnDelete();
            $table->string('teacher_referral_code', 6)->nullable()->after('teacher_profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('class_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('teacher_profile_id');
            $table->dropColumn('teacher_referral_code');
        });
    }
};
