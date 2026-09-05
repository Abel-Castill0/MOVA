> **HISTORICAL SNAPSHOT** — verify against `docs/MOVA_SYSTEM_MAP.md` and current code.
> Documento de una fase anterior. Conservado como registro de decisiones, NO como
> descripcion fiable del comportamiento actual.

# MOVA — Auditoría Forense (Fase 2) · **actualizada tras Fase 3**

**Diagnóstico original:** 2026-08-24 (Fase 2)
**Estado posterior a las correcciones:** 2026-08-24 (Fase 3 — ver `docs/MOVA_PHASE3_COMPLETION.md`)
**Alcance:** backend completo + frontend (páginas con acciones sensibles) + scheduler + migraciones + FK + integraciones externas.
**Base:** `docs/MOVA_SYSTEM_KNOWLEDGE.md` (Fase 1).

> **⚠️ FASE 6 (2026-08-24).** El "consentimiento de WhatsApp" de la Fase 5 NO era consentimiento
> explícito: `verify()` lo fijaba automáticamente al validar el OTP, y la migración hacía backfill
> silencioso para todos los usuarios ya verificados. Corregido con casilla real y sin backfill.
> También se cerró la auditoría de privacidad de IA (headers/URL/logs/Sentry) y se añadió
> `--json` a F-02. Detalle en `docs/MOVA_PHASE6_AUDIT.md`. Conteo oficial: **415 tests**.
>
> **⚠️ FASE 5 (2026-08-24).** Hallazgo nuevo **F-20**: el worker de producción tenía `--timeout`
> igual a `retry_after`, lo que permite que un job se ejecute en dos workers a la vez. Cerrados
> además el consentimiento de WhatsApp y la matriz campo-por-campo hacia IA. Detalle en
> `docs/MOVA_PHASE5_FINAL_AUDIT.md`. Conteo oficial: **406 tests**.
>
> **⚠️ FASE 4 (2026-08-24).** La Fase 3 introdujo una **regresión grave** (F-18 rompía las
> notificaciones al padre) y su afirmación de "exactly once" en F-04 era **falsa**. GAP-03 ya
> está cerrado con concurrencia real contra MySQL, y el barrido del frontend encontró F-19.
> Detalle en `docs/MOVA_PHASE4_AUDIT.md`. Conteo oficial: **375 tests**.
>
> **⚠️ REAUDITADO (2026-08-24).** Tres findings que la Fase 3 marcó como corregidos NO lo estaban
> (F-04, F-12, F-14), uno se reclasificó (F-05) y apareció uno nuevo (F-18). El detalle y la
> evidencia están en `docs/MOVA_PHASE3_REAUDIT.md`; la tabla de abajo ya refleja el estado final.
> Conteo oficial de tests: **364** (los 324/346 citados en la Fase 3 eran instantáneas parciales).
>
> **Cómo leer este documento.** El cuerpo conserva el diagnóstico ORIGINAL, sin reescribirlo: es el registro de qué se encontró y con qué evidencia. El estado actual de cada hallazgo está en la tabla de abajo y en el bloque **▶ ESTADO TRAS FASE 3** que abre cada sección. Donde el diagnóstico original resultó **equivocado**, se dice explícitamente — no se corrige en silencio.

---

## Estado actual de los hallazgos

| ID | Severidad original | Título | Estado tras Fase 3 |
|---|---|---|---|
| F-01 | 🔴 CRITICAL | `DELETE` en `users` destruye el ledger por cascada | 🟡 **MITIGATED** — el diagnóstico era erróneo (MySQL ya usaba RESTRICT); guard de app añadido; cero bulk deletes; inventario FK incompleto |
| F-02 | 🔴 HIGH | C-1 implementado pero inactivo (`--dry-run` permanente) | ⏳ **CÓDIGO LISTO — DECISIÓN PENDIENTE** (`LESSON_SETTLEMENT_MODE=live`) |
| F-03 | 🔴 HIGH | Proveedores Fake *fail-open* | ✅ **CORREGIDO** — `ProviderGuard` fail-closed |
| F-04 | 🟠 HIGH | Race condition en 4 de 5 barridos de recordatorios | ✅ **FIXED** — claim recuperable; semántica corregida: exactly-once solo en el encolado, at-least-once en la entrega externa |
| F-05 | 🟠 HIGH | Anonimización que no cumple su contrato (menores) | 🟡 **MITIGATED** — reducción de PII, NO garantía; matriz campo-por-campo ahora es un contrato ejecutable |
| F-06 | 🟠 MEDIUM | JWT 24 h + `join()` sin ventana temporal | 🟡 + ⏳ **EXTERNAL VALIDATION** — ventana correcta y probada; falta smoke test contra JaaS real |
| F-07 | 🟠 MEDIUM | `jitsi_password`: columna muerta | ✅ **CORREGIDO** — eliminada (ciclo MySQL verificado) |
| F-08 | 🟡 MEDIUM | `reversed`/`reversal` sin etiqueta en UI | ✅ **CORREGIDO** — verificado en navegador |
| F-09 | 🟡 MEDIUM | `in_progress`: estado muerto con UI viva | ✅ **CORREGIDO** — eliminado del enum y del frontend |
| F-10 | 🟡 MEDIUM | Marca como avisado sin avisar | ✅ **CORREGIDO** — destinatarios unificados |
| F-11 | 🟡 MEDIUM | `paid` mezcla estado de clase y de pago | ✅ **DOCUMENTADO** — refactor pospuesto a propósito |
| F-12 | 🟢 LOW | Sin guard de doble submit | ✅ **FIXED (rehecho)** — faltaban 2 páginas, una de ellas financiera (aceptar solicitud) |
| F-13 | 🟢 LOW | `confirm()`/`prompt()` nativos | ✅ **CORREGIDO** — y se encontró uno más al re-buscar |
| F-14 | 🟢 LOW | Residuos de Twilio | ✅ **FIXED (rehecho)** — la política de privacidad pública seguía nombrando a Twilio como procesador de datos |
| F-15 | 🟢 LOW | Documentación contradice el código | ✅ **CORREGIDO** |
| F-16 | 🟢 LOW | Precio por crédito hardcodeado en Vue | ✅ **CORREGIDO** |
| F-17 | 🟢 LOW | `RechargeRequestPolicy` confusa | ✅ **CORREGIDO** |
| **F-18** | 🟠 HIGH | Borrar un alumno destruía su historial de clases | ✅ **FIXED** — soft delete + anonimización + guard; **regresión de Fase 3 corregida en Fase 4** (withTrashed) |
| **F-19** | 🟡 MEDIUM | 7 superficies sin ninguna visualización de error | ✅ **FIXED** (2 más en Fase 5) |
| **F-20** | 🟠 HIGH | **NUEVO (Fase 5)** — `--timeout` == `retry_after`: un job puede correr en dos workers a la vez | ✅ **FIXED** — timeout a 60s + guardián en health-check |

**Gaps:** GAP-01/02/03/04/05/06/07 ✅ cerrados · GAP-08 🟡 **parcial** (inventario y acciones con evidencia; falta visual, a11y y móvil).

**Cerrados en Fase 5:** consentimiento WhatsApp (opt-in/opt-out, con el OTP protegido) · matriz de datos hacia IA (contrato ejecutable) · tests del propio analizador de frontend · auditoría de colas.

### Verificación

