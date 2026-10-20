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
- Autorización permanente de completitud (propietario, reafirmada el 2026-10-05): Claude debe terminar MOVA de punta a punta y puede usar las sesiones autenticadas disponibles y los paneles de Azure, Namecheap, Google/Gmail, Mercado Pago, JaaS, Cloudinary y Sentry para configurar staging y producción, crear aplicaciones/credenciales separadas por entorno, cargar secretos directamente en Azure, desplegar, configurar webhooks/DNS, y hacer commit/push de cambios validados de MOVA en `master`. No vuelvas a pedir permiso para esas acciones comprendidas en el objetivo de completar y publicar MOVA.
- Esta autorización permite probar integraciones live al final con datos sintéticos y una cuenta pagadora controlada por el propietario. Si una prueba financiera requiere un cargo, usa una sola transacción por el importe mínimo permitido y reembolsa después de verificarla. Nunca cargues a terceros, hagas pruebas repetidas, abras liquidaciones a profesores ni envíes correo masivo.
- Usa `movaeduca.me` como dominio canónico; `www` debe redirigir ahí. El propietario autoriza los cambios necesarios de registros DNS web en la cuenta Namecheap que administra el dominio: no vuelvas a pedir autorización por una diferencia en el nombre visible del registrante. Nunca cambies titular registral, contactos de propiedad ni transfieras el dominio. Conserva todos los MX, SPF, TXT y registros de correo; guarda el estado anterior y el rollback antes de modificar DNS. Si Namecheap exige una passkey, 2FA o acción que solo el titular puede completar, pide únicamente esa acción concreta.
- Azure y gastos: el propietario prioriza conservar el crédito existente. Verifica saldo, vencimiento, gasto real y previsión antes de cada cambio con costo. El saldo informado más reciente fue US$86, con vencimiento 2027-09-14; trátalo como límite total hasta que el portal confirme lo contrario. No actualices a Pay-As-You-Go, no añadas método de pago ni compres planes/servicios de pago. Optimiza réplicas y recursos con evidencia; solo elimina recursos de staging si verificaste que contienen datos sintéticos recreables, guardaste el backup/export necesario y el ahorro supera el impacto funcional. Las alertas de presupuesto no son un límite de gasto.
- Rotación final de credenciales: completa primero código y pruebas sandbox; al cerrar esa etapa, genera/rota las credenciales live desde los paneles y transfiérelas directamente a Azure. Nunca pidas que se peguen en chat ni las muestres. No reutilices en producción credenciales sandbox ni entre entornos cuando el proveedor permita aislamiento. Cualquier secreto mostrado/revelado en una pantalla, captura o salida se considera expuesto y debe renovarse antes de activar esa integración.
- Nunca muestres ni pegues secretos en la conversación, salida de terminal, comandos visibles, logs, archivos versionados, reportes, capturas ni documentación. Al copiar desde un panel, transfiérelos directamente al almacén de secretos o al `.env` local ignorado; verifica únicamente presencia, formato y modo, sin revelar valores.
- No modifiques `.env` real fuera del alcance autorizado arriba o de una autorización explícita del propietario para la tarea concreta.
- La autorización permanente anterior incluye las modificaciones necesarias de producción para completar y publicar MOVA, una vez superados los gates técnicos. No hagas cambios de producción ajenos a ese objetivo.
- No ejecutes seeders en entornos reales.
- Mantén IA, WhatsApp, pagos y liquidaciones desactivados hasta que su configuración esté completa y verificada; para las pruebas live finales sigue la autorización permanente anterior y sus límites.
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
- No cargues a terceros, no envíes correo masivo ni abras liquidaciones reales. Para completar el lanzamiento, la autorización permanente permite una sola prueba de pago live con cuenta controlada por el propietario, por el importe mínimo permitido, seguida de reembolso y registro de comisiones. Mantén pagos/webhooks cerrados hasta renovar credenciales expuestas y superar los gates técnicos.
- Revisa todas las migraciones antes de publicar.
- `php artisan migrate --force` forma parte del arranque de Azure y debe tratarse como riesgo critico de produccion.
- No consultes ni muestres valores secretos de Azure. Para revisar estado, muestra solo nombres, booleanos o valores no sensibles permitidos.
- Agrupa los cambios de configuración de Azure en la menor cantidad de revisiones posible. Lee costos por recurso y configura alertas, pero no presentes una alerta como tope de gasto.

## Dinero Y Creditos

- Toda operacion financiera debe ejecutarse dentro de `DB::transaction`.
- Usa `lockForUpdate` al leer o modificar saldos, reservas, cupos o aprobaciones financieras.
- El ledger de transacciones es la fuente de verdad de auditoria.
- Disena operaciones idempotentes ante reintentos, doble click, webhooks repetidos o fallas parciales.
- No permitas saldos negativos.
- No permitas doble consumo, doble reserva, doble liberacion ni doble deposito.
- Toda reserva, consumo, liberacion y deposito debe dejar transaccion de auditoria.
- Las pruebas de concurrencia son obligatorias antes de produccion para dinero, creditos, recargas y cupos.
- Las pruebas live financieras deben limitarse a una cuenta controlada por el propietario, un importe mínimo y una transacción; documenta fees y reembolso, y no involucres alumnos/profesores reales.

## Criterio De Tarea Terminada

Una tarea esta terminada solo si:

- El codigo solicitado esta implementado o la razon de bloqueo esta documentada.
- El build correcto fue ejecutado o se reporto por que no pudo ejecutarse.
- Los tests relevantes fueron ejecutados en entorno seguro o se documento por que no aplican.
- Los archivos modificados fueron enumerados.
- Los riesgos residuales fueron documentados.
- No se expusieron secretos.
- Producción solo puede modificarse dentro de las autorizaciones expresas de este archivo; la autorización permanente de completitud sí cubre los cambios de producción necesarios para terminar y publicar MOVA, una vez superados los gates técnicos, financieros y legales aplicables.
- Una acción pendiente del propietario no es motivo para detener el resto del trabajo: completa todas las tareas independientes y pide únicamente la acción concreta que el propietario deba realizar.
