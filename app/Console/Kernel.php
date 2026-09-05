<?php

namespace App\Console;

use App\Support\SettlementMode;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // F-04: withoutOverlapping() añadido. Este comando corre cada minuto y
        // sus barridos tardan más de 60s con volumen real (notifyBoth despacha
        // un job por canal y por destinatario), así que dos instancias podían
        // solaparse y duplicar recordatorios. El lock por lección dentro del
        // comando es la garantía real; esto es defensa en profundidad.
        $schedule->command('classmate:send-reminders')->everyMinute()->withoutOverlapping();

        // C-1 / F-02: el modo ya NO está hardcodeado aquí. Lo decide
        // LESSON_SETTLEMENT_MODE (config/credits.php → App\Support\SettlementMode).
        // Por defecto sigue siendo dry_run, así que el comportamiento no cambia
        // sin una decisión explícita — pero ahora esa decisión es visible en la
        // configuración y `mova:health-check` avisa si producción sigue apagada.
        //
        // withoutOverlapping() es defensa en profundidad; la garantía real
        // contra doble liquidación es el UNIQUE de idempotency_key dentro de
        // LessonSettlementService.
        $schedule->command('mova:settle-lessons'.(SettlementMode::isLive() ? '' : ' --dry-run'))
            ->hourly()
            ->withoutOverlapping();

        // PRODUCTION ENABLEMENT (readiness pass): sin esto, la recuperación
        // de webhooks/pagos atascados de Mercado Pago (MercadoPagoReconcile
        // → MercadoPagoWebhookRecoveryService) solo corría si alguien la
        // ejecutaba a mano — así lo documentaba explícitamente el propio
        // comando como PRODUCTION BLOCKER pendiente. El comando en sí es
        // idempotente/seguro de re-ejecutar (nunca duplica créditos ni
        // reversals, ver su docblock), así que agendarlo es la única pieza
        // que faltaba.
        //
        // withoutOverlapping() usa como mutex el cache store por defecto
        // (config('cache.default')) — SOLO es un lock compartido entre
        // procesos/réplicas si ese store es realmente compartido
        // (database/redis). El default de config/cache.php (y de
        // .env.example) es 'file', que es local al contenedor — NO
        // asumir que esto protege contra solapamiento si mova-scheduler
        // llegara a escalar a más de una réplica sin haber configurado
        // CACHE_DRIVER=database primero (ver docs/DEPLOY_RAILWAY.md,
        // sección de invariantes del scheduler; mova:health-check avisa
        // si CACHE_DRIVER no es compartido en producción). Mientras tanto,
        // el invariante operativo real es: mova-scheduler corre con
        // EXACTAMENTE 1 réplica.
        //
        // CORREGIDO OTRA VEZ (payment scheduler isolation gate) — la ronda
        // anterior interpretó mal ->retry(2, 300): NO son 3 intentos.
        // Confirmado leyendo el código instalado de Laravel
        // (Illuminate\Http\Client\PendingRequest::retry(), que delega en el
        // helper global retry() de Illuminate\Support\helpers.php): el
        // primer argumento es "the number of times the request should be
        // attempted" — total, no "reintentos además del primero". Trazado
        // el loop del helper a mano con $times=2: intento 1 ($times pasa de
        // 2 a 1, 1 < 1 es falso → reintenta), intento 2 ($times pasa de 1 a
        // 0, 0 < 1 es verdadero → lanza). Son 2 intentos totales, con UN
        // solo sleep de 300ms entre ambos, no dos.
        //
        //   - timeout(15) es el techo real por intento (connectTimeout(5)
        //     es un sub-límite de la conexión, no se suma aparte).
        //   - fetchPayment()/searchPaymentsByExternalReference() con
        //     ->retry(2, 300, throw: false): 2 intentos ⇒ 2×15s + 1×0.3s
        //     ≈ 30.3s por llamada en el peor caso (NO 45.6s).
        //   - cancelPayment() NO reintenta: 15s por llamada.
        //   - batch_size = 200 por defecto (config/payments.php), aplicado
        //     de forma INDEPENDIENTE a cada una de las 6 pasadas de
        //     MercadoPagoWebhookRecoveryService::recover() (cada una con su
        //     propio ->limit($batchSize), no un presupuesto compartido):
        //     requeueStaleReceived/requeueOrExhaustFailed (sin HTTP, solo
        //     DB+dispatch, despreciable) + reconcileStuckOrders/
        //     reconcilePaidLookback (1 fetchPayment/fila ≈ 30.3s cada una) +
        //     reconcileUncertainSubmissions (1 search/fila ≈ 30.3s) +
        //     compensateLostChallenges (GET previo + cancelPayment + GET
        //     final ≈ 30.3+15+30.3 = 75.6s/fila).
        //
        //   3 pasadas de una sola llamada retried: 3 × 200 × 30.3s
        //   ≈ 18,180s ≈ 303 min.
        //   Compensación (3 llamadas/fila): 200 × 75.6s ≈ 15,120s ≈ 252 min.
        //   Total ≈ 303 + 252 = 555 min ≈ 9.25h — el mismo peor caso
        //   teórico absoluto de antes (todo el tráfico a Mercado Pago al
        //   límite de timeout en las 4 pasadas con HTTP, simultáneamente),
        //   solo que con la aritmética de retry() corregida.
        //
        // 900 minutos SIGUE siendo un margen de seguridad suficiente sobre
        // esta cota corregida (900 > 555, ~62% de margen) — no se cambia
        // solo porque la aritmética anterior estaba mal, como pide esta
        // ronda; el número ya era conservador de sobra incluso con el
        // error.
        //
        // runInBackground() (NUEVO): Laravel ejecuta los eventos agendados
        // SECUENCIALMENTE dentro de un mismo `schedule:run`
        // (ScheduleRunCommand: foreach ($events as $event) { $event->run() }
        // — confirmado leyendo el código instalado — y Event::execute()
        // usa Process::run(), que BLOQUEA hasta que el subproceso termina).
        // Sin runInBackground(), un barrido de mercadopago:reconcile que
        // tardara horas dejaría ese `schedule:run` sin poder evaluar
        // classmate:send-reminders/mova:settle-lessons SI estuvieran
        // registrados DESPUÉS de este comando — hoy no es así (este es el
        // último de los tres), pero depender del orden de declaración para
        // la correctitud es frágil, no un invariante real.
        //
        // Con runInBackground(), CommandBuilder::buildBackgroundCommand()
        // envuelve el comando en un subshell que termina en `&` (se
        // backgroundea de inmediato, sin bloquear el `schedule:run` que lo
        // lanzó) y encadena `php artisan schedule:finish "<mutex>" "$?"` al
        // final de ESE MISMO subshell — confirmado leyendo
        // CommandBuilder::buildBackgroundCommand() y ScheduleFinishCommand
        // instalados. finish() (que libera el mutex vía removeMutex()) se
        // dispara cuando el comando de verdad termina, no cuando el
        // scheduler pasa al siguiente evento — el mutex de
        // withoutOverlapping(900) sigue reflejando el runtime REAL del
        // comando, no se debilita ni se libera antes de tiempo.
        //
        // Sin cambio de observabilidad: la salida de consola de este
        // comando (la tabla de conteos) YA se redirigía a /dev/null por
        // defecto en foreground (Event::$output default, sin
        // ->sendOutputTo() configurado aquí) — runInBackground() no pierde
        // nada que ya fuera visible. La verdad operativa real (estado de
        // payment_webhooks/payment_orders, y cada Log::info/warning/
        // critical dentro de MercadoPagoWebhookRecoveryService/
        // MercadoPagoPaymentReconciliationService) sigue yendo al canal de
        // log configurado (LOG_CHANNEL=stderr en producción vía Railpack),
        // exactamente igual que antes.
        $schedule->command('mercadopago:reconcile')
            ->everyFiveMinutes()
            ->withoutOverlapping(900)
            ->runInBackground();

        // H-03 — El health-check ya existía, era completo y NADIE lo ejecutaba.
        //
        // Ese era el hallazgo: MOVA sabía detectar que la liquidación automática
        // estaba en dry_run en producción (créditos reservados para siempre),
        // que APP_DEBUG estaba encendido, o que el timeout del worker colisiona
        // con retry_after — y ese conocimiento no salía nunca de un comando que
        // había que recordar lanzar a mano.
        //
        // CADA HORA, no cada minuto: comprueba CONFIGURACIÓN, que solo cambia en
        // un deploy. Un barrido por minuto no detectaría nada antes y sí
        // ejecutaría ~1.400 consultas diarias inútiles a `jobs`/`failed_jobs`/
        // `users`. Una hora es el retardo máximo aceptable para enterarse de un
        // despliegue mal configurado.
        //
        // --alert hace que los avisos lleguen a los administradores vía
        // OperationalAlertService, deduplicados por código: mientras la
        // configuración siga mal, es LA MISMA incidencia, no una por hora.
        // Sigue siendo estrictamente de solo lectura sobre dinero y estados: lo
        // único que escribe es la fila de incidencia.
        $schedule->command('mova:health-check --alert')
            ->hourly()
            ->withoutOverlapping();

        // El reconciliador del ledger tampoco estaba agendado, así que un
        // descuadre entre credit_transactions y los saldos almacenados solo se
        // descubría si a alguien se le ocurría mirar.
        //
        // DIARIO Y DE MADRUGADA (03:10 hora del servidor), no cada hora: recorre
        // TODAS las clases y TODOS los perfiles sin paginar
        // (LedgerReconciliation::classifyLessons()), así que su coste crece
        // linealmente con el histórico. Un descuadre de ledger no es una
        // urgencia de minutos —el dinero ya está mal o ya está bien, y la
        // respuesta correcta es investigación manual, nunca un ajuste
        // automático— así que una comprobación diaria es proporcionada.
        //
        // El minuto :10 evita coincidir con la hora en punto, cuando ya corren
        // settle-lessons y health-check.
        $schedule->command('mova:reconcile-ledger --alert')
            ->dailyAt('03:10')
            ->withoutOverlapping()
            ->runInBackground();

        // §14 — Cierra las solicitudes que nadie respondió.
        //
        // CADA HORA, no cada minuto: la ventana es de 24 h
        // (CLASS_REQUEST_EXPIRY_HOURS), así que la precisión de un minuto no
        // aportaría nada y multiplicaría por 60 un barrido que nadie está
        // esperando. Con granularidad horaria, una solicitud caduca entre las
        // 24 h y las 25 h de vida — indistinguible para el usuario.
        //
        // Al minuto :30 para no competir con settle-lessons y health-check, que
        // corren en punto.
        $schedule->command('mova:expire-class-requests')
            ->hourlyAt(30)
            ->withoutOverlapping();

        // mova:reconcile-whatsapp NO se agenda en esta fase, a propósito.
        //
        // Es igual de read-only que los dos anteriores, pero su valor depende de
        // que WhatsApp esté realmente encendido (WHATSAPP_ENABLED=false por
        // defecto) y de que existan plantillas aprobadas por Meta. Agendarlo hoy
        // sería un barrido diario garantizado a cero filas. Cuando WhatsApp pase
        // a producción, este es el sitio donde debe añadirse.
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
