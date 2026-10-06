# MOVA V1 — Completion Ledger

**CURRENT — fuente de verdad** del programa de completitud de MOVA V1 para
el cutover a TARGET_PRODUCTION. `MOVA_V1_STATE.md` es HISTÓRICO. **El ledger
decide el estado, no la prosa.** Nada pasa a
`CLOSED` sin evidencia ejecutada contra el snapshot correcto (y, cuando el
criterio lo exige, evidencia en vivo del entorno real).

- Baseline: `master` @ `28fb28fe6a5b5b87e13b358ec1b1108860513c36`
- Staging QA: MOVA 1.0 STAGING RELEASE QA PASS — imagen
  `sha256:e34f814c2ec11fe6bec55b8869f28d7f042cf90b51bba1a861654ba0fae5b28a`
- Fase C1: rama `release/mova-v1-production-completion` — **fusionada**. PR #3
  (fusión normal) → `master` @ `d6bd462b06c8308d13c27016e7879976d2997111`;
  HEAD de C1 `5a0abf2261eaaab60003b450049593ae39303189`. GitHub Actions sobre
  el commit de fusión, ejecución `36653637812`: `frontend`, `composer`,
  `sqlite`, `mysql` y `e2e` en `success`.
- **Baseline de C2** = `master` @ `d6bd462b06c8308d13c27016e7879976d2997111`.
- Fase C2.1: **fusionada**. PR #4 (`release/mova-v1-c2-email`) → `master` @
  `ddcc2b081ad5d0d077d7f3485a3ca431d8667751`, ejecución `36667114270`; PR #5
  (`release/mova-v1-c2-gmail-oauth`, C2.1d) → `master` @
  `a37f36e02a6ff3ac8349e240f716811e47ba7514`, ejecución `36670394102`:
  `frontend`, `composer`, `sqlite`, `mysql` y `e2e` en `success` en ambas.
- **Consolidación de repositorio (2026-10-04, solo Git/documentación):** las 25
  ramas locales y las 10 remotas distintas de `master` están contenidas en
  `master` (0 commits propios, verificado con `merge-base --is-ancestor`); no
  hay PRs abiertos. No cambió ningún ID de este ledger ni ningún entorno. El
  gate `composer audit --locked` sobre `a37f36e` empezó a fallar tras el CI
  verde por dos avisos nuevos de `league/commonmark` 2.10.1 (dependencia
  transitiva de `laravel/framework`, sin uso directo en `app/`); se resolvió
  con un bump solo de `composer.lock` a 2.10.3. `LOCAL_TEST` sobre ese
  snapshot (PHP 8.3.35 en `php_qa`/`e2e_qa`): `composer validate --strict` y
  `composer audit --locked` sin avisos; `npm ci`, `npm audit --omit=dev
  --audit-level=high` (0), `npm run build` y los tres `check:*` con código 0;
  PHPUnit SQLite 1306 tests / 5137 assertions, 0 failures, 1 skip; PHPUnit
  MySQL 8.4 (`mysql_qa`, tras `mova:qa-mysql-fresh-migrate`) 1306 tests / 5136
  assertions, 0 failures, 1 skip; Playwright 65/65, 0 flaky, 0 skipped, código
  0 (12,1 min de tests; ~9,9 min en `operations.spec.js`, por esperas
  deliberadas al siguiente timestep TOTP del admin QA, anti-replay). No es
  evidencia `LIVE_STAGING` ni `PRODUCTION_LIVE`.

## Estado actual (2026-10-05, cierre de la ronda de lanzamiento)

**No se declara «100 % terminado».** Producción Azure existe, está sana, aislada en datos y **atiende
`https://movaeduca.me` con TLS válido** (2026-10-06), con **correo SMTP real verificado**, pero **sin JaaS/Cloudinary de producción y sin
cobros live** (pagos `fake`/apagados). Este bloque **sustituye** cualquier estado anterior del ledger que lo contradiga.

**Evidencia de código sobre el árbol definitivo (`LOCAL_TEST`, PHP 8.3.35):** `composer audit`/`npm audit`
sin avisos, `npm run build` OK; PHPUnit SQLite 1395 tests / 5456 assertions, 0 failures, 7 skips; PHPUnit MySQL 8.4
1395 / 5455, 0 failures; Playwright **79 passed, 1 skipped** (la reunión JaaS real, que exige credenciales y
`JAAS_REAL=1`), 0 failed. Tras esa corrida se añadieron solo los seeders seguros y `mova:create-admin`
(+9 tests, verificados en SQLite y MySQL) y un repaso completo de SQLite: 1401 tests / 5476 assertions, 0 failures.
Mercado Pago, Cloudinary, Sentry, SMTP y JaaS reales: ver las rondas siguientes (`LIVE_STAGING`, solo sandbox/prueba).

**Despliegue final (2026-10-05):** imagen `mova@sha256:c2b396e24f93bd204e4e3c9140a455bf9c20a6ae7ada66c5c1265a9e00d97c9c`
(construida del commit `783138e`; `master` publicado en `0c01259`, con solo tests y documentación posteriores). GitHub
Actions sobre `0c01259`: `CI` en `success`.
Producción: `movap-web--0000004`, `movap-worker--0000004`, `movap-scheduler--0000004` (`/readyz` ready; las revisiones
2–4 solo añadieron variables/secretos de configuración, misma imagen).
Staging: `mova-web--0000038`, `mova-worker--0000024`, `mova-scheduler--0000017` (`/readyz` ready; checkout de
Mercado Pago cerrado a todos, sandbox). Sin migraciones nuevas en esta imagen.

### Matriz por función

| Función | Implementada | LOCAL_TEST | LIVE_STAGING | PRODUCTION_LIVE | Pendiente exacto |
|---|---|---|---|---|---|
| Registro, verificación, recuperación de contraseña, perfiles | Sí | PHPUnit + E2E | Registro → verificación → recuperación → ingreso probados con correo real (un buzón alias) | **Sí, 2026-10-06**: registro, verificación, correo de bienvenida, recuperación y reingreso en `https://movaeduca.me` con un buzón de prueba; usuario eliminado después | Crear el admin con `mova:create-admin` (necesita el correo del titular) |
| Disponibilidad semanal, búsqueda y recomendaciones | Sí | PHPUnit + E2E (3) | Desplegado; migraciones aplicadas | Migraciones aplicadas, sin datos | Prueba con docentes reales |
| Solicitud y reserva de clases | Sí | E2E «flujo de 8 pasos» | Sin re-verificar en esta ronda | No | Verificación en producción tras cutover |
| Clases JaaS (sala, permisos, ventana, reconexión) | Sí | 11 E2E con script simulado | **Reunión real** docente+padre (escritorio y móvil), salida/reingreso | No (sin credenciales) | Credenciales JaaS de producción; prueba humana de audio/video |
| Presencia JaaS (`PARTICIPANT_JOINED/LEFT`) | Sí (solo evidencia) | 21 tests | **Webhook real firmado**, idempotente | No | Registrar endpoint de producción; **no** decide asistencia |
| Créditos del profesor / checkout Mercado Pago | Sí | Sonda sandbox + PHPUnit | **Sandbox**: Card Brick, 3DS, Yape, rechazos, móvil, reintentos, webhook real y simulador, reverso sandbox | No (`PAYMENTS_ENABLED=false`) | Credenciales **live**, regenerar la clave de firma del webhook (ya registrado, inactivo), un cobro real mínimo + reembolso aprobados por el titular |
| Pago padre → profesor por la clase | **No existe** | — | — | — | MOVA solo carga créditos del profesor; el flujo padre→profesor no está implementado ni probado |
| Reversos/contracargos | Reacciona a reembolso/contracargo confirmado; reversión manual de ledger (admin) | PHPUnit | Reembolso **sandbox** reconciliado (un `reversal`) | No | Política de deuda/negativos y quién inicia reembolsos |
| Notificaciones por correo | Sí | Mailpit + PHPUnit | SMTP real a buzón autorizado (`MAIL_ALLOWLIST`) | No | Credenciales y dominio remitente (SPF/DKIM/DMARC) |
| Paneles padre/profesor/admin | Sí | E2E (operaciones, créditos, clases) | Parcial | Admin no creado (sin contraseña conocida) | `mova:create-admin` + MFA tras tener correo |
| Libro de reclamaciones | Sí | PHPUnit | Constancia por correo | No | Verificar tras cutover |
| Avatares (Cloudinary) | Sí (falla cerrado sin credencial) | Mocks + sonda | Subida/lectura/borrado reales desde staging | No | `CLOUDINARY_URL` de producción |
| Sentry | Sí (`send_default_pii=false`) | — | 3 eventos confirmados en el panel | Proyecto `mova-production` + DSN propio cargado (sin evento sintético aún: requiere tráfico/cutover) | Evento sintético tras el cutover |
| Liquidación automática | Sí | PHPUnit | `dry_run` | `dry_run` | `live` solo con decisión + ACK explícito del titular |
| WhatsApp, Google Login, IA de diagnóstico, broadcasting | Sí (apagados) | PHPUnit | Apagados | Apagados | Decisión de producto/credenciales; fuera del alcance de arranque |
| Asistencia/ausencias/disputas, clases recurrentes, verificación documental docente | **No definidos** | — | — | — | Decisión de negocio (ver abajo) |
| Infraestructura de producción | Sí | — | — | **Sí (infra y salud)** | Ver siguiente sección |
| Dominio `movaeduca.me` → producción | — | — | — | **Sí (infra)**: apex y `www` con TLS gestionado, `www`→apex 301, `/healthz` y `/readyz` 200, `noindex` | Confirmar titularidad del registrante (ver abajo); sin correo operativo los registros no pueden verificarse |

### Producción Azure (`PRODUCTION_LIVE` para infraestructura y salud únicamente)

- Apps `movap-web` (min 1), `movap-worker`, `movap-scheduler` (1 réplica) en el único entorno ACA permitido por la
  suscripción Student (1 por suscripción: `MaxNumberOfGlobalEnvironmentsInSubExceeded`, probado también en
  `canadacentral`), por lo que comparten entorno/VNet/ACR con staging; apps, secretos, base y usuario de BD son propios.
  Sirven `https://movaeduca.me` (y el FQDN `https://movap-web.whitecliff-88cda913.mexicocentral.azurecontainerapps.io`):
  `/healthz` 200, `/readyz` `{"status":"ready"}` (BD, migraciones, caché), `/login` y `/register` 200.
- **Base separada:** servidor MySQL 8.4 `mova-prod-mysql-8uxzgq` (B1ms, 32 GB, privado, TLS, PITR 14 días, sin
  geo-redundancia), BD `mova`, usuario `movap_app` con DML+DDL solo sobre `mova.*`. Se aplicaron las 102 migraciones
  (revisadas; las 3 del 2026-10-05 son aditivas o relajan una restricción) y solo los datos de referencia
  (3 roles, 6 materias). **Ningún dato de staging/QA, pagos, créditos ni usuarios** se copió.
- **Restauración real:** PITR a las 20:15:45Z hacia un servidor aislado (`mova-prod-restore-drill`): iniciado 20:17Z,
  `Ready` ≈ 21:08Z (**RTO medido ≈ 50 min**); contenido verificado con un job de solo lectura (102 migraciones,
  42 tablas, 3 roles, 6 materias); servidor de prueba eliminado.
- **Configuración segura por defecto:** `APP_ENV=production`, `APP_DEBUG=false`, correo `array`, pagos `fake`/apagados,
  recargas y webhooks apagados, WhatsApp/Google/IA apagados, indexación apagada, liquidación `dry_run`, solo un
  scheduler. Sin credenciales de Mercado Pago, JaaS, Cloudinary ni SMTP; Sentry sí (nombres y procedimiento en
  `docs/release/MOVA_PRODUCTION_RUNBOOK.md`).
- **Incidente del admin (cerrado con evidencia agregada, 2026-10-06):** `RoleSeeder` creó `admin@mova.test` con
  contraseña conocida en la base de producción vacía y se eliminó ≈ 70 min después. Hoy producción tiene **0 usuarios,
  0 sesiones autenticadas, 0 tokens de recuperación, 0 tokens personales, 0 notificaciones, 0 asignaciones de rol y 0
  correos `@mova.test`**; las únicas tablas con filas son `migrations` (102), `roles` (3), `subjects` (6),
  `system_heartbeats`, `operational_alerts` (4, ver abajo) y `sessions`. Las 6 sesiones existentes son **de invitado**
  (`user_id` nulo): se crean al abrir `/login` o `/register`, así que no contradicen «0 sesiones de usuario» (eso se
  refería a sesiones autenticadas; las de invitado crecen con cada visita, incluidas mis comprobaciones). Las notificaciones
  huérfanas de la cuenta temporal ya no existen. Seeders ejecutados: `RoleSeeder` y `SubjectSeeder` (no `DatabaseSeeder`
  ni `LocalTestDataSeeder`). **Límite de la evidencia:** el contenedor no registra accesos HTTP, por lo que ningún log
  prueba la ausencia de `POST /login` en esa ventana; lo verificado es que la cuenta nunca verificó su correo, que no
  quedó sesión autenticada ni token, y que ya no existe. Corregido en código: `RoleSeeder` solo crea roles fuera de local/testing; `DatabaseSeeder` y `LocalTestDataSeeder` abortan en producción; nuevo `mova:create-admin` (contraseña desconocida + MFA). No se ejecutarán más seeders en producción.
- **Alertas operativas de producción (4 críticas, esperadas al 2026-10-06 antes de configurar el correo):**
  `SETTLEMENT_DRY_RUN_IN_PRODUCTION`, `MAIL_MAILER_NON_DELIVERING` (ya resuelta por la configuración SMTP),
  `LEGAL_PROVIDER_DATA_MISSING`, `JAAS_NOT_CONFIGURED`. Confirman que el monitor detecta
  los huecos reales; cada una desaparece al cerrar su causa.
- **Costo y crédito (Azure for Students, 2026-10-06):** crédito restante **US$ 86**, vence **14/09/2027** (≈ 11,3 meses
  ⇒ gasto sostenible ≈ **US$ 7,6/mes**); previsión del portal US$ 19,22/mes. Gasto real (API de Cost Management):
  septiembre ≈ US$ 12,3 (solo staging); octubre 1–5 ≈ US$ 2,23 (Container Registry 0,78; IP pública del entorno 0,57;
  Container Apps 0,80; DNS 0,08; MySQL 0). El portal **aún no incluye** el MySQL de producción ni las réplicas siempre
  activas de `movap-*` (retraso de facturación 24–48 h), así que el 19,22 es un piso; mi estimación con producción
  completa es ≈ US$ 30–38/mes (MySQL de producción ≈ 16, porque la capa gratuita cubre un solo servidor y ya la usa el de
  staging; `movap-*` ≈ 8–10). **A ese ritmo el crédito alcanza ≈ 2,5–3 meses, no hasta septiembre de 2027**; al agotarse,
  la suscripción Student se deshabilita (no hay cargo a tarjeta). Ahorro aplicado: staging `worker` y `scheduler` a 0
  réplicas (≈ US$ 3/mes; staging no tiene tráfico; revertir con `az containerapp update --min-replicas 1`). No se añadió
  ningún recurso. Presupuesto `mova-monthly-20` creado (US$ 20/mes; correo al 50 % y 80 % reales y al 100 % previsto;
  **informa, no limita el gasto**). Decisión del titular: pasar a plan de pago antes de ~dic-2026, o recortar (apagar el
  staging por completo ahorra ≈ US$ 7–10/mes) aceptando menos disponibilidad. Actualizar esta cifra con la facturación real
  de producción en cuanto aparezca.
