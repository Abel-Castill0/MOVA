# MOVA — Auditoría de Tests / Documentación / QA-Config

## (0) Snapshot

**Snapshot auditado: working tree actual, HEAD=692b365 — NO un checkout aislado.**

```
Commit HEAD:   692b3651d09cb2731865efcb0d83dc83d2a36102
Branch:        master
origin/master: 692b3651d09cb2731865efcb0d83dc83d2a36102 (0 commits de divergencia)
Working tree:  ~150 rutas modificadas/untracked, TODAS incluidas en esta revisión
Checkout:      C:\Users\ABEL\OneDrive\Desktop\ProyectoMOVA (directorio de trabajo real)
PHP:           8.1.25 (cli, ZTS)
Node:          v22.23.2
Composer:      2.8.10 (composer validate: OK)
```

No se modificó ningún archivo, no se hizo ningún commit. Todo lo que sigue es inspección + ejecución real de comandos (tests, `composer validate`, `npm run test:security`, `grep`/`git` de verificación).

---

## (1) Tests — ejecutados de verdad, con resultado

Comando ejecutado dos veces (una vez con los 13 filtros juntos, una vez con 8 de ellos) para confirmar reproducibilidad; ambos runs, cero fallos:

```
php artisan test --filter="AiPayloadContractTest|CrossTenantAccessTest|FinancialConcurrencyTest|
FinancialHistoryDurabilityTest|FrontendAuditorTest|JitsiAccessWindowTest|ProviderGuardTest|
SchedulerConfigurationTest|StudentDeletionIntegrityTest|TextRedactionTest|MonetizationIntegrityTest|
NotificationSecurityTest|RescheduleTest"

Tests: 206 passed (648 assertions)   Duration: 53.42s
```

También se ejecutó la suite **completa** del proyecto para contexto: `php artisan test` → **454 passed (1328 assertions)**, `Duration: 130.94s` — coincide exactamente con la cifra que `docs/MOVA_PRODUCTION_READINESS.md` declara como "única fuente de verdad" (ver §2).

### Veredicto por archivo

