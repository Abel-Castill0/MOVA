> **HISTORICAL SNAPSHOT** — verify against `docs/MOVA_SYSTEM_MAP.md` and current code.
> Documento de una fase anterior. Conservado como registro de decisiones, NO como
> descripcion fiable del comportamiento actual.

# MOVA — Fase 4: regresiones, concurrencia real y frontend forense

**Fecha:** 2026-08-24
**Premisa:** 375 tests verdes no prueban que las correcciones sean correctas. Verificar qué comportamiento NUEVO introdujeron.

**Resultado corto:** la Fase 3 introdujo **una regresión real y grave** (F-18 rompía las notificaciones al padre), la afirmación de F-04 sobre "exactly once" era **falsa**, GAP-03 ya está **cerrado con concurrencia auténtica**, y el barrido del frontend encontró **formularios sin ningún error visible** (F-19).

---

## 0. Baseline real

| Comando | Resultado |
|---|---|
| `php artisan test` | **375 passed** (1130 assertions) |
| `php artisan migrate:status` | 72/72 `Ran` |
| `npm run build` | limpio |
| `mova:reconcile-ledger --json` | `healthy: true` |
| `mova:reconcile-whatsapp --json` | `healthy: true` |
| `mova:health-check --json` | `healthy: true` |
| `git diff --check` | limpio |

---

## 1. F-18 — La Fase 3 introdujo una regresión grave

Predijiste exactamente esto: *"podrías resolver el problema de borrado y crear otro: las clases históricas de un alumno desaparecieron de la interfaz."*

**Verificado empíricamente contra MySQL real, no razonado:**

```
lesson 1 -> student: Mateo Prueba
tras soft-delete -> student: NULL          <-- REGRESIÓN
parent alcanzable: NO                      <-- NOTIFICACIÓN PERDIDA
```

Al añadir `SoftDeletes` a `Student`, `$lesson->student` empezó a devolver `NULL` en cuanto el padre daba de baja al alumno. **~20 ubicaciones** lo consumen. La consecuencia más grave no era visual:

```php
$lesson->student?->parent?->notify($notification);   // AdminController:208, 270
```

Ese patrón —usado al **cancelar** y al **forzar devolución**— dejaba de encontrar destinatario. **El padre dejaba de recibir avisos financieros sin ningún error visible.**

**Corrección:** `withTrashed()` en las cinco relaciones históricas hacia `Student` (`Lesson`, `ClassRequest`, `LessonReport`, `StudentDiagnostic`, `TeacherReview`). `User::students()` deliberadamente **no** lo lleva: esa es la lista activa del padre y debe seguir ocultando al alumno dado de baja.

Respondiendo a tu pregunta sobre qué necesita cada relación:

| Relación | Decisión | Motivo |
|---|---|---|
| `Lesson::student()` | `withTrashed()` | Registro histórico que respalda el ledger |
| `ClassRequest::student()` | `withTrashed()` | Historial académico |
| `LessonReport::student()` | `withTrashed()` | Historial académico |
| `StudentDiagnostic::student()` | `withTrashed()` | Historial académico |
| `TeacherReview::student()` | `withTrashed()` | La reseña sobrevive al alumno |
| `User::students()` | **sin** `withTrashed()` | Lista activa: debe ocultar al dado de baja |

**Tests:** 4 nuevos en `StudentDeletionIntegrityTest` (12 en total), incluido uno que verifica que el profesor sigue viendo la clase histórica y otro que el padre sigue siendo notificable.

**Estado: ✅ FIXED** (regresión corregida y fijada con tests).

---

## 2. F-04 — La afirmación "exactly once" era falsa

Tenías razón. La Fase 3 escribió `COMMIT → exactamente una vez`. Eso describe **una** de las tres capas y engaña sobre las otras dos.

**La semántica real, ahora documentada en el código:**