- **Sentry:** proyecto propio `mova-production`, DSN propio cargado como secreto `sentry-laravel-dsn` en los tres roles.
  El archivo temporal local que lo contuvo se sobrescribió y se eliminó; el valor no aparece en el repositorio, la
  documentación ni los logs. Todavía no hay evento sintético de producción.
- **Dominio y registro (Namecheap):** el apex `A` ya apuntaba a `68.155.88.233` (lo cambió el titular; en esta ronda no
  edité Namecheap), `www` CNAME → `movap-web`, MX (`eforward1–5`), SPF y TXT intactos; los 4 registros de GitHub Pages ya
  no existen. Enlacé el apex con certificado gestionado en `movap-web`. **Titularidad:** el registrante de `movaeduca.me`
  en Namecheap figura con un nombre y un correo distintos de los del titular de este proyecto; no se cambian más
  registros hasta que el titular confirme que la cuenta es la autorizada.
- **Mercado Pago producción:** webhook de modo productivo registrado (`https://movaeduca.me/api/webhooks/mercadopago`,
  evento Pagos). **La clave de firma es una sola por aplicación** (la de modo de prueba y la productiva coinciden), así
  que está compartida con staging, y se mostró en la interfaz al copiarla: se considera **potencialmente expuesta**. Está
  cargada como secreto `mercadopago-webhook-secret` pero **inactiva**: `MERCADOPAGO_WEBHOOKS_ENABLED=false`,
  `PAYMENT_PROVIDER=fake`, `PAYMENTS_ENABLED=false`. Hay que regenerarla en el panel (y actualizarla en staging) **antes** de
  habilitar el webhook. Ningún cobro es posible.
- **Correo de producción (verificado el 2026-10-06):** SMTP de Gmail con una contraseña de aplicación propia («MOVA
  produccion», distinta de la de staging), cargada como secretos `mail-username`/`mail-password` en los tres roles
  (el valor pasó por un archivo temporal ya eliminado; no se mostró). Prueba única con un buzón alias de prueba: registro
  de un padre en `https://movaeduca.me` → correo de verificación recibido → verificación (aterriza en `/dashboard`) →
  correo «su cuenta está lista» → solicitud de recuperación → correo recibido → nueva contraseña → ingreso con ella. Tres
  correos al mismo buzón, ninguno masivo. El usuario de prueba y sus filas se eliminaron de producción después (0 usuarios).
  Defecto encontrado y corregido: producción no tenía `APP_NAME`, por lo que los correos mostraban el logo y el nombre
  «Laravel»; ahora `APP_NAME=MOVA` (revisión 0000006, y en `apps.bicep`). Pendiente: el correo usa el remitente de Gmail
  (`m0v4class@gmail.com`); SPF/DKIM/DMARC de un remitente propio del dominio siguen siendo decisión del titular, y Gmail
  limita el envío (≈ 500/día). La alerta `MAIL_MAILER_NON_DELIVERING` debe cerrarse en el siguiente health-check.
- **JaaS / Cloudinary producción:** no configurados. JaaS: plan **JaaS Dev (25 MAU, US$ 0)** con una sola app compartida
  con staging (8 MAU ya consumidos por las pruebas); producción necesita clave API propia y decidir plan (25 MAU solo
  sirve de piloto). Cloudinary: sin sesión abierta en el navegador.
- **Lista de rotación antes de activar integraciones live:** (1) clave de firma de Mercado Pago (expuesta); (2) DSN de
  Sentry de producción (pasó por un archivo temporal ya eliminado; rotación opcional pero recomendable); (3) token y clave
  pública live de Mercado Pago; (4) la contraseña de aplicación de correo de producción (ya cargada; rotar si se sospecha exposición), y JaaS y Cloudinary de producción al crearlas;
  (5) la credencial histórica del incidente F-26 (MySQL de Railway en el historial de git), que sigue abierta y separada.
- **Datos legales:** `apps.bicepparam` acepta `MOVA_LEGAL_BUSINESS_NAME/RUC/ADDRESS/SUPPORT_EMAIL`; sin ellos
  `mova:health-check` marca `LEGAL_PROVIDER_DATA_MISSING` (los aporta el titular; no se inventan).
- **Cobertura V1 añadida:** interruptor de control parental, alta de perfil docente y bandeja de notificaciones
  (`ParentSettingsAndTeacherSetupTest`, 7 tests). PHPUnit SQLite sobre el árbol final: **1408 tests / 5513 assertions,
  0 failures, 7 skips**. CI de `0c01259`: `success`.

### Decisiones pendientes del titular (con recomendación)

| Decisión | Recomendación | Consecuencia si no se decide |
|---|---|---|
| Credenciales live y de producción (MP live, SMTP, JaaS, Cloudinary, Sentry) | Cargarlas por `az containerapp secret set` (runbook §5), distintas de las de staging | Producción sin correo, reuniones, avatares ni pagos: no se hace el cutover |
| Titularidad de `movaeduca.me` en Namecheap | Confirmar que el registrante que figura es la persona autorizada (o añadir/transferir contacto) | No se tocan más registros DNS; el dominio ya sirve producción |
| Entorno ACA propio y plan de pago | Pasar a una suscripción de pago y mover `movap-*` antes de ~dic-2026 | Producción comparte entorno con staging y el crédito (US$ 86) se agota en ≈ 2,5–3 meses |
| Reembolsos/deuda por saldo negativo, quién inicia devoluciones | Reembolso manual desde el panel de Mercado Pago + reversión de ledger; saldo negativo = bloqueo de cuenta | Sin política, un contracargo deja saldo negativo sin dueño |
| Pago padre → profesor | Definir el modelo (marketplace vs. créditos prepagos por familia) antes de prometerlo | No debe anunciarse |
| Liquidación `live`, asistencia/disputas, clases recurrentes, verificación documental, consentimiento de menores/ANPD/transferencia internacional | Validación legal primero; mantener `dry_run` y verificación manual | Gate legal abierto: no se declara producción «lista» |

## Clases de evidencia y nombres de entorno

`CODE` = comportamiento y migraciones inspeccionados; `LOCAL_TEST` = suites
ejecutadas en QA local; `STATIC_IAC` = intención de plantilla, **nunca** estado
desplegado; `LIVE_STAGING` = Azure CLI de solo lectura en las apps que sirven
`staging.movaeduca.me`; `OWNER_CONFIRMATION` = decisión o prueba aportada por
el titular; `PRODUCTION_LIVE` = comprobación del servicio que realmente sirve
el dominio público tras cutover. `PUBLIC_APEX` nombra el dominio público cuya
ruta efectiva aún debe verificarse; `LEGACY` nombra el servicio anterior sin
presuponer que siga atendiendo producción; `TARGET_PRODUCTION` nombra la
arquitectura Azure prevista. El nombre `mova-prod-rg` y `APP_ENV=production`
son configuración de staging y no constituyen prueba `PRODUCTION_LIVE`.

### Inventario LIVE_STAGING (Azure CLI de solo lectura, 2026-09-29)

| Rol | Revisión lista | Estado | Escala | Modo de liquidación | Correo |
|---|---|---|---|---|---|
| `mova-web` | `mova-web--0000024` | app Running; revisión Healthy, ScaledToZero | min 0, max 2 | `dry_run` | `array` |
| `mova-worker` | `mova-worker--0000013` | Healthy / Running, 1 réplica | min 1, max 1 | `dry_run` | `array` |
| `mova-scheduler` | `mova-scheduler--0000008` | Healthy / Running, 1 réplica observada | min 1, max 1 | `dry_run` | `array` |

`LIVE_STAGING`: los tres roles tienen `APP_URL=https://staging.movaeduca.me`,
`APP_ENV=production`, `APP_DEBUG=false`, `SEARCH_INDEXING_ENABLED=false`,
`PAYMENTS_ENABLED=false`, `PAYMENT_PROVIDER=fake`, `RECHARGES_ENABLED=false`,
`MERCADOPAGO_WEBHOOKS_ENABLED=false`, `WHATSAPP_ENABLED=false`,
`WHATSAPP_PROVIDER=fake`, `GOOGLE_LOGIN_ENABLED=false`,
`DIAGNOSTIC_AI_ENABLED=false`, `QUEUE_CONNECTION=database`,
`CACHE_DRIVER=database`, `BROADCAST_DRIVER=null`. `CHATBOT_ENABLED` no figura
como variable en ninguna de las tres apps; no se deduce su valor efectivo.
Solo **presencia de nombres**, sin valores: `GMAIL_CLIENT_ID`,
`GMAIL_CLIENT_SECRET` y `GMAIL_REFRESH_TOKEN` en web y worker (`secretRef`);
`GMAIL_FROM_ADDRESS`/`GMAIL_FROM_NAME` (directas) en los tres roles — el
scheduler **no** tiene las tres credenciales (corregido en C2.1; el inventario
anterior decía «en los tres roles»). `JAAS_*` en web/scheduler (clave por
`secretRef`), `CLOUDINARY_URL` en web (`secretRef`), `SENTRY_LARAVEL_DSN`
en web/worker (`secretRef`), `MERCADOPAGO_*` de configuración/credenciales
en web (credencial por `secretRef`). `GOOGLE_CLIENT_*`, `META_WHATSAPP_*` y
`PUSHER_*` no aparecieron. Presencia no acredita validez, entrega o uso.

`STATIC_IAC`: el archivo `apps.bicepparam` anterior a C1.1 decía
`deployScheduler=false` y liquidación `live`, en contradicción con el
inventario. La corrección C1.1 fija `dry_run` por defecto y separa la
decisión de desplegar scheduler de la de activar liquidación. No se desplegó.

## Seguimiento operativo — 2026-10-04 (solo lectura; nada se desplegó ni se cambió)

Consultas de solo lectura realizadas el 2026-10-04 (hora local; las del plano
Azure aparecen como 2026-10-05 UTC). No se cambió DNS, Azure, GitHub ni Railway,
no se desplegó, no se aplicó ninguna migración y no se leyó ningún valor
secreto.

**LIVE_STAGING (Azure `mova-prod-rg`, Mexico Central).** `mova-web--0000025`
(creada 2026-10-01, min 0 / máx 2, `ScaledToZero` en reposo), `mova-worker--0000014`
y `mova-scheduler--0000009` (1/1, `RunningAtMaxScale`), las tres `Healthy` y con
la **misma imagen** `mova@sha256:cfcf0105…f4da21` (= tag `release-a37f36e02a6f`,
construida 2026-09-30T05:28Z, es decir el código de `a37f36e`, **no** el de
`master` actual). Solo `staging.movaeduca.me` está vinculado (certificado
gestionado, emitido 2026-09-25, vence 2027-03-25). `/readyz` responde `ready`
(BD, migraciones y caché `ok`); `robots.txt` bloquea todo, `X-Robots-Tag:
noindex`, HSTS activo. Siguen vigentes en los tres roles `APP_URL=https://staging.movaeduca.me`,
`MAIL_MAILER=array`, `PAYMENT_PROVIDER=fake`, pagos y recargas apagados,
`LESSON_SETTLEMENT_MODE=dry_run`, Google Login/WhatsApp/IA apagados;
`MERCADOPAGO_EXPECTED_LIVE_MODE=false`, `WHATSAPP_MODE=sandbox`,
`FILESYSTEM_DISK=local` (disco efímero; los avatares usan Cloudinary y en
producción fallan cerrado si no está configurado). El remitente Gmail
configurado es una cuenta `@gmail.com`, no un buzón del dominio propio.

**Datos en la BD Azure `mova` (MySQL 8.4.9-azure; solo conteos agregados).** 40
tablas, 99 migraciones aplicadas. Filas: `users` 21 (roles: admin 2, parent 10,
teacher 10), `teacher_profiles` 10 (3 verificados), `students` 3, `class_requests`
10, `classes` 5 (3 `scheduled`, 2 `completed`), `credit_transactions` 4 (2
`deposit`, 2 `reservation`; ningún `consumption`), `recharge_requests` 6 (4
`pending`, 2 `approved`), `payment_orders` 3 (**todas `pending`**),
`student_data_consents` **0**, `legal_acceptances` 40. Es una base de
staging con fixtures: **no** debe asumirse limpia ni apta para que el dominio
público apunte a ella. No se pudo clasificar cuántas cuentas son QA frente a
reales (el límite de peticiones de `az containerapp exec` devolvió 429); los 3
alumnos existentes no tienen consentimiento por menor registrado.

**Railway (LEGACY, no operable).** Todos los deployments de `MOVA` están
`FAILED` desde julio de 2026 (el último `REMOVED` es del 2026-07-18) y el de
`MySQL` quedó `REMOVED` el 2026-09-15; el workspace figura con plan `free` y
los fallos ocurren al programar el build, sin logs: la causa probable es el
plan, pero **no está confirmada**. El volumen `mysql-volume` sigue `Ready`
(153 MB de 500 MB) y **no debe borrarse** hasta confirmar qué datos contiene.
Cada push a `master` sigue disparando un deployment fallido.

**DNS / dominio público (Namecheap `registrar-servers.com`).** `movaeduca.me` A →
IPs de GitHub Pages (185.199.108–111.153); `www` CNAME → `movaeduca.me`. Ningún
repositorio de la cuenta reclama el dominio: responde 404 con certificado
`*.github.io` (inválido para el dominio). MX `eforward1–5.registrar-servers.com`
y SPF `include:spf.efwd.registrar-servers.com` (reenvío de correo de Namecheap)
**deben conservarse**. No hay CAA, DMARC ni DKIM. El HTML servido no incluye
canonical ni Open Graph: `title`/`og:*` los inyecta Vue en el cliente (sin SSR),
así que los rastreadores sociales no los ven.

**GitHub.** Repositorio **público**; `master` sin protección ni rulesets; secret
scanning, push protection y Dependabot **desactivados**. El historial contiene una
credencial de BD expuesta (C-P0-DB-CREDENTIAL), por lo que la exposición es
pública. En `HEAD`/`origin/master` los archivos de la ficha F-26 ya no existen,
pero `origin/Elias` (rama pública) todavía los contiene y el historial público
los conserva; el incidente sigue abierto. Los valores tipo secreto de
`docs/DEPLOY_RAILWAY.md` parecen placeholders (comprobación heurística, no prueba).