| Archivo | Qué invariante prueba | ¿Aserción fuerte o débil? | Solapamiento |
|---|---|---|---|
| **AiPayloadContractTest** (14 tests) | Contrato campo-por-campo de lo que sale hacia el proveedor de IA: captura el body HTTP real (`Http::fake`), decodifica y busca substrings de PII (nombre, teléfono, email, IDs internos) en body/URL/headers/logs/Sentry. También verifica columnas reales de `ai_usage_logs` vía `Schema::getColumnListing`. | **Fuerte**. No es un test de "no lanza excepción" — compara contenido real serializado, incluyendo el caso adversarial de escapes Unicode (`\u00ed` vs `í`) que un assert ingenuo pasaría por alto. | Ninguno relevante. |
| **CrossTenantAccessTest** (14 tests) | Matriz IDOR: extraño no puede unirse/cancelar/reprogramar/confirmar pago/reportar sobre recursos de otra familia; profesor no aprueba su propia recarga; admin suspendido conserva acceso (comportamiento documentado, no bug). | **Fuerte** — verifica `assertForbidden()` + estado de BD sin cambios (`fresh()->status` sigue igual), no solo el código HTTP. | Solapa parcialmente con `test_lesson_join_rejects_unrelated_parent_and_teacher/guest` de MonetizationIntegrityTest (ver abajo). |
| **FinancialConcurrencyTest** (8 tests) | Idempotencia bajo repetición: doble aceptación de solicitud, doble settlement, doble refund, doble aprobación de recarga por 2 admins distintos. Declara explícitamente la limitación de SQLite single-connection (no es concurrencia real de motor). | **Fuerte**, con límite honestamente documentado en el docblock de la clase. Verifica `assertLedgerHealthy()` (reconciliación real) tras cada escenario, no solo el conteo de filas. | Ninguno. |
| **FinancialHistoryDurabilityTest** (9 tests) | Guard de `User::deleting` independiente del motor de BD: borrar profesor/padre con historial financiero lanza `RuntimeException`; sin historial, se permite; el endpoint de borrado de perfil anonimiza en vez de destruir. | **Fuerte** — captura la excepción y su mensaje, y confirma la fila sigue en BD. | Complementario (no duplicado) con StudentDeletionIntegrityTest: aquí es `User`/hard-delete guard, allá es `Student`/soft-delete. |
| **FrontendAuditorTest** (8 tests) | Tests de la HERRAMIENTA analizadora de frontend (no del frontend en sí), contra fixtures reales que reproducen 3 falsos positivos ya conocidos (confirm() en comentario, regex de comentarios de bloque vaciando el archivo, `loading` no reconocido como guard). | **Fuerte y honesto** — el docblock explica por qué existe cada fixture como regresión de un bug real de la herramienta. | Ninguno. |
| **JitsiAccessWindowTest** (12 tests) | Ventana de acceso server-authoritative (15 min antes / 2h de gracia después), decodifica el JWT real (RS256) y verifica `exp`, `room`, `aud`, `iss`, `features.recording/livestreaming/transcription`. | **Fuerte** — decodifica y verifica el payload criptográfico real, no solo el código HTTP de `join()`. | Solapa con la parte de JWT/moderator de `test_lesson_join_allows_owner_parent_and_assigned_teacher` de MonetizationIntegrityTest (ese test añade el claim `moderator` y el header `kid`, que JitsiAccessWindowTest no cubre — complementario, no puramente redundante). |
| **ProviderGuardTest** (14 tests) | Fail-closed de `ProviderGuard::resolve()`: proveedor desconocido/typo/vacío/whitespace aborta; `fake` en producción con la feature encendida aborta; `fake` en producción con la feature apagada es válido; matching case-insensitive; el *container binding* real también aborta. | **Fuerte** — prueba tanto la función pura como la integración real con el contenedor de Laravel. | Ninguno. |
| **SchedulerConfigurationTest** (19 tests) | El modo de `SettlementMode` gobierna de verdad el flag `--dry-run` del scheduler (reconstruye el `Schedule` real vía reflection); `withoutOverlapping()` presente; comando `mova:health-check` reporta rojo/verde según corresponda, incluida resiliencia cuando la tabla `jobs` no existe. | **Fuerte** — reconstruye el Schedule real desde el Kernel, no un mock; verifica salida JSON real del health-check. | Ninguno. |
| **StudentDeletionIntegrityTest** (12 tests) | Soft-delete + anonimización de `Student` al borrar; `forceDelete()` con historial protegido lanza excepción; relaciones (`lesson->student`, `parent`, `ClassRequest->student`) siguen resolviendo tras el soft-delete (regresión real F-18: antes devolvían `null`). | **Fuerte**. | Ver nota arriba (complementario con FinancialHistoryDurabilityTest). |
| **TextRedactionTest** (18 tests, dataProvider) | Redacción adversarial de PII en texto libre antes de mandarlo a IA: nombres al inicio/medio de frase, teléfonos, DNI, email, URL, nombre de colegio — y un caso documentado como **límite conocido** (nombre en minúscula sin marcador). | **Fuerte y honesto** — documenta explícitamente lo que la técnica NO puede resolver, en vez de ocultarlo. | Ninguno. |
| **MonetizationIntegrityTest** (49 tests, el archivo más grande — 1065 líneas) | Suite de integración end-to-end del núcleo financiero: recargas (catálogo server-side, operation_number normalizado/anti-reuso), aceptación de clase (créditos escalan con duración), cancelación (refund completo), confirm-payment (gate temporal sin bypass HTTP), reportes→reviews→settlement, bono de bienvenida por teléfono (anti-duplicado cross-cuenta), borrado de cuenta (anonimiza, preserva ledger), `lessons.join` (JWT con claim `moderator` + header `kid`), migración de monetización (aborta con duplicados, rollback rechaza pérdida de historial), tiers de tarifa por rating. | **Fuerte** en general — muchas aserciones verifican estado de BD y payload JWT real, no solo códigos HTTP. | **Solapamiento real, aunque menor**: `test_lesson_join_rejects_unrelated_parent_and_teacher` / `..._rejects_guest` duplican casi exactamente `CrossTenantAccessTest::test_a_stranger_cannot_join_another_familys_lesson_room` / `test_a_guest_cannot_join_any_lesson_room`. No es grave (backend financiero probado desde dos ángulos no es dañino), pero es redundancia genuina que un lector futuro notará. |
| **NotificationSecurityTest** (13 tests) | Ninguna notificación (database/mail/broadcast/whatsapp) expone `jitsi_room` o `meet.jit.si`; barrido de TODO `app/Notifications/*.php` descartando comentarios (vía tokenizer real, no regex ingenuo); comando de purga histórica (`mova:purge-jitsi-urls`) es idempotente y no toca otras claves. | **Fuerte** — usa `token_get_all()` real para descartar comentarios, y compara contra el VALOR real de la sala, no solo la cadena "meet.jit.si". | Ninguno. |
| **RescheduleTest** (17 tests) | v1 del exploit C-2 cerrado: cambiar `duration_minutes` en reschedule está bloqueado incluso si el valor "coincide por casualidad"; autorización (dueño/profesor asignado); inmutabilidad financiera (ledger/créditos sin tocar); solapamiento de horario (rechaza cruzado, permite auto-solapamiento); reevaluación bajo lock (no lee de una instancia "stale"). | **Fuerte**, con límite de concurrencia SQLite documentado igual que FinancialConcurrencyTest. | Ninguno. |