| Comando | Fase 2 (diagnóstico) | Fase 3 (tras correcciones) |
|---|---|---|
| `php artisan test` | 248 passed | **415 passed** (1214 assertions) |
| `php artisan migrate:status` | 69/69 `Ran` | **73/73 `Ran`** |
| `npm run build` | limpio | limpio |
| `mova:reconcile-ledger` | GREEN | **GREEN** |
| `mova:reconcile-whatsapp` | healthy | **healthy** |
| `mova:health-check` | (no existía) | **GREEN** (local) |
| `git diff --check` | limpio | limpio |

---

# CRITICAL

## F-01 — `DELETE` en `users` destruye el ledger financiero por cascada

> **▶ ESTADO TRAS FASE 3: 🟡 EL DIAGNÓSTICO DE ABAJO ERA INCORRECTO.**
>
> La conclusión "CRITICAL" salió de leer solo las migraciones `CREATE TABLE`. La migración
> `2026_07_10_000002_protect_monetization_history.php` convierte esas seis FK a `RESTRICT`, y
> **el esquema MySQL real lo confirma** (consultado vía `information_schema`, no leyendo el
> archivo). En producción el ledger ya estaba protegido. La "confianza: ALTA" declarada abajo
> no estaba justificada: faltó buscar migraciones posteriores que alteraran esas FK.
>
> **El hueco real, que sí existía:** esa migración hace `return` temprano si el driver no es
> MySQL, y la suite corre sobre SQLite — la protección de producción **no era observable por
> ningún test**, y en el entorno de pruebas un `$user->delete()` sí arrasaba el ledger.
>
> **Corregido:** guard `User::booted()::deleting` que rechaza el borrado si hay historial
> protegido, independiente del motor y con mensaje accionable; `hasProtectedHistory()` movido
> de `ProfileController` al modelo para que todo camino de borrado lo respete.
> Tests: `FinancialHistoryDurabilityTest` (9).
>
> **Pendiente:** el inventario FK completo (`student_diagnostics`, `lesson_reports`,
> `diagnostic_recommendations` siguen en `CASCADE`, sin auditar caso por caso).

**Severidad:** 🔴 CRITICAL *(reclasificada — ver arriba)*
**Categoría:** FINANCIAL / DATABASE
**Confianza declarada originalmente:** ALTA — **no estaba justificada**

**Archivos y líneas:**
- `database/migrations/2024_01_01_000003_create_teacher_profiles_table.php:13` — `user_id ... cascadeOnDelete()`
- `database/migrations/2026_07_08_000003_create_credit_transactions_table.php:13` — `teacher_profile_id ... cascadeOnDelete()`
- `database/migrations/2026_07_08_000004_create_recharge_requests_table.php:13` — `teacher_profile_id ... cascadeOnDelete()`
- `database/migrations/2024_01_01_000008_create_classes_table.php:13` — `teacher_profile_id ... cascadeOnDelete()`

**Flujo del fallo:**
```
DELETE FROM users WHERE id = X
   └─ CASCADE → teacher_profiles
        ├─ CASCADE → credit_transactions   ← EL LEDGER COMPLETO DEL PROFESOR
        ├─ CASCADE → recharge_requests     ← TODO EL HISTORIAL DE PAGOS
        └─ CASCADE → classes               ← TODAS LAS CLASES DICTADAS
```

**Por qué importa:** todo el diseño financiero de MOVA descansa en que `credit_transactions` es append-only e inmutable. Esa inmutabilidad se defiende hoy contra `UPDATE`/`DELETE` a nivel de fila (correctamente — ver *Falsos positivos*, FP-01), pero **no contra la eliminación del registro padre**. Una sola sentencia sobre `users` borra la contabilidad de un profesor sin dejar rastro, y `mova:reconcile-ledger` no puede detectarlo: no quedan filas que reconciliar.

**Escenario de fallo concreto:**
1. Un profesor con 40 recargas aprobadas y 300 asientos de ledger pide baja por GDPR/soporte.
2. Alguien ejecuta `User::find(X)->delete()` desde Tinker, un script de soporte, un seeder, o una futura ruta de admin.
3. Desaparecen 300 asientos, 40 recargas y todas sus clases. Los ingresos históricos de MOVA quedan sin respaldo contable.

**Defensa actual:** parcial y solo a nivel de aplicación.
- `app/Http/Controllers/ProfileController.php:99-142` — `hasProtectedHistory()` (líneas 151-169) detecta `creditTransactions`/`rechargeRequests`/`classes` y **anonimiza en lugar de borrar**. Este camino es correcto y bien construido.
- **No existe ninguna ruta de admin que borre usuarios** (verificado: `AdminController` expone `suspendUser`/`unsuspendUser`, nunca `destroy`).
- Protección accidental parcial: `teacher_reviews` sí usa `onDelete('restrict')` sobre `teacher_profile_id` (`2026_06_29_300000_create_teacher_reviews_table.php:26`), así que un profesor **con reseñas** sí bloquea el cascade a nivel DB. Un profesor **sin reseñas pero con ledger** no está protegido.

**La inconsistencia es el hallazgo:** el proyecto ya conoce y usa `restrict` — lo aplicó a las **reseñas**, pero no a la **contabilidad**. La reseña de un profesor está mejor protegida a nivel de base de datos que su historial financiero.

**Recomendación (no aplicada):** cambiar `credit_transactions.teacher_profile_id`, `recharge_requests.teacher_profile_id` y `classes.teacher_profile_id` a `restrict`, y hacer que el camino de anonimización sea el único posible. Requiere migración cuidadosa y decisión de negocio sobre retención — **no tocar sin pasada de revisión financiera explícita** (CLAUDE.md).

**Cobertura de tests:** ninguna. No existe test que verifique que borrar un usuario no destruye el ledger.

---

# HIGH

## F-02 — C-1 está implementado pero inactivo en producción

> **▶ ESTADO TRAS FASE 3: ⏳ CÓDIGO LISTO, DECISIÓN PENDIENTE.**
>
> El modo ya no está hardcodeado: lo gobierna `LESSON_SETTLEMENT_MODE` (`config/credits.php` →
> `App\Support\SettlementMode`), el scheduler lo consulta, y `mova:health-check` reporta
> `SETTLEMENT_DRY_RUN_IN_PRODUCTION` con exit code 1 cuando producción sigue apagada — enganchable
> a un monitor o a un paso de despliegue.
>
> **El valor por defecto sigue siendo `dry_run` a propósito.** Activar la liquidación real por
> accidente sería peor que tenerla apagada. **Mientras no se ponga `LESSON_SETTLEMENT_MODE=live`,
> C-1 NO está operativo y este hallazgo sigue vigente.**
> Tests: `SchedulerConfigurationTest` (12).

**Severidad:** 🔴 HIGH
**Categoría:** FINANCIAL
**Confianza:** ALTA (búsqueda global exhaustiva)

**Archivos y líneas:**
- `app/Console/Kernel.php:23` — `$schedule->command('mova:settle-lessons --dry-run')->hourly()->withoutOverlapping();`
- `app/Console/Commands/SettleLessons.php:40-42, 70-75, 113-118` — cada rama comprueba `$dryRun` y hace `continue` sin escribir.

**Verificación global:** `grep -rn "settle-lessons"` sobre todo el repositorio excluyendo `docs/` devuelve exactamente **13 invocaciones reales, todas dentro de `tests/Feature/LessonSettlementScenariosTest.php`**, y una única invocación de producción — la del `Kernel`, con `--dry-run`. No existe cron externo, worker, job, ni ruta que ejecute la liquidación real.

