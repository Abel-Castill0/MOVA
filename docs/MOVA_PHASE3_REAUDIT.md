> **HISTORICAL SNAPSHOT** — verify against `docs/MOVA_SYSTEM_MAP.md` and current code.
> Documento de una fase anterior. Conservado como registro de decisiones, NO como
> descripcion fiable del comportamiento actual.

# MOVA — Reauditoría post-Fase 3

**Fecha:** 2026-08-24
**Premisa:** no confiar en el reporte de la Fase 3. Verificar código, base de datos, tests y comportamiento directamente.

**Resultado corto:** tres findings estaban **mal cerrados** (F-04, F-12, F-14), uno se reclasifica por honestidad (F-05), y apareció uno **nuevo** (F-18).

---

## 0. Baseline real

La Fase 3 mencionó 324 y luego 346. Ninguno es el actual. Ejecución limpia ahora:

| Comando | Resultado |
|---|---|
| `php artisan test` | **364 passed** (1106 assertions) |
| `php artisan migrate:status` | **72/72 `Ran`** |
| `php artisan route:list --except-vendor` | 105 rutas |
| `npm run build` | limpio |
| `mova:reconcile-ledger --json` | `healthy: true` |
| `mova:reconcile-whatsapp --json` | `healthy: true` |
| `mova:health-check --json` | `healthy: true` · `settlement_mode: dry_run` · `queue_driver: database` |
| `git diff --check` | limpio |

**364 es el número oficial.** Los anteriores eran instantáneas a mitad de sesión.

---

## 1. F-04 — Estaba MAL CERRADO. Tenías razón.

Esta era tu preocupación técnica principal y era correcta.

**Lo que había tras la Fase 3:**
```
UPDATE reminder_24h_sent_at = now()   ← claim, fuera de transacción
        ↓
notify()                              ← si falla aquí…
        ↓
… el marcador queda consumido y el barrido filtra por whereNull
        → el recordatorio se pierde PARA SIEMPRE
```

El propio comentario del código lo asumía: *"Marcar-antes-de-enviar es deliberado: preferimos perder un recordatorio ante un fallo de despacho a enviarlo dos veces."* Cambiar un duplicado por una pérdida permanente no es buen intercambio, y aquí **era evitable**.

**Dato que cambia el análisis:** las **21** clases de `App\Notifications` implementan `ShouldQueue` (verificado por reflexión, no leyendo). Es decir, `notify()` **no hace ninguna llamada externa**: inserta filas en la tabla `jobs`. Con `QUEUE_CONNECTION=database` esas filas viven en la **misma** base de datos.

**La corrección:** envolver claim + despacho en una transacción.

```
DB::transaction:
    UPDATE condicional (el motor decide quién gana la carrera)
    notify()  ← solo INSERT en `jobs`, misma conexión, cero red
COMMIT   -> exactamente una vez
ROLLBACK -> marcador liberado, se reintenta en la próxima pasada
```

Esto satisface a la vez las dos reglas que planteaste: **no perder el aviso** y **no mantener una transacción abierta durante una llamada externa** — porque no hay llamada externa dentro.

**Aplicado a los cinco barridos**, incluido `sendPendingReportAlerts()`, que también notificaba después de cerrar su transacción.

**Tests (`ReminderClaimRecoveryTest`, 10):** inyectan un `ChannelManager` que revienta al notificar y comprueban que el marcador queda **liberado** en los cinco casos, y que la siguiente pasada **sí entrega**. Dos son guardianes de la premisa: que toda notificación siga siendo `ShouldQueue`, y que `mova:health-check` avise si alguien cambia a un driver de cola que rompa la atomicidad.

**Dependencia declarada:** `database` y `sync` son compatibles (con `sync` el envío es inline y también entra en el rollback). `redis`/`sqs` **romperían** la premisa; `mova:health-check` lo reporta como `QUEUE_NOT_TRANSACTIONAL`.

**Estado: ✅ FIXED** — ahora sí.

---

## 2. F-14 — También estaba MAL CERRADO, y lo peor era legal

