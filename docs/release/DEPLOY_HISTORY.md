# MOVA — Historial de despliegues a producción (Azure)

> Registro **temporal por despliegue**: las instrucciones permanentes siguen en
> [`MOVA_PRODUCTION_RUNBOOK.md`](MOVA_PRODUCTION_RUNBOOK.md). Sin secretos ni URLs con tokens. Añade una entrada nueva
> (la más reciente arriba) en cada despliegue.

## 2026-10-23 (12) — `8a33594` (master) → producción — cuentas suspendidas o con deuda pueden cerrarse (lista de bloqueo por hash)

| Campo | Valor |
|---|---|
| Cambio | Decisión del titular: el cierre automático ya no deriva a soporte a cuentas suspendidas o con saldo negativo; su correo y teléfono (solo hashes HMAC) van a `closure_blocklist` y el registro, el alta con Google y la verificación de teléfono los rechazan. Ver [`ACCOUNT_LIFECYCLE.md`](../ACCOUNT_LIFECYCLE.md) |
| CI | [39958604239](https://github.com/Abel-Castill0/MOVA/actions/runs/39958604239): Composer, frontend, SQLite, MySQL y E2E **success** |
| Imagen | `ghcr.io/abel-castill0/mova@sha256:4badbcc90f760680e1aac475031c73a4185089d419b606acfe0d349d319cd559`; workflow [39959754972](https://github.com/Abel-Castill0/MOVA/actions/runs/39959754972) **success** (etiqueta `img-20261023-8a33594`); revisión OCI = SHA completo, sin `.env` |
| Respaldo y migración | Backup manual `predeploy-8a33594-20261023`; Job `movap-migrate`: `2026_10_23_080000_create_closure_blocklist` (tabla nueva, aditiva) DONE 153 ms; job eliminado, antes de cambiar imágenes |
| Aplicaciones | Scheduler y web `0000034` → `0000035`, ambos Healthy, 0 errores en logs; solo `--image`; escala, secretos y worker (Stopped) sin cambios; pagos sin cambios (`mercadopago`) |
| Pruebas | SQLite completa **1656 pruebas, 7327 aserciones, OK** (7 omitidas); `AccountLifecycleTest` 45; MySQL: 109 pruebas afectadas y 15 escenarios multiproceso OK |
| Verificación en producción | `/readyz` ready; `/`, `/login`, `/register`, `/marketplace`, `/healthz` 200; rutas privadas → 302. Sin cierres reales ejecutados |
| Rollback | Imagen `sha256:bea825f22e058dad19ebef92465164baf76c00f0a3cb1060c15f2c4093923df0` (`b220cd2`, revisión `0000034`); la tabla nueva es inerte para ese código (pero entonces la lista de bloqueo no se aplicaría). Sin `migrate:rollback` |

## 2026-10-23 (11) — `b220cd2` (master) → producción — eliminación automática de cuentas (autoservicio)

| Campo | Valor |
|---|---|
| Cambio | «Eliminar mi cuenta» cierra y suprime la cuenta sin aprobación de soporte: cancela clases futuras, republica solo solicitudes generales cuando cierra un profesor, da de baja el saldo no reembolsable con asiento `forfeiture` aceptado expresamente, anonimiza datos personales, libera correo/teléfono y revoca sesiones. Cuentas del flujo provisional reconfirman y completan por sí mismas. Detalle: [`ACCOUNT_LIFECYCLE.md`](../ACCOUNT_LIFECYCLE.md) |
| CI | [39948857900](https://github.com/Abel-Castill0/MOVA/actions/runs/39948857900): Composer, frontend, SQLite, MySQL y E2E **success** |
| Imagen | `ghcr.io/abel-castill0/mova@sha256:bea825f22e058dad19ebef92465164baf76c00f0a3cb1060c15f2c4093923df0`; workflow *Image (GHCR)* [39949831944](https://github.com/Abel-Castill0/MOVA/actions/runs/39949831944) **success** (etiqueta git `img-20261023-b220cd2`); revisión OCI = SHA completo, sin `.env`, con manifest de Vite y la migración |
| Respaldo previo | Backup manual `predeploy-b220cd2-20261023` (12:00Z) además de PITR 14 d |
| Migración | `2026_10_23_070000_automatic_account_closure` (aditiva: `users.account_erased_at`, `class_requests.republished_at`, columnas de `account_deletion_requests`, valor `forfeiture` del enum `credit_transactions.type`). Job `movap-migrate`: `migrate:status --pending` mostró solo esa; `migrate --force` → DONE 311 ms; job eliminado. Antes de cambiar imágenes (el código nuevo lee las columnas) |
| Aplicaciones | Scheduler `0000033` → `0000034` (Healthy, 0 errores en logs) y después web `0000033` → `0000034` (Healthy, 100 %). Solo `--image`; sin cambios de variables, secretos ni escala (web 1–2, scheduler 1–1); worker **Stopped** |
| Pagos y finanzas | Sin cambios: `PAYMENT_PROVIDER=mercadopago`, `PAYMENTS_ENABLED=true` (histórico), sin Culqi. Sin cobros, reembolsos, seeders ni cierres de cuentas reales en producción |
| Verificación en producción | `/readyz` ready; `/`, `/login`, `/register`, `/marketplace`, `/servicios`, `/privacidad`, `/healthz` 200; rutas privadas sin sesión → 302; `/cuenta/solicitud` sin sesión → 404; el manifest incluye `ClosureStatus`; 0 errores en logs. **No verificado en producción**: el flujo autenticado de eliminación (se probó solo con datos sintéticos en QA local/MySQL) |
| Pruebas locales | SQLite completa **1651 pruebas, 7290 aserciones, 7 omitidas, OK**; `AccountLifecycleTest` 44; perfil MySQL de PHPUnit 154 afectadas OK; **15 escenarios multiproceso MySQL** (doble cierre, cierre vs reserva/solicitud/aprobación admin/recarga/liquidación/cancelación); build y checks npm OK; navegador (profesor con clase y saldo → pantalla «cerrada»). Revisiones `mova-security-finance` (1 alto, 3 medios y bajos corregidos con pruebas; 1 decisión del titular) y `mova-vue-reviewer` |
| Rollback | Imagen: `ghcr.io/abel-castill0/mova@sha256:be20af5505924268021980a6edefdc0cec00a85eecf282924bb2ca4aaeb7ab07` (`132b6af`, revisión `0000033`) a scheduler y web. El esquema es aditivo: **no** `migrate:rollback`. Las supresiones ya ejecutadas son irreversibles; el código anterior no conoce `forfeiture` (la conciliación del ledger lo ignoraría) y trataría una cuenta cerrada de forma restringida. Hoy no se ha ejecutado ninguna supresión real |
| Pendientes | Redacción de Términos sobre pérdida de saldo no usado, validación legal de la retención (ledger, recargas, reclamaciones, tributaria) y del proceso de devolución de créditos reembolsables, y política para cuentas suspendidas o con deuda (hoy: soporte) |

## 2026-10-23 (10) — `132b6af` (master) → producción — una cuenta cerrada puede cancelar sus clases programadas

| Campo | Valor |
|---|---|
| Cambio | Decisión del titular: `EnsureAccountIsOpen` exceptúa solo `lessons.cancel`; el comprobante lista las clases programadas (sin datos del alumno) con cancelación en dos pasos. Autorización, devolución de la reserva y avisos siguen en `LessonController::cancel` |
| CI | [39917354455](https://github.com/Abel-Castill0/MOVA/actions/runs/39917354455): Composer, frontend, SQLite, MySQL y E2E **success** |
| Imagen | `ghcr.io/abel-castill0/mova@sha256:be20af5505924268021980a6edefdc0cec00a85eecf282924bb2ca4aaeb7ab07`; workflow *Image (GHCR)* [39918352032](https://github.com/Abel-Castill0/MOVA/actions/runs/39918352032) **success** (etiqueta git `img-20261023-132b6af`); revisión OCI = SHA completo, sin `.env`, con manifest de Vite |
| Aplicaciones | Scheduler `0000032` → `0000033` (Healthy) y después web `0000032` → `0000033` (Healthy, 100 %). Solo `--image`; sin migraciones, variables, secretos ni escala (web 1–2, scheduler 1–1); worker **Stopped**; pagos sin cambios (`mercadopago`, sin Culqi) |
| Pruebas | SQLite completa **1640 pruebas, 7189 aserciones, 7 omitidas, OK**; `AccountLifecycleTest` 29; 13 escenarios MySQL multiproceso (incluye cierre↔cancelación en ambos órdenes: un solo reembolso, sin interbloqueos); build y checks npm; navegador local con cuenta sintética (lista, confirmación y cancelación) |
| Verificación en producción | `/readyz` ready; `/`, `/login`, `/marketplace`, `/servicios`, `/privacidad`, `/healthz` 200; rutas privadas sin sesión → 302; `/cuenta/solicitud` 404 sin sesión; 0 errores en los logs de las revisiones nuevas. El flujo autenticado no se ejecutó en producción |
| Rollback | Reaplicar `ghcr.io/abel-castill0/mova@sha256:330d3a416e544f1291b9bb01df745315f4475279555155606cbfd31a0c6b4919` (`cadd779`, revisión `0000032`) a scheduler y web; sin cambios de esquema |

## 2026-10-23 (9) — `cadd779` (master) → producción — perfil, menú de cuenta y solicitud de cierre de cuenta

| Campo | Valor |
|---|---|
| Código | `cadd7796d8b9b973fd6cfc2a80e2c3cd1b7fe368`; funcionalidad `4501c2f`, ajuste del E2E (logout desde el menú del avatar) `cadd779` |
| CI | Funcionalidad `4501c2f`: Composer, frontend, SQLite y MySQL verdes; **E2E falló** (el helper de logout buscaba el botón antiguo del sidebar). Corregido en `cadd779`: [39912844557](https://github.com/Abel-Castill0/MOVA/actions/runs/39912844557) Composer, frontend, SQLite, MySQL y E2E **success** |
| Imagen | `ghcr.io/abel-castill0/mova@sha256:330d3a416e544f1291b9bb01df745315f4475279555155606cbfd31a0c6b4919`; workflow *Image (GHCR)* [39913576103](https://github.com/Abel-Castill0/MOVA/actions/runs/39913576103) **success** (etiqueta git `img-20261023-cadd779`, etiqueta de imagen `cadd779`); revisión OCI = SHA completo, sin `.env`, con manifest de Vite y la migración |
| Respaldo previo | Backup manual `predeploy-cadd779-20261023` (completo, 06:49Z) además de PITR 14 d |
| Migración | `2026_10_23_060000_create_account_deletion_requests` (aditiva: `users.account_closed_at` nullable indexado + tabla `account_deletion_requests`). Job manual `movap-migrate` (misma imagen y env/secretos Key Vault de `movap-web`): `migrate:status --pending` mostró **solo** esa migración; `migrate --force` → DONE en 723 ms; job eliminado. **Antes** de cambiar imágenes: el código nuevo consulta la columna en cada petición |
| Aplicaciones | Scheduler `0000031` → `0000032` (Healthy, 0 errores en logs) y después web `0000031` → `0000032` (Healthy, 100 % tráfico). Solo `--image`; sin cambios de variables, secretos, escala (web 1–2, scheduler 1–1) ni infraestructura. Worker independiente **Stopped** |
| Pagos y finanzas | Sin cambios: `PAYMENT_PROVIDER=mercadopago`, `PAYMENTS_ENABLED=true` (Mercado Pago solo histórico), sin llaves de Culqi; sin cobros, reembolsos, seeders ni cuentas QA en producción; ningún cierre de cuenta real |
| Verificación en producción | `/readyz` ready (BD, migraciones, caché), `/healthz`, `/`, `/login`, `/register`, `/marketplace` (consulta la columna nueva), `/servicios`, `/quienes-somos`, Términos, Privacidad, Devoluciones y Libro 200; `/profile`, `/account/settings`, `/dashboard` y `/admin/account-deletions` sin sesión → 302 a `/login`; `/cuenta/solicitud` sin sesión → 404; `DELETE /profile` sin sesión → 302; el manifest de Vite incluye los chunks nuevos; 0 errores/excepciones en los logs de las revisiones nuevas. **No verificado en producción**: el flujo autenticado (cierre de cuenta, menú, bandeja admin): se probó solo en QA local con cuentas sintéticas para no cerrar cuentas reales |
| Pruebas locales | SQLite completa **1638 pruebas, 7146 aserciones, 7 omitidas, 35 deprecaciones, OK**; `AccountLifecycleTest` 27 y perfil MySQL (121 pruebas afectadas) OK; 11 escenarios de carreras multiproceso en MySQL OK; build y checks npm OK. Revisiones `mova-security-finance` (sin críticos/altos; 1 decisión del titular) y `mova-vue-reviewer` (el alto y los medios aplicables corregidos; textos de botón conforme a la definición del producto) |
| Rollback | **Imagen**: `ghcr.io/abel-castill0/mova@sha256:3360c62315e5ec030a09740108360fc6d6dbc45443d838976c22f94442139c29` (`ae5a220`, revisión `0000031`) en scheduler y web con `az containerapp update --image`. El esquema es aditivo: **no** ejecutar `migrate:rollback`. **Advertencia**: ese código anterior ignora `account_closed_at`; si ya existiera alguna cuenta cerrada, recuperaría acceso a su dashboard. Nosotros no ejecutamos ningún cierre real (no verificado por consulta a la BD de producción), de modo que el rollback inmediato es el caso de menor riesgo; si se hiciera después de cierres reales, preferir corrección hacia adelante |
| Pendientes | Política de conservación y borrado definitivo (deshabilitado), decisión sobre cancelar clases con cuenta cerrada, envío real del código por correo (solo probado con transporte simulado), validación legal de plazos y representación de menores |

Detalle funcional: [`ACCOUNT_LIFECYCLE.md`](../ACCOUNT_LIFECYCLE.md).

## 2026-10-23 (8) — `ae5a220` (master) → producción — cinco paquetes y contacto autorizado

| Campo | Valor |
|---|---|
| Código | `ae5a22034f9da4586312eaf6f8fb37245026fcb5`; implementación `ef913d9`, ajuste E2E `ae5a220` |
| CI | [39902196077](https://github.com/Abel-Castill0/MOVA/actions/runs/39902196077): Composer, frontend, SQLite, MySQL y E2E **success** |
| Imagen | `ghcr.io/abel-castill0/mova@sha256:3360c62315e5ec030a09740108360fc6d6dbc45443d838976c22f94442139c29`; [workflow 39902256087](https://github.com/Abel-Castill0/MOVA/actions/runs/39902256087) **success**; etiqueta `img-20261023-ae5a220`, revisión OCI comprobada, sin `.env` y con manifest de Vite |
| Aplicaciones | Scheduler `0000030` → `0000031`, comprobado Healthy antes de actualizar web `0000030` → `0000031`; ambas Running y listas. Worker independiente permanece Stopped |
| Cambio de configuración | Solo `LEGAL_SUPPORT_PHONE=+51916666934` en web, autorizado por el titular. Sin cambios de secretos/proveedor, escala, infraestructura o base de datos |
| Escala conservada | Web min1/max2; scheduler min1/max1 |
| Verificación pública | `/readyz`, `/healthz`, `/servicios`, cinco detalles, `/quienes-somos`, Términos, Privacidad, Devoluciones y Libro HTTP200; importes 1000/1980/2940/3880/5760 centavos; teléfono visible en Devoluciones; `payments.online=false` |
| Pruebas locales | SQLite y MySQL: 1611 pruebas cada uno; siete omitidas y 35 deprecaciones. Tres carreras de ocho procesos GREEN; build, checks npm, MCP 19/19. Sandbox real aprobado/rechazo/devolución; navegador Esencial S/19.80 → diez créditos, un depósito tras conciliaciones repetidas |
| Datos y finanzas | Sin migraciones/seeders/cobros reales/cuentas QA en producción. PITR14d confirmado. Históricos mantienen precios aceptados |
| Costos | Sin recursos ni réplicas añadidos. Costos por recurso consultados; saldo exacto y previsión no disponibles (API404/429). No afirmar crédito restante verificado |
| Pendientes | Revisión legal (incluida regla existente de siete días), aceptación del modelo por Culqi, QA HTTPS pública, entrega real de webhook, 3DS interactivo (HTTP400 antes del reto), tarifas contractuales y alojamiento comercial compatible |
| Rollback | Reaplicar a ambas apps `ghcr.io/abel-castill0/mova@sha256:f5b5aa9a9f55c01ce9f42009f59fd8fc518fd5e726533e110a9f1875edbd5c0a`. Para revertir también contacto, eliminar solo `LEGAL_SUPPORT_PHONE` con `--remove-env-vars`; antes no existía. No hay rollback de BD |

Evidencia y matriz comercial: [informe actualizado](../culqi-commercial-review.md). La documentación de cierre se comitea después del despliegue; el código de la imagen sigue siendo `ae5a220`.

## 2026-10-23 (7) — `546d44c` (master) → producción — catálogo público, política de devoluciones y cuenta de evaluador (revisión comercial de Culqi)

| Campo | Valor |
|---|---|
| Commit | `546d44c` (`feat(catalog): public services catalog, refund policy and evaluator account for the Culqi commercial review`); CI de GitHub Actions en `success` |
| Imagen | `ghcr.io/abel-castill0/mova@sha256:f5b5aa9a9f55c01ce9f42009f59fd8fc518fd5e726533e110a9f1875edbd5c0a` (workflow *Image (GHCR)* por la etiqueta `img-20261023-546d44c`, éxito) |
| Apps | `movap-scheduler` `0000029` → `0000030`, luego `movap-web` `0000029` → `0000030` (solo `--image`; sin cambios de variables ni secretos; worker **Stopped**) |
| Imagen anterior (rollback) | `ghcr.io/abel-castill0/mova@sha256:f26a1e0d51beda363c857bb32d9017a3af6cd0bda2b05d5c1baced5682a9466f` (`b1076c4`) → `az containerapp update … --image <ese digest>`; sin migraciones |
| Efecto | Páginas públicas nuevas (`/servicios`, `/comprar-creditos/{paquete}`, `/politica-de-devoluciones`); Términos y Privacidad pasan a la versión 2026-10-23 → **todos los usuarios deben aceptarlos de nuevo al entrar** |
| Verificación | revisión nueva sana; `/readyz`, `/healthz`, `/`, `/servicios`, detalle de los 3 paquetes, devoluciones, libro, términos, privacidad y sitemap 200; paquete inexistente 404; checkout sin sesión 302; configuración de pagos sin cambios (`PAYMENT_PROVIDER=mercadopago`, sin `CULQI_*`); banner «Pago en línea en activación» visible |
| Pruebas | SQLite completa 1608 OK (con `.env` equivalente al CI); checks npm y MCP OK; `git diff --check` limpio |
| No verificado | cobro con tarjeta de prueba dentro del iframe de Culqi en navegador (no automatizable); cuenta de evaluador en producción (pendiente del titular) |

## 2026-10-23 (6) — `b1076c4` (master) → producción — corrección del CI de `7f69072` (sin cambios de comportamiento en producción)

| Campo | Valor |
|---|---|
| Motivo | El CI de `7f69072` falló (SQLite y MySQL): `culqiLegacyProcessing()` exigía llave secreta aun con Culqi como proveedor (rompía `SchedulerConfigurationTest` en un entorno sin llaves, como el del CI con `.env.example`) y `MercadoPagoRetirementTest` dependía del `.env` local. Las pruebas locales habían pasado porque usaban el `.env` del desarrollador: **validar siempre también con un `.env` equivalente al del CI** (copia de `.env.example` como `.env.testing`) |
| Commit | `b1076c4` (`fix(payments): keep culqi:reconcile scheduled whenever Culqi is the provider; make the retirement test hermetic`); CI de GitHub Actions en `success` |
| Imagen | `ghcr.io/abel-castill0/mova@sha256:f26a1e0d51beda363c857bb32d9017a3af6cd0bda2b05d5c1baced5682a9466f` (workflow *Image (GHCR)* por la etiqueta `img-20261023-b1076c4`, éxito) |
| Apps | `movap-scheduler` `0000028` → `0000029`, luego `movap-web` `0000028` → `0000029` (solo `--image`; sin cambios de variables ni secretos; worker **Stopped**) |
| Imagen anterior (rollback) | `ghcr.io/abel-castill0/mova@sha256:0d2fbb41d77fd5491c8b0e14ed10c8052ae9c45e91a947065f915847e699216e` (`7f69072`) → `az containerapp update … --image <ese digest>`; sin migraciones |
| Verificación | revisiones nuevas `Healthy`; `/readyz` `ready`; `/`, `/quienes-somos`, `/login`, `/register`, `/healthz` 200; checkout sin sesión 302; webhook de Mercado Pago sin firma 401; Culqi 404; scheduler ejecutando recordatorios, latido y `WorkerHeartbeatJob`; `PAYMENT_PROVIDER=mercadopago`, `PAYMENTS_ENABLED=true`, sin `MERCADOPAGO_NEW_PAYMENTS_ENABLED` (compras nuevas cerradas) |
| Pruebas (entorno equivalente al CI) | SQLite completa 1590 OK; MySQL completa 1590 OK; carreras multi-proceso GREEN (ejecutadas antes con el mismo código de pagos) |
## 2026-10-23 (5) — `7f69072` (master) → producción — Culqi único proveedor de compras nuevas; Mercado Pago retirado para pagos nuevos

| Campo | Valor |
|---|---|
| Commit | `7f6907256769f2f072c82b5fd7a87cf1dd5ab50c` (`feat(payments): Culqi as the only provider for new purchases; retire Mercado Pago for new payments`). Incluye también `994e180` (el aviso por correo de `credit()`/`reverse()` ya no revierte el ledger) |
| Imagen | `ghcr.io/abel-castill0/mova@sha256:0d2fbb41d77fd5491c8b0e14ed10c8052ae9c45e91a947065f915847e699216e` (tag `7f69072`, workflow *Image (GHCR)* disparado por la etiqueta `img-20261022-7f69072`, éxito; etiqueta OCI `revision` = SHA completo) |
| Apps | `movap-scheduler` (cola + scheduler) `0000027` → `0000028`, luego `movap-web` `0000027` → `0000028` (solo `--image`; variables y secretos **sin cambios**; el worker sigue **Stopped**) |
| Imagen anterior (rollback) | `ghcr.io/abel-castill0/mova@sha256:59cb9f4e25ab18400d4ea7459e7d5c1edbac28c7a2a6fe4803e6fd27b329b2a2` → `az containerapp update -g mova-prod-rg -n movap-web\|movap-scheduler --image <ese digest>` (mismos `command`/`args`; no hay migraciones) |
| Migraciones | ninguna |
| Efecto en producción | Con `PAYMENT_PROVIDER=mercadopago` y **sin** `MERCADOPAGO_NEW_PAYMENTS_ENABLED` (default `false`), **MOVA ya no acepta compras nuevas**: `store` 503, `pay` 410, el proveedor se niega y `CheckoutProvider::active()` es `null` (la pantalla de créditos muestra «Las recargas se habilitarán próximamente»). No hay respaldo hacia Mercado Pago ni `fake`. Webhook y conciliación de Mercado Pago, `mercadopago:reconcile` y devoluciones **siguen activos**. Culqi sigue inactivo (sin llaves) |
| Verificación | web y scheduler revisión nueva `Healthy` (web con el 100 % del tráfico); `/readyz` `ready`; `/`, `/quienes-somos`, `/login`, `/register`, `/healthz` 200; `POST /teacher/credits/checkout` sin sesión 302; webhook de Mercado Pago sin firma 401; webhook de Culqi 404; el scheduler ejecuta `classmate:send-reminders`, `mova:heartbeat` y `WorkerHeartbeatJob`; sin errores en logs |
| Pruebas previas al despliegue | suite SQLite completa 1590 OK; perfil MySQL de pagos 564 OK; carreras multi-proceso (reconcile-paid, reconcile-refund, charge-attempt, 12 procesos) GREEN; build Vite y checks npm OK; MCP 19/19; hooks 79/79; revisión `mova-security-finance` sin críticos/altos (los medios se corrigieron) |
| No verificado en producción | el recorrido de un profesor autenticado (no hay credenciales de profesor en producción y se prohíben compras reales): el comportamiento «compras cerradas» está cubierto por `MercadoPagoRetirementTest` |
| Activación de Culqi | **bloqueada**: ver `docs/payments-culqi.md` §14.1 (Azure for Students no admite uso comercial, no hay llaves live, comercio y webhooks sin registrar) |
## 2026-10-22 (4) — C-3: imagen de producción servida desde ghcr.io (autorizado por el titular)

| Campo | Valor |
|---|---|
| Cambio | `movap-web` y `movap-scheduler` pasan de `movaacrsplisbj6ldoqw.azurecr.io/mova@sha256:fece8113…` a `ghcr.io/abel-castill0/mova@sha256:59cb9f4e25ab18400d4ea7459e7d5c1edbac28c7a2a6fe4803e6fd27b329b2a2` (tag `b43290d`, construida por GitHub Actions desde un commit que solo añade el workflow sobre `a32a1f0`; mismo código, 91 ficheros de `public/build` con otro hash). Solo cambió `image`: variables, secretos, sondas, escala y `args` idénticos |
| Revisiones | web `movap-web--0000026` → `0000027` · scheduler `movap-scheduler--0000026` → `0000027` (el worker sigue **Stopped**) |
| Orden y verificación | scheduler primero (sano, ambos procesos y 3 tareas ejecutándose), luego web (revisión nueva Healthy antes de recibir tráfico). `/`, `/quienes-somos`, `/login`, `/register`, `/forgot-password`, `/privacidad`, `/terminos`, `/healthz`, `/readyz`, manifest de Vite: 200; el asset `app-DIRbwJtj.js` servido coincide con el de la imagen de ghcr.io; webhook de Mercado Pago sin firma 401, Culqi 404 (inactivo); `PAYMENT_PROVIDER=mercadopago`; 0 errores en los logs del web |
| ACR | **intacto** (`movaacrsplisbj6ldoqw`, con `fece8113…`, `bf92e6dc…`, `237d93c8…`) y aún configurado como registro de las apps; sigue facturando US$ 5,0/mes hasta que el titular apruebe eliminarlo (no antes de ≥ 1 semana) |
| Rollback | `PATCH` de cada app con `image = movaacrsplisbj6ldoqw.azurecr.io/mova@sha256:fece8113f637667f47240a8f744812c45f608d31b3253e94b3a51c3d5fa964e4` (mismos campos `name,image,command,args,env,resources,probes`); crea una revisión nueva sin interrupción |

## 2026-10-22 (3) — mismo digest `fece8113…` — C-1: worker + scheduler en un contenedor (sin imagen nueva)

| Campo | Valor |
|---|---|
| Cambio | `movap-scheduler` pasa a ejecutar `queue:work` + `schedule:work` con `docker/mova-supervise.sh` (`command: ["mova-entrypoint"]`, `args: ["bash","-c","<script>","mova-supervise"]`); `movap-worker` **detenido** (acción ARM `stop`, 0 réplicas). Ni imagen, ni variables, ni secretos cambiados |
| Revisiones | scheduler `movap-scheduler--0000025` → `0000026` · worker app `movap-worker--0000023` → `0000024` (solo `scale.minReplicas=0`; la app quedó **Stopped**) |
| Verificación | 195 min en Azure: CPU media 6,6 mCPU, memoria 164 MiB (máx. 180/512), 0 reinicios, 40/40 `WorkerHeartbeatJob`, `mova:heartbeat` y `classmate:send-reminders` en los últimos 40 min, `/readyz` ready |
| Rollback | ver `docs/azure-cost-optimization.md` §10.1 (reponer worker con `scale min=1` + `start`; devolver el `args` original del scheduler; revisión `0000025`) |
| Prueba de GHCR (sin cambios en producción) | app de **staging** `mova-worker` apuntada a `ghcr.io/abel-castill0/mova@sha256:9c8a9456…`, arrancada, probada y **restaurada** al digest del ACR (Stopped, min 0). Imágenes GHCR publicadas por GitHub Actions: `a6f7b0f` (`sha256:9c8a9456…`, contiene `994e180`) y `b43290d` (`sha256:59cb9f4e…`, equivalente al código de `a32a1f0`) |
| Tags de prueba en GitHub | `img-test-2`, `img-a32a1f0-eq` (disparan solo el workflow de imagen; se pueden borrar) |

## 2026-10-22 (2) — `a32a1f0` (master) → producción — optimización de costo (OPcache CLI)

| Campo | Valor |
|---|---|
| Commit | `a32a1f01438fe0a2c26ad0c0caadb11a7604d810` (`perf(docker): file-based OPcache for PHP CLI…`; solo `Dockerfile`, `docker/php.ini`, `docker/php-cli.ini`) |
| Imagen | `movaacrsplisbj6ldoqw.azurecr.io/mova:release-20261022-a32a1f0` → digest `sha256:fece8113f637667f47240a8f744812c45f608d31b3253e94b3a51c3d5fa964e4` (construida desde `git archive a32a1f0`) |
| Revisiones | web `movap-web--0000025` → `0000026` · worker `0000022` → `0000023` · scheduler `0000024` → `0000025` (solo `--image`; sin cambios de variables/secretos) |
| Imagen anterior (rollback) | `sha256:bf92e6dca233d42a2b07ac54c15b116d7defe2ec30fc5bec49086a1bfa62dc6a` (`77667e9`) |
| Migraciones | ninguna |
| Verificación | `/readyz` ready, `/healthz`, `/quienes-somos`, `/login` 200; scheduler y worker ejecutando tareas sin errores; pagos sin cambios (`mercadopago`, Culqi inactivo) |
| Motivo | reducir el costo del scheduler: ver `docs/azure-cost-optimization.md` |

## 2026-10-22 — `77667e9` (master) → producción

| Campo | Valor |
|---|---|
| Commit | `77667e957ce772a25fab119ffee6ad1c860c4636` (`origin/master`, ancestro directo de `a6e5140`; CI de GitHub Actions en `success` sobre ambos) |
| Qué incluye respecto a lo desplegado antes | 33 commits desde `99d873c`: integración Culqi (inactiva), WhatsApp WAHA (inactivo, `WHATSAPP_ENABLED=false`), Movi/UI, nueva página `/quienes-somos` (`77667e9`) |
| Procedencia | Checkout limpio con `git archive 77667e9` (sin cambios sin confirmar), `docker build` con el `Dockerfile` del repo, etiqueta OCI `org.opencontainers.image.revision` = SHA completo |
| Imagen | `movaacrsplisbj6ldoqw.azurecr.io/mova:release-20261022-77667e9` → digest `sha256:bf92e6dca233d42a2b07ac54c15b116d7defe2ec30fc5bec49086a1bfa62dc6a` |
| Prueba de procedencia en vivo | los assets servidos en producción (`app-DWanjuqm.js`, `app-4XsBp7NP.css`) están en la imagen construida desde `77667e9`; las revisiones activas usan exactamente ese digest |
| Suscripción / RG | `Azure for Students` · `mova-prod-rg` (`mexicocentral`); entorno ACA `mova-aca-env` compartido con staging |
| Apps actualizadas | `movap-web`, `movap-worker`, `movap-scheduler` (modo de revisión **Single**, solo `--image`; ninguna variable ni secreto modificado) |
| Revisiones | web `movap-web--kv10192207` → `movap-web--0000025` · worker `movap-worker--kv10192206` → `movap-worker--0000022` · scheduler `movap-scheduler--kv10192205` → `movap-scheduler--0000024` |
| Imagen anterior (rollback) | `mova:release-20261007-99d873c` → `sha256:237d93c8a2c7af946c34784e0d30ef45af5c8400214ea5777f1b8a3e2dc27aff` |
| Respaldo previo | PITR 14 d (restaurable desde 2026-10-08), backups diarios completos hasta 2026-10-22 02:38Z y respaldo manual `predeploy-77667e9-20261022` creado a las 07:19Z |
| Migraciones | 1 pendiente (`migrate:status --pending` desde un Job con la imagen nueva): `2026_10_22_000001_add_culqi_checkout_fields_to_payment_orders` (añade 3 columnas **nulas**; ejecutada con `migrate --force`: `DONE` en 210 ms; después `No pending migrations`). Sin operaciones destructivas |
| Job usado | `movap-migrate` (Container Apps Job manual, misma imagen y env/secretos Key Vault de `movap-web`); creado y **eliminado** tras cada uso. Se ejecutó 4 veces: `migrate:status`, `migrate --force`, `migrate:status`, y 2 lecturas de conteos (antes/después) |
| Pagos (sin cambios) | `PAYMENT_PROVIDER=mercadopago`, `PAYMENTS_ENABLED=true`, `MERCADOPAGO_WEBHOOKS_ENABLED=true`, `MERCADOPAGO_EXPECTED_LIVE_MODE=true`, allowlist definida; **cero** variables `CULQI_*` ⇒ Culqi inactivo (webhook `/api/webhooks/culqi/{x}` responde 404; `/api/webhooks/mercadopago` sin firma responde 401) |
| Datos financieros | instantánea de solo lectura idéntica antes y después: 1 recarga `pending`, 1 orden `mercadopago`, 0 `payment_webhooks`, 0 asientos de ledger, créditos 0, 5 usuarios |
| Verificación | `/healthz` 200, `/readyz` `{"status":"ready","checks":{"database":"ok","migrations":"ok","cache":"ok"}}`, `/`, `/quienes-somos`, `/login`, `/register`, `/privacidad`, `/terminos` 200; assets Vite 200; `www` → 301, `http` → 301 a HTTPS; revisión web nueva `Healthy` con 100 % del tráfico; worker y scheduler `Healthy`, `WorkerHeartbeatJob` y `mova:heartbeat` ejecutándose; sin errores en los logs de las 3 apps tras el despliegue |
| Incidencias | Ninguna. Nota: `77667e9` (About) se publicó después del commit validado `a6e5140`; se desplegó por decisión expresa del titular |

### Rollback (sin tocar migraciones ni datos)
La migración nueva solo añade columnas nulas: el código anterior las ignora, así que **no hay que revertir la base de datos** (no ejecutar `migrate:rollback`).
```bash
IMG_OLD=movaacrsplisbj6ldoqw.azurecr.io/mova@sha256:237d93c8a2c7af946c34784e0d30ef45af5c8400214ea5777f1b8a3e2dc27aff
for a in movap-web movap-worker movap-scheduler; do az containerapp update -g mova-prod-rg -n $a --image "$IMG_OLD"; done
```
Las revisiones anteriores (`…--kv1019220x`) siguen existiendo inactivas. Tras revertir: `/readyz`, logs y heartbeats. **No probado en caliente**: la compatibilidad se deduce de que el esquema nuevo es solo aditivo.

### Cómo se hizo (reproducible sin Azure CLI instalado)
1. Azure CLI en contenedor: `docker run --rm -v mova-azcli:/root/.azure mcr.microsoft.com/azure-cli az …` (el volumen guarda la sesión; `az login --use-device-code`, MFA con un método distinto de la llave USB si hace falta). Cierra la sesión con `docker volume rm mova-azcli`.
2. `git archive <sha> | tar -x -C <dir limpio>` → `docker build --label org.opencontainers.image.revision=<sha> .`
3. `az acr login -n movaacrsplisbj6ldoqw --expose-token` → `docker login` por stdin → `docker tag/push …:release-YYYYMMDD-<sha7>`; leer el digest con `az acr repository show`.
4. Respaldo manual: `az mysql flexible-server backup create -g mova-prod-rg -n mova-prod-mysql-8uxzgq --backup-name predeploy-<sha7>-<fecha>`.
5. Job manual de migración: generar el YAML desde `az containerapp show -n movap-web` (misma `environmentId`, identidad, registro, secretos Key Vault y env), con `command: [mova-entrypoint]` y `args` = `php artisan migrate:status --pending` y luego `migrate --force`; `az containerapp job create/update --yaml`, `job start`, leer logs con `az containerapp job logs show`, y `job delete` al terminar.
6. `az containerapp update -n movap-worker|movap-scheduler|movap-web --image <registro>@sha256:<digest>` (solo `--image`: la config se conserva, regla del Runbook §5a).
7. Verificar revisiones (`revision list`), endpoints públicos, logs y conteos financieros.

### Estado de Culqi en producción
Desplegado e **inactivo**. Para activarlo (decisión futura del titular): `docs/payments-migration-mp-to-culqi.md` §7 (llaves live en el almacén de secretos, webhooks HTTPS, prueba live única de S/ 1 con reembolso, `PAYMENT_PROVIDER=culqi` con allowlist). Pendientes: ver `docs/AI_HANDOFF.md` §6.
