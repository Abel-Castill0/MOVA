<?php

namespace Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Sentry\ClientInterface;
use Sentry\EventId;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * H-01 — Sentry realmente engancha con el ExceptionHandler.
 *
 * La auditoría (docs/MOVA_SYSTEM_MAP.md §29.2) encontró que `sentry-laravel`
 * estaba instalado, con DSN configurable, y aun así NO capturaba nada: el
 * paquete quita sus propias integraciones de error esperando que la aplicación
 * lo enganche, y `Handler::register()` tenía un `reportable` vacío.
 *
 * Estos tests fijan el contrato para que la regresión no pueda volver en
 * silencio. NO se necesita un DSN real: se sustituye el Hub global de Sentry
 * por uno con un cliente doble y se observa qué recibe.
 */
class SentryReportingTest extends TestCase
{
    private ?HubInterface $originalHub = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalHub = SentrySdk::getCurrentHub();
    }

    protected function tearDown(): void
    {
        if ($this->originalHub !== null) {
            SentrySdk::setCurrentHub($this->originalHub);
        }

        parent::tearDown();
    }

    /**
     * Instala un cliente Sentry doble y devuelve la lista (por referencia) donde
     * se van acumulando las excepciones que Sentry habría enviado.
     *
     * @param  array<int, \Throwable>  $captured
     */
    private function spyOnSentry(array &$captured): void
    {
        $client = Mockery::mock(ClientInterface::class);

        $client->shouldReceive('captureException')
            ->andReturnUsing(function (\Throwable $e) use (&$captured) {
                $captured[] = $e;

                return EventId::generate();
            });

        // Cualquier otro método del contrato (getOptions, flush, etc.) que el
        // Hub pueda tocar: no interesa para esta prueba.
        $client->shouldIgnoreMissing();

        SentrySdk::setCurrentHub(new Hub($client));
    }

    public function test_report_helper_reaches_sentry(): void
    {
        $captured = [];
        $this->spyOnSentry($captured);

        $exception = new RuntimeException('Anomalía financiera de prueba');

        report($exception);

        $this->assertCount(1, $captured, 'report($e) debe llegar a Sentry.');
        $this->assertSame($exception, $captured[0]);
    }

    public function test_an_unhandled_exception_reaches_sentry(): void
    {
        $captured = [];
        $this->spyOnSentry($captured);

        // Camino real de una excepción no controlada: el Handler la reporta
        // antes de renderizar el 500.
        app(ExceptionHandler::class)->report(new RuntimeException('Fallo no controlado'));

        $this->assertCount(1, $captured);
        $this->assertSame('Fallo no controlado', $captured[0]->getMessage());
    }

    /**
     * El requisito explícito de la fase: NO convertir cada 4xx esperado en un
     * error crítico. Estos son exactamente los que MOVA produce a diario
     * (`abort(403)` de las policies, `abort(422)` de estados inválidos,
     * validación de formularios, `findOrFail` de ownership).
     *
     * El proveedor devuelve FÁBRICAS, no instancias: los data providers de
     * PHPUnit corren antes de que exista la aplicación, y
     * `ValidationException::withMessages()` necesita el traductor del
     * contenedor ("A facade root has not been set").
     *
     * @dataProvider expectedClientErrors
     */
    public function test_expected_client_errors_do_not_reach_sentry(callable $makeException): void
    {
        $captured = [];
        $this->spyOnSentry($captured);

        $exception = $makeException();

        report($exception);

        $this->assertSame(
            [],
            $captured,
            get_class($exception).' es un error esperado del cliente y no debe ensuciar Sentry.'
        );
    }

    public static function expectedClientErrors(): array
    {
        return [
            'abort(403) de una policy'      => [fn () => new HttpException(403, 'No autorizado')],
            'abort(422) de estado inválido' => [fn () => new HttpException(422, 'Solo se pueden cancelar clases programadas.')],
            '404 de ruta o modelo'          => [fn () => new NotFoundHttpException()],
            'validación de formulario'      => [fn () => ValidationException::withMessages(['campo' => 'inválido'])],
            'findOrFail de ownership'       => [fn () => new ModelNotFoundException()],
            'authorization exception'       => [fn () => new AuthorizationException()],
        ];
    }

    /**
     * Enganchar Sentry no debe apagar el log local: el callback `reportable` NO
     * devuelve `false`, así que `reportThrowable()` sigue cayendo al logger por
     * defecto después de enviar el evento.
     */
    public function test_local_logging_is_preserved_alongside_sentry(): void
    {
        $captured = [];
        $this->spyOnSentry($captured);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn (string $message) => $message === 'Debe seguir en el log local');

        // El Handler resuelve el logger por el contenedor; el resto de niveles
        // no deben usarse para una excepción normal.
        Log::shouldReceive('log')->never();

        report(new RuntimeException('Debe seguir en el log local'));

        $this->assertCount(1, $captured, 'Y además debe haber llegado a Sentry.');
    }

    /**
     * Un solo camino de captura. Si alguien añadiera un canal `sentry` al stack
     * de logging además de este callback, cada excepción viajaría dos veces.
     */
    public function test_a_single_exception_produces_exactly_one_sentry_event(): void
    {
        $captured = [];
        $this->spyOnSentry($captured);

        report(new RuntimeException('Una sola vez'));

        $this->assertCount(1, $captured, 'Evento duplicado: hay más de una vía de captura activa.');
    }

    /**
     * Guarda de configuración: el stack de logging no debe incluir un canal
     * `sentry`. Es la otra mitad de la garantía de no-duplicación — el test
     * anterior observa el comportamiento, este impide que la configuración
     * derive sin que nadie lo note.
     */
    public function test_logging_stack_does_not_also_ship_to_sentry(): void
    {
        $stack = config('logging.channels.stack.channels', []);

        $this->assertNotContains(
            'sentry',
            $stack,
            'El canal `sentry` en el stack de logging duplicaría cada excepción, '
            .'porque App\Exceptions\Handler ya la envía vía reportable().'
        );
    }
}
