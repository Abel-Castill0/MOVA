<?php

namespace Database\Factories;

use App\Models\LegalAcceptance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Un usuario de factory modela a alguien que se registró normalmente, y
     * el registro siempre guarda la aceptación de las versiones vigentes de
     * Términos/Privacidad (C-P1-LEGAL-REACCEPTANCE). Sin esto, el middleware
     * legal.current redirigiría a todo usuario de prueba.
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (User $user) => self::recordCurrentLegalAcceptance($user));
    }

    /**
     * Usuario SIN aceptación vigente (p. ej. anterior a un cambio de versión).
     * Los callbacks afterCreating corren en orden, así que este retira lo que
     * configure() acaba de registrar. Query builder a propósito: el modelo es
     * append-only y este es un fixture de test, no un camino de producto.
     */
    public function withoutCurrentLegalAcceptance(): static
    {
        return $this->afterCreating(fn (User $user) => DB::table('legal_acceptances')->where('user_id', $user->id)->delete());
    }

    public static function recordCurrentLegalAcceptance(User $user): void
    {
        foreach (LegalAcceptance::DOCUMENTS as $document) {
            LegalAcceptance::create([
                'user_id'     => $user->id,
                'document'    => $document,
                'version'     => (string) config("legal.versions.{$document}"),
                'accepted_at' => now(),
            ]);
        }
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