**LOCAL_TEST final de integraciones (sin commit), PHP 8.3.35, 2026-10-05.**
Sobre el árbol con sondas de sandbox, correo, JaaS (UX + webhooks de presencia)
y guarda de separación staging/producción, una sola pasada final, todo con
código 0: `git diff --check`, `composer validate --strict`, `composer audit
--locked`, `npm audit --omit=dev` (0), `npm run build` y los tres `check:*`;
PHPUnit SQLite 1378 tests / 5390 assertions, 0 failures, 7 skips; PHPUnit MySQL
8.4 (guarda `mysql_qa`/`mova_qa`) 1378 tests / 5389 assertions, 0 failures,
7 skips; Playwright completo (1 worker, `.env` efímero creado dentro del
contenedor): **79 passed, 1 skipped, 0 failed** en 12,6 min. El skip es
`qa/tests/jaas-real-meeting.spec.js` (exige `JAAS_REAL=1` y credenciales JaaS
reales; nunca ejecutado). Los skips de PHPUnit incluyen `MailDeliverySmokeTest`
(requiere Mailpit, cubierto aparte). Esto es `LOCAL_TEST`: no acredita Mercado
Pago, Cloudinary, Sentry ni JaaS reales (ver «Integraciones operativas»).

**LOCAL_TEST sobre el árbol de trabajo (sin commit), PHP 8.3, 2026-10-04.**
Sobre el árbol con los cambios de disponibilidad y recomendaciones (sin commit,
base `c97a62e`), PHP 8.3.35, todo con código de salida 0 y sin fallos:
PHPUnit SQLite completo (`vendor/bin/phpunit`): 1338 tests / 5266 assertions,
0 failures, 1 skip; PHPUnit MySQL 8.4 completo (`phpunit.mysql.xml`, tras
`mova:qa-mysql-fresh-migrate` con guarda `mysql_qa`/`mova_qa`): 1338 tests /
5265 assertions, 0 failures, 1 skip; `composer validate --strict` y
`composer audit --locked` sin avisos; `npm run build` y los tres `check:*`
OK; Playwright completo (contenedor QA, 1 worker, 0 reintentos, `.env`
efímero): **68/68 passed**, 0 failed, 0 flaky, 0 skipped, 13,4 min (los 3 tests
nuevos de `qa/tests/teacher-availability.spec.js` incluidos). Los 68 tests de
Playwright corrieron sobre un snapshot tomado antes de la edición de `CLAUDE.md`
y de este documento (solo documentación). Un intento previo abortó por
`tar: file changed as we read it` al editar un archivo durante la copia del
snapshot; fue un error de procedimiento y se repitió sin ediciones concurrentes.
Revisión visual de la pantalla de disponibilidad en el navegador del QA
(escritorio 1280 y móvil 390; capturas del formulario con franja guardada y con
error de solape): estructura, textos y errores correctos; en móvil la barra
superior fija se superpone a la captura del elemento tras el scroll (artefacto
de captura, no verificado como defecto de layout). No se probó modo oscuro.
Concurrencia real multi-proceso (8 procesos, MySQL `mova_qa` con guarda): `accept-lesson`,
`settle`, `refund`, `approve-recharge` y `reminder-claim` terminaron `GREEN`
(exactamente una mutación y ledger sano). Restore lógico de prueba dump→restore
sobre MySQL QA local: 41 tablas / 419 columnas idénticas y checksums iguales
(datos sintéticos; **no** es un restore de Azure).

### Plan de cutover de `movaeduca.me` (NO ejecutado; requiere autorización)

Fuente: documentación oficial de Container Apps, certificados gestionados.
1. Prerrequisitos de datos: elegir/crear una BD de **producción** separada de la de
   staging (no reutilizar `mova` con fixtures), revisar y aplicar las migraciones
   pendientes como paso manual, backup recuperable + restore aislado verificados,
   `APP_URL=https://movaeduca.me`, imagen construida desde el `master` final.
2. Apex: registro `A @` → IP estática del entorno (`az containerapp env show … --query properties.staticIp`)
   y `TXT asuid` con `customDomainVerificationId`; validación **HTTP**. Retirar
   los cuatro A de GitHub Pages. **No tocar MX ni SPF.**
3. `www`: `CNAME www` **directo** al FQDN de la app (un CNAME hacia el apex o hacia
   un intermedio bloquea la emisión) y `TXT asuid.www`; el middleware
   `RedirectWwwToApex` ya redirige `www` → apex.
4. `az containerapp hostname add` + `hostname bind --validation-method HTTP|CNAME`; la
   app debe estar en ejecución durante la emisión y renovaciones (fijar `minReplicas ≥ 1`
   en producción; hoy web escala a 0).
5. Tras comprobar HTTPS, redirects y health checks: `SEARCH_INDEXING_ENABLED=true` solo
   en el dominio canónico y SEO con canonical/OG reales (hoy solo client-side).
6. Reversión: restaurar los A/CNAME previos (TTL bajo antes del cambio) y conservar
   `staging.movaeduca.me` sin indexar. Un solo scheduler activo (Railway no corre).

### Controles que faltan antes de habilitar pagos reales (Mercado Pago)

La batería local de pagos (≈290 tests) y las sondas de concurrencia pasan, pero no
hay verificación contra el sandbox real de Mercado Pago ni entrega real de correo.
Faltan: (1) credenciales de **prueba** de Mercado Pago aportadas/autorizadas para
ejercer checkout, 3DS, rechazo, webhook repetido, timeout y reversa en staging con
`PAYMENTS_ENABLED=true` (hoy apagado; cambiarlo es una acción de Azure no realizada);
(2) webhook público con la URL final y secreto de firma verificados; (3) correo real
operativo (re-autorización Gmail pendiente, `INVALID_GRANT`); (4) base de producción
separada de QA; (5) decisión del titular sobre paquetes, precios y destino de la
recarga; (6) liquidación `live` solo tras el gate financiero. Hasta entonces los pagos
reales siguen **NO habilitados**.

## Integraciones operativas — estado real y bloqueos (2026-10-05)

Regla de lectura: **código** ≠ **probado con mocks / local** ≠ **sandbox real** ≠
**producción**.

**Actualización 2026-10-05 (tarde), con credenciales del `.env` del propietario
leídas solo en memoria (autorizado en `AGENTS.md`):** contra proveedor real,
desde el contenedor QA local: Cloudinary (subida, lectura por HTTPS, sin copia en
disco, borrado verificado); JaaS (reunión real docente escritorio + padre móvil en
la misma sala, 2 participantes, audio/video activos, salida y reingreso; JaaS
muestra su pantalla previa «Join meeting» aunque MOVA pida
`prejoinPageEnabled:false`); correo vía Gmail SMTP (3 mensajes ACEPTADOS por el
transporte al buzón del remitente; **falta confirmación humana de recepción**);
Sentry (un evento sintético, ID devuelto; falta confirmar ingestión en el panel).
Mercado Pago: autenticación y tokenización con credenciales `TEST-` funcionan, pero
`POST /v1/payments` responde 403 `Payer email forbidden` (código 4390) incluso con
un comprador de prueba creado por API: la credencial pertenece a una cuenta normal;
se necesita el Access Token de una cuenta de prueba **vendedor**. Pagos NO probados.
Azure staging (config solamente, sin desplegar código): `mova-web` con secretos
Cloudinary/Sentry/JaaS, `JAAS_APP_ID`/`JAAS_KEY_ID`, `SENTRY_ENVIRONMENT=staging`;
`mova-worker` con DSN y `SENTRY_ENVIRONMENT`; pagos `fake`/apagados y correo
`array` intactos. En staging se comprobó: web 200, evento Sentry enviado, Cloudinary
configurado y firma de JWT JaaS posible (por inferencia de ramas en `tinker`, sin
subida ni reunión reales en staging). Gmail: sin cambiar el mailer de staging.

**Actualización 2026-10-05 (noche) — despliegue del árbol de trabajo en staging
(sin commit; imagen `mova@sha256:09d64d9f…`, tag `staging-20261005-0231-wt`,
construida del árbol sin commit; imagen anterior de web
`sha256:cfcf0105…` para rollback).** Destino verificado: RG `mova-prod-rg` (staging),
`APP_URL=https://staging.movaeduca.me`, indexación/pagos/recargas/IA/WhatsApp
apagados, liquidación `dry_run`, PITR de 7 días. `/readyz` bloqueó la revisión
nueva hasta aplicar migraciones (comportamiento esperado); se aplicaron SOLO las
3 pendientes y revisadas (`teacher_availability_slots`, `lesson_presence_events`,
y `class_offer_id` NULL + índice no único en `diagnostic_recommendations`: relaja
una restricción, no borra ni reescribe datos). Revisiones activas: web 30, worker
17, scheduler 11. Verificado en staging: `/healthz` y `/readyz` 200; webhook JaaS
401 sin credenciales; webhook Mercado Pago 404 (apagado).
- **Correo:** `MAIL_MAILER=smtp` (Gmail SMTP, contraseña de aplicación en secreto) con
  `MAIL_ALLOWLIST` = un solo buzón autorizado en web/worker/scheduler
  (`App\Support\MailAllowlist`, también cubierto por tests). En staging: envío a una
  dirección fuera de la lista → cancelado; envío al buzón autorizado → aceptado por
  el transporte (**falta confirmación humana de recepción**). Gmail API (refresh
  token del `.env`/staging) falla al renovar el access token (HTTP 400, típico
  `invalid_grant`): requiere reautorización; no se usa.
- **JaaS en staging:** reunión REAL docente (escritorio) + padre (móvil Pixel 7) con
  cuentas sintéticas `jaas-sbx-*@mova.test` y la clase #7: misma sala (2 participantes
  en ambos), audio/video activos sin errores, salida (1) y reingreso (2). Webhook de
  presencia: receptor verificado con firmas `X-Jaas-Signature` generadas por mí con un
  secreto de PRUEBA (200 correcto, reintento sin duplicar, 401 con otro secreto/sin
  firma/timestamp viejo; 2 filas atribuidas, clase y ledger sin cambios). **No** se
  recibió ningún evento real de JaaS: el endpoint no está registrado en su consola y el
  secreto de staging es el de prueba hasta que se cargue el real.
- **Cloudinary (staging):** subida sintética, lectura HTTPS, sin copia local y borrado
  confirmado por la Admin API, ejecutado desde el contenedor de staging. **Sentry:**
  un evento sintético desde web y otro desde worker (entorno `staging`); falta
  confirmar ingestión en el panel.
- **Mercado Pago:** sigue SIN probar. Con el pagador `test_payer@example.com`,
  `POST /v1/payments` responde `HTTP 500 {"message":"internal_error"}` sin causa
  (x-request-id `f2fc1434-976a-45c4-97fe-bda867597ff9`, 2026-10-05 07:32:42 UTC), igual con
  variantes de payload, tarjetas (Mastercard/Visa de prueba) y montos; con un
  pagador `@testuser.com` responde 403 `Payer email forbidden` (4390). La credencial es
  de una cuenta personal normal con claves `TEST-`; el app id y el user id del token
  coinciden con `MERCADOPAGO_APPLICATION_ID`/`COLLECTOR_ID`. Webhook sin configurar:
  `MERCADOPAGO_WEBHOOK_SECRET` vacío; pagos y webhook de staging siguen apagados.
- **Deriva respecto a Bicep:** estos cambios se aplicaron con `az containerapp
  update/secret set`; `apps.bicepparam` ya admite `MOVA_MAIL_ALLOWLIST` y los secretos
  de firma/token de JaaS, pero el próximo deploy de plantilla debe reproducirlos.
Herramienta común: `qa/.env.sandbox` (ignorado por Git; nombres en
`qa/.env.sandbox.example`) + `bash scripts/sandbox-smoke.sh <payments|mail|files|sentry|jaas>`;
cada sonda se niega fuera de local/testing, usa una BD SQLite temporal, ve solo las
credenciales de su integración y no imprime secretos.

**Actualización 2026-10-05 (fase paneles, navegador integrado de la app).**
Herramienta de navegador: el navegador integrado de Claude (`mcp__Claude_Browser__*`)
con sesiones ya abiertas del propietario en Mercado Pago, JaaS y Gmail; Claude in Chrome
no estaba conectado. Esto **sustituye** lo anterior donde contradiga:
- **Mercado Pago:** la aplicación del `.env` es «MOVA Recarga de Creditos Payments»
  (ID 6583217782927097, coincide con el app id y el user id del Access Token). El panel
  de credenciales y el de Webhooks piden verificar identidad (código al celular …5850):
  **no pude abrirlos**, por tanto NO se comprobó en el panel que Public Key y Access
  Token sean de la misma aplicación ni se configuró el webhook (URL prevista
  `https://staging.movaeduca.me/api/webhooks/mercadopago`, evento Pagos, modo prueba).
  Sonda corregida (payer por defecto `test_payer@example.com`): `POST /v1/payments` sigue
  en HTTP 500 `internal_error` (request id `afeea2bc-0c83-47d0-aa10-f0b6ac844569`,
  2026-10-05 08:58:18 UTC). Se abrió un caso en el asistente de soporte de Mercado Pago
  Developers (producto Checkout API, MPE, request id `f2fc1434-…`, sin secretos); el
  número `WCS-…` llega por correo al titular. El asistente sugirió comprobar que ambas
  credenciales sean de prueba de la misma aplicación y usar un comprador de prueba; el
  comprador de prueba creado por API ya dio 403 (4390). **Pagos y webhook de Mercado
  Pago siguen apagados en staging; no se declara ningún flujo de pago funcional.**
- **JaaS:** endpoint `https://staging.movaeduca.me/api/webhooks/jaas` creado en la
  consola con `PARTICIPANT_JOINED` y `PARTICIPANT_LEFT` (sin header Authorization). El
  secreto de firma real se guardó en el `.env` local (`JAAS_WEBHOOK_SIGNING_SECRET`) y
  en el secreto `jaas-webhook-signing-secret` de `mova-web`, **reemplazando** el de
  prueba (la revisión 31 llevó por error otro valor; la 32 lleva el correcto). Eventos
  reales de una reunión docente+padre llegaron firmados: 13 filas en
  `lesson_presence_events` (ids distintos, atribuidas al docente y al padre), clase y
  ledger sin cambios (1 reserva, saldos intactos). Los intentos anteriores al secreto
  correcto figuran «Failed» en el panel; los posteriores «Successful». Con el secreto
  real: otro secreto → 401, sin cabecera → 401, firma válida con `t` de hace 1 h → 401.
- **Correo:** en Gmail (m0v4class@gmail.com) están en la bandeja el correo de staging
  («MOVA staging - prueba de correo») y los 3 de la sonda local (verificación, reset,
  constancia).
- **Sentry:** el panel no se pudo abrir (`sentry.io` pidió inicio de sesión y no
  encontró una cuenta para m0v4class@gmail.com; no se creó ninguna organización).
- **Estado de staging al cierre:** imagen `mova@sha256:09d64d9f…`; web
  `mova-web--0000032`, worker `mova-worker--0000017`, scheduler
  `mova-scheduler--0000011`; pagos `fake`/apagados, recargas y webhook de Mercado Pago
  apagados, correo `smtp` con `MAIL_ALLOWLIST`, webhook JaaS activo. Variables
  marcadoras `MOVA_RELEASE_LABEL` y `MOVA_SECRETS_ROTATED_AT` solo fuerzan revisiones.

