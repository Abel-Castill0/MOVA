<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Sentry\Laravel\Integration;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * H-01 — ENGANCHE DE SENTRY (antes este callback estaba VACÍO).
     *
     * `Sentry\Laravel\ServiceProvider` elimina deliberadamente del SDK las
     * integraciones `ErrorListenerIntegration`, `ExceptionListenerIntegration` y
     * `FatalErrorListenerIntegration` (verificado en
     * vendor/sentry/sentry-laravel/src/Sentry/Laravel/ServiceProvider.php:370-385)
     * porque espera que la aplicación enganche Sentry ella misma, por una de dos
     * vías: este `reportable`, o un canal de log `sentry`.
     *
     * MOVA no tenía NINGUNA de las dos, así que ni las excepciones no capturadas
     * ni los `report($e)` explícitos del código financiero llegaban a Sentry —
     * el DSN podía estar perfectamente configurado y el proyecto seguía en
     * silencio.
     *
     * POR QUÉ ESTA VÍA Y NO EL CANAL DE LOG:
     *
     * Elegir UNA sola es obligatorio. Con `reportable` + canal `sentry` en el
     * stack de logging, cada excepción se enviaría DOS veces: una por este
     * callback y otra cuando `reportThrowable()` cae al logger por defecto.
     * Se elige `reportable` porque es la vía que Sentry documenta para el
     * Handler clásico de Laravel 10 (`Integration::handles()` existe pero recibe
     * `Illuminate\Foundation\Configuration\Exceptions`, el bootstrap de Laravel
     * 11+, que este proyecto no usa).
     *
     * QUÉ NO SE ENVÍA (y por qué no hace falta filtrar a mano):
     *
     * `Handler::report()` comprueba `shouldntReport($e)` ANTES de ejecutar los
     * report callbacks (verificado en el framework instalado), y
     * `$internalDontReport` ya excluye todo lo que en MOVA es un 4xx esperado:
     * `HttpException` (los `abort(403/404/422)` de policies y controllers),
     * `ValidationException`, `AuthenticationException`, `AuthorizationException`,
     * `ModelNotFoundException`, `TokenMismatchException`, `HttpResponseException`.
     * Es decir: un padre que intenta ver la clase de otro genera un 403 y NO
     * ensucia Sentry, mientras que una anomalía financiera real
     * (`Lesson::reservedCreditAmount()`, `LessonSettlementService::consume()`)
     * llega como error, que es exactamente lo que se quiere.
     *
     * LOS LOGS LOCALES SE CONSERVAN:
     *
     * Este callback NO devuelve `false` a propósito. En `reportThrowable()`, un
     * report callback que devuelve `false` corta la cadena y se salta el logger
     * por defecto. Al devolver `null`, Sentry recibe el evento Y el log local
     * sigue escribiéndose como hasta ahora.
     *
     * SIN DSN CONFIGURADO NO PASA NADA:
     *
     * `Sentry\Laravel\ServiceProvider` solo enlaza el cliente si hay DSN
     * (`hasDsnSet()`), y un `Hub` sin cliente devuelve `null` en
     * `captureException()` sin lanzar. Por eso no hace falta una guarda extra
     * aquí: en tests y en local sin DSN, esta llamada es un no-op.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            Integration::captureUnhandledException($e);
        });
    }
}
