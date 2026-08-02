<?php

use App\Models\TeacherProfile;
use Illuminate\Database\Migrations\Migration;

/**
 * Antes de CAMBIO 1, TeacherProfile se creaba con hourly_rate=0 al
 * registrarse (el profesor lo fijaba manualmente en Setup.vue). Los
 * perfiles creados antes de ese cambio se quedaron congelados en 0 — el
 * registro ya no pasa por ahí, así que nunca se corrigen solos. Se
 * recalculan con maxAllowedRate() (no se hardcodea 20) para que un perfil
 * legacy con historial real de clases/calificaciones caiga en el nivel que
 * le corresponde en vez de resetearse a Base.
 */
return new class extends Migration
{
    public function up(): void
    {
        TeacherProfile::where('hourly_rate', 0)->get()->each(function (TeacherProfile $profile) {
            $profile->update(['hourly_rate' => $profile->maxAllowedRate()]);
        });
    }

    public function down(): void
    {
        // Irreversible a propósito: no hay forma de distinguir "0 porque
        // nunca se corrigió" de "0 porque alguien lo puso a propósito"
        // después de correr esta migración.
    }
};