**Actualización 2026-10-05 (ronda 3 — Mercado Pago operativo en sandbox).** Esta ronda
**sustituye** lo anterior donde diga que Mercado Pago «no se probó», que
`POST /v1/payments` «falla con 500» o que Sentry no se pudo verificar:
- **Verificación de identidad:** el titular completó el código por celular en el navegador
  integrado; con esa sesión se accedió a la aplicación «MOVA Recarga de Creditos Payments»
  (ID 6583217782927097, User ID 3659471468 = `MERCADOPAGO_EXPECTED_COLLECTOR_ID`). La
  Public Key de prueba del panel coincide con la del `.env` (comparación por hash, sin
  mostrar valores). El Access Token no se reveló (el permiso fue denegado); se verificó
  por el id de aplicación incrustado (6583217782927097) y porque `GET /users/me`
  devuelve ese vendedor.
- **POST de pago:** a las 2026-10-05 17:57:43 UTC el mismo payload de MOVA devolvió
  HTTP 201 aprobado (request id `babb650e-1c59-4555-ae13-cb3165b4a482`, pago
  `1328350720`, `live_mode=false`, `accredited`, S/ 25). Los 500 anteriores (request ids
  `f2fc1434-976a-45c4-97fe-bda867597ff9` 07:32:42 UTC y `afeea2bc-0c83-47d0-aa10-f0b6ac844569`
  08:58:18 UTC) no se reprodujeron más; no se identificó la causa. El pagador por defecto de
  la sonda es `test_payer@example.com`; un correo `@testuser.com` da 403 4390 y un correo
  `.test` da 400 «payer.email must be a valid email».
- **Soporte:** ticket **WCS-53285** (Checkout API, MPE). Mercado Pago respondió pidiendo
  payload, confirmación de credenciales TEST de la misma aplicación y cuerpo del 500; se
  respondió en el ticket con el payload saneado, ambos request ids, la confirmación y la
  nueva situación (sin tokens ni datos de tarjeta); pendiente su explicación.
- **Sonda local, 3 escenarios con webhook firmado:** aprobado `paid`, 1 depósito, 5
  créditos, webhook x1/replay 200/200; rechazado `failed` 0/0; pendiente `pending` 0/0.
- **Webhook en el panel (modo prueba):** URL
  `https://staging.movaeduca.me/api/webhooks/mercadopago`, evento Pagos; modo productivo sin
  URL. La clave de firma generada se guardó en el `.env` local
  (`MERCADOPAGO_WEBHOOK_SECRET`) y en staging como secreto `mercadopago-webhook-secret`.
- **Staging solo sandbox:** `PAYMENT_PROVIDER=mercadopago`, `PAYMENTS_ENABLED=true`,
  `MERCADOPAGO_WEBHOOKS_ENABLED=true`, `MERCADOPAGO_EXPECTED_LIVE_MODE=false`,
  `RECHARGES_ENABLED=false`, credenciales `TEST-` (comprobado antes de cargarlas) en web,
  worker y scheduler. Revisiones al cierre: web 33, worker 19, scheduler 12. Un pago
  sandbox real creado desde staging (`1328350776`, profesor sintético
  `test_payer@example.com`) quedó `paid` con 1 depósito de 5 créditos.
- **Webhooks recibidos en staging:** `payment.created` real de Mercado Pago (firma válida)
  y `payment.updated` del simulador del panel, ambos sobre `1328350776`, terminaron
  `processed` sin crear abonos adicionales (ledger: un solo `deposit:5`, saldo 5).
  El primero había quedado `failed` porque el worker no tenía la variable
  `MERCADOPAGO_ACCESS_TOKEN` (solo el secreto); corregido, `mercadopago:reconcile` lo
  reprocesó. Otras notificaciones llegaron por pagos creados por la sonda local con la
  misma aplicación; sin orden en staging terminan `failed`
  («no existe PaymentOrder local»), sin efecto en créditos. **Aplicación compartida:** local
  y staging usan la misma app de pruebas, así que staging recibe notificaciones de las
  pruebas locales.
- **Sentry:** con la sesión propietaria (org `gym-lima`, proyecto `php-laravel`, la llave
  del DSN coincide por hash) se confirmaron los 3 eventos sintéticos: `qa`
  (`ac05b50d…`), `staging` web (`de5511f2…`) y `staging` worker (`a1ee7ece…`), sin
  `user.email`. Hay además 4 eventos `local` anteriores (`QueryException` con una ruta
  local en el mensaje).
- **Pendientes (no probados):** flujo de recarga desde el navegador con Card Brick y 3DS,
  Yape, reversos/contracargos en sandbox, y cualquier operación en producción.

**Actualización 2026-10-05 (ronda 4 — checkout visible en staging, navegador).** Esta
ronda **sustituye** el «pendiente» anterior sobre Card Brick, 3DS, Yape y reversos:
- **Hallazgo y corrección de acceso:** el checkout automático NO depende de
  `RECHARGES_ENABLED` (solo del flujo manual): con `PAYMENTS_ENABLED=true`, proveedor
  `mercadopago` y Public Key, quedaba abierto a **todos** los profesores de staging.
  Se añadió `MERCADOPAGO_CHECKOUT_ALLOWLIST` (`App\Support\CheckoutAllowlist`, config
  `payments.mercadopago.checkout_allowlist`): con lista, solo esos correos ven el botón y
  pueden crear/pagar un checkout (los demás reciben 503); vacía = sin restricción
  (producción). No afecta a webhooks ni a la recuperación. 5 tests nuevos
  (`CheckoutAllowlistTest`) + 32 existentes de checkout en verde; los tests destaparon un
  `use` faltante en `CreditController` (corregido).
- **Despliegue:** imagen `mova@sha256:5ded3a24…` (árbol sin commit), sin migraciones nuevas;
  revisiones web 34, worker 20, scheduler 13. Usuario QA: profesor sintético
  `test_payer@example.com` (correo `example.com` porque Mercado Pago rechaza `.test`).
  Verificado en navegador: otro profesor (`jaas-sbx-teacher@mova.test`) no ve el botón y
  `POST /teacher/credits/checkout` → 503.
- **Navegador (Playwright contra `https://staging.movaeduca.me`, profesor QA, sandbox):**
  | Escenario | Resultado en la UI | Orden | Depósitos |
  |---|---|---|---|
  | Card Brick aprobado (APRO) | «¡Pago aprobado!» | `paid` | 1 × 5 |
  | Card Brick rechazado (OTHE) | «El pago no pudo completarse» | `failed` | 0 |
  | 3DS challenge exitoso (`5483 9281 6457 4623`) | challenge mostrado, «Confirmar» → aprobado | `paid` | 1 × 5 |
  | 3DS no autorizado (`5361 9568 0611 7557`) | challenge mostrado, «Confirmar» → fallido | `failed` | 0 |
  | Yape aprobado (`111111111` / `123456`) | «¡Pago aprobado!» | `paid` | 1 × 5 |
  | Yape rechazado (`111111112` / `123456`) | «El pago no pudo completarse» | `failed` | 0 |
  Ledger final del profesor QA: exactamente un `deposit:5` por pago aprobado
  (recargas 8, 11, 13, 15), cero por los rechazados. Tarjetas y teléfonos son los publicados
  por Mercado Pago (documentación oficial). Una primera corrida del Yape rechazado mostró
  «No se pudo enviar el pago. Intenta de nuevo.»: era `throttle:10,1` por usuario (12
  peticiones en un minuto de mi script); repetido en solitario pasó. La UI usa ese mensaje
  genérico también para un 429.
- **Reintentos:** `POST …/refresh` ×3 y `POST …/pay` sobre una recarga ya pagada devolvieron
  `approved` sin crear órdenes ni depósitos (órdenes con pago: 7 antes y después; ledger
  idéntico). Los webhooks reales (`payment.created`/`updated`) de cada pago quedaron
  `processed` (10 de 10 para pagos de QA) sin abonos extra.
- **Reverso:** MOVA **no inicia** devoluciones en Mercado Pago (no hay llamada de reembolso
  en el código); solo **reacciona** a un reembolso/contracargo ya confirmado por el
  proveedor (`RechargeApprovalService::reverse()` con `actorId=null`, que aplica el reverso
  aun dejando saldo negativo) y ofrece al administrador una reversión **manual solo de
  ledger** (`POST /admin/recharges/{id}/reverse`, MFA sensible, motivo ≥ 10 caracteres; falla
  cerrado si dejaría saldo negativo y alerta). Prueba **sandbox** (no real): reembolso total
  del pago `1353133007` (recarga 11) creado por la API de Mercado Pago (`live_mode=false`);
  el webhook real reconcilió, la recarga pasó a `reversed`, se añadió **un** asiento
  `reversal:-5` y el saldo bajó de 20 a 15; `mercadopago:reconcile` posterior no añadió un
  segundo reverso. Falta decidir (negocio): qué hacer con la deuda
  si el profesor ya gastó esos créditos, quién y cómo inicia un reembolso (hoy solo desde el
  panel de Mercado Pago) y si MOVA debe exponer una acción de devolución.
- **Soporte WCS-53285:** sigue abierto («Pendiente»); soporte pidió payload, confirmación de
  credenciales y cuerpo del 500, ya entregados (comentario del 2026-10-05 13:24 hora local);
  sin respuesta nueva todavía. No se cambiaron ni rotaron credenciales.
- **Estado final de staging (más seguro):** al terminar, `MERCADOPAGO_CHECKOUT_ALLOWLIST`
  quedó con un correo inexistente (`checkout-cerrado@invalid.invalid`): el checkout
  automático está cerrado para TODOS, incluido QA, mientras webhooks y recuperación siguen
  activos. Para volver a probar, poner `test_payer@example.com` en esa variable (web, worker
  y scheduler) y crear revisión nueva. `RECHARGES_ENABLED=false`, proveedor sandbox
  (`live_mode` esperado `false`) sin cambios.
- **No probado:** pagos con 3DS en un navegador móvil, Amex/Débito, cuotas > 1, otros
  rechazos de la tabla (`FUND`, `CALL`, …), contracargos y producción.

| Integración | Código | Mocks / local | Sandbox real | Bloqueo exacto |
|---|---|---|---|---|
| Mercado Pago | completo (Yape, tarjeta + 3DS, webhook firmado, conciliación, recovery, reversos, ledger) | ≈300 tests con HTTP simulado; 5 sondas de concurrencia real MySQL en verde; sonda `mercadopago:sandbox-smoke` (12 tests de su mecánica, HTTP simulado) | **Funciona en sandbox**: `POST /v1/payments` aprobado (201, `live_mode=false`); sonda local aprobado/rechazado/pendiente OK (1 abono y 5 créditos en aprobado, 0 en los otros, conciliación y webhook repetidos sin duplicar); en **staging** pago sandbox real `1328350776` → orden `paid`, 1 depósito de 5 créditos; webhooks reales y del simulador recibidos con firma válida; recuperación reprocesa sin duplicar | Los HTTP 500 del 2026-10-05 (07:32 y 08:58 UTC) no se reproducen desde las 17:57 UTC; caso WCS-53285 abierto para conocer la causa. Falta 3DS interactivo y el flujo con el navegador (Card Brick); recargas siguen apagadas en staging (`RECHARGES_ENABLED=false`); producción no probada |
| Correo | Gmail API + `smtp` genérico, reintentos y recuperación | **SMTP real local (Mailpit)**: 6 tests (verificación, reset, constancia, enlaces al dominio configurado, SMTP caído); correo de reset ahora en español | **Gmail SMTP real**: 3 correos de sonda + 1 de staging, **recibidos** en la bandeja del buzón autorizado (verificado en Gmail). Gmail API: renovación del token falla (HTTP 400, probable `invalid_grant`) | Reautorizar Gmail API si se quiere ese transporte (`mova:gmail-auth-url`); proveedor/dominio final con SPF/DKIM/DMARC sin tocar MX |
| Reuniones (JaaS) | servidor sólido; cliente endurecido; receptor de presencia (solo evidencia) | 11 E2E con `external_api.js` simulado (escritorio y móvil) + PHPUnit de ventana, permisos y presencia | **Reunión real en staging** (docente escritorio + padre móvil: misma sala, audio/video, salida y reingreso) y **webhook real**: endpoint registrado en la consola de JaaS, eventos `PARTICIPANT_JOINED/LEFT` entregados, firma `X-Jaas-Signature` verificada con el secreto real, reintentos sin duplicar, rechazo de firma inválida/timestamp viejo | Prueba humana con dos dispositivos reales (calidad de audio/video); reglas de asistencia/ausencia/disputas siguen sin definirse (los eventos son solo evidencia) |
| Archivos (Cloudinary) | avatares: Cloudinary; en producción falla cerrado sin él; sin otro uso de disco local | tests con mocks existentes; sonda `mova:cloudinary-smoke` sin ejecutar | **Cloudinary real, local y desde staging**: subida, lectura HTTPS, sin copia local, borrado verificado en la Admin API | Prueba de subida desde el navegador con un usuario real de staging (no hecha) |
| Sentry | integrado en el Handler; `send_default_pii=false` por defecto | — | **Verificado en el panel**: los 3 eventos (`qa`, `staging` web y worker) están en el proyecto `php-laravel` (org `gym-lima`), IDs idénticos a los devueltos por el SDK | El proyecto/org se llama `gym-lima`: confirmar que es el destino deseado para MOVA; hay 4 eventos `local` de pruebas tempranas (excepciones con ruta local) que conviene borrar en Sentry |

### Mercado Pago
- **Faltan** (nombres exactos, en `qa/.env.sandbox`): `MERCADOPAGO_ACCESS_TOKEN`, `MERCADOPAGO_PUBLIC_KEY` (ambas de PRUEBA, de la misma aplicación), `MERCADOPAGO_APPLICATION_ID`, `MERCADOPAGO_EXPECTED_COLLECTOR_ID` (user id del vendedor de prueba) y, opcional, `MERCADOPAGO_WEBHOOK_SECRET` (panel → Tus integraciones → Webhooks → modo de prueba → clave secreta).
- **Después ejecutaré:** `bash scripts/sandbox-smoke.sh payments --scenario=approved --scenario=rejected --scenario=pending`: tarjeta de prueba publicada por Mercado Pago (APRO / OTHE / CONT), conciliación dos veces y webhook firmado dos veces, verificando **exactamente un depósito** y créditos exactos. Aborta si Mercado Pago responde `live_mode=true`.
- **No cubre y no debe afirmarse:** webhooks reales desde Mercado Pago a una URL pública (los pagos TEST no los disparan; hace falta el simulador del panel contra una URL pública, p. ej. staging), 3DS interactivo, Yape con OTP real, producción.
- Staging vivo: `mova-web` **no tiene** `MERCADOPAGO_WEBHOOK_SECRET` y `MERCADOPAGO_WEBHOOKS_ENABLED=false`. La IaC ya soporta el secreto; activar el sandbox en staging es `MOVA_PAYMENTS_MODE=sandbox` en el próximo deploy (nuevo, con defaults seguros).
- El `.env` local del checkout trae credenciales de Mercado Pago (no se leyeron). Si son de PRUEBA, puedes copiarlas a `qa/.env.sandbox`.