**Flujo del fallo:**
```
Clase termina → padre nunca confirma → status queda 'scheduled' para siempre
                                     → crédito del profesor sigue en credits_reserved
                                                ↓
        scheduler (cada hora) → mova:settle-lessons --dry-run
                                                ↓
                         imprime "[dry-run] escalaría Lesson N" y NO escribe nada
                                                ↓
                              el crédito NUNCA se libera ni se consume
```

Lo mismo aplica al primer barrido: clases en `paid`/`pending_parent_confirmation` pasada la gracia de 7 días **no se liquidan**.

**Por qué importa:** C-1 se documenta en `docs/HANDOFF_FINAL.md` §17 y en 32+ comentarios del código como *resuelto*. Operacionalmente **no lo está**. Un profesor puede quedarse sin créditos disponibles indefinidamente porque tiene N créditos atrapados en `credits_reserved` de clases que ya ocurrieron y nadie cerró. Esa es exactamente la patología que C-1 existía para eliminar.

**Es una decisión deliberada, no un descuido.** `Kernel.php:17-22` y `SettleLessons.php:24-28` lo explican: la primera ejecución real exige revisión humana del dry-run, y quitar la bandera es "una decisión de negocio explícita". El código está bien; **el hallazgo es que la decisión de negocio nunca se tomó y el estado intermedio se está tratando como si fuera el estado final.**

**Defensa actual:** el escalado a `needs_admin_review` sería el mecanismo de rescate, pero también está bajo `--dry-run`. Hoy no hay ningún mecanismo automático que rescate un crédito varado; solo la intervención manual de un admin vía `force-complete`/`force-refund`, que requiere que alguien note el problema.

**Recomendación (no aplicada):** ejecutar el dry-run en producción, revisar la salida con un humano, y decidir. Hasta entonces MOVA **no debe describirse como financieramente lista para producción** en la documentación.

**Cobertura de tests:** excelente para el comando en sí (`LessonSettlementScenariosTest`, 13 escenarios incluyendo dry-run). **Cero cobertura de que el scheduler haga lo correcto** — ningún test inspecciona `Kernel::schedule()`.

---

## F-03 — Los proveedores Fake son *fail-open*

> **▶ ESTADO TRAS FASE 3: ✅ CORREGIDO.**
>
> `App\Support\ProviderGuard` valida el proveedor antes de instanciarlo: un valor desconocido,
> vacío, o un Fake en producción con la integración habilitada **detienen el arranque**. Los
> `match` de `AppServiceProvider` ya no tienen rama `default`, así que añadir un proveedor y
> olvidar instanciarlo lanza `UnhandledMatchError` en vez de devolver el Fake en silencio.
> El Fake sigue siendo válido en `local`/`testing`; `staging` NO se considera fake-friendly.
> Tests: `ProviderGuardTest` (14).

**Severidad:** 🔴 HIGH
**Categoría:** DEPLOYMENT / FINANCIAL
**Confianza:** ALTA

**Archivos y líneas:**
- `app/Providers/AppServiceProvider.php:26-31` (pagos) y `:38-43` (WhatsApp)

```php
return match (config('payments.provider')) {
    'culqi' => new CulqiPaymentProvider(),
    default => new FakePaymentProvider(),   // ← cualquier otra cosa cae aquí
};
```

**Por qué importa:** `default =>` convierte *cualquier* valor no reconocido en el proveedor falso. No hay ninguna comprobación de entorno. Los modos de fallo:

| Causa | Consecuencia |
|---|---|
| `PAYMENT_PROVIDER=culqui` (typo) | Silenciosamente `FakePaymentProvider` |
| Variable ausente en el entorno de producción | Silenciosamente `FakePaymentProvider` |
| `config:cache` ejecutado antes de definir la variable | Silenciosamente `FakePaymentProvider` |
| `WHATSAPP_PROVIDER` mal escrito con `WHATSAPP_ENABLED=true` | Ningún mensaje sale; todo el sistema reporta éxito |

**Escenario de fallo concreto (pagos):** `app/Payment/FakePaymentProvider.php:66` devuelve siempre `status: 'paid'`. Con Culqi ya conectado y un typo en la variable, MOVA marcaría órdenes como pagadas **sin que exista ningún cobro real**, abonaría créditos al profesor vía `RechargeApprovalService` y el ledger quedaría internamente consistente pero divorciado del dinero real. `mova:reconcile-ledger` seguiría reportando GREEN, porque el ledger sí cuadra consigo mismo.

**Escenario de fallo concreto (WhatsApp):** `FakeWhatsAppProvider` acepta todo y no envía nada. Se despliega, la suite pasa, los logs están limpios, `whatsapp_messages` registra filas — y ningún padre recibe jamás un recordatorio de clase ni un OTP. El fallo es invisible hasta que un usuario se queja.

**Defensa actual:** ninguna. No hay guard de `app()->environment('production')` en ninguno de los dos bindings. El único `environment('production')` del archivo (`:48`) es para `URL::forceScheme('https')`.

**Recomendación (no aplicada):** convertir el `default` en fail-closed: lanzar `RuntimeException` si el entorno es producción y el proveedor resuelto es el Fake mientras la funcionalidad está habilitada. Que un despliegue mal configurado falle al arrancar, en vez de operar en silencio.

**Cobertura de tests:** los tests ejercitan ambos proveedores, pero **ninguno verifica que producción + Fake sea imposible**.

---

## F-04 — Race condition real en `classmate:send-reminders`

> **▶ ESTADO TRAS FASE 3: ✅ CORREGIDO.**
>
> Los cuatro barridos usan ahora un **claim atómico**: un `UPDATE` condicional de una sola
> sentencia decide quién notifica, evaluado por el motor de base de datos. Se eligió frente a
> `lockForUpdate()` deliberadamente para **no retener un lock mientras se despachan jobs**.
> Además `classmate:send-reminders` ya usa `withoutOverlapping()` en el scheduler — defensa en
> profundidad, como pediste, no una sola capa.
> Tests: `ReminderConcurrencyTest` (11).

**Severidad:** 🟠 HIGH
**Categoría:** CONCURRENCY
**Confianza:** ALTA

**Archivos y líneas:**
- `app/Console/Kernel.php:15` — `$schedule->command('classmate:send-reminders')->everyMinute();` — **sin `withoutOverlapping()`**
- `app/Console/Commands/SendClassReminders.php:36-40` (24 h), `:53-57` (2 h), `:70-74` (10 m), `:136-142` (solicitudes sin responder)

**El patrón vulnerable** (idéntico en los 4 métodos):
```php
$lessons = Lesson::...->whereNull('reminder_24h_sent_at')->get();  // SELECT sin lock
foreach ($lessons as $lesson) {
    LessonNotifier::notifyBoth($lesson, $notif);      // ← notifica PRIMERO
    $lesson->update(['reminder_24h_sent_at' => now()]); // ← marca DESPUÉS, sin lock
}
```

**Por qué importa:** el comando corre **cada minuto sin `withoutOverlapping()`**. Si una ejecución tarda más de 60 s (probable: `notifyBoth` genera 2 usuarios × hasta 4 canales de notificación por lección, y cada canal se despacha como su propio job), la siguiente arranca en paralelo. Ambas ejecutan el mismo `SELECT`, ambas ven `reminder_24h_sent_at = NULL`, ambas notifican.

