# MOVA — Fase 5: cierre de gaps

**Fecha:** 2026-08-24
**Objetivo:** cerrar todo lo que sigue bajo control del repositorio, sin refactors nuevos de backend salvo que un hallazgo real lo exija.

**Hallazgo nuevo de esta fase: F-20** — el worker de producción tenía `--timeout` **igual** a `retry_after`, lo que permite que un job se ejecute en dos workers a la vez.

---

## 0. Baseline real

| Comando | Resultado |
|---|---|
| `php artisan test` | **406 passed** (1193 assertions) |
| `php artisan migrate:status` | 73/73 `Ran` |
| `php artisan route:list --except-vendor` | 106 rutas |
| `npm run build` | limpio |
| `mova:reconcile-ledger --json` | `healthy: true` |
| `mova:reconcile-whatsapp --json` | `healthy: true` |
| `mova:health-check --json` | `healthy: true` |
| `git diff --check` | limpio |

Progresión honesta del conteo: 248 → 364 → 375 → **406**.

---

## 1. F-20 — NUEVO: colisión de timeout en la cola

Preguntaste quién ejecuta el worker y el scheduler. **La respuesta estaba en el repositorio y yo no había mirado**: `railway.queue.toml`, `railway.scheduler.toml`, `railway.toml` y `docs/DEPLOY_RAILWAY.md` lo documentan todo.

Al mirarlos apareció un problema real:

```
railway.queue.toml:  queue:work ... --timeout=90
config/queue.php:    retry_after => 90
```

**Iguales.** Laravel exige que `retry_after` sea **estrictamente mayor** que el timeout del job. Con valores idénticos, un job que se acerca a su límite puede liberarse a la cola y ser recogido por un **segundo worker mientras el primero sigue ejecutándose** — el mismo WhatsApp o correo sale dos veces.

Peor: `docs/DEPLOY_RAILWAY.md` documentaba `--timeout=60`. **El `.toml` se había desviado de su propia documentación** sin que nada lo detectara — el mismo patrón que F-14 (código y documentación divergiendo en silencio).

**Corregido** a `--timeout=60` (30 s de margen, coincide con la documentación). Y `mova:health-check` ahora **lee el `.toml`** y compara con `retry_after`, porque ese valor no vive en la config de Laravel: es un argumento de proceso, y por estar en otro sitio se desvió sin ser visto.

**Estado: ✅ FIXED**

### Resto de la auditoría de colas

| Pregunta | Respuesta |
|---|---|
| ¿Quién ejecuta el worker? | `railway.queue.toml` → servicio propio, `restartPolicyType = ON_FAILURE`, hasta 10 reintentos |
| ¿Quién ejecuta `schedule:run`? | `railway.scheduler.toml` → servicio propio, bucle `while true … sleep 60` |
| ¿Reintentos? | `--tries=3 --backoff=5` |
| ¿Si el worker muere? | Railway lo reinicia (política ON_FAILURE); los jobs son filas en `jobs`, sobreviven |
| ¿`failed_jobs`? | Driver `database-uuids`. **Ahora vigilado**: `mova:health-check` avisa si crece |
| ¿Backlog? | **Ahora vigilado**: el health-check reporta pendientes/fallidos |
| ¿Cuántos workers? | ⏳ **BLOQUEO EXTERNO** — depende del escalado configurado en Railway, no del repositorio |
| ¿Alertas? | ❌ No hay. `mova:health-check` existe pero nadie lo ejecuta automáticamente |

---

## 2. Consentimiento de WhatsApp — cerrado

Tenías razón en que era un gap real: `WhatsAppChannel` solo comprobaba `phone_verified_at`, que responde *«controla este número»*, no *«aceptó que le escribamos»*.

**Implementado:** `whatsapp_opt_in_at` + `whatsapp_opt_out_at` (dos marcas de tiempo, no un booleano: hay que poder responder *cuándo* lo aceptó y *cuándo* lo revocó).

**La distinción que insististe en preservar, y que los tests protegen:**

