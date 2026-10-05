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
- **Estado:** `BLOCKED_EXTERNAL` (solo el dueño de la cuenta puede rotarla; esta fase no rota secretos por instrucción).
- **Criterio de cierre:** rotación hecha por el titular en el proveedor real + evidencia de que la credencial vieja ya no autentica (verificada por el titular, no por un agente) + la base de producción definitiva (Azure) usa credenciales nunca expuestas.
- **Commit / tests:** — ; no aplica prueba de código.
- **Evidencia en vivo:** no aportada.
- **Notas:** ausencia del secreto en HEAD no demuestra revocación.

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
