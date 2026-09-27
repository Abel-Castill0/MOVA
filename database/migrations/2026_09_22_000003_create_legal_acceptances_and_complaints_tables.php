<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P0-K — aceptación versionada de Términos/Privacidad (append-only) y
// Libro de Reclamaciones virtual.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('document', 32);
            $table->string('version', 32);
            $table->timestamp('accepted_at');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->index(['user_id', 'document']);
        });

        // Usuarios previos: aceptaron (validación 'accepted' en el registro)
        // pero sin versión ni timestamp propio. Se registra honestamente como
        // 'unversioned' con su fecha de alta, no como la versión vigente.
        foreach (['terms', 'privacy'] as $document) {
            DB::table('legal_acceptances')->insertUsing(
                ['user_id', 'document', 'version', 'accepted_at'],
                DB::table('users')->whereNotNull('created_at')
                    ->select('id', DB::raw("'{$document}'"), DB::raw("'unversioned'"), 'created_at'),
            );
        }

        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('type', 16);            // reclamo | queja
            $table->string('consumer_name', 150);
            $table->string('document_type', 16);   // DNI | CE | PASAPORTE
            $table->string('document_number', 20);
            $table->string('address', 255);
            $table->string('phone', 20)->nullable();
            $table->string('email', 150);
            $table->boolean('is_minor')->default(false);
            $table->string('guardian_name', 150)->nullable();
            $table->string('good_type', 16);       // producto | servicio
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('good_description', 255);
            $table->text('detail');
            $table->text('consumer_request');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('open'); // open | answered
            $table->text('response')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
        Schema::dropIfExists('legal_acceptances');
    }
};