| Mensaje | ¿Requiere consentimiento? | Por qué |
|---|---|---|
| **OTP de verificación** | ❌ NO | Lo pide el propio usuario y no pasa por `WhatsAppChannel`. Bloquearlo impediría el paso donde se obtiene el consentimiento |
| **Notificaciones** (recordatorios, confirmaciones) | ✅ SÍ | Las inicia MOVA |

Dos tests fijan explícitamente que el OTP sale **sin consentimiento previo** y **también tras darse de baja** — es seguridad, no marketing.

**Detalles de diseño:**
- El backfill marca como aceptado a quien ya tenía el teléfono verificado: esas personas completaron el flujo **por WhatsApp**, es decir recibieron un mensaje nuestro y actuaron sobre él. Tratarlas de golpe como no consentidas las dejaría sin recordatorios sin avisarles.
- Volver a verificar el teléfono **no resucita** un consentimiento revocado.
- Un `opt_out` gana siempre sobre un `opt_in` anterior: es la última voluntad expresada.
- UI en `Profile/Edit` con texto explícito de que **no afecta al código de verificación**.

**Tests:** `WhatsAppConsentTest` (14). **Estado: ✅ FIXED**

---

## 3. Matriz campo-por-campo hacia IA — cerrada, y con un fallo mío corregido

Pediste una matriz. La hice **ejecutable**, no una tabla en un documento: `AiPayloadContractTest` captura el payload HTTP real y comprueba campo a campo. Si alguien añade un campo al prompt, el test falla.

| Campo | ¿PII? | ¿Menor? | ¿Se envía? | Tratamiento |
|---|---|---|---|---|
| `id`, `parent_user_id`, `student_id`, `subject_id` | sí (ids) | sí | **NO** | Nunca salen |
| `subject.name` | no | no | sí | Se envía el nombre, no el id |
| `level`, `goal`, `urgency` | no | no | sí | Enums cerrados |
| `difficulty_text` | **SÍ** | **SÍ** | sí | **Redactado** (best-effort) |
| `school_feedback` | **SÍ** | **SÍ** | **NO** | Verificado, no solo prometido |
| `status`, `ai_*`, timestamps | no | no | **NO** | Los `ai_*` son la respuesta, no la entrada |

**Un fallo grave en mi propio test, corregido:** el cuerpo sale JSON-encoded con escapes ASCII, así que `"Joaquín"` viaja como `"Joaquín"`. Mis `assertStringNotContainsString('Joaquín', ...)` **habrían pasado aunque el nombre estuviera presente** — un falso negativo que habría hecho inútil todo el archivo. Ahora se decodifica antes de comprobar.

**Terminología corregida en toda la documentación:** «redacción de identificadores conocidos» / «reducción de PII», nunca «anonimización garantizada».

**Estado: 🟡 MITIGATED** (no FIXED: la redacción sigue siendo best-effort y los identificadores indirectos escapan por diseño).

---

## 4. F-18 — Matriz de relaciones validada

Pediste clasificar las cinco relaciones, no aceptar «puse `withTrashed()`». Verificado consumidor por consumidor:

| Relación | `withTrashed` | Consumidores reales | Riesgo de privacidad |
|---|---|---|---|
| `Lesson::student()` | ✅ | Notificaciones a padre (`AdminController:208,270`), listados de profesor, admin | Ninguno: la fila superviviente está anonimizada |
| `ClassRequest::student()` | ✅ | Notificaciones (`ClassRequestController:225`), listados | Ninguno |
| `LessonReport::student()` | ✅ | Reportes históricos | Ninguno |
| `StudentDiagnostic::student()` | ✅ | Diagnósticos | Ninguno |
| `TeacherReview::student()` | ✅ | **El perfil público NO carga esta relación** — `TeacherPublicController:31` selecciona `['id','rating','comment','created_at']` explícitamente | **Ninguno, verificado** |
| `User::students()` | ❌ **sin** | Lista activa del padre | Debe ocultar al dado de baja |

