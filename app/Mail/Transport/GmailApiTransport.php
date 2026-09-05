<?php

namespace App\Mail\Transport;

use App\Services\GmailApiMailService;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * H-05 — Transporte REAL de Symfony para la Gmail API.
 *
 * EL PROBLEMA QUE RESUELVE:
 *
 * `MAIL_MAILER=gmail_api` estaba configurado en producción pero `gmail_api` no
 * era un mailer de Laravel: no existía en `config/mail.php`. Funcionaba solo
 * porque `SafeMailChannel` interceptaba ese valor ANTES de que el framework
 * intentara resolverlo y llamaba a la API por su cuenta.
 *
 * Consecuencia: cualquier `Mail::send()`, cualquier Mailable, cualquier correo
 * de un paquete de terceros habría fallado con "Mailer [gmail_api] is not
 * defined". No ocurría porque hoy no existe ni un solo `Mail::` directo en el
 * repositorio — era una trampa latente, no un fallo activo.
 *
 * LA SOLUCIÓN NO ES UN PARCHE DE CONFIGURACIÓN: al registrar un transporte de
 * verdad, `gmail_api` pasa a ser un mailer como cualquier otro. El pipeline
 * estándar de Laravel (Mailer → Symfony Mailer → Transport) funciona para
 * notificaciones, Mailables y cualquier uso futuro, y el `failover` nativo del
 * framework expresa el respaldo a SMTP sin código propio.
 *
 * VENTAJA COLATERAL: Symfony ya construye el mensaje MIME completo y correcto
 * (`SentMessage::toString()`), con codificación de cabeceras, multipart,
 * adjuntos y Reply-To. Eso sustituye a un constructor MIME escrito a mano que
 * concatenaba cabeceras con `\r\n` y derivaba la versión de texto plano con
 * `strip_tags()`.
 */
class GmailApiTransport extends AbstractTransport
{
    public function __construct(private readonly GmailApiMailService $gmail)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        // El mensaje ya viene completo y firmado por Symfony: aquí solo se
        // entrega. Si la entrega falla, se lanza — el mailer de Laravel
        // propagará la excepción y, si el mailer configurado es `failover`,
        // Symfony pasará al siguiente transporte automáticamente.
        $this->gmail->sendRawMessage($message->toString());
    }

    public function __toString(): string
    {
        return 'gmail+api://gmail.googleapis.com';
    }
}
