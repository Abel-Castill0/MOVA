# MOVA V1 — Completion Ledger

Fuente de verdad persistente del programa de completitud de MOVA V1 para
producción real. **El ledger decide el estado, no la prosa.** Nada pasa a
`CLOSED` sin evidencia ejecutada contra el snapshot correcto (y, cuando el
criterio lo exige, evidencia en vivo del entorno real).

- Baseline: `master` @ `28fb28fe6a5b5b87e13b358ec1b1108860513c36`
- Staging QA: MOVA 1.0 STAGING RELEASE QA PASS — imagen
  `sha256:e34f814c2ec11fe6bec55b8869f28d7f042cf90b51bba1a861654ba0fae5b28a`
- Fase C1 (esta): rama `release/mova-v1-production-completion`

## Evidencia de gates en esta rama

Snapshot de trabajo previo al commit final de ledger/revisión: `2a1b62f` más
los cambios locales descritos al final. En 2026-09-29 pasaron con código 0:
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
| C-P0-LEGAL-TRUTH | P0 | Legal | VERIFIED |
| C-P0-MINOR-CONSENT | P0 | Menores / legal | VERIFIED |
| C-P0-ANPD-REGISTRATION | P0 | Legal | REQUIRES_OWNER_INPUT |
| C-P0-TRANSBORDER | P0 | Legal | REQUIRES_OWNER_INPUT |
| C-P1-TEACHER-PROFILE-SCORE | P1 | Producto | VERIFIED |
| C-P1-TEACHER-ONBOARDING | P1 | Producto | VERIFIED |
| C-P1-PHONE-VERIFICATION | P1 | Integraciones | BLOCKED_EXTERNAL |
| C-P1-GOOGLE-OAUTH | P1 | Auth | BLOCKED_EXTERNAL |
| C-P1-MOVI | P1 | Producto / IA | BLOCKED_EXTERNAL |
| C-P1-MERCADOPAGO | P1 | Dinero | BLOCKED_EXTERNAL |
| C-P1-LEGAL-REACCEPTANCE | P1 | Legal | VERIFIED |
| C-P1-JITSI-MEDIA | P1 | Integraciones | BLOCKED_EXTERNAL |
| C-P1-BACKUP-RESTORE | P1 | Operación | BLOCKED_EXTERNAL |
| C-P1-DOMAIN-CUTOVER | P1 | Operación | BLOCKED_EXTERNAL |
| C-P1-REALTIME | P1 | Integraciones | BLOCKED_EXTERNAL |
| C-P1-CLOUDINARY | P1 | Integraciones | BLOCKED_EXTERNAL |
| C-P1-SENTRY | P1 | Operación | BLOCKED_EXTERNAL |
| C-P2-CI-MOVI | P2 | CI | IMPLEMENTED_NOT_VERIFIED |
| C-P2-DOCS | P2 | Docs | IMPLEMENTED_NOT_VERIFIED |
| C-P2-COOKIE-TRUTH | P2 | Legal / UI | IMPLEMENTED_NOT_VERIFIED |
| C-P2-QA-PAYMENTS | P2 | QA | OPEN |

_(El detalle de cada ID, abajo, es la única fuente del estado final.)_

## Decisión C1

**NO-GO para producción/GA.** Los gates de código locales pasaron, pero
ningún ID se marca `CLOSED` solo por ello. Persisten los bloqueos P0 de
entrega real de correo, fuente de créditos operativa, liquidación viva,
rotación verificable de la credencial expuesta, validación jurídica del texto,
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
- **Evidencia:** Términos y Privacidad previos contenían afirmaciones contradichas por el runtime; `Legal/Terms.vue`, `Legal/Privacy.vue`, `LegalController` y `HealthCheck` ahora describen minimización, consentimiento, proveedores y estado apagado de IA.
- **Estado:** `VERIFIED` (código verificado localmente; validación jurídica y publicación pendientes).
- **Criterio de cierre:** tests de verdad y build verdes en HEAD final; titular/asesor legal valida texto, datos del proveedor y versión efectiva del entorno; smoke de documentos publicados.
- **Commit / tests:** `2a1b62f`, `a68d58e`; `LegalDocumentsTruthTest`, SQLite 1264/0, MySQL 1264/0, build y checks pasaron.
- **Evidencia en vivo:** no aportada.
- **Notas:** `LEGAL_TERMS_VERSION` y `LEGAL_PRIVACY_VERSION`, si están definidos en el entorno, pueden prevalecer sobre los defaults `2026-09-29`; verificar nombres/versiones efectivas antes del despliegue sin mostrar secretos.