| Nivel | Garantía | Por qué |
|---|---|---|
| 1. Claim + encolado | **exactly-once** | Marcador y job comparten transacción |
| 2. Proceso del job | **at-least-once** | Si el worker muere, la cola reintenta (`retry_after=90s`) |
| 3. Entrega externa | **at-least-once** | Meta acepta → worker muere → reintento → **usuario recibe el aviso dos veces** |

El escenario que señalaste —Meta acepta, el worker cae antes del ACK— **no lo resuelve el claim, y ninguna transacción puede**: el efecto ya salió del sistema.

Lo que sí se logró: eliminar el modo de fallo grave (marcador consumido sin nada encolado → aviso perdido para siempre) a cambio de un duplicado posible y poco frecuente. Ese intercambio es correcto; afirmar "exactly-once" no lo era.

**Mitigación del duplicado:** `whatsapp_messages` tiene `UNIQUE(provider, provider_message_id)`, así que un reenvío es **detectable a posteriori** aunque no prevenible. Documentado.

**Tests:** `ReminderDeliverySemanticsTest` (7), incluido el crash del worker tras el commit — verifica que el marcador **no** se reabre, porque reabrirlo duplicaría el aviso por diseño. Uno de los tests falla si alguien vuelve a escribir "exactamente una vez" en ese archivo.

**Acoplamiento declarado:** el mecanismo exige que la cola comparta la conexión (`database` o `sync`). `redis`/`sqs` lo romperían y `mova:health-check` lo reporta como `QUEUE_NOT_TRANSACTIONAL`. Es un acoplamiento real de arquitectura, documentado como pediste, no eliminado.

**Estado: ✅ FIXED** con semántica honesta.

---

## 3. GAP-03 — Cerrado con concurrencia AUTÉNTICA

Tenías razón en no aceptar tests consecutivos como pruebas de concurrencia. Ahora hay procesos paralelos de verdad.

**Herramienta nueva:** `mova:concurrency-probe` + `mova:concurrency-verify` + `scripts/concurrency-probe.sh`. Lanza N procesos PHP **independientes** con `&`, cada uno con su propia conexión, contra **MySQL real**. Los locks y los `UNIQUE` los resuelve el motor.

**Resultados con 6 procesos simultáneos:**

| Operación | Resultado | Estado final | Ledger |
|---|---|---|---|
| `accept-lesson` | 1 WON, 5 LOST | 1 lección, 1 reserva, solicitud `accepted` | ✅ sano |
| `settle` | 6 DONE | **1** asiento `consumption` | ✅ sano |
| `refund` | 6 DONE | **1** asiento `refund` | ✅ sano |
| `approve-recharge` | 6 DONE | **1** depósito, 5 créditos | ✅ sano |
| `reminder-claim` | 1 WON, 5 LOST | 1 marcador | ✅ sano |

`settle`/`refund`/`approve-recharge` devuelven `DONE` en los seis porque son **idempotentes**: los seis "tienen éxito", pero el `UNIQUE(idempotency_key)` garantiza un solo asiento. Es exactamente el comportamiento deseado.

**Nota metodológica:** las dos primeras ejecuciones salieron ROJAS por defectos de mi propio escenario (saldo sembrado sin asiento de `deposit`, y una lección sin reserva). `LedgerReconciliation` los detectó correctamente — el reconciliador hizo su trabajo sobre el banco de pruebas antes que sobre el código.

**Estado: ✅ CERRADO** — con la salvedad de que la suite de PHPUnit sigue siendo de serialización; la concurrencia real vive en este script, que hay que ejecutar aparte.

---

## 4. GAP-08 — Barrido forense del frontend

**Herramienta nueva:** `scripts/frontend-audit.php`. Extrae de cada una de las 52 páginas: acciones mutantes con su ruta, guard de doble envío, `:disabled`, manejo de error, estado vacío, uso de modal, y diálogos nativos.

**Inventario:** 52 páginas · 30 con acciones mutantes · 22 de solo lectura · 22 componentes.

