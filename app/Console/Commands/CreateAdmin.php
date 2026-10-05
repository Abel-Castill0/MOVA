<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Crea (o promueve) el primer administrador SIN conocer ni imprimir una contraseña.
 *
 * La cuenta queda con correo verificado y una contraseña aleatoria que nadie conoce: la persona
 * titular fija la suya con «Olvidé mi contraseña» (necesita correo operativo) y el primer ingreso
 * al panel exige configurar MFA (middleware admin.mfa). Idempotente: si el usuario existe solo
 * se le asigna el rol admin y NO se toca su contraseña.
 */
class CreateAdmin extends Command
{
    protected $signature = 'mova:create-admin {email : Correo del administrador} {--name= : Nombre visible}';

    protected $description = 'Crea o promueve a administrador sin contraseña conocida (la fija el titular por recuperación).';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('El correo no es válido.');

            return self::FAILURE;
        }

        // Nunca cuentas de prueba/QA como administrador de un entorno real.
        if (app()->environment('production') && preg_match('/@(mova\.test|example\.(com|org|net)|invalid)$|\.test$/i', $email)) {
            $this->error('Ese correo parece de prueba; no se crea un administrador real con él.');

            return self::FAILURE;
        }

        Role::findOrCreate('admin', 'web');

        $user = User::where('email', $email)->first();
        $created = false;

        if (! $user) {
            $user = User::create([
                'name' => (string) ($this->option('name') ?: Str::before($email, '@')),
                'email' => $email,
                'password' => Hash::make(Str::random(64)),
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $created = true;
        }

        if (! $user->hasRole('admin')) {
            $user->assignRole('admin');
        }

        $this->info(($created ? 'Administrador creado' : 'Usuario existente promovido a administrador').": {$email}");
        $this->line('La contraseña NO se conoce: usa «Olvidé mi contraseña» para fijarla; el primer ingreso exige configurar MFA.');

        return self::SUCCESS;
    }
}