### C-P0-MINOR-CONSENT
- **Severidad / dominio:** P0 · Menores / legal.
- **Evidencia:** `StudentController::store` exige casilla explícita y crea alumno + `StudentDataConsent` en una transacción; migración `2026_09_29_000001` conserva versión, padre y alumno.
- **Estado:** `VERIFIED` (código local; validación jurídica e históricos pendientes).
- **Criterio de cierre:** gates SQLite/MySQL/E2E verdes, migración y rollback revisados, texto validado por titular/asesor, flujo real de registro de menor probado con cuenta QA propia. Decidir tratamiento de alumnos históricos sin consentimiento específico.
- **Commit / tests:** `a951632`, `d61d81c`; `StudentDataConsentTest` incluye reaceptación de la versión vigente antes de registrar al menor; test dirigido 1/12 assertions y suites SQLite/MySQL completas pasaron.
- **Evidencia en vivo:** no aportada.
- **Notas:** sin backfill deliberadamente; código nuevo no acredita consentimiento de alumnos históricos. El `down()` elimina la tabla y su evidencia, así que cualquier rollback posterior al uso real requiere preservar una copia de auditoría antes de ejecutarse.

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
- **Commit / tests:** `8ea68fa`, `a68d58e`; `LegalReacceptanceTest` 13 tests / 48 assertions dirigido en SQLite; suites SQLite/MySQL completas y Playwright pasaron.
- **Evidencia en vivo:** no aportada.
- **Notas:** el middleware conserva GET JSON y operaciones de checkout ya iniciado (`show`, `status`, `pay`, `refresh`) para no cortar un pago; bloquea otros POST y JSON de mutación. Confirmar con asesoría si estas excepciones son aceptables. Los seeders solo deben crear aceptación para usuarios recién creados, nunca para usuarios existentes.

### C-P2-CI-MOVI
- **Severidad / dominio:** P2 · CI.
- **Evidencia:** `.github/workflows/ci.yml` ejecuta `check:movi-availability` en la puerta frontend.
- **Estado:** `IMPLEMENTED_NOT_VERIFIED`.
- **Criterio de cierre:** check local verde y corrida CI del commit remoto verde.
- **Commit / tests:** `1f5588d`; `check:movi-availability` pasó localmente; corrida CI remota pendiente.
- **Evidencia en vivo:** corrida CI remota pendiente.
- **Notas:** no se creó PR ni se fusionó a master.

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
- **Evidencia:** `docs/release/MOVA_V1_STATE.md` atribuye Gmail API a producción histórica; `infra/azure/apps.bicepparam` fija `MAIL_MAILER=array` para Azure. No se consultaron valores secretos ni se verificó entrega real. De este canal dependen verificación de email, recuperación de contraseña, copia del Libro de Reclamaciones y avisos de clase.
- **Estado:** `BLOCKED_EXTERNAL`.
- **Criterio de cierre:** en el entorno de producción definitivo, un registro real recibe el correo de verificación y un reclamo de prueba recibe su constancia; evidencia = ids de mensaje / capturas del buzón, sin exponer contenido personal.
- **Commit / tests:** — (sin cambio de código en C1).
- **Evidencia en vivo:** pendiente.
- **Notas:** comprobar proveedor efectivo sin mostrar credenciales y sus límites de envío antes de GA.