**Conclusión Paso 1:** los 13 archivos son de **alta calidad** — ninguno se limita a `assertStatus(200)`; todos verifican estado de base de datos, contenido de payload, o comportamiento criptográfico real. El único hallazgo de solapamiento (join de lección probado en CrossTenantAccessTest y de nuevo, con más profundidad JWT, en MonetizationIntegrityTest) es menor y no amerita eliminar ningún test — cada uno agrega una aserción que el otro no cubre.

---

## (2) Documentación — tabla MATCH / OUTDATED / CONTRADICTED / UNKNOWN

Metodología: 5-10 afirmaciones muestreadas por documento, verificadas contra código real (`grep`, lectura de archivos, ejecución de comandos), no releído línea por línea.

| Documento | Afirmaciones muestreadas | Veredicto |
|---|---|---|
| **MOVA_MASTER_CONTEXT.md** (diff) | Migración Twilio→Meta Cloud API; `App\WhatsApp\Contracts\WhatsAppProviderContract` + `MetaCloudApiProvider`/`FakeWhatsAppProvider`; `ProviderGuard` fail-closed; JaaS/JWT reemplaza `meet.jit.si`; ventana de acceso backend-autoritativa | **MATCH** — todas las clases citadas existen; `twilio/sdk` confirmado ausente de `composer.json`/`composer.lock` (la única mención de "twilio" en el repo es un email de mantenedor de un paquete no relacionado, dentro de `composer.lock`). |
| **SETUP.md** (diff) | Diff solo tocó las líneas de WhatsApp (Twilio→Meta opcional/fake por defecto) | **CONTRADICTED (parcial)** — el diff dejó intacta la línea "Cuenta de Zoom con app Server-to-Server OAuth activa" y "Zoom es **requerido** para crear clases" (líneas 9 y 104). El código real **no tiene ningún rastro de Zoom**: cero resultados de `grep -rli zoom app/ config/`, y existen dos migraciones (`2026_07_18_000001_drop_zoom_columns.php`, `2026_07_26_153152_drop_zoom_meeting_id_column.php`) que confirman que Zoom fue **eliminado y reemplazado por JaaS/Jitsi**. La sesión que tocó las líneas de WhatsApp no corrigió esta parte, que ya estaba desactualizada desde antes. |
| **.telemetry/product.md** (diff) | Diff cambió "WhatsApp: Twilio Sandbox"→"Meta Cloud API" y los estados del enum de `Lesson` | **CONTRADICTED (parcial)** — igual que SETUP.md: el resto del documento (One-liner, Tech Stack "Video: Zoom API — `ZoomService`", Core Features, campos de `Lesson` `zoom_meeting_id/zoom_link/zoom_password`, pasos de flujo "Zoom creado automáticamente") describe un sistema de video que **ya no existe en el código** (no hay `ZoomService`, las columnas zoom fueron dropeadas). Este documento fue tocado en esta misma sesión (según el diff) pero solo parcialmente — quedó una contradicción real y verificable entre el propio documento y el código. |
| **docs/WHATSAPP_PRODUCTION_NOTES.md** (diff) | `WhatsAppWebhookController` ya existe en la ruta `/api/webhooks/whatsapp`; `WHATSAPP_PROVIDER=fake` por defecto; plantillas Meta pendientes | **MATCH** — confirmado `app/Http/Controllers/WhatsAppWebhookController.php` y las rutas `webhooks.whatsapp.verify`/`.handle` en `routes/api.php`. |
| **docs/HANDOFF_FINAL.md** (diff, solo adiciones §22-25) | `payment_orders`/`payment_webhooks` (migraciones), `RechargeApprovalService`, `PaymentWebhookService`, `WhatsAppMessageStatus` (enum con `unknown`/`deliveryRank()`), `WhatsAppWebhookController::shouldApply()`, `php artisan mova:reconcile-whatsapp` | **MATCH** — todas las clases, migraciones y comandos citados existen y coinciden con la descripción funcional (verificado leyendo el código real de cada uno, no solo su existencia). |
| **docs/MOVA_CREDENTIAL_EXPOSURE.md** (nuevo) | 5 de los archivos de F-26/F-27 siguen en `HEAD`/`origin/master` pese a estar borrados en disco; `qa/end-to-end-welcome-email.mjs` y `qa/check-twilio.mjs` sí tienen la eliminación *staged* (`git rm`), los otros 3 no | **MATCH exacto** — reproducido con `git status --porcelain`, `git ls-files` y `git cat-file -e HEAD:<ruta>` para los 5 archivos: los 5 existen en `HEAD` (idéntico a `origin/master`, 0 divergencia); `qa/auth/admin.json`, `qa/global-setup.js`, `qa/test-wizard-flow.mjs` siguen en el índice (`git ls-files` los lista, deletion sin stage); `qa/end-to-end-welcome-email.mjs` sí tiene la eliminación staged. Coincide dato por dato. |
| **docs/MOVA_QA_SECURITY_POLICY.md** (nuevo) | `qa/lib/enforce-safe-target.mjs` (allowlist `localhost`/`127.0.0.1`/`[::1]`, override `I_UNDERSTAND_E2E_REMOTE_TARGET_IS_DANGEROUS`); `installMutationFirewall` "instalado automáticamente vía fixture, **nunca a discreción de un spec individual**"; `npm run test:security` → 25/25 | **MATCH mayormente, con una imprecisión real**: el conteo 25/25 se reprodujo ejecutándolo (ver abajo). Pero la frase "nunca a discreción de un spec individual" es **inexacta**: el propio comentario de `qa/playwright.production-readonly.config.js` reconoce que el firewall solo se instala **si el spec importa `test` desde `qa/lib/fixtures.mjs` en vez de `@playwright/test` directamente** — es decir, SÍ depende de una elección del autor del spec. Hoy es moot (0 specs usan ese config), pero la afirmación absoluta de la política es más fuerte de lo que el mecanismo real garantiza. |
| **docs/MOVA_SYSTEM_KNOWLEDGE.md** (nuevo) | Declara explícitamente "toda afirmación cita `archivo:línea` cuando es verificable". Muestreadas 6 citas: `GoogleAuthController.php:2898/2903-2907`, `Lesson.php:318-322`, `LessonSettlementService.php:924-926`, `LessonController.php:1113`, `PhoneVerificationController.php:3168-3173`, `AdminController.php:308-310` | **Contenido sustantivo: MATCH** (verificado: el gate de `GOOGLE_LOGIN_ENABLED` en backend existe, `credits_settled_at` fuera de `$fillable` existe, el catch de `UniqueConstraintViolationException` existe, el rate-limit por teléfono existe, el guard de "no suspender a otro admin" existe). **Pero las citas de línea están sistemáticamente mal**: `GoogleAuthController.php` tiene 77 líneas totales (cita línea 2898 — imposible); `Lesson.php` tiene 274 líneas (cita 318-322 — imposible); `LessonSettlementService.php` tiene 260 líneas (cita 924-926 — imposible); `LessonController.php` tiene 468 líneas (cita 1113 — imposible); `PhoneVerificationController.php` tiene 252 líneas (cita 3168-3173 — imposible). Solo `AdminController.php:308-310` está cerca (el archivo tiene exactamente 308 líneas, pero la lógica real citada vive en 281-296). **Veredicto: CONTRADICTED en la precisión de sus propias citas** (5 de 6 citas muestreadas son numéricamente imposibles), aunque el contenido que dicen respaldar es correcto. Esto socava la promesa explícita del propio documento de que las citas son "verificables". |
| **docs/MOVA_PRODUCTION_READINESS.md** (nuevo) | Baseline "única fuente de verdad": 454 tests / 1328 assertions, 118 rutas, 75 migraciones | **MATCH exacto** — reproducido: `php artisan test` → 454 passed (1328 assertions); `php artisan route:list --json` → 118 entradas; `ls database/migrations/*.php \| wc -l` → 75. (El estado "Ran" de cada migración no se pudo verificar por falta de MySQL local — ver §6.) |
| **docs/MOVA_QA_BASELINE.md** (nuevo) | `target-safety.spec.js` 12/12, `mutation-firewall.spec.js` 13/13, `test:security` 25/25 | **MATCH exacto** — reproducido ejecutando `npm run test:security` dentro de `qa/`: 25 passed, mismo desglose 12+13. |
| **docs/payments-architecture.md** / **docs/whatsapp-architecture.md** (nuevos) | Secciones consistentes con lo ya verificado vía HANDOFF_FINAL.md (`payment_orders`, `PaymentWebhookService`, plantilla única de WhatsApp, `WhatsAppMessageStatus`) | **MATCH** (verificación cruzada vía las clases ya confirmadas en código; no se releyeron íntegros dado el volumen, siguiendo la instrucción de muestreo). |
| **docs/phase0-appendices/A2_seguridad.md** | Re-confirma que F-26/F-27 siguen en `HEAD`; identifica que el worktree aislado usado por los 6 subagentes de Fase 0 carecía de `qa/lib/enforce-safe-target.mjs` y de la regla `qa/auth/` en `.gitignore` (por ser cambios sin commitear) | **MATCH** — consistente con la verificación directa de git que hice en §(0)/(1), y consistente internamente con `MOVA_CREDENTIAL_EXPOSURE.md`. No reproduce ningún valor de secreto (cumple su propia regla de no divulgación). |
| **docs/MOVA_AUDIT_PHASE0.md** | 454 tests/1328 assertions (checkout principal); BUG-1 (borrar alumno con historial) "ya corregido" gracias a `StudentDeletionIntegrityTest` (12 tests) | **MATCH** — mismo conteo de tests reproducido; `StudentDeletionIntegrityTest` efectivamente tiene 12 tests, todos verdes (confirmado en §1). |