**Escenario de fallo concreto:** 200 clases entran en la ventana de 24 h a la misma hora. La pasada A tarda 90 s. En el segundo 60 arranca la pasada B, lee las ~130 lecciones que A todavía no marcó, y envía **un segundo recordatorio por WhatsApp y por email** a 130 padres y 130 profesores.

**La prueba de que el equipo ya conoce este bug:** `SendClassReminders.php:96-99` contiene el comentario *"antes el `update()` iba después del `notify()`, sin transacción, dejando una ventana de carrera"* — y `sendPendingReportAlerts()` (`:100-116`) **sí fue corregido**: transacción + `lockForUpdate()` + recomprobación bajo lock. La corrección se aplicó a **uno solo de los cinco barridos**; los otros cuatro conservan el patrón original.

**Defensa actual:** ninguna en los 4 métodos afectados. No hay lock, no hay transacción, no hay `withoutOverlapping()` en el scheduler.

**Impacto financiero:** ninguno (los recordatorios no mueven créditos). Impacto real: spam a usuarios, coste de mensajes de WhatsApp duplicados (Meta cobra por conversación), y erosión de confianza.

**Recomendación (no aplicada):** replicar el patrón ya presente en `sendPendingReportAlerts()` en los otros cuatro, y/o añadir `withoutOverlapping()` al scheduler. El patrón correcto ya existe en el mismo archivo — es copiarlo.

**Cobertura de tests:** ninguna prueba de concurrencia para este comando.

---

## F-05 — La anonimización previa al envío a IA no cumple su contrato (datos de menores)

> **▶ ESTADO TRAS FASE 3: ✅ CORREGIDO, con límite documentado.**
>
> La redacción vive ahora en `App\Support\TextRedactor` y cubre: emails, URLs, secuencias de 6+
> dígitos (teléfonos peruanos, +51, DNI, números de operación), nombres tras marcador de
> parentesco (rescata «mi hijo juan», en minúscula) y nombres propios capitalizados **sin el
> lookbehind** que impedía detectarlos al inicio del texto. Los 11 casos adversariales que
> planteaste pasan.
>
> **El docblock del servicio se corrigió**: ya no promete lo que no puede cumplir. Separa lo
> garantizado por construcción (los campos estructurados nunca se envían) de lo best-effort (el
> texto libre). Un nombre de pila en minúscula sin marcador sigue siendo indetectable — está
> **probado como límite conocido**, no escondido.
>
> No se activó la IA (sigue en `DIAGNOSTIC_AI_ENABLED=false`).
> Tests: `TextRedactionTest` (18).

**Severidad:** 🟠 HIGH
**Categoría:** AI / PRIVACY / MENORES
**Confianza:** ALTA (regex analizado carácter a carácter)

**Archivos y líneas:**
- `app/Services/DiagnosticAiEnrichmentService.php:364-372` — `anonymize()`
- `app/Services/DiagnosticAiEnrichmentService.php:11-21` — el docblock que promete la garantía
- `app/Services/DiagnosticAiEnrichmentService.php:175` — dónde se aplica

**El contrato documentado** (`:16-17`):
> *"Nunca recibe: student_id, parent_id, nombre del alumno/padre, email, teléfono, school_feedback, ni IDs internos."*

**La implementación real:**
```php
$text = preg_replace(
    '/(?<=[a-záéíóúüñ ,])\b([A-ZÁÉÍÓÚÜÑ][a-záéíóúüñ]+(?:\s+[A-ZÁÉÍÓÚÜÑ][a-záéíóúüñ]+)+)\b/',
    '[NOMBRE]', $text);
return mb_substr($text, 0, 400);
```

**Qué NO captura este regex** — y `difficulty_text` es un campo de texto libre escrito por un padre sobre su hijo menor de edad:

| Entrada del padre | ¿Se anonimiza? | Por qué |
|---|---|---|
| `Juan no entiende fracciones` | ❌ **No** | El grupo exige `(?:\s+[A-Z][a-z]+)+` — mínimo **dos** palabras capitalizadas. Un nombre de pila solo nunca coincide. |
| `Juan Pérez tiene problemas...` | ❌ **No** | El lookbehind `(?<=[a-záéíóúüñ ,])` exige un carácter previo. **Al inicio del string no hay nada que mirar atrás**, así que ni siquiera un nombre completo coincide si abre el texto. |
| `mi hijo juan no entiende` | ❌ No | Requiere mayúscula inicial. |
| `llámame al 987654321` | ❌ **No** | **No existe ningún patrón para teléfonos.** |
| `escríbeme a mama@gmail.com` | ❌ **No** | **No existe ningún patrón para emails.** |
| `DNI 71234567` | ❌ No | Sin patrón. |
| `estudia en Colegio San Agustín` | ✅ Sí | Irónicamente, el colegio sí se captura; el nombre del niño no. |

**Por qué importa:** CLAUDE.md establece que *"cualquier cambio en el manejo de datos de estudiantes debe pasar por una revisión de seguridad explícita"*. Aquí el problema no es un cambio, sino que **el docblock afirma una garantía que la implementación no puede sostener**. Cualquiera que lea el contrato asumirá que es seguro alimentar ese campo con texto libre. El destinatario es un tercero (OpenAI o Google), con sus propias políticas de retención y entrenamiento.

**El caso más probable en producción es precisamente el que falla:** un padre escribiendo "Juan no logra entender las fracciones" — nombre de pila, una sola palabra, al inicio de la frase. Se envía literal.

**Defensa actual — mitigaciones reales que sí existen:**
- La IA está **apagada por defecto**: `config('diagnostic.ai_enabled', false)` (`:33`). Si nunca se activó en producción, la exposición real hasta hoy es nula. *(No confirmado: no se leyó el `.env` de producción — ver UNKNOWN-01.)*
- `goal === 'solve_homework'` se salta la IA por completo (`:46-48`).
- `mb_substr(..., 0, 400)` limita el volumen.
- `ai_usage_logs` **no guarda prompts ni respuestas ni `difficulty_text`** — verificado, la auditoría no amplifica la fuga.
- La IA **nunca decide** qué profesor se recomienda; `DiagnosticRecommendationService` es siempre el árbitro (`:18-19`).
- El system prompt instruye explícitamente a no mencionar nombres (`:181`) — pero eso restringe la *salida* del modelo, no impide que el dato *entre*.

**Recomendación (no aplicada):** o se endurece `anonymize()` (nombre de pila único, inicio de string, teléfonos peruanos de 9 dígitos, emails, DNI), o se corrige el docblock para que describa lo que realmente hace: *"anonimización best-effort de secuencias de nombres propios; no garantiza la eliminación de identificadores"*. **La peor de las opciones es dejar el contrato prometiendo más de lo que cumple.**

**Cobertura de tests:** no se encontró ningún test que ejercite `anonymize()` con entradas adversas.

---

# MEDIUM

## F-06 — JWT de JaaS válido 24 h, y `join()` sin ventana temporal en backend