### Correo
- **Qué falla hoy con Gmail:** `INVALID_GRANT` indica un refresh token no válido. Según Google, un refresh token deja de servir si: el proyecto OAuth está en estado de publicación **Testing** (caduca a los 7 días), el usuario revocó el acceso, **cambió la contraseña de esa cuenta Gmail**, pasaron 6 meses sin usarlo o se superaron 100 tokens por cliente. La causa concreta aquí es desconocida; lo más probable es *Testing* o cambio de contraseña. **Falta:** un refresh token nuevo.
- **Qué hacer (en tu máquina, con tu cuenta remitente):** en Google Cloud Console → proyecto del cliente OAuth → pantalla de consentimiento: publicar en *In production* (o aceptar que caduca cada 7 días); confirmar Gmail API habilitada y `http://localhost` como URI de redirección autorizada; luego `php artisan mova:gmail-auth-url`, abrir la URL con la cuenta remitente, copiar el `code` de la URL de redirección y `php artisan mova:gmail-exchange-code <code>`. **Ojo:** ese comando imprime el token en tu terminal: cópialo directo a `qa/.env.sandbox` (`GMAIL_REFRESH_TOKEN`) y a la configuración de Azure; no lo pegues en chats ni logs. Verificación: `bash scripts/sandbox-smoke.sh mail`.
- **Recomendación:** no usar `@gmail.com` como remitente de producción (cuenta personal con límites de envío, sin dominio propio ni autenticación SPF/DKIM de `movaeduca.me`). Usar un proveedor transaccional **por SMTP**, que el código ya soporta (`MAIL_MAILER=smtp`, sin dependencias nuevas). Opción concreta: **Resend**: host `smtp.resend.com`, puerto 587 (STARTTLS) o 465 (TLS implícito), usuario `resend`, contraseña = API key, remitente en un dominio verificado. Usar un **subdominio** de envío (p. ej. `mail.movaeduca.me`; Resend lo recomienda para aislar reputación) para no tocar el MX ni el SPF del apex (reenvío de Namecheap). Los registros DNS exactos los muestra el panel de Resend al añadir el dominio (no se afirman aquí). Añadir además `_dmarc` (`v=DMARC1; p=none; rua=mailto:<buzón>`) y endurecer después de observar. **Necesito de ti:** elegir proveedor, crear la cuenta y API key (cargarla en `qa/.env.sandbox` como `MAIL_PASSWORD`, con `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`), un buzón de prueba tuyo (`MAIL_SMOKE_RECIPIENT`) y autorizar añadir los registros DNS en Namecheap.
- Deploy: `MOVA_MAIL_MAILER=smtp|gmail_api` (+ variables) cambia el mailer de `array` en la plantilla; si falta algo, vuelve a `array`.

### Reuniones (JaaS)
- **Hecho (local, simulado):** ventana de entrada autoritativa (403 antes/después, 200 dentro), JWT por sala con moderador solo para el docente, sin grabación, que vence al cerrar la ventana y ahora lleva solo el `id` de usuario (para atribuir webhooks); cliente: el fallo al cargar `8x8.vc` (antes: "Conectando…" para siempre y promesa sin capturar) muestra un mensaje y no manda a confirmar pago; avisos de cámara/micrófono con la acción concreta sin cerrar la clase; error fatal comunicado; salir/reentrar libera la instancia; liberación al desmontar. Los 4 tests de errores fallan con el código anterior (verificado).
- **Falta (real):** `JAAS_APP_ID`, `JAAS_KEY_ID` y `JAAS_PRIVATE_KEY` (**base64 de una línea** del PEM: `base64 -w0 clave.pem`), de jaas.8x8.vc → API Keys. Después: `bash scripts/sandbox-smoke.sh jaas` (docente en escritorio y padre en móvil, medios sintéticos de Chromium, misma sala, 2 participantes, audio/video activos, reingreso). Aun así **no probará** que una persona real vea y oiga con calidad: eso exige una prueba humana con dos dispositivos. `UNKNOWN-03` (cómo trata JaaS un `exp` que vence con la llamada en curso) sigue sin confirmar.
- **Presencia (solo código):** `POST /api/webhooks/jaas` guarda `PARTICIPANT_JOINED/LEFT` en `lesson_presence_events` sin nombre, correo ni avatar, idempotente, autenticado con la firma `X-Jaas-Signature` (HMAC-SHA256, `JAAS_WEBHOOK_SIGNING_SECRET`, el que genera JaaS) y/o un header `Authorization: Bearer <JAAS_WEBHOOK_AUTH_TOKEN>` opcional (secreto distinto, definido por MOVA), **desactivado por defecto** y **sin ningún efecto** en estado de clase, créditos o liquidación. **No es asistencia ni disputa:** siguen sin existir reglas (C-P1-ATTENDANCE-DISPUTES). Para activarlo (no hecho): consola de JaaS → Webhooks → Add endpoint con `https://<dominio>/api/webhooks/jaas`, eventos `PARTICIPANT_JOINED` y `PARTICIPANT_LEFT` y el header Authorization; en el deploy `MOVA_JAAS_WEBHOOKS_ENABLED=true` + `MOVA_JAAS_WEBHOOK_SIGNING_SECRET` (y/o `MOVA_JAAS_WEBHOOK_AUTH_TOKEN`). No pude comprobar en la documentación qué plan o rol de la consola se necesita para crear webhooks.

### Archivos y Sentry
- **Cloudinary:** `bash scripts/sandbox-smoke.sh files` sube un PNG sintético con un id reservado (≥ 9 000 000 000), lo lee por HTTPS desde el CDN, comprueba que no queda copia en disco local y lo borra, confirmando el borrado contra la Admin API. **Falta:** `CLOUDINARY_URL` de un cloud de PRUEBA en `qa/.env.sandbox`. El `CLOUDINARY_URL` de staging existe como secreto pero su validez no se probó (requeriría iniciar sesión en staging).
- **Sentry:** `bash scripts/sandbox-smoke.sh sentry` envía un evento de prueba (`environment=qa`). **Falta:** `SENTRY_LARAVEL_DSN` de un proyecto de PRUEBA. Que el evento no contiene PII indebida solo se confirma mirándolo en Sentry (campo *User* vacío, sin cookies/cabeceras): `send_default_pii` es `false` por defecto, pero no hay prueba automatizada del payload. Nuevo: la plantilla etiqueta los eventos (`SENTRY_ENVIRONMENT`, por defecto `staging`); sin eso, staging (`APP_ENV=production`) contaminaría el proyecto de producción.

### Staging ≠ producción
- La base de staging contiene cuentas y movimientos de prueba (ver conteos arriba): **no** se debe apuntar el dominio público a ella. Nuevo control: `mova:health-check` marca `QA_FIXTURE_DATA_IN_PUBLIC_SITE` si el sitio está declarado público (`SEARCH_INDEXING_ENABLED=true`) y la base contiene cuentas `@mova.test` / `.test` / `qa-*`.
- Falta decidir y preparar la base de producción separada (nueva base o servidor, migraciones revisadas, backup/restore verificados) y credenciales de aplicación nunca expuestas.

### Autorizaciones concretas que se necesitarían (no concedidas)
1. Cargar en Azure staging, mediante un deploy de la plantilla, las credenciales de **prueba** y `MOVA_PAYMENTS_MODE=sandbox`, `MOVA_MAIL_MAILER`, `MOVA_JAAS_WEBHOOKS_ENABLED` y `MOVA_SENTRY_ENVIRONMENT`.
2. Registro del endpoint de presencia en la consola de JaaS (requiere URL pública HTTPS).
3. Añadir en Namecheap los registros DNS del proveedor de correo y `_dmarc`, sin tocar MX/SPF del apex.
4. Crear la base de producción separada.

## Evidencia de gates en esta rama

Evidencia C1 sobre la rama después de las correcciones de comportamiento
hasta `d61d81c`; C1.1 solo modifica IaC y documentación, sin volver a
ejecutar la matriz funcional completa. En 2026-09-29 pasaron con código 0:
`composer validate --strict`, `composer audit --locked`,
`npm audit --omit=dev --audit-level=high` (0 vulnerabilidades),
`npm run build`, `npm run check:title`, `npm run check:chatbot-escape`,
`npm run check:movi-availability` y `git diff --check`.
El build avisó que las fuentes PlusJakartaSans se resolverán en runtime;
la salida confirmó 2674 módulos y build completo. PHPUnit SQLite,
PHPUnit SQLite terminó con código 0: 1264 tests, 4972 assertions, 0 failures,
1 skip y 33 deprecations (10:03.827, PHP 8.3.33). PHPUnit MySQL 8.4 terminó
con código 0: 1264 tests, 4971 assertions, 0 failures, 1 skip y 33
deprecations (10:57.423, PHP 8.3.33), tras `mova:qa-mysql-fresh-migrate`
con guarda `mysql_qa`/`mova_qa` confirmada. Playwright terminó con 65/65
tests y código 0 sobre el contenedor QA local (12,7 min).
Una pasada SQLite previa tras cerrar el bypass de reaceptación terminó con
1264 tests, 1 failure y 1 skip: un test de consentimiento cambió la versión
de Privacidad y omitió la nueva reaceptación. `d61d81c` corrigió el escenario;
la prueba dirigida pasó (1 test, 12 assertions). Las pasadas completas sobre
ese commit se detallan arriba y pasaron.

## Estados

| Estado | Significado |
|---|---|
| `OPEN` | Defecto o brecha conocida, sin trabajo que lo resuelva todavía. |
| `BLOCKED_EXTERNAL` | Depende de una acción/credencial/servicio fuera del repo. |
| `REQUIRES_OWNER_INPUT` | Depende de una decisión o dato del titular (legal, negocio, identidad). |
| `IMPLEMENTED_NOT_VERIFIED` | Código hecho y probado localmente; falta evidencia en entorno real o gate final. |
| `VERIFIED` | Evidencia local completa (tests + gates) sobre el snapshot de la rama. |
| `CLOSED` | Verificado **y** con evidencia en vivo en producción cuando el criterio la exige. |

Severidad: `P0` bloquea lanzamiento; `P1` debe resolverse antes de GA o
tener mitigación aceptada por el titular; `P2` deuda que no bloquea.

## Resumen

| ID | Sev | Dominio | Estado |
|---|---|---|---|
| C-P0-EMAIL | P0 | Integraciones | BLOCKED_EXTERNAL |
| C-P0-CREDITS | P0 | Dinero | BLOCKED_EXTERNAL |
| C-P0-SETTLEMENT | P0 | Dinero | BLOCKED_EXTERNAL |
| C-P0-DB-CREDENTIAL | P0 | Seguridad | BLOCKED_EXTERNAL |
| C-P0-LEGAL-TRUTH | P0 | Texto/código legal | VERIFIED |
| C-P0-LEGAL-APPROVAL | P0 | Aprobación legal externa | REQUIRES_OWNER_INPUT |
| C-P0-MINOR-CONSENT-NEW | P0 | Registro nuevo de menores | VERIFIED |
| C-P0-MINOR-CONSENT-HISTORICAL | P0 | Alumnos anteriores | REQUIRES_OWNER_INPUT |
| C-P0-ANPD-REGISTRATION | P0 | Legal | REQUIRES_OWNER_INPUT |
| C-P0-TRANSBORDER | P0 | Legal | REQUIRES_OWNER_INPUT |
| C-P1-TEACHER-PROFILE-SCORE | P1 | Producto | VERIFIED |
| C-P1-TEACHER-ONBOARDING | P1 | Producto | VERIFIED |
| C-P1-PHONE-VERIFICATION | P1 | Integraciones | BLOCKED_EXTERNAL |
| C-P1-GOOGLE-OAUTH | P1 | Auth | BLOCKED_EXTERNAL |
| C-P1-MOVI | P1 | Producto / IA | BLOCKED_EXTERNAL |
| C-P1-MERCADOPAGO | P1 | Dinero | BLOCKED_EXTERNAL |
| C-P1-LEGAL-REACCEPTANCE | P1 | Legal | VERIFIED |
| C-P1-JITSI-MEDIA | P1 | Integraciones | IMPLEMENTED_NOT_VERIFIED |
| C-P1-BACKUP-RESTORE | P1 | Operación | BLOCKED_EXTERNAL |
| C-P1-DOMAIN-CUTOVER | P1 | Operación | BLOCKED_EXTERNAL |
| C-P1-REALTIME | P1 | Integraciones | BLOCKED_EXTERNAL |
| C-P1-CLOUDINARY | P1 | Integraciones | IMPLEMENTED_NOT_VERIFIED |
| C-P1-SENTRY | P1 | Operación | IMPLEMENTED_NOT_VERIFIED |
| C-P2-CI-MOVI | P2 | CI | CLOSED |
| C-P2-DOCS | P2 | Docs | IMPLEMENTED_NOT_VERIFIED |
| C-P2-COOKIE-TRUTH | P2 | Legal / UI | IMPLEMENTED_NOT_VERIFIED |
| C-P2-QA-PAYMENTS | P2 | QA | OPEN |
| C-P1-RECOMMENDATIONS | P1 | Producto | IMPLEMENTED_NOT_VERIFIED |
| C-P1-TEACHER-AVAILABILITY | P1 | Producto | IMPLEMENTED_NOT_VERIFIED |
| C-P1-ATTENDANCE-DISPUTES | P1 | Producto / operación | REQUIRES_OWNER_INPUT |
| C-P1-RECURRING-MENTORSHIP | P1 | Producto | REQUIRES_OWNER_INPUT |
| C-P1-TEACHER-VERIFICATION | P1 | Producto / legal | REQUIRES_OWNER_INPUT |

_(El detalle de cada ID, abajo, es la única fuente del estado final.)_

## Decisión C1

**NO-GO para producción/GA.** Los gates de código locales pasaron, pero
ningún ID se marca `CLOSED` solo por ello. Persisten los bloqueos P0 de
entrega real de correo, fuente de créditos operativa, liquidación viva,
rotación verificable de la credencial expuesta, aprobación jurídica del texto,
consentimiento de alumnos históricos y gestión ANPD/transfronteriza. El
cutover de dominio, JaaS con cámara/micrófono y demás proveedores requieren
pruebas o decisiones externas. C1 no cambió producción, Azure, DNS, Railway,
variables de entorno ni activó pagos, WhatsApp o IA.

---

## Detalle

Formato por ID: severidad · dominio · evidencia · estado · criterio de
cierre · commit · tests · evidencia en vivo · notas.