### Nota sobre redundancia entre rondas de auditoría (pedida explícitamente)

`docs/MOVA_FULL_AUDIT.md`, `MOVA_PHASE3_COMPLETION.md`, `MOVA_PHASE3_REAUDIT.md`, `MOVA_PHASE4_AUDIT.md`, `MOVA_PHASE5_FINAL_AUDIT.md`, `MOVA_PHASE6_AUDIT.md` **sí se solapan entre sí y con `MOVA_PRODUCTION_READINESS.md`/`MOVA_AUDIT_PHASE0.md`** — pero **no es duplicación ciega**: es una cadena cronológica explícita donde cada documento posterior corrige o cierra hallazgos del anterior (verificado leyendo los encabezados de los 10 documentos):

`SYSTEM_KNOWLEDGE` (Fase 1, base) → `FULL_AUDIT` (Fase 2, 17 findings) → `PHASE3_COMPLETION` (corrige) → `PHASE3_REAUDIT` (encuentra 3 "cierres" falsos + F-18 nuevo) → `PHASE4_AUDIT` (encuentra una regresión real de Fase 3) → `PHASE5_FINAL_AUDIT` (F-20 nuevo) → `PHASE6_AUDIT` (corrige un "consentimiento" que no era tal) → `MOVA_AUDIT_PHASE0` (auditoría más amplia y posterior, 2026-08-26, con 6 subagentes) → `MOVA_PRODUCTION_READINESS` (matriz "única fuente de verdad", declarada explícitamente para no tener que narrar cifras de documentos anteriores).