**Hallazgo real — F-19: formularios sin ningún error visible.**

| Página | Problema |
|---|---|
| `Students/Edit.vue` | **Cero** visualización de errores: un 422 dejaba al padre ante un formulario que no guardaba, sin explicación |
| `Students/Create.vue` | Igual |
| `ClassOffers/Edit.vue` | Igual |
| `Admin/Reviews.vue` | Sin `onError`: un fallo de moderación era invisible para el admin |
| `Students/Index.vue` | Sin `onError` en el borrado |

Corregidos los cinco con `InputError` / `onError` + mensaje.

**Consistencia frontend↔backend de permisos** (tu punto 18): la navegación está correctamente gateada por rol en `AppLayout.vue:182-212` (admin / teacher / resto). Ninguna ruta de admin aparece en la navegación de otros roles. No se encontró ningún caso de "el frontend ofrece lo que el backend deniega".

**Falsos positivos de mi propio extractor** — vale la pena decirlo porque ilustra el riesgo de fiarse de una herramienta sin verificarla:
- `Diagnostics/Create.vue` "sin guard" → **tenía** guard completo (usa `loading`, que no estaba en mi lista).
- `Students/Index.vue` "diálogo nativo" → solo lo mencionaba un **comentario** que documenta su eliminación.
- 7 listados "sin estado vacío" → todos lo tenían; el regex de comentarios de bloque había perdido sus escapes (`/*` = "cero o más barras") y **vaciaba el archivo entero** antes de analizarlo.

Los tres defectos del extractor están corregidos y el script queda en `scripts/` para reutilizarlo.

**Estado: 🟡 PARCIAL.** Cubierto: inventario completo, acciones mutantes con evidencia, guards, errores, estados vacíos, gateo por rol. **NO cubierto:** verificación visual página por página, matriz completa de estados (403/404/419/422/500, sesión expirada, fallo de red), accesibilidad, y comportamiento móvil. GAP-08 sigue siendo un proyecto propio.

---

## 5. Consistencia de drivers (tu punto 5)

Migraciones que se comportan distinto según el motor:

| Migración | Comportamiento |
|---|---|
| `2026_07_10_000002_protect_monetization_history` | **Solo MySQL** — las FK RESTRICT del ledger NO existen en los tests |
| `2026_07_17_000001_add_payment_states` | MySQL: `ALTER ... ENUM`. SQLite: columna a string plano |
| `2026_08_16_000002_add_needs_admin_review` | Solo MySQL |
| `2026_08_24_000002_remove_in_progress` | Solo MySQL |

**Implicación:** la integridad referencial del ledger **no es observable por la suite**. Por eso el guard de aplicación (`User::deleting`, `Student::forceDeleting`) importa: es la única capa que los tests pueden ejercitar.

Suscribo tu política: los tests críticos de integridad deberían tener una suite de integración contra MySQL. `scripts/concurrency-probe.sh` es el primer paso en esa dirección.

**Estado: 🟡 documentado, suite MySQL pendiente.**

---

## 6. Clasificación final