### C-P0-LEGAL-TRUTH
- **Severidad / dominio:** P0 · Legal.
- **Evidencia `CODE` / `LOCAL_TEST`:** Términos y Privacidad previos contenían afirmaciones contradichas por el runtime; `Legal/Terms.vue`, `Legal/Privacy.vue`, `LegalController` y `HealthCheck` ahora describen minimización, consentimiento, proveedores y estado apagado de IA.
- **Estado:** `VERIFIED` para veracidad del texto frente al software local; no implica aprobación legal.
- **Criterio de cierre:** tests de verdad y build verdes en HEAD final; smoke de documentos publicados en PRODUCTION_LIVE. La aprobación jurídica se sigue por separado en C-P0-LEGAL-APPROVAL.
- **Commit / tests:** `2a1b62f`, `a68d58e`; `LegalDocumentsTruthTest`, SQLite 1264/0, MySQL 1264/0, build y checks pasaron.
- **Evidencia en vivo:** no aportada.
- **Notas:** `LEGAL_TERMS_VERSION` y `LEGAL_PRIVACY_VERSION`, si están definidos en el entorno, pueden prevalecer sobre los defaults `2026-09-29`; verificar nombres/versiones efectivas antes del despliegue sin mostrar secretos.

### C-P0-LEGAL-APPROVAL
- **Severidad / dominio:** P0 · Legal externo.
- **Evidencia `CODE`:** los documentos describen el flujo implementado; no hay `OWNER_CONFIRMATION` de asesoría legal, datos formales del titular ni aprobación de versiones efectivas.
- **Estado:** `REQUIRES_OWNER_INPUT`.
- **Criterio de cierre:** titular/asesor valida texto, identidad del proveedor, excepciones de reaceptación y versiones finales; registra su decisión antes de publicar.
- **Evidencia `PRODUCTION_LIVE`:** pendiente. La aprobación de textos no sustituye trámites ANPD o flujo transfronterizo.

### C-P0-MINOR-CONSENT-NEW
- **Severidad / dominio:** P0 · Menores / legal.
- **Evidencia `CODE` / `LOCAL_TEST`:** `StudentController::store` exige casilla explícita y crea alumno + `StudentDataConsent` en una transacción; migración `2026_09_29_000001` conserva versión, padre y alumno.
- **Estado:** `VERIFIED` localmente para alumnos nuevos; no acredita aceptación jurídica ni despliegue.
- **Criterio de cierre:** flujo real con cuenta QA propia, texto aprobado en C-P0-LEGAL-APPROVAL y migración revisada antes de producción. Históricos se siguen por separado.
- **Commit / tests:** `a951632`, `d61d81c`; `StudentDataConsentTest` incluye reaceptación de la versión vigente antes de registrar al menor; test dirigido 1/12 assertions y suites SQLite/MySQL completas pasaron.
- **Evidencia en vivo:** no aportada.
- **Notas / STOP de rollback:** sin backfill deliberadamente. Una vez existan filas reales, `down()` ejecuta `dropIfExists` y destruye evidencia. No revertir automáticamente esa migración; detener, preservar y verificar copia de auditoría, y decidir una recuperación supervisada. No se inventa plazo legal de conservación.

### C-P0-MINOR-CONSENT-HISTORICAL
- **Severidad / dominio:** P0 · Menores / legal externo.
- **Evidencia `CODE`:** la migración no hace backfill y `student_data_consents` solo registra consentimientos nuevos. No existe evidencia de consentimiento específico por alumno histórico.
- **Estado:** `REQUIRES_OWNER_INPUT`.
- **Criterio de cierre:** titular/asesor define tratamiento de alumnos anteriores y reúne consentimiento válido por alumno cuando corresponda, sin inferirlo de otras acciones; evidencia documentada antes del cutover.
- **Evidencia `OWNER_CONFIRMATION` / `PRODUCTION_LIVE`:** pendiente.

### C-P1-TEACHER-PROFILE-SCORE
- **Severidad / dominio:** P1 · Producto.
- **Evidencia:** `active_offer` era un requisito imposible en el puntaje de perfil; se eliminó del cálculo y de la UI.
- **Estado:** `VERIFIED` (código local; smoke de perfil QA pendiente).
- **Criterio de cierre:** tests y build en HEAD final verdes; perfil de profesor QA muestra puntaje correcto.
- **Commit / tests:** `aff61e5`, `7cc6e1b`; `TeacherDashboardProfileScoreTest`, SQLite/MySQL y build pasaron.
- **Evidencia en vivo:** no aportada.
- **Notas:** `hourly_rate` es tarifa de referencia del sistema; no hay defecto demostrado que justifique retirarla.

### C-P1-TEACHER-ONBOARDING
- **Severidad / dominio:** P1 · Producto / notificaciones.
- **Evidencia:** las bienvenidas dejaron de instruir crear ofertas y fijar tarifa propia; describen solicitudes y contrapropuestas.
- **Estado:** `VERIFIED` (código local; entrega real de canales pendiente).
- **Criterio de cierre:** `WelcomeOnboardingCopyTest` y suite final verdes; copia de canales reales comprobada en entorno controlado.
- **Commit / tests:** `54cd7cc`; `WelcomeOnboardingCopyTest`, SQLite/MySQL y build pasaron.
- **Evidencia en vivo:** no aportada.
- **Notas:** no se envió correo ni WhatsApp masivo.

### C-P1-PHONE-VERIFICATION
- **Severidad / dominio:** P1 · Integraciones.
- **Evidencia:** `PhoneVerificationController::isAvailable` y `PhoneVerification.vue` no prometen OTP si el único canal está desactivado.
- **Estado:** `BLOCKED_EXTERNAL` (verdad de UI implementada; OTP real pendiente).
- **Criterio de cierre:** tests y build verdes; proveedor autorizado y configurado, OTP de una cuenta QA entregado y verificado en producción.
- **Commit / tests:** `7cc6e1b`; `PhoneVerificationAvailabilityTest`, SQLite/MySQL y build pasaron; OTP real pendiente.
- **Evidencia en vivo:** no aportada.
- **Notas:** WhatsApp no se activó en C1; sin OTP no se otorga bono de bienvenida.

### C-P1-GOOGLE-OAUTH
- **Severidad / dominio:** P1 · Auth.
- **Evidencia:** CTA solo visible con flag y configuración; redirect y callback usan la misma disponibilidad.
- **Estado:** `BLOCKED_EXTERNAL` (gating implementado; OAuth real pendiente).
- **Criterio de cierre:** tests y build verdes; credenciales/callback autorizados y login real de cuenta QA probado en dominio definitivo.
- **Commit / tests:** `9e0549c`; `GoogleAuthTest`, SQLite/MySQL, build y Playwright QA pasaron; OAuth real pendiente.
- **Evidencia en vivo:** no aportada.
- **Notas:** Google no se activó en C1.

### C-P1-MOVI
- **Severidad / dominio:** P1 · Producto / IA.
- **Evidencia:** `ChatbotService::isAvailable` condiciona widget y endpoint; verificación estática corregida y añadida a CI.
- **Estado:** `BLOCKED_EXTERNAL` (verdad de UI implementada; servicio real pendiente).
- **Criterio de cierre:** tests y checks verdes; activación intencional, política revisada, conversación QA segura en entorno definitivo.
- **Commit / tests:** `824bdd3`, `1f5588d`, `f141d6f` (router QA); `WelcomeMoviAvailabilityTest`, `check:movi-availability`, SQLite/MySQL, build y Playwright XSS pasaron; Gemini real pendiente.
- **Evidencia en vivo:** no aportada.
- **Notas:** Gemini/IA no se activó en C1.

### C-P1-LEGAL-REACCEPTANCE
- **Severidad / dominio:** P1 · Legal.
- **Evidencia:** `EnsureCurrentLegalAcceptance`, rutas y página de aceptación piden versiones vigentes en navegación y bloquean mutaciones normales con versiones vencidas; evidencia append-only y lock de usuario para doble envío.
- **Estado:** `VERIFIED` (código local; smoke de reaceptación en entorno final pendiente).
- **Criterio de cierre:** suite final verde, versiones efectivas verificadas en despliegue, aceptación de usuario QA antiguo y ausencia de bucles observadas en vivo.
- **Commit / tests:** `8ea68fa`, `a68d58e`, C1.2 (cierre de la excepción `pay`); `LegalReacceptanceTest` 17 tests / 68 assertions dirigido en SQLite (PHP 8.3.33, `php_qa`). Las suites SQLite/MySQL completas y Playwright de C1 pasaron sobre `9b0aaeb`/`d61d81c`; la matriz completa del HEAD C1.2 la ejecuta la CI remota de ese SHA.
- **Evidencia en vivo:** no aportada.
- **Notas:** el middleware conserva GET JSON y tres rutas de un checkout que ya existe: `teacher.credits.checkout.show`, `.status` y `.refresh` (ver, leer estado y reconciliar un intento ya enviado; ninguna crea un intento nuevo). `teacher.credits.checkout.pay` **no** está exento: `createPaymentAttempt()` puede crear un `PaymentOrder` nuevo (primer intento o reintento tras `failed`/`cancelled`/`expired`), así que exige la versión legal vigente; `teacher.credits.checkout.store` tampoco lo está. El test verifica que con términos vencidos `pay` no crea `PaymentOrder` ni llama al proveedor, y que sí llega al proveedor tras aceptar. Confirmar con asesoría si las tres excepciones restantes son aceptables (C-P0-LEGAL-APPROVAL sigue `REQUIRES_OWNER_INPUT`). Los seeders solo deben crear aceptación para usuarios recién creados, nunca para usuarios existentes.

### C-P2-CI-MOVI
- **Severidad / dominio:** P2 · CI.
- **Evidencia:** `.github/workflows/ci.yml` ejecuta `check:movi-availability` en la puerta frontend.
- **Estado:** `CLOSED`.
- **Criterio de cierre:** check local verde y corrida CI del commit remoto verde.
- **Commit / tests:** `1f5588d`; `check:movi-availability` pasó localmente. PR #3 fusionado (HEAD de C1 `5a0abf2261eaaab60003b450049593ae39303189`; commit de fusión en master `d6bd462b06c8308d13c27016e7879976d2997111`).
- **Evidencia en vivo:** GitHub Actions, ejecución `36653637812` sobre `d6bd462`: `frontend` (que ejecuta `npm run check:movi-availability`), `composer`, `sqlite`, `mysql` y `e2e` en `success`. Criterio de cierre cumplido; es CI de repositorio, no requiere evidencia PRODUCTION_LIVE.
- **Notas:** las corridas previas `36648600938` (sobre `9b0aaeb`) quedan superadas por esta.

### C-P2-DOCS
- **Severidad / dominio:** P2 · Documentación.
- **Evidencia:** README, `.env.example`, docs Azure y chequeo de producción de solo lectura se alinearon con Laravel 13/PHP 8.3, JaaS y Azure.
- **Estado:** `IMPLEMENTED_NOT_VERIFIED`.
- **Criterio de cierre:** revisión de diff y documentación aprobada por titular; comandos de chequeo seguros validados.
- **Commit / tests:** `d10d76d`, `fc89714`; revisión dirigida del diff completada; aprobación editorial del titular pendiente.
- **Evidencia en vivo:** no aplica hasta cutover.
- **Notas:** Railway permanece como contexto histórico/rollback.

### C-P2-COOKIE-TRUTH
- **Severidad / dominio:** P2 · Legal / UI.
- **Evidencia:** banner y Política describen cookies necesarias; revisión C1 no encontró trackers de publicidad/analítica.
- **Estado:** `IMPLEMENTED_NOT_VERIFIED`.
- **Criterio de cierre:** build y Playwright final verdes; cookies observadas en navegador QA coinciden con texto.
- **Commit / tests:** `f2950bf`; build y Playwright 65/65 pasaron; inspección específica de cookies reales pendiente.
- **Evidencia en vivo:** no aportada.
- **Notas:** volver a revisar al introducir analítica o publicidad.

### Gate E2E local
- **Evidencia:** la primera preparación local falló antes del resultado de Playwright porque `tar` intentó copiar `storage/logs/laravel.log` mientras PHPUnit lo escribía; `bf092e1` excluye los logs vivos y recrea el directorio dentro del contenedor QA. La siguiente corrida halló que el spec XSS esperaba el widget Movi, ahora oculto por el gating real; `f141d6f` lo habilita con configuración sintética solo en el router QA, donde Playwright intercepta la respuesta y no llama a Gemini.
- **Estado:** `VERIFIED` para el gate local de navegador.
- **Criterio de cierre:** salida final de Playwright con código 0 en el contenedor QA.
- **Commit / tests:** `bf092e1`, `f141d6f`; `bash -n docker/qa-e2e-serve.sh` y `php -l qa/stabilization-server.php` pasaron; Playwright 65/65, código 0 (2026-09-29).
- **Evidencia en vivo:** no aplica a producción.
- **Notas:** este gate no es un nuevo ID de lanzamiento; documenta la reparación de infraestructura QA necesaria para ejecutar C1.