> **▶ ESTADO TRAS FASE 3: ✅ CORREGIDO.**
>
> `LessonController::join()` aplica ahora la ventana en el **servidor**: desde
> `join_window_before_minutes` antes del inicio hasta `join_grace_after_minutes` tras el fin
> (`config/jaas.php`). El `exp` del JWT queda acotado a esa misma ventana, así que el token no
> puede sobrevivir al periodo en que el propio endpoint lo habría concedido.
>
> **No se eligió una cifra arbitraria**, como advertiste: la gracia por defecto es de 2 h,
> deliberadamente generosa para que una clase que se alarga nunca se corte. Reducirla exige el
> smoke test contra JaaS real (UNKNOWN-03).
>
> El frontend se alineó con la misma regla y se encontró que tenía **su propio hueco**: `<=15`
> no tenía límite inferior, así que el botón seguía apareciendo días después de la clase. Se
> eliminó además una **cuarta copia** divergente de la regla en `Dashboard/Parent.vue`.
> Tests: `JitsiAccessWindowTest` (12).

**Severidad:** 🟠 MEDIUM
**Categoría:** JITSI / AUTHORIZATION
**Confianza:** ALTA (código) / MEDIA (impacto real: depende de cómo JaaS interpreta `exp`, no verificable desde el repo)

**Archivos y líneas:**
- `app/Services/JaasService.php:32` — `'exp' => $now + (24 * 60 * 60)`
- `app/Services/JaasService.php:33` — `'nbf' => $now - 10`
- `app/Http/Controllers/LessonController.php:161-184` — `join()`
- `resources/js/utils/lessonJoin.js:13` y `resources/js/Pages/Dashboard/Parent.vue:377` — el gate de 15 minutos, **solo en frontend**

**Dos problemas relacionados:**

**(a) Duración del token.** Una clase dura típicamente 60 minutos; el token que da acceso a esa sala vive **24 horas**. Si el JWT se filtra (historial del navegador, captura de DevTools, extensión maliciosa, log de un proxy corporativo), concede acceso a una videollamada con un menor durante un día entero. `nbf = now - 10` significa además que es válido inmediatamente, sin relación con la hora de la clase.

**(b) La ventana temporal solo existe en el frontend.** `join()` comprueba autorización (`LessonPolicy::view()`) y estado (`scheduled`/`paid`/`pending_parent_confirmation`), pero **no comprueba proximidad a `start_time`**. La regla "disponible 15 minutos antes" vive exclusivamente en `lessonJoin.js` y en `Dashboard/Parent.vue:377`. Un `POST` directo a `lessons.join` días antes de la clase devuelve un token válido.

**Escenario de fallo:** un profesor legítimo pide el token cinco días antes de la clase, lo guarda, y entra a la sala en cualquier momento de las 24 h siguientes. No es un atacante externo — la Policy hace bien su trabajo — pero **la UI comunica una restricción que el backend no aplica**, que es la definición de una divergencia de confianza.

**Defensa actual:** buena en lo esencial — `jitsi_room` está en `$hidden` (`Lesson.php:52`), `join()` es el único punto que lo revela, exige `LessonPolicy::view()`, responde con `Cache-Control: no-store, private`, y el JWT deshabilita explícitamente grabación, streaming y transcripción (`JaasService.php:39-43`). El `room` va en el payload, así que el token no sirve para otras salas.

**Recomendación (no aplicada):** alinear `exp` con `end_time + margen` (p. ej. `start_time - 15min` → `end_time + 30min`) y replicar el gate temporal en `join()`. Requiere confirmar cómo JaaS trata un `exp` corto cuando la llamada ya está en curso — **no cambiar sin verificarlo contra JaaS real** (una clase cortada a mitad sería peor que el riesgo actual).

**Cobertura de tests:** `MonetizationIntegrityTest` verifica la autorización de `join()` y que `jitsi_room` no se filtre en listados. **Sin cobertura de la ventana temporal ni de la expiración.**

---

## F-07 — `jitsi_password`: columna muerta, generada y almacenada sin uso

> **▶ ESTADO TRAS FASE 3: ✅ CORREGIDO.**
>
> Búsqueda global reconfirmada antes de tocar nada. Ya no se genera, salió de `$fillable` y
> `$hidden`, y la columna se eliminó en
> `2026_08_24_000001_drop_jitsi_password_from_classes_table`.
> Ciclo up → verify → rollback → verify → reapply → verify ejecutado sobre **MySQL real**.

**Severidad:** 🟠 MEDIUM
**Categoría:** JITSI / DATA HYGIENE
**Confianza:** ALTA (búsqueda global)

**Archivos y líneas:**
- `app/Http/Controllers/LessonController.php:96` — `'jitsi_password' => Str::random(10)` (se genera)
- `app/Models/Lesson.php:19` — está en `$fillable`
- `app/Models/Lesson.php:52` — está en `$hidden`
- `database/migrations/2026_07_22_000001_add_jitsi_password_to_classes_table.php:12` — la columna

**El hallazgo:** búsqueda global de `jitsi_password` en todo el repositorio: aparece en el modelo, la migración, el punto de generación, tests que verifican que **no** se filtre, y comentarios. **En ningún sitio se lee para usarlo.** `join()` (`LessonController.php:176-183`) devuelve `jitsi_room`, `jitsi_token` y `jaas_app_id` — nunca el password. JaaS autentica por JWT firmado, no por contraseña de sala; la columna es un residuo de la época de `meet.jit.si`.

**Por qué importa:** es un secreto almacenado en texto plano en una tabla de producción que no protege nada. Aumenta la superficie de un volcado de base de datos sin aportar seguridad. Además está en `$fillable`, lo que lo hace teóricamente asignable en masa (no explotable hoy: ningún endpoint acepta ese campo del usuario, verificado).

**Defensa actual:** `$hidden` impide su serialización, y hay tests explícitos que verifican que no aparece en notificaciones (`LessonSettlementScenariosTest.php:388`, `NotificationSecurityTest.php:282`) — la disciplina de C-3 se aplicó correctamente. El dato está bien contenido; simplemente no debería existir.

**Recomendación (no aplicada):** confirmar contra JaaS que no se usa en ningún flujo, y si se confirma, dejar de generarlo y eliminar la columna en una migración con `down()` seguro.

---

## F-08 — Los estados `reversed` / `reversal` no existen en la UI

> **▶ ESTADO TRAS FASE 3: ✅ CORREGIDO.**
>
> `reversed` y `reversal` tienen etiqueta en castellano y badge propio en las dos superficies
> («Revertida», «Movimiento revertido»), más una línea explicando por qué se descontaron
> créditos. **Verificado en el navegador** con una recarga revertida real: la tabla de admin
> muestra «Revertida», no `reversed`.

**Severidad:** 🟡 MEDIUM
**Categoría:** FRONTEND
**Confianza:** ALTA

**Archivos y líneas:**
- `resources/js/Pages/Admin/Recharges/Index.vue:154-160` — `statusLabel()` mapea solo `pending`/`approved`/`rejected`
- `resources/js/Pages/Admin/Recharges/Index.vue:162-171` — `statusBadge()`, mismo hueco
- `resources/js/Pages/Teacher/Credits/Index.vue:290-297` — `transactionLabel()` mapea `deposit`/`reservation`/`consumption`/`refund`, **falta `reversal`**
- `resources/js/Pages/Teacher/Credits/Index.vue:311-317` — `rechargeLabel()`, **falta `reversed`**

**Por qué importa:** el backend ganó el estado `reversed` (`recharge_requests`) y el tipo `reversal` (`credit_transactions`) al preparar la arquitectura de pagos. El frontend no se actualizó. El fallback es `?? status`, así que la UI degrada mostrando el identificador crudo en inglés.

