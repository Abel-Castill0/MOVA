# Incidente P1 — horas distintas entre WhatsApp y calendario (2026-11-18)

## Hechos verificados (producción, solo lectura: `classes` id 2, Álgebra, alumno «Alumno QA…»)
| Dato | Valor |
|---|---|
| `start_time` guardado | `2026-11-16 09:30:00` UTC = **04:30 de Lima** |
| `duration_minutes` | 60 |
| `original_start_time` / `rescheduled_at` | `NULL` (nunca se reprogramó) |
| Solicitud creada | 2026-11-16 09:21:30 UTC (04:21 Lima) |
| Clase creada | 2026-11-16 09:22:09 UTC (04:22 Lima) |

La clase se creó 8 minutos antes de empezar (mínimo del formulario = ahora + 5 min): la intención del profesor fue **04:30 de Lima**, que coincide con lo guardado.
El dato no cambió después del mensaje (`updated_at` 09:23:01 UTC, 40 s después de crear la clase).

## Causa raíz
- **Guardado: correcto.** El formulario enviaba `new Date(datetime-local).toISOString()` desde un navegador en Lima → UTC exacto.
- **Mostrado: WhatsApp usaba UTC** («09:30»): el commit `3b40846` (2026-11-16 04:41 -0500) introdujo `LimaClock`, pero la imagen de producción hasta el 2026-11-17 (`27a5ce4`) aún no lo traía; el mensaje de la captura (sin «hora de Perú») es de código antiguo. No se editan mensajes ya enviados.
- **Calendario:** correcto solo cuando el navegador estaba en hora de Lima. `WeeklyCalendar.vue` usaba `getHours()/getDate()/setDate()/toLocale*` sin zona: con el reloj en otra zona mostraba otro día y otra hora. Además la rejilla fija 07:00–24:00 con `Math.max(0, …)` y `Math.min(…)` **comprimía una clase de las 04:30 contra la primera fila (07:00)**, superponiéndola.

## Corrección
- `resources/js/utils/limaTime.js`: `limaParts`, `limaDayKey`, `limaStartOfWeek`, `addLimaDays`, `limaMinutesOfDay`, `fmtLimaClock` (Lima = UTC-5 fijo; nunca métodos locales del navegador).
- `WeeklyCalendar.vue`: día, semana, encabezados, hora inicial/final, posición, tooltip, detalle, indicador de «ahora» (se refresca cada minuto; temporizador liberado al desmontar) y navegación entre semanas en Lima; el rango visible se **amplía dinámicamente** si hay clases fuera de 07:00–24:00; lo que cruza la medianoche se recorta en su columna. Etiqueta «hora de Perú» en cabecera y detalle.
- `weekGrouping.js::startOfWeek` ahora devuelve el lunes 00:00 de Lima (dashboards y listas agrupan por la misma semana).
- Backend sin cambios funcionales: recordatorios (`SendClassReminders`), ventana de JaaS (`LessonController::join`) y liquidación usan instantes absolutos con `now()`; **no estaban desplazados 5 h**. Todas las notificaciones nuevas pasan por `LimaClock`.

## Pruebas
- `tests/Frontend/limaCalendar.test.mjs` bajo `TZ=America/Lima|UTC|America/New_York|Europe/Madrid` (`npm run check:lima-time`): casos 09:30, 04:30, 23:30, 00:00, 23:59, semana, navegación, datetime-local↔ISO.
- `tests/Feature/LessonTimeConsistencyTest.php`: mismos casos en WhatsApp de confirmación, recordatorio y reprogramación; serialización ISO con `Z`.
- Navegador real (Chromium, entorno aislado, clases sintéticas 04:30/09:30/23:30 Lima): bloques idénticos con el navegador en las 4 zonas, escritorio y móvil; detalle = WhatsApp = correo (`04:30 (hora de Perú)`). Capturas: `incident-2026-11-18-time/`.

## Otras clases históricas
Ningún dato histórico se modifica. Las clases ya guardadas son correctas (el instante UTC es el elegido); solo cambió cómo se muestran.
