<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P1-01 (auditoría Codex) — contador anual explícito del Libro de
// Reclamaciones. Sustituye "SELECT último code FOR UPDATE + 1", que bajo
// 20 procesos en MySQL 8.4 producía 1 alta y 19 SQLSTATE 40001 (gap/next-key
// locks sobre un rango). Ahora cada alta bloquea UNA fila concreta (el año).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaint_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        // Continuidad con hojas ya emitidas (MOVA-YYYY-NNNNNN).
        $rows = DB::table('complaints')->select('code')->get()
            ->map(fn ($r) => sscanf($r->code, 'MOVA-%d-%d'))
            ->filter(fn ($p) => is_array($p) && $p[0] && $p[1])
            ->groupBy(fn ($p) => $p[0])
            ->map(fn ($g, $year) => ['year' => (int) $year, 'last_number' => (int) $g->max(fn ($p) => $p[1]), 'created_at' => now(), 'updated_at' => now()])
            ->values()->all();

        if ($rows !== []) {
            DB::table('complaint_sequences')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_sequences');
    }
};