Comprobación adicional: `withTrashed()` **sí propaga** a través de `whereHas` (verificado inspeccionando el SQL generado, sin `deleted_at` en la subconsulta). Eso significa que un padre que dio de baja a su alumno sigue viendo el código de referido del profesor con quien tuvo una clase completada — correcto, la relación existió de verdad.

**Estado: ✅ FIXED**

---

## 5. El analizador de frontend ahora tiene tests propios

Insististe en esto y tenías razón: una herramienta que se usa para afirmar «el frontend está auditado» no puede equivocarse en silencio.

La lógica se extrajo a `App\Support\FrontendAuditor` con `tests/fixtures/frontend-audit/` y 8 tests. Cada fixture reproduce uno de los engaños que la versión anterior no supo resolver:

| Fixture | Bug que reproduce |
|---|---|
| `comment-trap.vue` | Un `confirm()` mencionado **solo en un comentario** contaba como diálogo nativo |
| `comment-trap.vue` | El regex de comentarios de bloque sin escapes (`/*` = "cero o más barras") **vaciaba el archivo entero** |
| (inline) | `loading` no estaba en la lista de guards |
| `native-dialog.vue` | Contrapartida: al arreglar los falsos positivos, el caso real debe seguir detectándose |

**Estado: ✅ FIXED**

---

## 6. F-19 ampliado — dos hallazgos más

Con el analizador ya fiable, el barrido encontró:

- **`Dashboard/Parent.vue::confirmPayment()` sin `onError`** — y la **misma acción** en `Lessons/ParentIndex.vue` sí lo tenía. Un 422 (p. ej. la clase aún no ha terminado) dejaba al padre pulsando un botón que no hacía nada. Corregido, con el error visible.
- `Profile/Partials/NotificationPreferencesForm.vue` — falso positivo: usaba `saving`, que faltaba en mi lista de guards. Añadido.

**Único flag restante:** `Auth/VerifyEmail.vue` — patrón estándar de Breeze (prop `status` de sesión + `form.processing`). Reenviar un correo de verificación es de bajo riesgo y el usuario puede reintentar. **Aceptado, no corregido.**

---

## 7. F-02 — Dry-run ejecutado y documentado

```
--dry-run: no se escribirá nada.
Consumo automático (gracia: 7d, corte: 2026-08-18) — candidatas: 0
Escalado a revisión (plazo: 7d, corte: 2026-08-18) — candidatas: 0
```

Estado de la base local: 2 clases `scheduled`, 2 `completed`, 2 créditos reservados.

**Clasificación: SAFE TO ENABLE en este entorno** — cero candidatas, así que activar `live` aquí no tendría ningún efecto.

**Pero esto NO autoriza producción.** Esta es la base de desarrollo, con 4 clases. La decisión exige ejecutar el mismo dry-run **contra producción** y revisar sus candidatas reales.

**Procedimiento, en el orden que planteaste:**

1. `php artisan mova:settle-lessons --dry-run` **en producción**
2. Revisar cada lección listada — ¿es correcto consumir su crédito?
3. `php artisan mova:reconcile-ledger --json` → debe estar `healthy`
4. Poner `LESSON_SETTLEMENT_MODE=live`
5. Ejecutar `mova:settle-lessons` una vez, a mano, observando
6. `mova:reconcile-ledger` de nuevo
7. Dejar que el scheduler siga; vigilar `mova:health-check`

**Estado: ⏳ BUSINESS DECISION** — el paso 1 no lo puedo dar yo.

---

## 8. Clasificación final

### ✅ FIXED
F-03 (proveedores fail-closed) · F-04 (claim recuperable + semántica honesta) · F-07 (`jitsi_password`) · F-08 (UI `reversal`) · F-09 (`in_progress`) · F-10 (destinatarios) · F-11 (semántica de `paid` documentada) · F-12 (doble envío) · F-13 (diálogos nativos) · F-14 (Twilio + política de privacidad) · F-15 (docs JaaS) · F-16 (precio) · F-17 (Policy) · F-18 (borrado de alumno + regresión) · F-19 (errores visibles) · **F-20 (colisión de timeout)** · WhatsApp opt-in · Tests del analizador · GAP-01/02/03/04/05/06/07