**Escenario concreto:** se revierte una recarga (chargeback de Culqi, cuando exista). El profesor abre *Mis créditos* y ve una fila con la etiqueta literal **`reversal`** y un badge gris genérico, junto a un movimiento que le ha restado créditos. No hay explicación en castellano de por qué perdió saldo.

**Defensa actual:** el `?? status` evita que la UI se rompa — degrada, no falla.

**Nota de alcance:** hoy **no hay ninguna superficie que dispare una reversión** (`RechargeApprovalService::reverse()` existe y está testeado, pero no hay ruta ni botón). El bug es latente: se manifestará el día que se conecte Culqi.

---

## F-09 — `in_progress`: estado muerto en backend con UI viva

> **▶ ESTADO TRAS FASE 3: ✅ CORREGIDO.**
>
> Eliminado del enum en `2026_08_24_000002_remove_in_progress_from_classes_status_enum` (con
> guard que **aborta** si hubiera filas en ese estado, en vez de dejar que MySQL las convierta
> en cadena vacía) y de `resources/js/utils/statusColors.js`. Se verificó que había **0 filas**
> antes de recortar. `RescheduleTest` se actualizó y ganó `needs_admin_review`, que sí es un
> estado alcanzable y tampoco debe permitir reprogramar.
> Ciclo MySQL up → rollback → reapply verificado.

**Severidad:** 🟡 MEDIUM
**Categoría:** BUSINESS LOGIC
**Confianza:** ALTA (búsqueda global; cierra el UNKNOWN de la Fase 1)

**Búsqueda global de `in_progress` en todo el repositorio:**

| Ubicación | Qué hace |
|---|---|
| `database/migrations/2024_01_01_000008:22` y las dos que amplían el enum | Define el valor en la columna |
| `resources/js/utils/statusColors.js:7` | **UI viva**: etiqueta `'En curso'`, badge amarillo |
| `tests/Feature/RescheduleTest.php:100` | Data provider: verifica que **no** se pueda reprogramar |
| `docs/*`, `.telemetry/product.md` | Documentación |

**Ningún controlador, servicio, comando, job, listener o modelo asigna nunca `in_progress`.** La máquina de estados real salta de `scheduled` directamente a `paid`.

**Por qué importa:** el frontend tiene una rama de renderizado que jamás se ejecutará, y el enum de la base de datos admite un valor que ningún código produce. Un desarrollador nuevo leyendo `statusColors.js` concluirá razonablemente que MOVA rastrea clases en curso en tiempo real. No lo hace.

Confirma además un hallazgo previo del propio proyecto: `docs/FINAL_PRODUCTION_AUDIT.md:54` ya lo señaló como LOW en su momento. **Sigue presente.**

**Recomendación (no aplicada):** decidir explícitamente — o se implementa (marcar `in_progress` cuando alguien entra a la sala) o se elimina del enum y de `statusColors.js`. Dejarlo a medias es la peor opción.

---

## F-10 — `sendUnansweredRequestAlerts()` marca como avisado sin haber avisado

> **▶ ESTADO TRAS FASE 3: ✅ CORREGIDO.**
>
> Como pediste, **primero se determinó el destinatario correcto** leyendo
> `SendClassRequestNotifications`: tres caminos (referido → ese profesor; oferta → su dueño;
> abierta → todos los verificados de la materia). Esa resolución se extrajo a
> `ClassRequest::eligibleTeacherUsers()` y **ahora la comparten el listener y el recordatorio**,
> que era la causa raíz de la divergencia.
>
> `request_reminder_sent_at` solo se escribe si hubo destinatario, y como claim atómico previo
> al envío. Sin destinatario, la solicitud sigue siendo candidata (un profesor puede verificarse
> más tarde).
> Tests: 6 de los 11 de `ReminderConcurrencyTest`.

**Severidad:** 🟡 MEDIUM
**Categoría:** BUG
**Confianza:** ALTA

**Archivo y líneas:** `app/Console/Commands/SendClassReminders.php:136-142`

```php
foreach ($requests as $classRequest) {
    $teacher = $classRequest->classOffer?->teacherProfile?->user;
    if ($teacher) {
        $teacher->notify(new UnansweredRequestNotification($classRequest));
    }
    $classRequest->update(['request_reminder_sent_at' => now()]);  // ← fuera del if
}
```

**El bug:** `request_reminder_sent_at` se escribe **incondicionalmente**, incluso cuando no había destinatario. Las solicitudes abiertas **sin `class_offer_id`** — es decir, las que se difunden a todos los profesores verificados de la materia, que son el caso normal según `SendClassRequestNotifications` — tienen `classOffer === null`, no notifican a nadie, y quedan marcadas como ya avisadas.

**Consecuencia:** una solicitud abierta que lleva 12 h sin respuesta y que debería generar un aviso, queda silenciosamente marcada y **nunca volverá a ser candidata** (el `whereNull('request_reminder_sent_at')` de la línea 131 la excluye para siempre). El aviso se pierde definitivamente.

**Además:** el aviso solo llega al profesor dueño de la oferta, nunca a los profesores elegibles por materia — aunque son ellos quienes deberían recibirlo en el flujo mayoritario.

**Defensa actual:** ninguna.

**Cobertura de tests:** ninguna para este método.

---

## F-11 — `Lesson.status = 'paid'` mezcla estado de clase y estado de pago no verificable

> **▶ ESTADO TRAS FASE 3: ✅ DOCUMENTADO — refactor pospuesto a propósito.**
>
> Se siguió tu criterio: **nada de migración de estados ahora**. `Lesson` lleva un docblock que
> explica que `paid` significa «el padre declaró haber pagado al profesor fuera de MOVA», que no
> debe leerse como `PAYMENT_VERIFIED`, qué cinco consecuencias reales gobierna, cuál es la
> asimetría asumida, y que el pago del profesor a MOVA es un flujo distinto que no debe
> mezclarse.
>
> Se revisaron los usos de `status = 'paid'`: ninguno lo trata como confirmación de un
> proveedor de pago. Separar `lesson_status` de `payment_confirmation_status` sigue pendiente
> como decisión de producto.

**Severidad:** 🟡 MEDIUM
**Categoría:** BUSINESS LOGIC
**Confianza:** ALTA (código) / requiere decisión de producto

**Archivos y líneas:** `app/Http/Controllers/LessonController.php:186-216` — `confirmPayment()`

**Qué hace realmente `confirmPayment()`** (auditado línea a línea, respondiendo al punto 3 del brief):

| Paso | Línea | Detalle |
|---|---|---|
| Autorización | `:189` | `LessonPolicy::confirmPayment` — **solo el padre** |
| Precondición de estado | `:190` | `status === 'scheduled'`, si no 422 |
| Precondición temporal | `:195` | `now() >= end_time`, sin bypass por entorno ni query param |
| Transacción + lock | `:197-201` | `lockForUpdate()` sobre la lección |
| Recomprobación bajo lock | `:203-204` | Repite **ambas** precondiciones — correcto contra TOCTOU |
| Mutación | `:206` | `status = 'paid'` — **única escritura** |
| Auditoría | `:208` | `ClassEvent::log('payment_confirmed', ...)` |
| Notificación | `:213` | **Fuera** de la transacción — correcto |

**Veredicto técnico: el método es correcto.** No mueve créditos, no liquida, no consume. Descarta la hipótesis del brief de que sea un punto de confianza financiero oculto.

