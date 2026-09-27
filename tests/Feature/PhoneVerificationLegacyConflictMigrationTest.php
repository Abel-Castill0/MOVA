<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * AZ-2.13 — remediación legacy de la migration
 * `2026_08_22_000002_add_verified_phone_uniqueness_to_users_table`.
 *
 * RefreshDatabase ya corre esta migration en un `users` vacío antes de cada
 * test (no hay conflicto posible en ese momento), así que para probar el
 * ALGORITMO de backfill hace falta: tirar la columna que esa migration
 * agregó, sembrar el conflicto legacy directamente por DB::table (igual que
 * lee la propia migration), y volver a invocar su up() manualmente.
 *
 * Sin PII real: todos los teléfonos y datos son sintéticos de test.
 */
class PhoneVerificationLegacyConflictMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function rerunMigration(): void
    {
        if (Schema::hasColumn('users', 'phone_verified_normalized')) {
            Schema::table('users', fn ($t) => $t->dropUnique('users_phone_verified_normalized_unique'));
            Schema::table('users', fn ($t) => $t->dropColumn('phone_verified_normalized'));
        }

        (require database_path('migrations/2026_08_22_000002_add_verified_phone_uniqueness_to_users_table.php'))->up();
    }

    public function test_a_single_legacy_verified_phone_keeps_its_verification_and_gets_normalized(): void
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update([
            'phone' => '+51911111111',
            'phone_verified_at' => now(),
        ]);

        $this->rerunMigration();

        $row = DB::table('users')->where('id', $user->id)->first();
        $this->assertNotNull($row->phone_verified_at);
        $this->assertSame('+51911111111', $row->phone_verified_normalized);
        $this->assertSame('+51911111111', $row->phone);
    }

    public function test_two_users_verified_on_the_exact_same_phone_both_get_revoked(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        DB::table('users')->where('id', $a->id)->update([
            'phone' => '+51922222222', 'phone_verified_at' => now(),
            'phone_verification_code_hash' => 'stale-hash-a', 'phone_verification_attempts' => 3,
        ]);
        DB::table('users')->where('id', $b->id)->update([
            'phone' => '+51922222222', 'phone_verified_at' => now(),
            'phone_verification_code_hash' => 'stale-hash-b', 'phone_verification_attempts' => 1,
        ]);

        $this->rerunMigration();

        foreach ([$a->id, $b->id] as $id) {
            $row = DB::table('users')->where('id', $id)->first();
            $this->assertNull($row->phone_verified_at, "user $id debería quedar sin verificar");
            $this->assertNull($row->phone_verified_normalized);
            $this->assertNull($row->phone_verification_code_hash);
            $this->assertNull($row->phone_verification_expires_at);
            $this->assertSame(0, (int) $row->phone_verification_attempts);
            $this->assertSame('+51922222222', $row->phone, 'el phone crudo no se toca');
        }

        // Ninguna cuenta desaparece, ningún rol ni relación se toca.
        $this->assertSame(2, DB::table('users')->whereIn('id', [$a->id, $b->id])->count());
    }

    public function test_two_equivalent_phone_formats_are_detected_as_the_same_conflict(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        DB::table('users')->where('id', $a->id)->update([
            'phone' => '933333333', 'phone_verified_at' => now(),
        ]);
        DB::table('users')->where('id', $b->id)->update([
            'phone' => '+51933333333', 'phone_verified_at' => now(),
        ]);

        $this->rerunMigration();

        foreach ([$a->id, $b->id] as $id) {
            $row = DB::table('users')->where('id', $id)->first();
            $this->assertNull($row->phone_verified_at, "'933333333' y '+51933333333' deben normalizar al mismo teléfono");
        }
    }

    public function test_an_unverified_account_sharing_the_phone_does_not_cause_a_conflict(): void
    {
        $verified = User::factory()->create();
        $unverified = User::factory()->create();

        DB::table('users')->where('id', $verified->id)->update([
            'phone' => '+51944444444', 'phone_verified_at' => now(),
        ]);
        // Mismo teléfono, pero nunca verificado: no debe entrar al conflicto.
        DB::table('users')->where('id', $unverified->id)->update([
            'phone' => '+51944444444', 'phone_verified_at' => null,
        ]);

        $this->rerunMigration();

        $row = DB::table('users')->where('id', $verified->id)->first();
        $this->assertNotNull($row->phone_verified_at);
        $this->assertSame('+51944444444', $row->phone_verified_normalized);

        $other = DB::table('users')->where('id', $unverified->id)->first();
        $this->assertNull($other->phone_verified_at);
        $this->assertNull($other->phone_verified_normalized);
    }

    public function test_only_the_conflicting_group_is_revoked_not_an_unrelated_verified_user(): void
    {
        $conflictA = User::factory()->create();
        $conflictB = User::factory()->create();
        $independent = User::factory()->create();

        DB::table('users')->where('id', $conflictA->id)->update([
            'phone' => '+51955555555', 'phone_verified_at' => now(),
        ]);
        DB::table('users')->where('id', $conflictB->id)->update([
            'phone' => '+51955555555', 'phone_verified_at' => now(),
        ]);
        DB::table('users')->where('id', $independent->id)->update([
            'phone' => '+51966666666', 'phone_verified_at' => now(),
        ]);

        $this->rerunMigration();

        $this->assertNull(DB::table('users')->where('id', $conflictA->id)->value('phone_verified_at'));
        $this->assertNull(DB::table('users')->where('id', $conflictB->id)->value('phone_verified_at'));

        $indep = DB::table('users')->where('id', $independent->id)->first();
        $this->assertNotNull($indep->phone_verified_at, 'un grupo sin conflicto no debe verse afectado');
        $this->assertSame('+51966666666', $indep->phone_verified_normalized);
    }

    public function test_unique_constraint_still_blocks_two_equal_normalized_phones_after_migration(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->rerunMigration();

        DB::table('users')->where('id', $a->id)->update(['phone_verified_normalized' => '+51977777777']);

        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $b->id)->update(['phone_verified_normalized' => '+51977777777']);
    }

    public function test_one_conflicted_account_can_re_verify_but_the_other_cannot_claim_the_same_phone(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        DB::table('users')->where('id', $a->id)->update(['phone' => '+51988888888', 'phone_verified_at' => now()]);
        DB::table('users')->where('id', $b->id)->update(['phone' => '+51988888888', 'phone_verified_at' => now()]);

        $this->rerunMigration();

        // Flujo moderno: A demuestra control real primero (mismo UPDATE que
        // hace PhoneVerificationController::verify() al confirmar el código).
        DB::table('users')->where('id', $a->id)->update([
            'phone_verified_at' => now(),
            'phone_verified_normalized' => '+51988888888',
        ]);

        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $b->id)->update([
            'phone_verified_at' => now(),
            'phone_verified_normalized' => '+51988888888',
        ]);
    }
}