### C-P0-EMAIL
- **Severidad / dominio:** P0 · Integraciones (correo transaccional).
- **Evidencia `LIVE_STAGING`:** `GMAIL_CLIENT_ID`, `GMAIL_CLIENT_SECRET` y `GMAIL_REFRESH_TOKEN` están presentes por `secretRef` en web/worker/scheduler; esto no prueba vigencia de credenciales. El runtime observado tiene `MAIL_MAILER=array` en los tres roles, por lo que no hay entrega externa de ese correo desde staging. `STATIC_IAC` coincide en `MAIL_MAILER=array`. La afirmación de Gmail en `MOVA_V1_STATE.md` es histórica. No se consultaron valores secretos ni se verificó entrega real. De este canal dependen verificación de email, recuperación de contraseña, copia del Libro de Reclamaciones y avisos de clase.
- **Estado:** `BLOCKED_EXTERNAL`.
- **Criterio de cierre:** en el entorno de producción definitivo, un registro real recibe el correo de verificación y un reclamo de prueba recibe su constancia; evidencia = ids de mensaje / capturas del buzón, sin exponer contenido personal.
- **Commit / tests:** C2.1: `mova:health-check` avisa (crítico, solo en producción) de `MAIL_MAILER=array|log`, mailer inexistente, configuración Gmail incompleta (solo nombres de variable), `failover` sin un segundo transporte capaz de enviar y remitente ausente/de ejemplo; `HealthCheckMailReadinessTest` 13 tests / 46 assertions (SQLite, PHP 8.3.33, `php_qa`); mutación verificada (8 de 13 fallan con el `HealthCheck` anterior). Es un chequeo de **configuración**: no hace red y no prueba validez de credenciales.
- **Evidencia en vivo:** `LIVE_STAGING` (Azure CLI, solo lectura, C2.1): `MAIL_MAILER=array` directo en web/worker/scheduler. Web y worker: `GMAIL_CLIENT_ID/SECRET/REFRESH_TOKEN` por `secretRef` y `GMAIL_FROM_ADDRESS/NAME` directas. Scheduler: solo `GMAIL_FROM_*`; sin credenciales Gmail (el IaC sí se las asignaría: deriva). Ningún rol define `MAIL_HOST/PORT/USERNAME/PASSWORD/URL` ni `MAIL_FROM_*`: SMTP **no** está configurado, así que `MAIL_MAILER=failover` hoy no daría redundancia real. `apps.bicepparam` (AZ-3G) registra que el refresh token dio `INVALID_GRANT` al validarlo en Railway: validez desconocida y probablemente vencida hasta re-autorizar. Entrega real: no verificada.
- **Hallazgo C2.1b (verificado en código):** `SafeMailChannel` capturaba cualquier `Throwable` del canal `mail` y no la relanzaba. Para notificaciones **solo correo** (`ComplaintFiledNotification`, `WelcomeEmailNotification`, `VerifyEmail`, `ResetPassword` de Laravel) eso convertía un fallo del proveedor en «éxito»: el job de cola no reintentaba ni llegaba a `failed_jobs`, y los flujos síncronos no veían el error; el correo se perdía para siempre. Corrección (`fix: retry failed mail-only notifications`): el canal lee `via()` (una vez, solo en el camino de fallo; los `via()` de MOVA son puros) y, si el único canal es `mail`, registra (sin destinatario ni mensaje, solo clase de excepción) y **relanza** sin `report()` (lo reporta quien la recibe: worker o handler HTTP). Multicanal: se conserva el comportamiento anterior (log + `report()`, sin relanzar) para no duplicar database/broadcast/WhatsApp ya entregados; si `via()` falla se asume multicanal. `array`/`log` siguen omitiéndose.
- **Semántica observada (tests con el worker real):** worker `queue:work --queue=default --tries=3 --backoff=5 --timeout=60` (`infra/azure/apps.bicep`), `retry_after=90` (`config/queue.php`, `database`): timeout 60 < retry_after 90. Un correo solo-correo en cola que falla: intento 1 + 2 reintentos (3 en total, con 5 s de espera en producción) y luego una fila en `failed_jobs`; si un reintento funciona se entrega y no queda fallido. Multicanal: 1 intento, sin reintento ni `failed_jobs`. Verificación de email (incluida la del evento `Registered`) y recuperación de contraseña **no** son de cola: corren dentro de la petición web, así que con un fallo del proveedor el llamador recibe el error (HTTP 500) en vez de un falso «enviado». La constancia del Libro de Reclamaciones y la bienvenida sí van por cola y reintentan.
- **C2.1c (recuperación síncrona, `fix: recover gracefully from synchronous mail failures`):** el fallo solo-correo ahora se lanza como `App\Exceptions\MailDeliveryException` (mensaje fijo sin destinatario, proveedor ni cuerpo; la causa real en `getPrevious()`), sin `report()` en el canal. En cola nadie la captura: reintenta (3 intentos) y llega a `failed_jobs` como antes. Multicanal: sin cambios. Los tres controladores síncronos capturan **solo** `MailDeliveryException`, hacen `report()` una vez y se recuperan: registro (autentica antes del evento; conserva cuenta, rol/perfil y aceptación legal; aviso de bienvenida in-app una sola vez; redirige a `verification.notice` con `flash.error`, sin `verification-link-sent`), reenvío de verificación (vuelve atrás con `error`, sin estado de éxito) y recuperación de contraseña (error de validación genérico en `email`, sin `RESET_LINK_SENT`). Otras excepciones (p. ej. de otro listener de `Registered`) siguen fallando en voz alta. El 500 tras crear la cuenta queda resuelto. `SynchronousMailFailureRecoveryTest` 8 tests; los tests de C2.1b se mantienen. Sigue sin haber credenciales Gmail validadas, despliegue ni entrega real: `BLOCKED_EXTERNAL`.
- **Baseline tras PR #4:** master `ddcc2b081ad5d0d077d7f3485a3ca431d8667751`; CI en ese SHA exacto: run 36667114270, los cinco jobs (frontend, composer, sqlite, mysql, e2e) en éxito.
- **C2.1d — OAuth de menor privilegio (`fix: restrict Gmail OAuth to send-only scope`):** `mova:gmail-auth-url` pedía `gmail.send` **y** `gmail.readonly`. `GmailApiMailService` solo intercambia tokens (`oauth2.googleapis.com/token`) y llama a `users.messages.send`; no existe consumidor de lectura de Gmail en runtime (búsqueda en `app/`, `config/`, `routes/`), así que se retiró `gmail.readonly`. Scope resultante: `https://www.googleapis.com/auth/gmail.send`. Sin cambio en el comportamiento de envío. `access_type=offline` y `prompt=consent` se mantienen (necesarios para obtener un refresh token de reemplazo). `redirect_uri` `http://localhost` es ahora una constante única (`GmailAuthUrl::REDIRECT_URI`) usada por autorización e intercambio; debe existir **idéntico** como redirect URI autorizado en el cliente OAuth de Google. Se corrigió texto obsoleto (Railway → Azure Container Apps; «Read email»). `mova:gmail-exchange-code` imprime el refresh token: es un paso **humano e interactivo, secreto**; su salida no debe pegarse en transcripciones de agentes, logs de CI ni archivos versionados (advertencia añadida al código y al comando; no se ejecutó en esta fase). Cubierto por `GmailOAuthCommandsTest` (config sintética, `Http::fake`, sin red).
- **Gate del propietario (EXTERNO, no verificado por el agente):** antes de generar un refresh token de reemplazo, confirmar en Google Cloud: Gmail API habilitada; cliente OAuth correcto; estado de consentimiento/publicación; redirect URI autorizado igual al de la aplicación; y que la cuenta de Google autorizada es el remitente MOVA previsto. Estado de publicación: `UNKNOWN`. Registro del redirect URI en Google: `UNKNOWN`. `INVALID_GRANT` sigue siendo evidencia externa **sin resolver**; causa confirmada: desconocida. `POSSIBLE_CAUSE_REQUIRES_VERIFICATION`: una pantalla de consentimiento en modo *Testing* puede emitir refresh tokens con vida limitada; es una hipótesis a verificar, no la conclusión. Vigencia de credenciales y `REAL_DELIVERY`: `UNKNOWN`. `C-P0-EMAIL` sigue `BLOCKED_EXTERNAL`.
- **Riesgo (resuelto en C2.1c; descripción original de C2.1b):** `RegisteredUserController` despacha `event(new Registered)` después de confirmar la transacción y antes de `Auth::login`; con el fallo ahora visible, una caída del proveedor en ese instante dejaría la cuenta creada, sin sesión y con un 500. La recuperación existente es iniciar sesión y usar «reenviar verificación». Decisión pendiente del arquitecto (no se rediseñó el registro aquí).
- **Configuración compartida:** el IaC actual asigna los mismos `runtimeSecrets`/`sharedEnv` a los tres roles; el scheduler no entrega correo de usuario, pero su hourly `health-check` lee la misma configuración de correo, por eso importa que la reciba. Endurecer el alcance de secretos por rol sería un hallazgo aparte, no parte de C-P0-EMAIL.
- **Notas:** comprobar proveedor efectivo, validez de credenciales y límites sin mostrar secretos. Configuración presente, mailer activo y entrega real son tres gates distintos. Roles que envían: web (verificación y recuperación, notificaciones del framework, síncronas) y worker (todas las notificaciones de MOVA son `ShouldQueue`); el scheduler solo encola, pero ejecuta `mova:health-check --alert` cada hora con su propia configuración. La re-autorización de Gmail (`GmailAuthUrl`/`GmailExchangeCode`) es un paso humano previo a la activación.

### C-P0-CREDITS
- **Severidad / dominio:** P0 · Dinero.
- **Evidencia `CODE` / `LIVE_STAGING`:** un profesor necesita créditos para aceptar una solicitud (`LessonSchedulingService`: `credits_available < creditsNeeded` → error). En staging, (1) bono de bienvenida depende de OTP y `WHATSAPP_ENABLED=false`; (2) recarga manual requiere destino de pago y aprobación admin con MFA; (3) checkout Mercado Pago tiene `PAYMENTS_ENABLED=false` y `PAYMENT_PROVIDER=fake`, aunque existen nombres de configuración/credenciales en web. El estado de estas fuentes en PUBLIC_APEX/PRODUCTION_LIVE no está demostrado.
- **Estado:** `BLOCKED_EXTERNAL` (configuración de producción + decisión del titular sobre qué fuente habilitar al lanzar).
- **Criterio de cierre:** al menos una fuente de créditos operativa en producción y probada extremo a extremo con evidencia de ledger (`credit_transactions`) — sin tocar datos reales de terceros.
- **Commit / tests:** — (sin cambio de código en C1 para este ID); SQLite/MySQL y build pasaron.
- **Evidencia en vivo:** pendiente.
- **Notas:** ninguna fuente de créditos se activó en C1.

### C-P0-SETTLEMENT
- **Severidad / dominio:** P0 · Dinero.
- **Evidencia `CODE` / `LIVE_STAGING` / `STATIC_IAC`:** `app/Console/Kernel.php` programa `mova:settle-lessons` en `--dry-run` salvo `SettlementMode::isLive()`. Azure staging tiene scheduler Healthy, una réplica, min/max 1 y `LESSON_SETTLEMENT_MODE=dry_run` en los tres roles. El archivo IaC anterior decía `deployScheduler=false` y `live`; eso era intención estática desactualizada, no runtime. C1.1 cambia el default IaC a `dry_run` y exige selección explícita para `live`, sin desplegarlo. No hay evidencia de liquidación real en PRODUCTION_LIVE.
- **Estado:** `BLOCKED_EXTERNAL` (gate financiero y cutover controlado posteriores).
- **Criterio de cierre:** scheduler único en PRODUCTION_LIVE, modo live elegido deliberadamente tras revisar reconciliación y migraciones, una liquidación real observada en el ledger y ausencia de ejecución duplicada del entorno LEGACY.
- **Commit / tests:** — (fuera de alcance C1 por instrucción: "no activar settlement live").
- **Evidencia en vivo:** LIVE_STAGING confirmada en `dry_run`; PRODUCTION_LIVE pendiente.
- **Notas:** `php artisan migrate --force` en el arranque de Railway sigue siendo riesgo crítico antes de publicar cualquier migración.

### C-P0-DB-CREDENTIAL
- **Severidad / dominio:** P0 · Seguridad.
- **Evidencia:** `docs/MOVA_CREDENTIAL_EXPOSURE.md` F-26: contraseña de la base MySQL de producción expuesta en el historial git; validez `UNKNOWN`, se trata como comprometida hasta rotarse.
- **Estado:** `BLOCKED_EXTERNAL` — **incidente ABIERTO** (solo el dueño de la cuenta puede rotarla; esta fase no rota secretos por instrucción).
- **Evidencia `CODE` reverificada el 2026-10-05 (sin leer ni usar valores), por dimensión:**
  - *Archivos presentes en el árbol actual:* los 5 archivos de la ficha F-26/F-27 (`qa/test-wizard-flow.mjs`, `qa/end-to-end-welcome-email.mjs`, `qa/check-twilio.mjs`, `qa/global-setup.js`, `qa/auth/admin.json`) **no existen** en `HEAD`, en `origin/master` (ambos `c97a62e`), en el disco ni en los worktrees. `6029ac4` (2026-08-26) **es ancestro de ambos**: ya está publicado (la ficha lo daba por local y sin push; esa afirmación quedó obsoleta y se corrigió). **Excepción abierta:** el tip de la rama pública `origin/Elias` (último commit 2026-08-01, sin protección) **aún contiene los 5 archivos**, y 4 ramas locales no publicadas también (`chore/codex-safety-hardening`, `fix/monetization-integrity`, dos `worktree-agent-*`).
  - *Exposición en el historial público:* los commits `d7f288d`, `117dc36`, `8cbac89` y `469f848` añadieron o modificaron esos archivos y son alcanzables desde 9 ramas remotas (incluida `master`) de un repositorio público (0 forks, 0 estrellas a esa fecha). Borrar archivos de la versión actual no los retira del historial ni de clones previos; la purga de historial es una decisión separada, no tomada.
  - *Vigencia:* `UNKNOWN`. Se trata como comprometida hasta que el titular la rote y lo acredite. No se probó con la credencial filtrada.
- **Pasos pendientes para cerrar:** (1) el titular rota la credencial en el proveedor y acredita que la anterior ya no autentica; (2) la base de producción definitiva usa credenciales nunca expuestas; (3) decidir con su autor cómo limpiar o archivar `origin/Elias` y revisar las ramas locales antes de publicarlas; (4) decisión documentada sobre purgar o no el historial; (5) activar secret scanning y push protection (hoy desactivados) y repetir el barrido sobre todos los tips remotos; (6) Database Privilege Audit documentado.
- **Criterio de cierre:** los seis pasos anteriores con evidencia; los archivos ausentes de `master` **no** bastan.
- **Commit / tests:** — ; no aplica prueba de código.
- **Evidencia en vivo:** no aportada.
- **Notas:** ausencia del secreto en HEAD no demuestra revocación. Ver `docs/MOVA_CREDENTIAL_EXPOSURE.md` (reverificada).

### C-P0-ANPD-REGISTRATION
- **Severidad / dominio:** P0 · Legal (Perú, Ley 29733).
- **Evidencia:** MOVA trata datos personales, incluidos datos de menores, en bancos de datos propios; no hay en el repo constancia de inscripción de bancos de datos ante la autoridad (ANPD).
- **Estado:** `REQUIRES_OWNER_INPUT` (trámite del titular con asesoría legal).
- **Criterio de cierre:** constancia de inscripción (o dictamen legal de que no aplica) archivada por el titular; la Política de Privacidad referencia lo que corresponda.
- **Commit / tests:** — ; no aplica prueba de código.
- **Evidencia en vivo:** no aportada.
- **Notas:** requiere decisión y gestión del titular con asesoría legal.

### C-P0-TRANSBORDER
- **Severidad / dominio:** P0 · Legal (flujo transfronterizo).
- **Evidencia `STATIC_IAC` / `LIVE_STAGING` / `CODE`:** la plantilla selecciona Azure `mexicocentral`; las tres Container Apps de staging se consultaron en `mova-prod-rg`. Hay nombres de configuración de Gmail, JaaS, Cloudinary, Sentry y Mercado Pago en distintos roles; presencia no demuestra transmisión real ni ubicación final de datos. Google OAuth, Meta y Pusher no tenían nombres de credenciales en ese inventario. En C1 la Política pasa a describir proveedores de modo condicional.
- **Estado:** `REQUIRES_OWNER_INPUT` (comunicación/gestión formal del flujo transfronterizo según la norma peruana; validación legal del texto).
- **Criterio de cierre:** gestión formal del titular completada y el texto de Privacidad validado por asesoría legal.
- **Commit / tests:** `2a1b62f` (divulgación de proveedores); SQLite/MySQL y build pasaron, gestión legal externa pendiente.
- **Evidencia en vivo:** no aportada.
- **Notas:** el cambio de texto no sustituye la gestión formal.

