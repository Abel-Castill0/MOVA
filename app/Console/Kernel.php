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