**El hallazgo real es semántico.** `paid` no significa *"MOVA verificó un pago"*, sino *"el padre declara que le pagó al profesor por fuera de la plataforma"*. MOVA no tiene ninguna evidencia. Sin embargo, ese estado declarativo **es el que gobierna consecuencias reales**:

```
'paid' habilita:
  ├─ LessonReportController:17,40,61 → el profesor puede subir el reporte
  ├─ SettleLessons:58                → entra en el barrido de consumo automático (arranca el reloj de gracia)
  ├─ DashboardController:82,127,132  → métricas y listados
  ├─ SendClassReminders:109          → alertas de reporte pendiente
  └─ lessonJoin.js:13                → acceso permanente a la sala de vídeo
```

**Riesgo concreto:** un padre puede declarar `paid` sin haber pagado nada. Eso arranca el reloj de gracia de C-1 y, cuando la liquidación real se active (F-02), **consumirá el crédito del profesor** por una clase que quizá no se cobró. El profesor paga a MOVA por una clase que el padre no le pagó a él.

**Por qué no es un bug hoy:** la asimetría es aceptable — el profesor ya dictó la clase y el crédito representa el coste de usar la plataforma, no el pago del padre. Es una decisión de negocio defendible. **El problema es que `paid` se lee en el código como si fuera un estado financiero verificado**, cuando es una declaración unilateral no auditable.

**Recomendación (no aplicada, requiere decisión de producto):** renombrar conceptualmente el estado (`parent_confirmed` sería más honesto) o documentar explícitamente en el modelo que `paid` es declarativo. **No refactorizar todavía**: `'paid'` aparece en 30+ ubicaciones entre PHP, Vue, migraciones y tests — cambiarlo es una migración de riesgo alto sin ganancia funcional inmediata.

---

# LOW

## F-12 — Sin guard de doble submit en cancelar / reprogramar / rechazar profesor

> **▶ ESTADO TRAS FASE 3: ✅ CORREGIDO.**
>
> `cancelling` / `rescheduling` / `rejecting` en `ParentIndex`, `TeacherIndex` y
> `PendingTeachers`, con el botón deshabilitado y texto de progreso. El backend sigue siendo la
> autoridad — esto elimina el 422 críptico, no sustituye a la protección real.

**Severidad:** 🟢 LOW (UX, **no** seguridad — el backend resiste)
**Confianza:** ALTA

**Archivos y líneas:**
- `resources/js/Pages/Lessons/ParentIndex.vue:183-189` — `submitCancel()`, sin `processing`
- `resources/js/Pages/Lessons/ParentIndex.vue:200-216` — `submitReschedule()`, sin `processing`
- `resources/js/Pages/Admin/PendingTeachers.vue:145-155` — `submitReject()`, sin `processing`

**Por qué NO es un problema de seguridad:** se verificó el backend. `LessonController::cancel()` (`:234-240`) hace `lockForUpdate()` y recomprueba `status === 'scheduled'` **dentro** de la transacción. Un segundo POST concurrente falla con 422 y **no produce un segundo refund**. La defensa financiera es sólida.

**Por qué sí es un problema:** el usuario que hace doble clic ve un error 422 críptico sobre una acción que en realidad funcionó. Además hay inconsistencia interna: `confirmPayment()` en el mismo archivo (`:165-176`) **sí** usa un guard (`payingId`), igual que `Admin/Lessons.vue` (`submitting`) y las 21 páginas que usan `useForm().processing`. Estos tres son los que quedaron fuera del patrón.

---

## F-13 — `confirm()` / `prompt()` nativos para acciones financieras irreversibles

> **▶ ESTADO TRAS FASE 3: ✅ CORREGIDO.**
>
> `Admin/Recharges` usa el patrón de `Admin/Lessons.vue`: modal con profesor, paquete, créditos,
> monto, operación, el efecto financiero explícito, motivo obligatorio validado y guard de
> envío. **Verificado en el navegador**, incluida una aprobación end-to-end (1 asiento de
> ledger, 5 créditos abonados).
>
> La re-búsqueda forense encontró **un `confirm()` más fuera del alcance original**: el borrado
> de un estudiante (`Students/Index.vue`). Se corrigió por tratarse de datos de un menor: el
> modal nombra al alumno y dice qué se conserva.

**Severidad:** 🟢 LOW
**Archivo:** `resources/js/Pages/Admin/Recharges/Index.vue:114` y `:126`

`approve()` usa `confirm()` del navegador; `reject()` usa `prompt()` para capturar el motivo. Funciona, pero: (a) es la única superficie de la app que usa diálogos nativos — el resto usa el componente `Modal.vue`; (b) `prompt()` está deprecado en contextos sandbox y bloqueado en algunos navegadores; (c) es la aprobación de un abono de dinero real, y `Admin/Lessons.vue` demuestra que el proyecto ya tiene un patrón mucho mejor (modal con advertencia explícita del efecto financiero + motivo obligatorio validado).

---

## F-14 — Residuos de Twilio tras la migración a Meta

> **▶ ESTADO TRAS FASE 3: ✅ CORREGIDO.** `qa/check-twilio.mjs` eliminado. Las referencias que
> quedan son comentarios que documentan la migración desde Twilio — historia, no residuo.

**Severidad:** 🟢 LOW
**Archivo:** `qa/check-twilio.mjs`

`twilio/sdk` se eliminó de `composer.json` y no queda ninguna referencia en `app/`, pero el script de QA sobrevivió a la migración. Es código muerto que puede confundir a quien audite la integración de WhatsApp.

---

## F-15 — `MOVA_MASTER_CONTEXT.md` contradice el código

> **▶ ESTADO TRAS FASE 3: ✅ CORREGIDO.** `MOVA_MASTER_CONTEXT.md` describe ahora JaaS/`8x8.vc`,
> el JWT RS256, la ventana de acceso aplicada en backend, la ausencia de contraseña de sala, y
> lleva una nota sobre F-02. Las menciones a `meet.jit.si` en `HANDOFF_FINAL.md` se conservan:
> son el relato histórico de la migración, no afirmaciones sobre el estado actual.

**Severidad:** 🟢 LOW (pero engañoso)
**Archivo:** `MOVA_MASTER_CONTEXT.md:161`

Describe la videollamada como `<iframe src="https://meet.jit.si/{jitsi_room}">`. El código real usa **JaaS (`8x8.vc`) con JWT firmado RS256** (`JaasService.php`), precisamente porque `meet.jit.si` corta el embed a los 5 minutos (comentario en `JaasService.php:8-10`). Documentación obsoleta en la raíz del repositorio, donde es lo primero que lee alguien nuevo.

Aplicando la regla del brief de Fase 1 (*"prioriza código real sobre documentación; si contradicen, señala la contradicción"*): **el código es correcto, el documento está desactualizado.**

---

## F-16 — Precio por crédito hardcodeado en Vue

> **▶ ESTADO TRAS FASE 3: ✅ CORREGIDO.** `price_per_credit` se deriva en
> `Teacher/CreditController::index()` desde el propio paquete y se renderiza desde props, junto
> con `currency`. El literal «S/ 2.00» ya no existe en la plantilla.

**Severidad:** 🟢 LOW
**Archivo:** `resources/js/Pages/Teacher/Credits/Index.vue:43` — `"1 crédito equivale a S/ 2.00."`