### C-P0-CREDITS
- **Severidad / dominio:** P0 · Dinero.
- **Evidencia:** un profesor necesita créditos para aceptar cualquier solicitud (`LessonSchedulingService`: `credits_available < creditsNeeded` → error). Hoy en producción sus tres fuentes están cerradas o condicionadas: (1) bono de bienvenida → solo al verificar el teléfono (`PhoneVerificationController::grantTeacherWelcomeBonus`), imposible con `WHATSAPP_ENABLED=false`; (2) recarga manual Yape/Plin → requiere `RECHARGE_PAYMENT_DESTINATION` (`Teacher\CreditController`) y aprobación admin con MFA; (3) checkout Mercado Pago → `PAYMENTS_ENABLED`/`PAYMENT_PROVIDER` apagados.
- **Estado:** `BLOCKED_EXTERNAL` (configuración de producción + decisión del titular sobre qué fuente habilitar al lanzar).
- **Criterio de cierre:** al menos una fuente de créditos operativa en producción y probada extremo a extremo con evidencia de ledger (`credit_transactions`) — sin tocar datos reales de terceros.
- **Commit / tests:** — ; SQLite/MySQL y build pasaron, entrega real de correo pendiente.
- **Evidencia en vivo:** pendiente.
- **Notas:** ninguna fuente de créditos se activó en C1.

### C-P0-SETTLEMENT
- **Severidad / dominio:** P0 · Dinero.
- **Evidencia:** `app/Console/Kernel.php` programa `mova:settle-lessons` en `--dry-run` salvo `SettlementMode::isLive()`; el scheduler en Azure no está desplegado (`infra/azure/apps.bicepparam`: `deployScheduler = false`, deliberado mientras Railway sea producción). Sin liquidación viva, los créditos reservados no se consumen automáticamente.
- **Estado:** `BLOCKED_EXTERNAL` (gate controlado posterior: activar scheduler + modo live en el cutover).
- **Criterio de cierre:** scheduler único desplegado en producción, `SettlementMode` live, una liquidación real observada en el ledger y sin doble ejecución (Railway apagado).
- **Commit / tests:** — (fuera de alcance C1 por instrucción: "no activar settlement live").
- **Evidencia en vivo:** pendiente; el parámetro Azure `live` no demuestra que haya scheduler desplegado ni liquidación real.
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
- **Evidencia:** la infraestructura destino es Azure región `mexicocentral` (`infra/azure/apps.bicep`), fuera de Perú; además envían/almacenan datos fuera de Perú: Google (Gmail API, OAuth), Meta (WhatsApp, cuando se active), 8x8 (JaaS), Cloudinary, Sentry (si se configura DSN), Mercado Pago (pagos de créditos). En C1 la Política de Privacidad pasa a declararlo explícitamente (ver C-P0-LEGAL-TRUTH).
- **Estado:** `REQUIRES_OWNER_INPUT` (comunicación/gestión formal del flujo transfronterizo según la norma peruana; validación legal del texto).
- **Criterio de cierre:** gestión formal del titular completada y el texto de Privacidad validado por asesoría legal.
- **Commit / tests:** `2a1b62f` (divulgación de proveedores); SQLite/MySQL y build pasaron, gestión legal externa pendiente.
- **Evidencia en vivo:** no aportada.
- **Notas:** el cambio de texto no sustituye la gestión formal.

### C-P1-MERCADOPAGO
- **Severidad / dominio:** P1 · Dinero (proveedor de pago automático previsto).
- **Evidencia:** integración Checkout API (tarjeta/Yape, 3DS, webhooks, reconciliación, reversión) verde en MySQL según `docs/release/MOVA_V1_STATE.md` (P0-G); flags apagados en staging; falta prueba con credenciales TEST reales en el entorno y homologación.
- **Estado:** `BLOCKED_EXTERNAL` (credenciales TEST/PROD del titular; esta fase no activa pagos por instrucción).
- **Criterio de cierre:** pago TEST extremo a extremo en staging (aprobado, rechazado, 3DS, webhook, reconciliación) con evidencia de `payment_orders` + `credit_transactions`; luego activación controlada en producción con un pago real mínimo y su reverso documentado. Usar la skill `mova-mercadopago`.
- **Commit / tests:** — en C1; pruebas con proveedor real pendientes.
- **Evidencia en vivo:** no aportada.
- **Notas:** pagos no activados en C1.