La Fase 3 declaró "corregido" tras borrar **un** archivo. La re-búsqueda encontró bastante más, incluido algo que no es cosmético:

| Ubicación | Qué decía | Gravedad |
|---|---|---|
| `resources/js/Pages/Legal/Privacy.vue:66` | **La política de privacidad pública nombraba a Twilio** como el tercero que recibe teléfonos y mensajes | 🔴 **Cumplimiento** — nombra al procesador de datos equivocado, y MOVA maneja datos de menores |
| `qa/package.json:12` | Script `check:twilio` apuntando al archivo que la Fase 3 borró | Script roto |
| `phpunit.xml:36-38` | Variables `TWILIO_*` muertas | Config residual |
| `SETUP.md:10,105` | Instruía a crear cuenta de Twilio y unirse a su Sandbox | Desorienta a quien llega nuevo |
| `MOVA_MASTER_CONTEXT.md` §3.1 + tabla de dependencias | Documentaba `twilio/sdk ^8.11` como integración vigente | Documentación falsa |
| `.telemetry/product.md:32,48` | "WhatsApp: Twilio Sandbox" | Documentación falsa |

Todo corregido. El procesador real es **Meta (WhatsApp Business Cloud API)**, y la política de privacidad ahora lo dice y detalla qué recibe.

Las dos menciones a Twilio que quedan en `MOVA_MASTER_CONTEXT.md` son la nota histórica explícita de la migración — correcto conservarlas.

**Estado: ✅ FIXED** — esta vez con búsqueda global, no borrando un archivo.

---

## 3. F-12 — Mal cerrado también

Búsqueda global de páginas con acciones mutantes sin guard de envío. Aparecieron dos que la Fase 3 no tocó:

- **`ClassRequests/TeacherIndex.vue`** — la más importante: es la pantalla donde el profesor **acepta** una solicitud, y aceptar **crea una `Lesson` y reserva créditos**. Doble clic en una acción financiera.
- **`Admin/Reviews.vue`** — moderación de reseñas (ocultar/mostrar).

Ambas tienen ahora `rejecting` / `moderating`, botones deshabilitados y texto de progreso.

**Estado: ✅ FIXED**

---

## 4. F-18 — Hallazgo NUEVO

Buscando caminos de borrado masivo que se saltaran el guard de `User` (tu observación de que `Model::deleting` no intercepta `where()->delete()`) apareció un problema un nivel más abajo.

**`StudentController::destroy()` hacía `$student->delete()` físico, sin comprobación alguna.** Con historial académico:
- **MySQL**: `classes.student_id` es `RESTRICT` → excepción cruda de FK → **error 500 al padre**.
- **SQLite (tests)**: las FK conservan el `CASCADE` original → **las clases del alumno se borraban de verdad**, y ningún test lo detectaba.

Las clases respaldan movimientos del ledger, así que ninguno de los dos comportamientos es aceptable.

**Corrección:** soft delete en `students` + anonimización de los datos personales del menor antes de borrar + guard contra `forceDelete()` con historial. Así el alumno **desaparece de la lista del padre** (la promesa de la UI se cumple), la fila sobrevive para sostener el historial, y el nombre, la fecha de nacimiento y el colegio del menor se sustituyen.

Migración `2026_08_24_000003_add_soft_deletes_to_students_table`, ciclo up → rollback → reapply verificado sobre **MySQL real**.

**Tests: `StudentDeletionIntegrityTest` (8).**

**Estado: ✅ FIXED**

---

## 5. F-01 — Verificación de tu observación sobre bulk deletes

Tenías razón en que `Model::deleting` no protege `User::where(...)->delete()`.

**Búsqueda global de todos los `->delete()` en `app/`:**

| Ubicación | Qué borra | ¿Riesgo? |
|---|---|---|
| `ProfileController:144` | `$user->delete()` | Pasa por el guard ✅ |
| `ProfileController:107-108` | tokens, sesiones | No financiero ✅ |
| `StudentController:64` | `$student->delete()` | **Era el hueco — ver F-18** |
| `ClassOfferController:135` | oferta | No financiero ✅ |
| `DiagnosticRecommendationService:32` | recomendaciones | Derivado ✅ |

