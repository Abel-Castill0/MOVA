# AGENTS.md - MOVA

## Stack

MOVA usa Laravel 13, PHP 8.3+ (`composer.json`), Vue 3, Inertia, Tailwind CSS, Vite, MySQL, Spatie Permission y Azure Container Apps / Azure Database for MySQL. Railway es legado y no destino de despliegue. El proveedor de correo se define por entorno; no asumas SMTP, Gmail API, Resend ni otro proveedor sin verificar el codigo y la configuracion efectiva.

## Fuente De Verdad

- Confia en el codigo actual, migraciones, rutas, tests, estado Git y estado real del entorno.
- Nunca confies unicamente en resumenes historicos, reportes previos o memoria conversacional.
- Antes de modificar, inspecciona los archivos reales relacionados con la tarea.
- Si una integracion o flujo importa, compruebalo con herramientas de solo lectura o pruebas seguras.

## Seguridad

- Nunca imprimas secretos, tokens, contrasenas, claves API, refresh tokens, app passwords ni cadenas de conexion.
- No leas valores de `.env` salvo necesidad explicita; si debes leerlos, no los muestres.
- Para la tarea de integraciones autorizada por el propietario el 2026-10-05, Claude puede leer y usar internamente solo las variables necesarias de `.env` para identificar credenciales y ejecutar sondas locales/QA. No las imprimas, copies a comandos visibles, logs, archivos versionados ni documentos. La presencia de una credencial no demuestra que sea de prueba: valida el modo de forma segura antes de cualquier llamada externa.
- Autorización vigente del propietario (2026-10-05): para terminar las integraciones de MOVA, Claude puede usar el navegador autenticado del propietario y los paneles de Mercado Pago y JaaS; consultar/obtener las credenciales necesarias de prueba; guardarlas directamente en el `.env` local ignorado por Git; y configurar Azure staging para pruebas de sandbox. No pidas una segunda autorización para esos pasos concretos. Si la sesión de navegador no está realmente conectada a Claude Code, indícalo y pide al propietario solo que habilite la conexión o realice el paso que la interfaz no permita.
- La autorización anterior cubre sandbox y staging. No uses credenciales live, no habilites cobros reales ni despliegues a producción bajo esta autorización. Solo hazlo si una instrucción posterior del propietario menciona expresamente producción/live y el resultado concreto; en ese caso, no repitas una confirmación ya dada para esa acción y limita la operación a ese alcance.
- Autorización de completitud y lanzamiento (2026-10-05): el propietario solicita terminar MOVA de punta a punta, incluida producción en Azure y el dominio raíz `movaeduca.me` administrado en Namecheap. Para este objetivo, Claude puede preparar/desplegar los recursos de producción, hacer commit/push de los cambios validados que pertenecen a MOVA y ejecutar el cutover DNS una vez que la producción independiente esté lista y verificada. No apuntes `movaeduca.me` ni `www.movaeduca.me` a staging. Mantén staging separado y con sandbox; conserva intactos todos los MX/TXT y registros necesarios para el reenvío de correo. Usa `movaeduca.me` como dominio canónico y redirige `www` a ese dominio si el stack lo permite.
- Antes de crear recursos que generen cargos, revisa el plan/crédito disponible y el costo esperado con evidencia del portal o calculadora oficial. No compres un plan ni excedas el crédito/límite disponible. Si producción requiere un gasto fuera del crédito ya disponible, continúa todo el trabajo independiente y reporta el costo exacto y el bloqueo para que el propietario decida.
- La regla del propietario de rotar credenciales al final sigue vigente: primero completa y verifica código e integraciones con sandbox; prepara el runbook; cuando el propietario rote/active las credenciales live, Claude puede cargarlas directamente al almacén de secretos de producción y continuar el cutover sin exponerlas.
- Nunca muestres ni pegues secretos en la conversación, salida de terminal, comandos visibles, logs, archivos versionados, reportes, capturas ni documentación. Al copiar desde un panel, transfiérelos directamente al almacén de secretos o al `.env` local ignorado; verifica únicamente presencia, formato y modo, sin revelar valores.
- No modifiques `.env` real fuera del alcance autorizado arriba o de una autorización explícita del propietario para la tarea concreta.
- No modifiques produccion fuera del alcance expresamente autorizado en la tarea vigente.
- No ejecutes seeders en entornos reales.
- No actives IA, WhatsApp, pagos ni correo masivo sin autorizacion explicita.
- Para auditorias de secretos, lista solo nombres de archivos y nombres de variables.

## Git

- La rama de publicacion es `master`.
- No hagas commit, push ni deploy fuera del alcance expresamente autorizado en la tarea vigente.
- No uses `git reset --hard` salvo orden explicita.
- No reviertas cambios ajenos. Si encuentras cambios de otro autor, trabaja alrededor de ellos o pide instrucciones si bloquean la tarea.

## Azure

- Las consultas de solo lectura son permitidas cuando ayudan a diagnosticar el estado real.
- Para la tarea de integraciones autorizada por el propietario el 2026-10-05, se autoriza configurar Azure staging con credenciales sandbox/de prueba para Mercado Pago, correo de prueba, JaaS, almacenamiento y Sentry, y ejecutar verificaciones controladas. No cargues credenciales live en staging.
- Para el objetivo de completitud/lanzamiento autorizado el 2026-10-05, se autoriza preparar la infraestructura de producción Azure separada de staging, desplegar allí después de superar los gates técnicos/legales/operativos, y configurar Namecheap para que el dominio público llegue únicamente a producción. La autorización DNS requiere preservar MX/TXT/correo y conservar captura/registro de los valores previos para rollback. No migres datos QA/financieros de staging a producción.
- No generes cargos reales, no envíes correo masivo y no habilites funciones live sin credenciales live rotadas y el gate de lanzamiento correspondiente; la autorización de infraestructura/DNS no constituye autorización para gastar dinero de clientes.
- Revisa todas las migraciones antes de publicar.
- `php artisan migrate --force` forma parte del arranque de Azure y debe tratarse como riesgo critico de produccion.
- No consultes ni muestres valores secretos de Azure. Para revisar estado, muestra solo nombres, booleanos o valores no sensibles permitidos.

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