### C-P1-JITSI-MEDIA
- **Severidad / dominio:** P1 · Integraciones (videollamadas JaaS/8x8).
- **Evidencia:** JWT RS256 por sala y moderador solo profesor, con tests (P0-F); en Azure faltan `JAAS_*` (health-check `JAAS_NOT_CONFIGURED` crítico en producción). Nunca se probó audio/video real entre dos dispositivos en el entorno destino.
- **Estado:** `BLOCKED_EXTERNAL`.
- **Criterio de cierre:** `JAAS_*` configurados en producción, health-check limpio y una clase de prueba con dos cuentas QA propias (audio, video, permisos de moderador) documentada.
- **Commit / tests:** — en C1; pruebas de medios físicos pendientes.
- **Evidencia en vivo:** no aportada.
- **Notas:** JWT probado no prueba cámara ni micrófono.

### C-P1-BACKUP-RESTORE
- **Severidad / dominio:** P1 · Operación.
- **Evidencia:** runbook Azure PITR en `docs/BACKUP_RESTORE_PLAYBOOK.md` §9; dump→restore local con paridad de esquema. La verificación de datos del servidor `mova-mysql-restoretest` quedó bloqueada (secretos dentro de la VNet) y ese servidor contiene copia de datos personales mientras exista.
- **Estado:** `BLOCKED_EXTERNAL`.
- **Criterio de cierre:** restauración verificada por el titular (conteos/tablas clave) y servidor de prueba eliminado; fecha y resultado anotados aquí.
- **Commit / tests:** — en C1; no aplica prueba local adicional.
- **Evidencia en vivo:** no aportada.
- **Notas:** conservar evidencia de la restauración sin datos personales.

### C-P1-DOMAIN-CUTOVER
- **Severidad / dominio:** P1 · Operación.
- **Evidencia:** Railway sigue siendo producción/rollback; `SEARCH_INDEXING_ENABLED=false` hasta el cutover; DNS en Namecheap sin tocar (instrucción).
- **Estado:** `BLOCKED_EXTERNAL` (gate controlado posterior).
- **Criterio de cierre:** dominio definitivo apuntando a Azure con TLS válido, `APP_URL`/callbacks (Google, Mercado Pago, JaaS) actualizados, indexación activada a propósito, Railway apagado sin doble scheduler.
- **Commit / tests:** — en C1; prueba de DNS pendiente.
- **Evidencia en vivo:** no aportada.
- **Notas:** no se modificaron DNS, Azure ni Railway.

### C-P1-REALTIME
- **Severidad / dominio:** P1 · Integraciones (notificaciones en vivo).
- **Evidencia:** `BROADCAST_DRIVER=pusher`; `HandleInertiaRequests::publicRealtimeConfig()` degrada a `enabled=false` sin clave pública, así que la app funciona sin tiempo real. No hay evidencia de credenciales Pusher en Azure ni de una prueba en vivo.
- **Estado:** `BLOCKED_EXTERNAL`.
- **Criterio de cierre:** decisión del titular (activar con credenciales y prueba en vivo, o lanzar sin tiempo real con la degradación ya soportada y documentada).
- **Commit / tests:** — en C1; pruebas en vivo pendientes.
- **Evidencia en vivo:** no aportada.
- **Notas:** la degradación de UI no prueba entrega en vivo.

### C-P1-CLOUDINARY
- **Severidad / dominio:** P1 · Integraciones (fotos de perfil).
- **Evidencia:** sin `CLOUDINARY_URL` la subida de avatar usa disco local (`.env.example`); en Azure Container Apps el disco de la réplica es efímero, así que las fotos se perderían en cada revisión/reinicio.
- **Estado:** `BLOCKED_EXTERNAL`.
- **Criterio de cierre:** `CLOUDINARY_URL` configurado en producción y una subida + borrado (`deleteAvatar`) verificados en vivo con una cuenta QA propia.
- **Commit / tests:** — en C1; prueba de proveedor pendiente.
- **Evidencia en vivo:** no aportada.
- **Notas:** no se modificó almacenamiento en producción.

### C-P1-SENTRY
- **Severidad / dominio:** P1 · Operación (monitoreo de errores).
- **Evidencia:** `sentry/sentry-laravel` instalado; `send_default_pii=false` por defecto (`config/sentry.php`). No hay evidencia de `SENTRY_LARAVEL_DSN` en Azure ni de un evento de prueba recibido.
- **Estado:** `BLOCKED_EXTERNAL`.
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
