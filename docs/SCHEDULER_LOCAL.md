> **HISTORICAL SNAPSHOT** — verify against `docs/MOVA_SYSTEM_MAP.md` and current code.
> Documento de una fase anterior. Conservado como registro de decisiones, NO como
> descripcion fiable del comportamiento actual.

# Scheduler en desarrollo local

MOVA depende del scheduler de Laravel para enviar recordatorios de clase y
alertas pendientes. En producción (Railway) esto lo dispara un cron externo
que llama a `schedule:run` cada minuto. En local no hay cron, así que hay que
ejecutarlo a mano.

## Qué hace

`app/Console/Kernel.php` registra un único job, cada minuto:

```php
$schedule->command('classmate:send-reminders')->everyMinute();
```

`classmate:send-reminders` ([app/Console/Commands/SendClassReminders.php](../app/Console/Commands/SendClassReminders.php))
revisa la tabla `classes` y `class_requests` y envía:

- Recordatorio 24h antes de una clase `scheduled` (`reminder_24h_sent_at`)
- Recordatorio 2h antes (`reminder_2h_sent_at`)
- Recordatorio 10min antes (`reminder_sent`)
- Alerta al profesor si una clase `completed` lleva 2h+ sin reporte (`report_reminder_sent_at`)
- Alerta al profesor si una solicitud `open` lleva 12h+ sin respuesta (`request_reminder_sent_at`)

Cada aviso se marca con su propio timestamp para no reenviarse en la siguiente
corrida — es seguro llamar al comando repetidamente.

## Cómo correrlo en local

**Una sola vez** (para probar un flujo puntual, p. ej. después de sembrar una
clase con `LocalTestDataSeeder` cuyo `start_time` caiga en alguna de las
ventanas de arriba):

```bash
php artisan classmate:send-reminders
```

Imprime el conteo de notificaciones enviadas por categoría.

**Simulando el cron real** — dispara el scheduler tal como lo haría el cron de
producción, respetando el `everyMinute()` (solo ejecuta el comando si está
"due" en el minuto actual):

```bash
php artisan schedule:run
```

**En loop, para dejarlo corriendo mientras desarrollas** (equivalente a lo que
Railway hace con su cron externo, uno por minuto):

```bash
php artisan schedule:work
```

Este último es el más cómodo para sesiones largas de desarrollo: queda
corriendo en una terminal aparte y llama a `schedule:run` cada 60s
automáticamente hasta que lo detengas con Ctrl+C.

## Verificar que llegó la notificación

Los recordatorios se envían vía el sistema de notificaciones de Laravel
(canales configurados por el usuario — email / WhatsApp según
`WHATSAPP_ENABLED`). Para confirmar sin depender de un envío real, revisa la
tabla `notifications` o usa `php artisan tinker`:

```bash
php artisan tinker --execute="echo App\Models\User::find(1)->notifications()->latest()->first();"
```

## Nota

`schedule:list` muestra el próximo horario calculado para cada job registrado:

```bash
php artisan schedule:list
```