**Cero bulk deletes sobre `User` o `TeacherProfile`** en todo `app/`. No hay ningún camino que evite el guard hoy — pero la defensa real sigue siendo el `RESTRICT` de MySQL, que es lo que protege contra SQL directo.

**Estado: 🟡 MITIGATED.** Sigue pendiente el inventario FK completo (`student_diagnostics`, `lesson_reports`, `diagnostic_recommendations` siguen en `CASCADE` sin auditar caso por caso).

---

## 6. F-05 — Reclasificado, como pediste

La Fase 3 lo etiquetó ✅ FIXED. **Es incorrecto y lo cambio a 🟡 MITIGATED.**

Tienes razón: ningún sistema léxico puede garantizar que un texto libre no identifique a un menor. Tus ejemplos lo ilustran bien — *"El hijo de la profesora de San Agustín"* no contiene ningún nombre y aun así puede reidentificar. Los identificadores indirectos (colegio, curso, profesor, hermano, fecha) escapan a cualquier regex por diseño.

Lo que sí es cierto: la exposición se redujo mucho (nombre de pila solo, inicio de texto, minúsculas tras marcador de parentesco, teléfonos, emails, DNI, URLs — 18 tests adversariales), el docblock ya no promete lo que no cumple, y el límite está **probado explícitamente** como test.

**La distinción correcta es la tuya: "reducción material de PII", no "garantía de anonimización".** La protección real sigue siendo la minimización más el feature flag (`DIAGNOSTIC_AI_ENABLED=false`).

**Estado: 🟡 MITIGATED** — y la arquitectura que propones (extracción local a representación estructurada, sin enviar texto libre) sigue siendo la solución robusta, pendiente.

---

## 7. F-03 — Verificado y mejorado

Tu duda sobre `UnhandledMatchError`: **no ocurre**. El guard intercepta antes. Comprobado ejecutándolo:

```
PAYMENT_PROVIDER="culqui"
→ RuntimeException: Configuración de pagos inválida: PAYMENT_PROVIDER="culqui"
  no es un proveedor soportado. Valores válidos: fake, culqi. ¿Quisiste decir "culqi"?
```

La sugerencia por distancia de edición es nueva, siguiendo tu petición de `Expected: culqi`.

**Estado: ✅ FIXED**

---

## 8. F-11 — Auditados los 40+ usos de `paid`

Clasificación como pediste:

- **Uso legítimo de estado de clase (la gran mayoría):** `SettleLessons`, `LessonSettlementService::CONSUMABLE_STATES/REFUNDABLE_STATES`, `AdminController` (qué estados permiten forzar), `LessonController::join`, `DashboardController`, `Lesson::scopeAwaitingReportWithinGrace`, y el frontend de tarjetas y calendario.
- **Semántica de pago engañosa (2, corregidos):**
  - `LessonReportController` × 3 — el mensaje decía *"Solo se pueden reportar clases con el pago confirmado"*, lo que sugiere que MOVA verificó un pago. Ahora: *"cuyo pago haya confirmado el padre"*.
  - `Dashboard/Parent.vue:156` — rama inalcanzable que además decía *"Disponible 15 min antes de empezar"* sobre una clase que ya terminó.
- **Ninguno trata `paid` como confirmación de un proveedor de pago.** Verificado uno por uno.

Sin refactor de estados, como acordamos.

**Estado: ✅ DOCUMENTED**

---

## 9. GAP-03 — Respuesta honesta a tu pregunta

Preguntaste si los tests de concurrencia ejecutan procesos simultáneos o solo peticiones consecutivas.

**Son consecutivos. No es concurrencia real.** SQLite en memoria, una conexión, un proceso.

Lo que sí demuestran, y no es poco: que la garantía **no depende de la temporización**. El `UNIQUE(idempotency_key)` y el `UPDATE` condicional los evalúa el motor de base de datos, así que un segundo intento no puede duplicar el efecto llegue cuando llegue. Y tras cada escenario se comprueba que `LedgerReconciliation` sigue considerando sano el ledger.