`MOVA_FULL_AUDIT.md` incluso lleva banners `⚠️ FASE 5`/`⚠️ FASE 6` insertados **dentro del mismo archivo** apuntando hacia adelante a los documentos que lo corrigen — es decir, el propio corpus ya se autogestiona como changelog, no como snapshots independientes que compitan por ser "la verdad".

**Dicho esto, el volumen es real**: un lector que quiera entender "¿cuál es el estado actual?" tiene que atravesar 6 documentos de fases más 2 documentos "fuente de verdad" para llegar a la misma respuesta que `MOVA_PRODUCTION_READINESS.md` ya da en una tabla. La propia regla que `MOVA_PRODUCTION_READINESS.md` se impone ("nunca narrar cifras de sesiones anteriores") es un reconocimiento implícito de que los documentos de fase quedaron obsoletos como referencia operativa y solo sirven como bitácora histórica. **Recomendación (no ejecutada, fuera de alcance de esta auditoría de solo-lectura): considerar archivar `PHASE3_COMPLETION`/`PHASE3_REAUDIT`/`PHASE4_AUDIT`/`PHASE5_FINAL_AUDIT`/`PHASE6_AUDIT` bajo una carpeta `docs/historico/` en vez de la raíz de `docs/`, dejando `MOVA_PRODUCTION_READINESS.md` + `MOVA_AUDIT_PHASE0.md` como los únicos documentos "vivos".** No se reclasifica como OBSOLETE porque cada uno sigue siendo la única fuente primaria de detalle para su propio hallazgo (p. ej. el razonamiento completo de F-18 solo está en `PHASE3_REAUDIT`).

