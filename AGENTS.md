# AGENTS.md - MOVA

## Stack

MOVA usa Laravel 10, PHP 8.1+, Vue 3, Inertia, Tailwind CSS, Vite, MySQL, Spatie Permission y Railway. El proveedor de correo se define por entorno; no asumas SMTP, Gmail API, Resend ni otro proveedor sin verificar el codigo y la configuracion efectiva.

## Fuente De Verdad

- Confia en el codigo actual, migraciones, rutas, tests, estado Git y estado real del entorno.
- Nunca confies unicamente en resumenes historicos, reportes previos o memoria conversacional.
- Antes de modificar, inspecciona los archivos reales relacionados con la tarea.
- Si una integracion o flujo importa, compruebalo con herramientas de solo lectura o pruebas seguras.

## Seguridad

- Nunca imprimas secretos, tokens, contrasenas, claves API, refresh tokens, app passwords ni cadenas de conexion.
- No leas valores de `.env` salvo necesidad explicita; si debes leerlos, no los muestres.
- No modifiques `.env` real sin autorizacion explicita.
- No modifiques produccion sin autorizacion explicita.
- No ejecutes seeders en entornos reales.
- No actives IA, WhatsApp, pagos ni correo masivo sin autorizacion explicita.
- Para auditorias de secretos, lista solo nombres de archivos y nombres de variables.

## Git

- La rama de publicacion es `master`.
- No hagas commit sin autorizacion explicita.
- No hagas push sin autorizacion explicita.
- No hagas deploy sin autorizacion explicita.
- No uses `git reset --hard` salvo orden explicita.
- No reviertas cambios ajenos. Si encuentras cambios de otro autor, trabaja alrededor de ellos o pide instrucciones si bloquean la tarea.

## Railway

- Las consultas de solo lectura son permitidas cuando ayudan a diagnosticar el estado real.
- Cambiar variables requiere autorizacion explicita.
- Desplegar requiere autorizacion explicita.
- Revisa todas las migraciones antes de publicar.
- `php artisan migrate --force` forma parte del arranque en Railway y debe tratarse como riesgo critico de produccion.
- No consultes ni muestres valores de variables Railway sin autorizacion explicita.

## Dinero Y Creditos

- Toda operacion financiera debe ejecutarse dentro de `DB::transaction`.
- Usa `lockForUpdate` al leer o modificar saldos, reservas, cupos o aprobaciones financieras.
- El ledger de transacciones es la fuente de verdad de auditoria.
- Disena operaciones idempotentes ante reintentos, doble click, webhooks repetidos o fallas parciales.
- No permitas saldos negativos.
- No permitas doble consumo, doble reserva, doble liberacion ni doble deposito.
- Toda reserva, consumo, liberacion y deposito debe dejar transaccion de auditoria.
- Las pruebas de concurrencia son obligatorias antes de produccion para dinero, creditos, recargas y cupos.

## Criterio De Tarea Terminada

Una tarea esta terminada solo si:

- El codigo solicitado esta implementado o la razon de bloqueo esta documentada.
- El build correcto fue ejecutado o se reporto por que no pudo ejecutarse.
- Los tests relevantes fueron ejecutados en entorno seguro o se documento por que no aplican.
- Los archivos modificados fueron enumerados.
- Los riesgos residuales fueron documentados.
- No se expusieron secretos.
- Produccion no fue modificada salvo autorizacion explicita.
