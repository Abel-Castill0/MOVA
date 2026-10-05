<?php

namespace App\Console\Commands;

use App\Models\Complaint;
use App\Models\User;
use App\Notifications\ComplaintFiledNotification;
use App\Support\QaDatabaseGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * QA: envía los tres correos esenciales (verificación de cuenta, recuperación
 * de contraseña y constancia del Libro de Reclamaciones) por el MAILER
 * CONFIGURADO a UN buzón de prueba, para comprobar que el proveedor real los
 * acepta.
 *
 * Qué demuestra: que el transporte (gmail_api, smtp, etc.) aceptó el mensaje
 * sin excepción. Qué NO demuestra: que llegue a la bandeja de entrada —eso lo
 * confirma una persona mirando el buzón (revisar también spam)—, ni
 * autenticación SPF/DKIM/DMARC del dominio remitente, ni volumen.
 *
 * Seguridad: solo APP_ENV local|testing y solo una BD QA; el destinatario es
 * SIEMPRE MAIL_SMOKE_RECIPIENT (nunca un argumento libre, para que no pueda
 * usarse contra usuarios reales); se niega a correr con los mailers que no
 * entregan (array, log); no imprime credenciales ni el destinatario completo.
 */
class MailSmoke extends Command
{
    protected $signature = 'mova:mail-smoke';

    protected $description = 'QA: envía verificación, recuperación de contraseña y constancia de reclamo al buzón de PRUEBA (MAIL_SMOKE_RECIPIENT) por el mailer configurado.';

    public function handle(): int
    {
        try {
            QaDatabaseGuard::assertSafeEnvironment();
            $connection = (string) config('database.default');
            if ($connection === 'mysql_qa') {
                QaDatabaseGuard::assertDatabase('mysql_qa', 'mova_qa');
            } elseif ($connection !== 'sqlite') {
                throw new RuntimeException("MOVA QA GUARD: la conexión [{$connection}] no es una BD QA (sqlite o mysql_qa). Abortado.");
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $to = trim((string) env('MAIL_SMOKE_RECIPIENT', ''));
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('Falta MAIL_SMOKE_RECIPIENT (un buzón TUYO de prueba, nunca un usuario real). Cárgalo en qa/.env.sandbox.');

            return self::FAILURE;
        }

        if (! \App\Support\MailAllowlist::allows($to)) {
            $this->error('MAIL_ALLOWLIST está activa y NO incluye el destinatario de la sonda: el correo se descartaría y el resultado sería engañoso. Añádelo a la allowlist o vacíala en esta sonda.');

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        if (in_array($mailer, ['array', 'log'], true)) {
            $this->error("El mailer configurado es [{$mailer}]: no entrega correo externo. Configura MAIL_MAILER (gmail_api o smtp) en qa/.env.sandbox.");

            return self::FAILURE;
        }

        $masked = Str::mask($to, '*', 2, max(1, strpos($to, '@') - 2));
        $this->info("Mailer: {$mailer} · destinatario: {$masked} · remitente (dominio): ".$this->fromDomain());

        $user = User::create([
            'name' => 'Sonda de correo',
            'email' => $to,
            'password' => bcrypt(Str::random(32)),
        ]);

        $results = [
            ['Verificación de cuenta', $this->attempt(fn () => $user->sendEmailVerificationNotification())],
            ['Recuperación de contraseña', $this->attempt(function () use ($to) {
                $status = Password::broker()->sendResetLink(['email' => $to]);
                if ($status !== Password::RESET_LINK_SENT) {
                    throw new RuntimeException("broker devolvió {$status}");
                }
            })],
            ['Constancia de reclamo', $this->attempt(function () use ($to) {
                $complaint = Complaint::file([
                    'type' => 'reclamo',
                    'consumer_name' => 'Sonda de correo',
                    'document_type' => 'DNI',
                    'document_number' => '00000000',
                    'address' => 'Dirección de prueba',
                    'email' => $to,
                    'phone' => '900000000',
                    'is_minor' => false,
                    'good_type' => 'servicio',
                    'amount' => '0.00',
                    'good_description' => 'Sonda de correo',
                    'detail' => 'Mensaje generado por la sonda de correo de QA.',
                    'consumer_request' => 'Ninguna.',
                ]);
                Notification::route('mail', $to)->notify(new ComplaintFiledNotification($complaint));
            })],
        ];

        $this->table(['correo', 'transporte'], $results);

        $failed = collect($results)->contains(fn ($r) => ! str_starts_with($r[1], 'ACEPTADO'));
        if ($failed) {
            $this->error('Al menos un correo NO fue aceptado por el transporte. Revisa la configuración del proveedor (sin exponer secretos).');

            return self::FAILURE;
        }

        $this->warn('El transporte aceptó los tres mensajes. FALTA tu confirmación humana: abre el buzón de prueba (y spam) y verifica que llegaron los tres, con enlaces al dominio esperado.');

        return self::SUCCESS;
    }

    private function attempt(callable $send): string
    {
        try {
            $send();

            return 'ACEPTADO por el transporte';
        } catch (Throwable $e) {
            // Solo la clase: el mensaje del proveedor puede contener datos que no deben imprimirse.
            return 'RECHAZADO ('.class_basename($e).')';
        }
    }

    private function fromDomain(): string
    {
        $address = (string) (config('mail.from.address') ?? '');

        return str_contains($address, '@') ? substr(strrchr($address, '@'), 1) : '(sin remitente)';
    }
}