---

## (3) QA / config

- **`.gitignore` cubre `qa/auth/`**: confirmado línea 34, además de `qa/.env.qa` (línea 13) con excepción explícita para `qa/.env.qa.example` (línea 30). El diff de `.gitignore` es **puramente aditivo** (`git diff` muestra solo `+`, cero líneas removidas) — no se reintrodujo ningún patrón peligroso ni se debilitó ninguna regla existente.
- **`.env.example` no contiene ningún valor real**: revisado completo (199 líneas) — todas las variables sensibles (`DB_PASSWORD`, `GMAIL_*`, `META_WHATSAPP_*`, `OPENAI_API_KEY`, `JAAS_PRIVATE_KEY`, `PUSHER_*`, `CULQI_*`) están vacías; solo hay comentarios explicando cómo obtenerlas. Barrido con patrones de secretos reales (`sk-`, `AKIA`, `BEGIN PRIVATE KEY`, etc.) → 0 coincidencias.
- **`composer.lock` coherente con `composer.json`**: `composer validate` → válido; `composer install --dry-run` → *"Nothing to install, update or remove"* (si el `content-hash` estuviera desincronizado, Composer habría advertido explícitamente "lock file is not up to date"). Confirmado también manualmente: los paquetes de `require`/`require-dev` de `composer.json` no incluyen `twilio/sdk` (coherente con la migración documentada).
- **`qa/lib/enforce-safe-target.mjs`, `enforce-read-only.mjs`, `fixtures.mjs`** existen y su contenido coincide con lo que la documentación describe (allowlist, override incómodo de escribir, HTTPS obligatorio, fixture que envuelve `page`).
- **Ejecución real de `npm run test:security`** (dentro de `qa/`): **25/25 passed** (`target-safety.spec.js` 12, `mutation-firewall.spec.js` 13) — coincide exactamente con lo que `MOVA_QA_BASELINE.md` y `MOVA_QA_SECURITY_POLICY.md` afirman.
- **Los 5 archivos de F-26/F-27** (`qa/test-wizard-flow.mjs`, `qa/auth/admin.json`, `qa/global-setup.js`, `qa/end-to-end-welcome-email.mjs`, `qa/check-twilio.mjs`) están borrados del disco (no aparecen con `ls`), pero `git cat-file -e HEAD:<ruta>` confirma que **los 5 siguen presentes en el commit `HEAD`** — la eliminación en disco **no detiene la exposición hacia adelante** hasta que se comitee y se pushee, exactamente como documentan `MOVA_CREDENTIAL_EXPOSURE.md` y `MOVA_QA_SECURITY_POLICY.md`. No se investigó de nuevo el hallazgo F-26/F-27 en sí (ya cubierto por esos documentos) — solo se confirmó que el estado en disco/índice/HEAD sigue siendo el mismo que ellos describen.

**Un detalle cosmético menor, no de seguridad**: `.env.example` línea 1 tiene una indentación accidental de 4 espacios antes de `APP_NAME=MOVA` (`    APP_NAME=MOVA`). No afecta el parseo de Laravel (dotenv ignora espacios en blanco iniciales de línea) pero es un residuo de edición que vale la pena limpiar en una pasada futura.

---

## (4) Clasificación por grupo

