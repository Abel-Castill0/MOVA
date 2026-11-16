# MOVA — Horarios y tiempo real / UX (rama de revisión, 2026-11-16)

## Alcance implementado en la rama

- LimaClock::formatClassDate() / formatClassDay(): presentación explícita en America/Lima de fechas UTC, sin mutar Carbon. Confirmación, recordatorio, reprogramación, cancelación (email, WhatsApp, texto de notificaciones); las fechas ISO de transporte siguen UTC.
- Las notificaciones de nuevas solicitudes elegibles, contraofertas, aprobación parental, rechazo y caducidad pueden anunciar transiciones al canal privado del usuario. Los payloads de broadcast con posible texto libre o datos de menores se reducen a metadatos y mensajes neutros. **No se ha modificado la lógica de elegibilidad padre → profesor.**
- AppLayout invalida props Inertia de las vistas pertinentes al recibir eventos, agrega deduplicación temporal y resincroniza en reconexión. Para solicitudes y créditos, el polling HTTP visible sigue funcionando si el WebSocket está deshabilitado. El servidor revalida auth/rol al refrescar.
- UX ampliada: listado compacto para profesor sin duraciones/créditos inventados, estados y próximos pasos para padre, wizard con verificación de código sin respuestas obsoletas y manejo de errores del servidor, sidebar agrupado por rol, accesibilidad de la campana, filtrado de últimos 20 movimientos y estados de recarga del profesor. Sin modificación de precios ni del ledger.
- Fechas también corregidas en dashboards, tarjetas de clases, informes y calendario semanal: fechas civiles de Lima independientes de la zona del navegador. Helper de JS y pruebas de medianoche UTC con tres zonas horarias diferentes.
- Root cause adicional de tiempo real: el host HTTP REST `api-<cluster>.pusher.com` se confundía con el host WebSocket `ws-<cluster>.pusher.com`; se añade normalización más desconexión al salir, y compatibilidad BROADCAST_CONNECTION/DRIVER. Los eventos de recarga acreditada/revertida y liquidación son únicamente señales de invalidación, no modifican saldos.

## Decisión de transporte — NO es SignalR

Se eligió **reutilizar Laravel Echo + Pusher** como transporte actual condicionado a la configuración efectiva BROADCAST_CONNECTION=pusher (con fallback de compatibilidad para BROADCAST_DRIVER). Se mantuvo null como estado seguro cuando no haya cuenta configurada. Ninguna variable de producción, servicio o gasto fue alterado por estos commits. No debe describirse como "SignalR implementado".

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


## Ejecución de pruebas y límites verificables (segunda revisión)

**Ejecución local aislada:** Node.js 22 ejecutó los casos del formateador Pusher (2/2); la lógica del calendario de Lima se reprodujo y comprobó con los mismos casos (2/2) bajo TZ=UTC, TZ=America/Lima y TZ=Asia/Tokyo (seis casos en total, 0 fallos). Se usaron archivos de prueba reproducidos en un entorno auxiliar porque el checkout Git no puede acceder a Internet en esta sesión. Las pruebas están versionadas en este PR pero **no fueron ejecutadas desde un checkout íntegro**; por tanto, esto no equivale a QA PASS del repositorio, ni a una compilación de Vue.

**Gates aún sin ejecutar:** PHP/Laravel PHPUnit, MariaDB/MySQL QA, suite de roles y transacciones, npm ci/build, Vue compiler, Playwright de padre y profesor, capturas responsive y pruebas reales WebSocket. No se ha obtenido configuración, estado o presupuesto actual de Azure ni generado/inspeccionado una imagen GHCR. Browser conectado no disponible en la sesión; CLI de Azure, Docker y red saliente tampoco disponibles. Se preserva master y el código de producción previo.

**Comandos de cierre en un entorno con el checkout y dependencias:**

~~~sh
git fetch origin
git switch feat/mova-lima-realtime-ux-20261116
git pull --ff-only
git diff --check origin/master...HEAD
node --test tests/Frontend/pusherHosts.test.mjs tests/Frontend/peruDate.test.mjs
npm ci && npm run build
php artisan test --filter='NotificationLimaTimeTest|RealtimeRequestNotificationTest|WahaNotificationsTest|RealtimeConfigTest|NotificationSecurityTest'
php artisan test --filter='ClassRequest|Credit|Recharge|Culqi|Authorization'
~~~

Después: MySQL QA aislado (sin producir cobros), Playwright móvil/escritorio, dos perfiles independientes, E2E de notificaciones sin F5, comprobar ocupación y costo de los servicios de tiempo real, y **recién si es PASS** construir el digest GHCR, desplegar a Azure por runbook, registrar rollback y producción en DEPLOY_HISTORY.md. GitHub Actions no forma parte de esta ruta.

## Criterio de seguridad para el merge

Esta rama contiene alteraciones de UX, frontend de solicitudes y mensajes de notificaciones. Al no haber build ni Playwright real, **no está aprobada para fusionar a master**. Un merge por petición del propietario sin pruebas arriesgaría el flujo padre→profesor y el checkout, que sí estaban operativos antes. La infraestructura y la producción permanecen sin cambios hasta verificar. Se requiere revisar especialmente la cola activa (scheduler ejecuta queue:work y el worker dedicado está detenido, según el último registro histórico), status real del broadcaster y permisos de usuario.