Lo que **no** demuestran: dos procesos compitiendo por el mismo lock de MySQL bajo carga real.

**Estado: 🟡 PARCIAL** — pruebas de serialización, no de paralelismo. Una suite de concurrencia real contra MySQL con procesos paralelos sigue pendiente.

---

## 10. Clasificación final

| ID | Estado | Nota |
|---|---|---|
| F-01 | 🟡 MITIGATED | MySQL ya protegía; guard de app añadido; inventario FK incompleto |
| F-02 | ⏳ BUSINESS DECISION | Código listo. `LESSON_SETTLEMENT_MODE=live` es tuyo |
| F-03 | ✅ FIXED | + sugerencia de typo |
| F-04 | ✅ FIXED | **Rehecho**: claim recuperable |
| F-05 | 🟡 MITIGATED | **Reclasificado**: reducción de PII, no garantía |
| F-06 | 🟡 + ⏳ EXTERNAL VALIDATION | Ventana correcta; falta smoke test JaaS real |
| F-07 | ✅ FIXED | Cero en código activo |
| F-08 | ✅ FIXED | Verificado en navegador |
| F-09 | ✅ FIXED | + doc de telemetría corregida |
| F-10 | ✅ FIXED | + `unique()` contra destinatarios repetidos |
| F-11 | ✅ DOCUMENTED | + 2 mensajes engañosos corregidos |
| F-12 | ✅ FIXED | **Rehecho**: 2 páginas más |
| F-13 | ✅ FIXED | Cero `confirm`/`prompt` reales |
| F-14 | ✅ FIXED | **Rehecho**: incluía la política de privacidad |
| F-15 | ✅ FIXED | |
| F-16 | ✅ FIXED | |
| F-17 | ✅ FIXED | |
| **F-18** | ✅ FIXED | **NUEVO** — borrado de alumno destruía historial |
| GAP-01/02/04/05/06/07 | ✅ | |
| GAP-03 | 🟡 PARCIAL | Serialización, no paralelismo real |
| GAP-08 | ❌ OPEN | Frontend sin auditar página por página |

---

## 11. Lo que sigue abierto

**⏳ Decisión de negocio**
- **F-02** — Ejecuta `php artisan mova:settle-lessons --dry-run`, revisa la salida, y decide si activas `LESSON_SETTLEMENT_MODE=live`. Mientras siga en `dry_run`, **C-1 no está operativo** y MOVA no debería describirse como financieramente lista para producción.

**⏳ Validación externa**
- **F-06** — Smoke test contra JaaS real: emitir un token con ventana corta, entrar, y observar qué hace JaaS cuando el `exp` vence con la llamada en curso. Por eso la gracia por defecto son 2 h.
- **UNKNOWN-01** — ¿`DIAGNOSTIC_AI_ENABLED` está activo en producción? Determina si F-05 fue exposición real o solo de diseño.

**❌ Abierto**
- **GAP-08** — Auditoría frontend página por página, botón por botón. Es la última superficie grande sin cubrir: 55 páginas, 22 componentes. Merece su propia fase.
- **GAP-03** — Concurrencia con procesos paralelos reales contra MySQL.
- **F-01 (resto)** — Inventario FK completo con política explícita por relación.

---

## 12. Lo que esta reauditoría enseña

Tres de diecisiete findings estaban mal cerrados, y los tres por el mismo motivo: **una corrección localizada se dio por buena sin búsqueda global**. F-14 es el caso más claro — se borró un archivo y se declaró resuelto, mientras la política de privacidad pública seguía nombrando al procesador de datos equivocado.

La regla que confirma esto, y que conviene mantener:

```
corregir → grep global → tests → verificación manual → reauditar
```

Ningún paso es opcional, y el resultado de esta ronda es la prueba: sin la re-búsqueda, F-12, F-14 y F-18 seguirían abiertos bajo una etiqueta verde.