| Grupo | Clasificación | Motivo |
|---|---|---|
| **Tests** (los 13 archivos) | **KEEP** | 206/206 pasan de verdad, aserciones fuertes (estado de BD, JWT decodificado, tokenizer real de PHP, no solo códigos HTTP), documentan honestamente sus propios límites (concurrencia SQLite, redacción de nombres en minúscula). El único hallazgo (solapamiento menor join-de-lección entre CrossTenantAccessTest y MonetizationIntegrityTest) no amerita tocar nada — cada uno prueba un ángulo distinto. |
| **MOVA_MASTER_CONTEXT.md** (diff) | **DOCS_ONLY** | Cambios verificados como MATCH exacto contra el código. |
| **SETUP.md** (diff) | **KEEP_WITH_FIX** | El diff en sí es correcto (WhatsApp), pero deja intactas 2 líneas sobre Zoom que contradicen el código real (Zoom fue eliminado, reemplazado por JaaS). Requiere una pasada adicional para eliminar la mención de Zoom como prerequisito. |
| **.telemetry/product.md** (diff) | **KEEP_WITH_FIX** | Mismo problema que SETUP.md pero más extenso: Tech Stack, Core Features, campos de `Lesson`, y pasos de flujo siguen describiendo Zoom, que no existe en el código (columnas dropeadas, sin `ZoomService`). Esto es responsabilidad del dueño del `.telemetry/` (skill de tracking), pero como documento de producto de cara al sistema real, la sección de video necesita reescribirse a JaaS/Jitsi. |
| **docs/WHATSAPP_PRODUCTION_NOTES.md** (diff) | **DOCS_ONLY** | MATCH completo. |
| **docs/HANDOFF_FINAL.md** (diff, adiciones §22-25) | **DOCS_ONLY** | MATCH completo, incluida verificación de clases/migraciones/comandos citados. |
| **docs/MOVA_CREDENTIAL_EXPOSURE.md** | **DOCS_ONLY** | MATCH exacto reproducido con `git`. No contiene ningún secreto real (cumple su propia regla de no-divulgación). |
| **docs/MOVA_QA_SECURITY_POLICY.md** | **DOCS_ONLY** | MATCH mayormente; una frase ("nunca a discreción de un spec individual") es más absoluta de lo que el mecanismo real garantiza — moot hoy (0 specs usan ese config) pero vale la pena suavizar la redacción en una pasada futura. No amerita KEEP_WITH_FIX porque no describe un problema de seguridad activo, solo una imprecisión de framing. |
| **docs/MOVA_SYSTEM_KNOWLEDGE.md** | **KEEP_WITH_FIX** | El contenido sustantivo verificado es correcto, pero 5 de 6 citas `archivo:línea` muestreadas son numéricamente imposibles (exceden el tamaño real del archivo citado, hasta 12x). Esto contradice la promesa explícita del propio documento ("cita archivo:línea cuando es verificable") y debería corregirse — no por urgencia de seguridad, sino porque un documento que promete precisión verificable y no la tiene es peor que uno que no promete nada. |
| **docs/MOVA_PRODUCTION_READINESS.md** | **DOCS_ONLY** | Cifras centrales (tests, rutas, migraciones) verificadas exactas. |
| **docs/MOVA_QA_BASELINE.md** | **DOCS_ONLY** | MATCH exacto reproducido (25/25). |
| **docs/MOVA_AUDIT_PHASE0.md** + **docs/phase0-appendices/*** | **DOCS_ONLY** | Consistente internamente y con verificación directa de git; no reproduce secretos. |
| **docs/payments-architecture.md**, **docs/whatsapp-architecture.md** | **DOCS_ONLY** | Consistentes con las clases ya verificadas vía HANDOFF_FINAL.md. |
| **docs/MOVA_FULL_AUDIT.md**, **PHASE3_COMPLETION**, **PHASE3_REAUDIT**, **PHASE4_AUDIT**, **PHASE5_FINAL_AUDIT**, **PHASE6_AUDIT** | **DOCS_ONLY** (con nota) | Válidos como bitácora histórica encadenada, no como snapshots contradictorios. Candidatos razonables a archivarse en `docs/historico/` ahora que `PRODUCTION_READINESS`/`AUDIT_PHASE0` son la fuente de verdad declarada — pero ninguno contiene información falsa hoy, así que no se reclasifican como OBSOLETE. |
| **`.env.example`, `.gitignore`, `composer.json`/`.lock`, `phpunit.xml`, `railway.queue.toml`** | **KEEP** | Sin secretos, sin patrones peligrosos reintroducidos, lock coherente con json. |
| **`qa/package.json`, `qa/playwright*.config.js`, `qa/tests/flujo-completo.spec.js`, `qa/lib/*`, `qa/tests/{mutation-firewall,target-safety}.spec.js`, `qa/playwright.production-readonly.config.js`, `qa/.env.qa.example`** | **KEEP** | 25/25 tests de seguridad de QA pasan de verdad; targeting seguro y firewall de mutaciones verificados en código, no solo en documentación. |
| **Eliminación en disco de `qa/auth/admin.json`, `qa/check-twilio.mjs`, `qa/end-to-end-welcome-email.mjs`, `qa/global-setup.js`, `qa/test-wizard-flow.mjs`** | **BLOCKED** (para completar) | La eliminación en disco es correcta y necesaria, pero **no está commiteada** — los 5 archivos con la contraseña RAW de producción y cookies de admin siguen en `HEAD`/`origin/master`. Esto bloquea cualquier cierre real de F-26/F-27 hasta que el usuario decida commitear+pushear esta limpieza (acción que, según `MOVA_CREDENTIAL_EXPOSURE.md`, es segura y reversible, distinta de purgar el historial). No se ejecuta aquí por ser una decisión que corresponde al usuario, no a esta auditoría de solo-lectura. |

---

## (5) Barrido de secretos

Ejecutado sobre **toda** la documentación en scope y **todos** los tests/fixtures nuevos o modificados:

```bash
grep -rEn "sk-[A-Za-z0-9]{20,}|AKIA[0-9A-Z]{16}|BEGIN (RSA|OPENSSH|EC|DSA)? ?PRIVATE KEY|xox[baprs]-...|ghp_...|AIza..." docs/ tests/ .telemetry/ MOVA_MASTER_CONTEXT.md SETUP.md qa/
→ 0 coincidencias

grep -rEn "(PASSWORD|SECRET|TOKEN|API_KEY)\s*[:=]\s*['\"]?[A-Za-z0-9+/]{12,}" docs/*.md docs/phase0-appendices/*.md ...
→ 0 coincidencias (tras excluir placeholders/ejemplos)
```

**Único valor "sensible" encontrado, y es intencional/documentado**: `phpunit.xml` contiene un `JAAS_PRIVATE_KEY` base64 de una clave RSA de prueba, con un comentario explícito en el propio archivo: *"Keypair de prueba, sin relación con ninguna cuenta real de JaaS — solo para que JaasService pueda firmar un JWT sintácticamente válido en tests (no hay llamada de red que valide la firma)"*. No es un secreto real filtrado — es un fixture criptográfico sintético para tests, con su origen documentado inline. Se decidió no tratarlo como hallazgo porque el propio archivo declara su naturaleza no productiva.

**Credenciales en `qa/tests/flujo-completo.spec.js`** (`padre@mova.test` / `profesor@mova.test`, password `password123`): confirmadas como cuentas ficticias de un seeder local (`LocalTestDataSeeder`), consistente con lo que `MOVA_QA_SECURITY_POLICY.md` documenta explícitamente — no son credenciales reales.

**Ningún secreto real** (contraseña de BD de producción, tokens de API, claves privadas de producción) aparece en ningún documento nuevo o modificado, ni en ningún test o fixture — todos los documentos que discuten los secretos reales de F-26/F-27 (`MOVA_CREDENTIAL_EXPOSURE.md`, `MOVA_QA_SECURITY_POLICY.md`, `phase0-appendices/A2_seguridad.md`) cumplen su propia regla declarada de no reproducir el valor.

---

## (6) Qué no se pudo verificar

- **Estado real de las migraciones (`Ran`/pendiente) contra MySQL de producción o local**: no hay servidor MySQL corriendo en este entorno (`php artisan migrate:status` falló con `SQLSTATE[HY000] [2002] Connection refused`). Se verificó como proxy el conteo de **archivos** de migración (75, coincide con lo que `MOVA_PRODUCTION_READINESS.md` afirma), pero no el estado `Ran` real en una base de datos viva.
- **Si la credencial de MySQL de producción filtrada (F-26) sigue activa**: deliberadamente no verificado, ni por esta auditoría ni por ninguna de las citadas — requeriría conectarse con una credencial potencialmente comprometida, exactamente lo que la documentación existente indica evitar.
- **El contenido íntegro de `docs/phase0-appendices/A1`, `A3`-`A6`** (cada uno 20-33 KB) no se leyó línea por línea, solo se sampleó `A2_seguridad.md` en profundidad por ser el más directamente relevante a mi dominio (secretos/QA) — consistente con la instrucción de muestrear 5-10 afirmaciones por documento en vez de releer cada uno íntegro.
- **`docs/payments-architecture.md` y `docs/whatsapp-architecture.md` íntegros**: se verificaron sus secciones principales por correlación cruzada con clases/migraciones ya confirmadas vía `HANDOFF_FINAL.md`, pero no se releyeron las ~460 líneas combinadas palabra por palabra.
- **Los bugs BUG-2 a BUG-5 y N1 que `MOVA_AUDIT_PHASE0.md` reporta como abiertos**: quedan fuera de mi dominio (código de aplicación backend/frontend, cubierto por otros agentes de esta ronda de auditoría) — no se re-verificaron aquí.