### 🟡 MITIGATED
- **F-01** — FK RESTRICT verificadas en MySQL + guard de aplicación. Pendiente: inventario FK completo (`student_diagnostics`, `lesson_reports`, `diagnostic_recommendations` siguen en CASCADE sin auditar caso por caso).
- **F-05** — Reducción material de PII, **no** anonimización garantizada. Los identificadores indirectos (*«el hijo de la profesora de San Agustín»*) escapan por diseño. La IA sigue apagada por defecto.
- **GAP-08** — Inventario completo (53 páginas), acciones mutantes con evidencia, guards, errores, estados vacíos y gateo por rol. **Falta**: verificación visual página por página, matriz de estados HTTP (403/404/419/422/500, sesión expirada, fallo de red), accesibilidad, móvil.

### ⏳ BUSINESS DECISION
- **F-02** — Ver §7. Mientras `LESSON_SETTLEMENT_MODE=dry_run`, **C-1 no está operativo**.

### ⏳ EXTERNAL VALIDATION
- **F-06** — Smoke test contra JaaS real. La ventana está bien implementada y probada, pero no se ha verificado cómo trata JaaS un `exp` que vence con la llamada en curso (por eso la gracia es de 2 h, deliberadamente generosa).
- **UNKNOWN-01** — ¿`DIAGNOSTIC_AI_ENABLED` activo en producción?
- **Escalado de workers** — Cuántos workers corren en Railway no está en el repositorio.

### ❌ OPEN
- **Verificación visual del frontend** — 53 páginas × estados × breakpoints. Fase propia.
- **Accesibilidad** — labels, foco, focus-trap de modales, contraste, semántica. No auditada; se han añadido varios modales que la necesitan.
- **Móvil** — 360/390/768/1024 px. No probado.
- **Estado obsoleto entre pestañas** (tu punto 23) — no auditado.
- **Back/refresh tras acciones financieras** (tu punto 22) — no auditado.
- **Suite de integración MySQL** — la integridad referencial del ledger no es observable desde SQLite.
- **Alertas de producción** — `mova:health-check` existe pero nadie lo ejecuta automáticamente.

---

## 9. Estado de MOVA

```
LEDGER / IDEMPOTENCIA        ████████████████████  maduro, concurrencia real probada
AUTORIZACIÓN                 ███████████████████░  IDOR probado; falta matriz visual
PRIVACIDAD (menores + IA)    ██████████████░░░░░░  contrato ejecutable; redacción best-effort
COLAS / OPERACIÓN            ███████████████░░░░░  F-20 corregido; falta alertas y escalado
WHATSAPP                     ████████████████░░░░  arquitectura + consentimiento; falta Meta real
CULQI                        ██████████████░░░░░░  preparado, sin proveedor
JaaS                         ███████████████░░░░░  ventana correcta; falta smoke test
C-1 SETTLEMENT               ████████░░░░░░░░░░░░  código listo, apagado por decisión
FRONTEND                     ███████████████░░░░░  acciones auditadas; falta visual/a11y/móvil
```

**No digo «production ready».** Hay un bloqueo de negocio (F-02), dos de validación externa (F-06, Meta/Culqi) y una superficie sin cubrir (frontend visual, accesibilidad, móvil).

---

## 10. Lo que esta fase confirma

El patrón se repitió otra vez: **el hallazgo nuevo salió de mirar donde no había mirado**, no de escribir más tests. F-20 estaba en un `.toml` del repositorio que llevaba cinco fases sin abrir, y su valor contradecía su propia documentación.

Y por segunda vez, **una herramienta mía tenía un fallo que la habría hecho mentir**: los assertions del contrato de IA pasaban por comparar contra texto sin decodificar. Un test que pasa por el motivo equivocado es peor que no tenerlo.

La regla que sigue funcionando:

```
corregir → buscar globalmente → probar → verificar la propia herramienta → reauditar
```
