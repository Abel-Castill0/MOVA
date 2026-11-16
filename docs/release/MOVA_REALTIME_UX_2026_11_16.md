# MOVA — Horarios y tiempo real / UX (rama de revisión, 2026-11-16)

## Alcance implementado en la rama

- LimaClock::formatClassDate() / formatClassDay(): presentación explícita en America/Lima de fechas UTC, sin mutar Carbon. Confirmación, recordatorio, reprogramación, cancelación (email, WhatsApp, texto de notificaciones); las fechas ISO de transporte siguen UTC.
- Las notificaciones de nuevas solicitudes elegibles, contraofertas, aprobación parental, rechazo y caducidad pueden anunciar transiciones al canal privado del usuario. Los payloads de broadcast con posible texto libre o datos de menores se reducen a metadatos y mensajes neutros. **No se ha modificado la lógica de elegibilidad padre → profesor.**
- AppLayout invalida props Inertia de las vistas pertinentes al recibir eventos, agrega deduplicación temporal y resincroniza en reconexión. Para solicitudes y créditos, el polling HTTP visible sigue funcionando si el WebSocket está deshabilitado. El servidor revalida auth/rol al refrescar.
- UX inicial: búsqueda docente móvil sin barra flotante, cabecera alineada con sidebar, cierre por Escape y trampa de foco del drawer móvil, fechas de avisos y créditos en Lima, mejor explicación de saldos, estados vacíos de solicitudes.

## Decisión de transporte — NO es SignalR

Se eligió **reutilizar Laravel Echo + Pusher** como transporte actual condicionado a la configuración efectiva BROADCAST_DRIVER=pusher. Se mantuvo null como estado seguro cuando no haya cuenta configurada. Ninguna variable de producción, servicio o gasto fue alterado por estos commits. No debe describirse como "SignalR implementado".

Comparación de opciones, **precios de lista y límites, no facturas de MOVA** (consultados el 16/11/2026):

| Alternativa | Ventaja | Limitación / costo |
|---|---|---|
| Pusher Channels + Echo (actual) | SDK PHP/JS y autorización privada ya existen; sin nuevo servidor propio | Sandbox US$0: hasta 100 conexiones simultáneas y 200 mil mensajes/día; Startup desde US$49/mes si excede, sin incluir otros costos |
| Azure SignalR Service con cliente Microsoft | Servicio administrado y protocolo SignalR real | Nivel gratuito: 20 conexiones simultáneas y 20 mil mensajes/día; requiere integrar negociación/autorización y transporte del backend Laravel (no cubierto aquí); estándar de pago requiere presupuesto y cotización regional |
| Laravel Reverb + Echo | Compatible con protocolo Pusher y Laravel 13 | Necesita proceso WebSocket persistente, ingreso seguro, memoria y posiblemente Redis/servicio/replicas de Azure; no se ha cotizado ni autorizado ese incremento |

Fuentes: https://pusher.com/channels/pricing/ ; https://azure.microsoft.com/es-es/pricing/details/signalr-service/ ; https://laravel.com/docs/13.x/reverb ; https://laravel.com/docs/13.x/broadcasting

**Costo incremental confirmado para esta rama: US$0 en nuevos recursos provisionados** (no se creó ninguno). **Costo real de producción y saldo Azure: no verificados** en esta sesión. No suponer que el tier gratis está disponible para la cuenta MOVA.

## Gates pendientes, NO PASS

1. PHPUnit: php artisan test --filter='NotificationLimaTimeTest|RealtimeRequestNotificationTest|WahaNotificationsTest|RealtimeConfigTest|NotificationSecurityTest' y suites de solicitudes/créditos.
2. npm ci && npm run build y Playwright en Chromium con cuentas QA y resolución móvil 320/375/390/768/1024/1440. Revisar especialmente drawer móvil, filtros, modalidades de pago sin compras reales y solicitudes dirigidas.
3. Dos sesiones independientes padre/profesor: comprobar llegada, seguridad del canal privado, mensajes sin PII, reconexión, eventos duplicados, error de red, actualización de saldos y rollback de estados fallidos. Verificar que la configuración del broadcaster en Azure esté habilitada y que el proveedor no exceda límites.
4. Es obligatoria la revisión integral de Create.vue, TeacherIndex.vue, Checkout*.vue y diseño compartido antes de declarar toda la misión cerrada. Los cambios visuales de esta rama son un avance puntual, no sustituyen un rediseño E2E.
5. Validar runtime Azure con los comandos del docs/release/MOVA_PRODUCTION_RUNBOOK.md, construir imagen GHCR inmutable y verificar digest, revisiones/healthz/readyz, UX de producción y DEPLOY_HISTORY.md. **No fusionar/desplegar hasta pasar gates.**

Estado: rama de trabajo; sin test ejecutado en entorno Laravel real, sin despliegue de Azure. Los resultados de producción existentes del incidente padre/profesor son previos a estos commits.