### C-P1-MERCADOPAGO
- **Severidad / dominio:** P1 · Dinero (proveedor de pago automático previsto).
- **Evidencia `CODE` / `LOCAL_TEST` / `LIVE_STAGING`:** integración Checkout API cubierta por pruebas MySQL locales; la afirmación P0-G de `MOVA_V1_STATE.md` es histórica. Azure web tiene nombres `MERCADOPAGO_*` de config y secretos, pero los tres roles mantienen pagos/recargas/webhooks apagados. Presencia no acredita credenciales TEST válidas, homologación ni cobros.
- **Estado:** `BLOCKED_EXTERNAL` (credenciales TEST/PROD del titular; esta fase no activa pagos por instrucción).
- **Criterio de cierre:** pago TEST extremo a extremo en staging (aprobado, rechazado, 3DS, webhook, reconciliación) con evidencia de `payment_orders` + `credit_transactions`; luego activación controlada en producción con un pago real mínimo y su reverso documentado. Usar la skill `mova-mercadopago`.
- **Commit / tests:** — en C1; pruebas con proveedor real pendientes.
- **Evidencia en vivo:** no aportada.
- **Notas:** pagos no activados en C1.

### C-P1-JITSI-MEDIA
- **Severidad / dominio:** P1 · Integraciones (videollamadas JaaS/8x8).
- **Evidencia `CODE` / `LOCAL_TEST` / `LIVE_STAGING`:** JWT RS256 por sala y autorización de moderador solo profesor tienen tests; `JAAS_APP_ID`, `JAAS_KEY_ID` y `JAAS_PRIVATE_KEY` figuran en Azure web/scheduler (clave por `secretRef`). No se verificó su validez ni una llamada real de audio/video entre dos dispositivos.
- **Estado:** `IMPLEMENTED_NOT_VERIFIED` (config presente, smoke físico pendiente).
- **Criterio de cierre:** config válida en PRODUCTION_LIVE, health-check limpio, JWT de sala aceptado por el proveedor y una clase de prueba con dos cuentas QA propias (audio, video, permisos de moderador) documentada.
- **Commit / tests:** — en C1; pruebas de medios físicos pendientes.
- **Evidencia en vivo:** no aportada.
- **Notas:** presencia de configuración, autorización JWT y medios físicos son tres evidencias distintas.

### C-P1-BACKUP-RESTORE
- **Severidad / dominio:** P1 · Operación.
- **Evidencia `CODE` / `LIVE_STAGING`:** el playbook ahora prioriza Azure PITR; dump→restore local con paridad de esquema fue evidencia histórica. Consulta Azure de solo lectura del 2026-09-29: origen `mova-mysql-splisbj6ldoqw` Ready, MySQL 8.4, retención 7 días, geo-backup Disabled; `mova-mysql-restoretest` **no apareció** en la lista actual. El reporte histórico de una restauración anterior no prueba que la copia o su verificación sigan vigentes.
- **Estado:** `BLOCKED_EXTERNAL`.
- **Criterio de cierre:** nueva restauración a servidor aislado verificada por el titular (incluida tabla de consentimientos y ledger financiero); limpieza de copia temporal confirmada por inventario; fecha y resultado anotados aquí.
- **Commit / tests:** — en C1; no aplica prueba local adicional.
- **Evidencia en vivo:** no aportada.
- **Notas:** conservar evidencia sin datos personales. STOP de rollback: `down()` de la migración de `student_data_consents` destruye filas reales; no revertirla automáticamente tras usarla. La migración se mantuvo intacta: un guard dependiente del entorno en `down()` complicaría la recuperación local y no protegería contra restauraciones destructivas; el runbook obliga a detenerse y preservar evidencia.

### C-P1-DOMAIN-CUTOVER
- **Severidad / dominio:** P1 · Operación.
- **Evidencia `LIVE_STAGING` / `OWNER_CONFIRMATION`:** Azure staging usa `APP_URL=https://staging.movaeduca.me` y `SEARCH_INDEXING_ENABLED=false`. No se verificó en esta fase qué servicio sirve el PUBLIC_APEX ni el estado actual de LEGACY; no se tocó DNS.
- **Estado:** `BLOCKED_EXTERNAL` (gate controlado posterior).
- **Criterio de cierre:** verificar primero ruta real de PUBLIC_APEX/LEGACY; dominio definitivo apuntando a Azure con TLS válido, `APP_URL`/callbacks (Google, Mercado Pago, JaaS) actualizados, indexación activada a propósito y ningún scheduler duplicado.
- **Commit / tests:** — en C1; prueba de DNS pendiente.
- **Evidencia en vivo:** no aportada.
- **Notas:** no se modificaron DNS, Azure ni Railway.

### C-P1-REALTIME
- **Severidad / dominio:** P1 · Integraciones (notificaciones en vivo).
- **Evidencia `CODE` / `LIVE_STAGING`:** `HandleInertiaRequests::publicRealtimeConfig()` degrada a `enabled=false` sin clave pública. Los tres roles Azure tienen `BROADCAST_DRIVER=null` y no tienen nombres `PUSHER_*` en su entorno. No hay prueba de entrega en vivo.
- **Estado:** `BLOCKED_EXTERNAL`.
- **Criterio de cierre:** decisión del titular (activar con credenciales y prueba en vivo, o lanzar sin tiempo real con la degradación ya soportada y documentada).
- **Commit / tests:** — en C1; pruebas en vivo pendientes.
- **Evidencia en vivo:** no aportada.
- **Notas:** la degradación de UI no prueba entrega en vivo.

### C-P1-CLOUDINARY
- **Severidad / dominio:** P1 · Integraciones (fotos de perfil).
- **Evidencia `CODE` / `LIVE_STAGING`:** `CLOUDINARY_URL` figura en Azure web por `secretRef`; no se consultó su valor ni se hizo subida/borrado real. Sin configuración válida, el fallback local usa disco efímero en Container Apps.
- **Estado:** `IMPLEMENTED_NOT_VERIFIED` (config presente, validez y smoke pendientes).
- **Criterio de cierre:** Cloudinary válido en PRODUCTION_LIVE y una subida + borrado (`deleteAvatar`) verificados con una cuenta QA propia, incluida persistencia tras reinicio/revisión.
- **Commit / tests:** — en C1; prueba de proveedor pendiente.
- **Evidencia en vivo:** no aportada.
- **Notas:** no se modificó almacenamiento en producción.

### C-P1-SENTRY
- **Severidad / dominio:** P1 · Operación (monitoreo de errores).
- **Evidencia `CODE` / `LIVE_STAGING`:** `sentry/sentry-laravel` instalado; `send_default_pii=false` por defecto (`config/sentry.php`). `SENTRY_LARAVEL_DSN` figura por `secretRef` en Azure web/worker, sin consultar su valor. No hay evidencia de evento recibido.
- **Estado:** `IMPLEMENTED_NOT_VERIFIED` (config presente, evento de prueba pendiente).
- **Criterio de cierre:** DSN configurado, evento de prueba recibido sin PII, y Sentry listado como encargado en Privacidad (ya declarado condicionalmente en C1).
- **Commit / tests:** `2a1b62f` (mención condicional); prueba de evento pendiente.
- **Evidencia en vivo:** no aportada.
- **Notas:** no se consultó el valor de DSN.

### C-P2-QA-PAYMENTS
- **Severidad / dominio:** P2 · QA / datos.
- **Evidencia:** la evidencia financiera de QA (órdenes, recargas y ledger de pruebas) vive en las bases QA/staging y no debe borrarse (instrucción); tampoco debe viajar a producción.
- **Estado:** `OPEN`.
- **Criterio de cierre:** confirmación documentada de que la base de producción definitiva no contiene `payment_orders`/`recharge_requests`/`credit_transactions` de QA, y ubicación archivada de la evidencia QA.
- **Commit / tests:** — en C1; revisión de datos reales pendiente.
- **Evidencia en vivo:** no aportada.
- **Notas:** no borrar evidencia financiera QA ni trasladarla a producción.

### C-P1-RECOMMENDATIONS
- **Severidad / dominio:** P1 · Producto.
- **Evidencia `CODE`:** `DiagnosticRecommendationService` dependía de `ClassOffer` activas (ya no se crean) y sumaba un bono por palabras clave de la IA. Ahora los candidatos salen del perfil docente (verificado, enseña la materia, cuenta no suspendida, biografía y tarifa **efectiva** de esa materia: `teacher_subject.specific_rate` o, si no hay, `hourly_rate`), puntúa con reglas deterministas (nivel, historial, experiencia, perfil, disponibilidad) y la IA no interviene en el ranking. La disponibilidad cuenta solo franjas futuras en hora de Lima (hoy/mañana; resto de la semana lun–dom; si ya pasaron, neutral). El cálculo se serializa con `lockForUpdate` sobre el diagnóstico y reemplaza las filas en una transacción (idempotente). Migración `2026_10_05_000002` (forward-fix: `class_offer_id` nullable + índice no único); las filas históricas conservan su oferta.
- **Estado:** `IMPLEMENTED_NOT_VERIFIED`.
- **Criterio de cierre:** suite final SQLite/MySQL/E2E verde sobre el snapshot final y comprobación en staging tras revisar y aplicar la migración (paso manual, no realizado).
- **Commit / tests:** sin commit (instrucción vigente: no commitear). `LOCAL_TEST` 2026-10-04 (PHP 8.3, `php_qa`): `DiagnosticRecommendationMatchingTest` (15 casos: tarifa base 0 con específica válida, específica 0, sin tarifa, franjas pasadas/en curso/domingo, idempotencia, tope de 5, desempate, IA sin efecto), `DiagnosticRecommendationsVisibilityTest` y `TeacherReferralRequestTest`: 48 tests dirigidos en verde; después las suites completas SQLite (1338) y MySQL 8.4 (1338, `mysql_qa` tras `mova:qa-mysql-fresh-migrate`) y Playwright (68/68) sobre el árbol actual, sin fallos (conteos en «Seguimiento operativo»). No se probó concurrencia real multi-proceso del cálculo; la garantía es el lock transaccional.
- **Evidencia en vivo:** no aportada (no se desplegó ni migró nada).
- **Notas:** `DiagnosticsController::requestClass` (ruta heredada con `{classOffer}`) sigue como redirección sin efecto. `DIAGNOSTIC_AI_AUTO_DISABLE_ON_ERROR` sigue declarada y sin implementar: decisión pendiente de quitarla o implementarla; hoy no afecta al ranking.

### C-P1-TEACHER-AVAILABILITY
- **Severidad / dominio:** P1 · Producto.
- **Evidencia `CODE`:** tabla `teacher_availability_slots` (migración `2026_10_05_000001`), `TeacherAvailabilityService` (única vía de escritura; valida fin > inicio en la misma jornada, mínimo 30 min, sin solapes por día, tope de 28 franjas; guardado atómico con lock del perfil), `Teacher\AvailabilityController` (`PUT /teacher/availability`, solo rol teacher y no suspendido; el perfil sale de la sesión), componente `TeacherAvailabilityEditor.vue` en `/teacher/profile`. Horas de pared de Lima, sin horario de verano. Es **informativa**: orienta recomendaciones y no autoriza ni bloquea agendas (`LessonSchedulingService` sigue siendo la fuente de verdad de choques).
- **Estado:** `IMPLEMENTED_NOT_VERIFIED`.
- **Criterio de cierre:** suite final verde sobre el snapshot final y comprobación manual de la pantalla en staging tras aplicar la migración.
- **Commit / tests:** sin commit. `TeacherAvailabilityTest` (16 casos: invitado, padre, suspendido, ownership con id inyectado, formatos, rangos, solapes, tope, idempotencia, vaciado explícito, fallo sin pérdida de datos, props de la pantalla, cascada al borrar perfil); `npm run build` y los tres `check:*` en verde. Más `qa/tests/teacher-availability.spec.js` (3 tests Playwright: agregar y persistir tras recarga, rechazo de solape y de fin ≤ inicio con mensaje visible, limpieza, y rechazo a un padre) en escritorio y móvil, y revisión visual de capturas en el QA local. Sigue sin verificarse en staging.
- **Evidencia en vivo:** no aportada.
- **Notas:** la disponibilidad no se muestra públicamente. Decisión de producto abierta: si debe validar o advertir al agendar.

### C-P1-ATTENDANCE-DISPUTES
- **Severidad / dominio:** P1 · Producto / operación.
- **Evidencia `CODE` (2026-10-04):** no existe código de asistencia, ausencia ni disputa. `JaasService` solo emite JWT de sala; emitir un enlace **no** prueba presencia. Controles actuales: confirmación/reporte del padre y del profesor, Libro de Reclamaciones, y acciones de admin con MFA (`cancel`, `force-complete`, `force-refund`), más la gracia previa a la liquidación.
- **Estado:** `REQUIRES_OWNER_INPUT`. **Fuera del alcance de código de esta fase.**
- **Motivo:** cualquier consecuencia (reembolso, consumo de crédito, penalización, plazos) es política de negocio que no está definida; evidencia fiable de presencia exige configurar webhooks de JaaS (externo, no verificado). Implementar el registro sin esas reglas sería inventar política.
- **Decisiones necesarias:** (1) plazo para reportar un problema; (2) qué ocurre con el crédito si el profesor no asiste y si el alumno no asiste; (3) si un reporte retiene la liquidación automática; (4) fuente de presencia aceptada (declaración de las partes, webhooks de JaaS o ambas).
- **Evidencia en vivo:** no aportada.

### C-P1-RECURRING-MENTORSHIP
- **Severidad / dominio:** P1 · Producto.
- **Evidencia `CODE` (2026-10-04):** "acompañamiento continuo" es un indicador (`class_requests.is_mentorship`) y un contador de cupos del profesor (`mentorship_slots_total/taken`, liberado al cancelar). No existen series de clases, recurrencia ni reserva de créditos para varias clases.
- **Estado:** `REQUIRES_OWNER_INPUT`. **Fuera del alcance de código de esta fase.**
- **Motivo:** una recurrencia reservaría créditos de varias clases futuras y cambiaría la reserva, el settlement y la cancelación; faltan reglas (frecuencia, duración, qué pasa al cancelar una serie, cobro). No se presenta como implementada: la interfaz no debe prometer agendado recurrente automático.
- **Evidencia en vivo:** no aportada.

### C-P1-TEACHER-VERIFICATION
- **Severidad / dominio:** P1 · Producto / legal.
- **Evidencia `CODE` (2026-10-04):** "verificado" significa que un admin aprobó el perfil (`is_verified`, con `reviewed_by/at`, MFA reciente); no se recopila ni almacena ningún documento (DNI, títulos, antecedentes).
- **Estado:** `REQUIRES_OWNER_INPUT`. **Verificación documental fuera del alcance de código.**
- **Motivo:** recopilar documentos sensibles exige política aprobada (qué se pide, base legal, retención, quién accede, cifrado). Hasta que el titular defina el alcance de "profesor verificado", los textos públicos no deben sugerir verificación documental.
- **Decisiones necesarias:** criterios exactos de verificación; si habrá documentos, cuáles y cómo se custodian; texto aprobado de la insignia.
- **Evidencia en vivo:** no aportada.