| ID | Estado | Evidencia |
|---|---|---|
| F-01 | 🟡 MITIGATED | FK RESTRICT en MySQL verificadas + guard de app; cero bulk deletes; inventario FK incompleto |
| F-02 | ⏳ **BUSINESS DECISION** | Código listo; `LESSON_SETTLEMENT_MODE=dry_run` en producción |
| F-03 | ✅ FIXED | `ProviderGuard` + sugerencia de typo verificada en ejecución |
| F-04 | ✅ FIXED | Claim recuperable + semántica de 3 niveles documentada; 17 tests |
| F-05 | 🟡 MITIGATED | Reducción de PII, **no** anonimización garantizada |
| F-06 | ⏳ **EXTERNAL VALIDATION** | Ventana correcta y probada; falta smoke test JaaS |
| F-07 | ✅ FIXED | Cero en código activo |
| F-08 | ✅ FIXED | Verificado en navegador |
| F-09 | ✅ FIXED | Enum + frontend + docs |
| F-10 | ✅ FIXED | Destinatarios unificados + `unique()` |
| F-11 | ✅ DOCUMENTED | 40+ usos auditados; 2 mensajes engañosos corregidos |
| F-12 | ✅ FIXED | Barrido completo de 30 páginas mutantes |
| F-13 | ✅ FIXED | Cero diálogos nativos |
| F-14 | ✅ FIXED | Incluida la política de privacidad pública |
| F-15/16/17 | ✅ FIXED | |
| F-18 | ✅ FIXED | **Regresión de Fase 3 detectada y corregida** |
| **F-19** | ✅ FIXED | **NUEVO** — 5 superficies sin error visible |
| GAP-01/02/04/05/06/07 | ✅ | |
| GAP-03 | ✅ **CERRADO** | Concurrencia real, 5 operaciones, 6 procesos, MySQL |
| GAP-08 | 🟡 PARCIAL | Inventario y acciones con evidencia; falta verificación visual y matriz de estados |

---

## 7. Lo que sigue abierto

**⏳ Decisión de negocio**
- **F-02** — `php artisan mova:settle-lessons --dry-run`, revisar, decidir. Mientras siga en `dry_run`, C-1 **no está operativo**.

**⏳ Validación externa**
- **F-06** — Smoke test JaaS con ventana corta.
- **UNKNOWN-01** — ¿`DIAGNOSTIC_AI_ENABLED` activo en producción?

**🟡 Aceptado con conocimiento**
- **F-05** — Redacción best-effort. Los identificadores indirectos (*"el hijo de la profesora de San Agustín"*) escapan por diseño. La minimización estructural sigue siendo la solución robusta.
- **F-04** — Duplicado posible en la entrega externa; detectable, no prevenible.
- **Acoplamiento cola↔BD** — El claim exige cola transaccional. Vigilado por health-check.

**❌ Abierto**
- **GAP-08** — Verificación visual página por página, matriz de estados HTTP, accesibilidad, móvil.
- **Suite de integración MySQL** — La integridad referencial del ledger no es observable desde SQLite.
- **F-01 (resto)** — Inventario FK completo con política explícita por relación.
- **Auditoría de campos hacia IA** (tu punto 8) — se verificó que `buildPrompts()` solo envía `subject_name`, `level`, `difficulty_text` redactado, `goal` y `urgency`; **no** se construyó la matriz campo-por-campo completa.
- **Consentimiento WhatsApp** (tu punto 13) — MOVA exige teléfono verificado antes de enviar (`WHATSAPP_REQUIRE_VERIFIED_PHONE`), pero **no existe un `whatsapp_opt_in` explícito**. Hallazgo abierto, no corregido.
- **Operativa de colas** (tu punto 20) — `retry_after=90`, driver `database`. **No** se auditó el crecimiento de `jobs`/`failed_jobs` ni la concurrencia de workers.

---

## 8. Lo que esta fase enseña

De las tres cosas que la Fase 3 dio por buenas, dos estaban mal:
- La afirmación "exactly once" era **falsa**.
- El soft delete **rompió** las notificaciones al padre.

Ninguna de las dos la habrían detectado más tests: los 364 de la Fase 3 pasaban con la regresión dentro. Lo que las detectó fue **ejecutar el escenario real contra la base de datos real** y **leer la afirmación con escepticismo**.

Y un detalle que conviene no olvidar: mi propio extractor de frontend produjo 10 falsos positivos por tres bugs distintos. Una herramienta de auditoría también necesita auditarse — verificar sus hallazgos a mano antes de actuar es parte del proceso, no un lujo.

**MOVA no está terminado.** Está considerablemente mejor documentado y protegido que hace cuatro fases, con dos bloqueos externos, una decisión de negocio pendiente y una superficie grande (frontend visual) sin cubrir.
