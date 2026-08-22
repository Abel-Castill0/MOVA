<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('classmate:send-reminders')->everyMinute();

        // C-1: agendado CON --dry-run a propósito. La primera ejecución real
        // requiere revisión humana del dry-run (Fase 3B §6, decisión de
        // negocio) — quitar la bandera es un cambio deliberado, no un ajuste
        // de despliegue. withoutOverlapping() es defensa en profundidad; la
        // garantía real contra doble liquidación es el UNIQUE de
        // idempotency_key dentro de LessonSettlementService.
        $schedule->command('mova:settle-lessons --dry-run')->hourly()->withoutOverlapping();
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