Literal en la plantilla, fuera de `config/credits.php`. Hoy es consistente (5cr/S/10, 15cr/S/30, 30cr/S/60 = S/2.00), pero si alguien ajusta los paquetes en el config, esta línea mentirá al profesor en la pantalla donde decide comprar. El resto de la página sí deriva todo del backend (`packages`, `amount_pen`, `credits`).

---

## F-17 — `RechargeRequestPolicy` es confusa de leer

> **▶ ESTADO TRAS FASE 3: ✅ CORREGIDO.** Se eliminó `before()` y cada método declara su regla
> con `hasRole('admin')`. El comportamiento es idéntico; lo que cambia es que ya no depende de
> recordar que existe un hook. Tests explícitos admin/teacher/parent × approve/reject/reverse en
> `FinancialConcurrencyTest`.

**Severidad:** 🟢 LOW (mantenibilidad; **no** es un bug)
**Archivo:** `app/Policies/RechargeRequestPolicy.php`

`before()` concede todo a `admin`; después `approve()`/`reject()`/`reverse()` devuelven `false`. Funciona correctamente — `before()` cortocircuita antes de llegar al método — pero leído de forma aislada, `approve()` parece denegar la acción a todo el mundo. Dado el historial de MOVA con Policies (ver FP-04), la legibilidad aquí tiene valor defensivo real.

---

# FALSOS POSITIVOS VERIFICADOS

Hipótesis del brief que se investigaron y **no** se confirmaron. Se documentan para que no se re-auditen.

**FP-01 — "El ledger podría mutarse con `UPDATE`/`DELETE` desde código privilegiado"**
Búsqueda global de mutaciones sobre `CreditTransaction` en `app/`: **cero ocurrencias**. Los únicos `->delete()` del código son `DiagnosticRecommendationService:32` (recomendaciones), `ClassOfferController:135`, `StudentController:64`, y `ProfileController:104-141` (tokens/sesiones/usuario). **El ledger es efectivamente append-only en la práctica.** *(La amenaza real al historial no es la mutación de filas sino el cascade — ver F-01.)*

**FP-02 — "`Admin/Lessons.vue` ejecuta acciones destructivas sin confirmación"**
Falso. `resources/js/Pages/Admin/Lessons.vue:147-211` es la **mejor** superficie destructiva de la app: modal dedicado, advertencia explícita del efecto financiero por acción (*"Esto consumirá el crédito reservado del profesor como si la clase se hubiera dictado"*, `:161`), motivo obligatorio de ≥5 caracteres validado en cliente (`:197-200`), y guard `submitting` (`:201, :208`). Es el patrón que las demás superficies deberían copiar.

**FP-03 — "`confirmPayment()` esconde efectos financieros"**
Falso. Auditado línea a línea (ver F-11): cambia un estado, escribe un `ClassEvent` y notifica. No toca créditos ni ledger. Su transacción y su recomprobación bajo lock son correctas.

**FP-04 — "Las Policies podrían tener más huecos como el de `is_verified`"**
No se encontró ninguno equivalente. `ClassRequestPolicy::accept()` (`:53-84`) sí comprueba `is_verified`, con un comentario extenso documentando la auditoría del 2026-08-22 que lo detectó. Las 7 Policies están todas mapeadas en `AuthServiceProvider:623-631` (ninguna huérfana), y todas usan el mismo patrón `before()`. **Advertencia:** esta comprobación fue de lectura estática; no sustituye a pruebas de IDOR reales por endpoint (ver GAP-02).

**FP-05 — "MOVA tiene cero tests de frontend"** *(afirmación errónea de mi propio documento de Fase 1 — se corrige aquí)*
**Falso.** Existe `qa/` con **Playwright** configurado (`playwright.config.js`, `playwright.local.config.js`, `global-setup.js`) y `qa/tests/flujo-completo.spec.js`, que ejercita el flujo end-to-end incluyendo la verificación de `status === 'paid'` (`:253`). La afirmación de Fase 1 se basó en inspeccionar solo `tests/` y era incorrecta. La cobertura E2E es estrecha (un único spec) pero **no es cero**.

---

# UNKNOWN / REQUIERE VALIDACIÓN EN EL MUNDO REAL

| ID | Pregunta abierta | Por qué no se puede resolver desde el repositorio |
|---|---|---|
| UNKNOWN-01 | ¿Está `DIAGNOSTIC_AI_ENABLED` activo en producción? | Determina si F-05 es una exposición real o solo de diseño. Requiere leer el `.env` de producción. |
| UNKNOWN-02 | ¿Existe algún cron **fuera** del repositorio que ejecute `mova:settle-lessons` sin `--dry-run`? | Si existiera, F-02 se degrada a LOW. Requiere acceso al panel de Railway. |
| UNKNOWN-03 | ¿Cómo trata JaaS un `exp` que vence durante una llamada en curso? | Bloquea la recomendación de F-06. Requiere prueba contra JaaS real. |
| UNKNOWN-04 | ¿`APP_DEBUG=false` en producción? | No se leyó el `.env` real. |
| UNKNOWN-05 | ¿Cuántos workers de cola corren simultáneamente? | Determina la probabilidad real de F-04. Con un solo worker el riesgo baja mucho. |
| UNKNOWN-06 | ¿Usa JaaS `jitsi_password` en algún flujo? | Bloquea la recomendación de F-07. |

---

# GAPS DE COBERTURA DE TESTS

| ID | Gap | Riesgo asociado |
|---|---|---|
| GAP-01 | Ningún test inspecciona `Kernel::schedule()` | F-02 pasó desapercibido pese a 248 tests verdes |
| GAP-02 | Ninguna prueba sistemática de IDOR por endpoint (`GET /lessons/{id_ajeno}` con cada rol) | Las Policies se verificaron por lectura, no por ejercicio |
| GAP-03 | Ninguna prueba de concurrencia con procesos paralelos reales | F-04 no es detectable por la suite actual |
| GAP-04 | Ningún test de que borrar un usuario preserve el ledger | F-01 |
| GAP-05 | Ningún test de que producción + proveedor Fake sea imposible | F-03 |
| GAP-06 | `anonymize()` sin tests con entradas adversas | F-05 |
| GAP-07 | `sendUnansweredRequestAlerts()` sin cobertura | F-10 |
| GAP-08 | Cobertura E2E limitada a un único spec de Playwright | Frontend en general |

---

# PRIORIZACIÓN SUGERIDA PARA LA FASE 3

Estrictamente **diagnóstico** — no se ha modificado nada.

1. **Decidir sobre F-02** (C-1 en `--dry-run`). Es una decisión de negocio, no técnica, y bloquea la afirmación de "listo para producción". Empezar por ejecutar el dry-run real y leer su salida.
2. **F-03** (proveedores fail-open). Es el arreglo de menor riesgo y mayor retorno: un guard de arranque, sin tocar lógica existente.
3. **F-01** (cascade del ledger). Alto impacto, requiere migración cuidadosa y decisión de retención. Debe pasar por revisión financiera explícita según CLAUDE.md.
4. **F-05** (anonimización IA). Si UNKNOWN-01 resulta ser "activo", sube a CRITICAL por tratarse de datos de menores.
5. **F-04** (race de recordatorios). El patrón correcto ya existe en el mismo archivo; es replicarlo.
6. El resto por severidad.

**No mezclar fases.** F-11 (semántica de `paid`) y F-09 (`in_progress`) son decisiones de producto, no correcciones — abordarlas junto a los bugs mezclaría categorías que este documento separa deliberadamente.
