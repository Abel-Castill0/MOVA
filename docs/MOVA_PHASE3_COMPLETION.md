> **HISTORICAL SNAPSHOT** — verify against `docs/MOVA_SYSTEM_MAP.md` and current code.
> Documento de una fase anterior. Conservado como registro de decisiones, NO como
> descripcion fiable del comportamiento actual.

# MOVA — Fase 3: Corrección, Hardening y Reauditoría

**Fecha:** 2026-08-24
**Entrada:** `docs/MOVA_FULL_AUDIT.md` (17 findings, 8 gaps, 6 unknowns)
**Restricción cumplida:** sin commit. El diff queda para revisión.

---

## 0. Matriz de evidencia

Más tests no demuestra por sí mismo que el problema original desapareciera. Cada fila declara **qué había**, **qué se hizo** y **con qué se comprueba**.

| Hallazgo | Estado anterior | Corrección | Test / evidencia | Resultado |
|---|---|---|---|---|
| **F-01** | Reportado como cascada que destruye el ledger | **El reporte era incorrecto**: MySQL ya usaba RESTRICT. Se añadió guard `User::deleting` independiente del motor | `FinancialHistoryDurabilityTest` (9) + esquema MySQL real inspeccionado | 🟡 **CORREGIDO PARCIALMENTE** (ver §1) |
| **F-02** | `--dry-run` hardcodeado en Kernel; C-1 inactivo | `LESSON_SETTLEMENT_MODE` + `SettlementMode` + `mova:health-check` | `SchedulerConfigurationTest` (12) | 🟡 **CÓDIGO LISTO, DECISIÓN PENDIENTE** |
| **F-03** | `default => new Fake…` fail-open | `ProviderGuard` fail-closed; `match` sin rama `default` | `ProviderGuardTest` (14) | ✅ **CORREGIDO** |
| **F-04** | 4 de 5 barridos notificaban antes de marcar, sin lock | Claim atómico vía UPDATE condicional + `withoutOverlapping()` | `ReminderConcurrencyTest` (11) | ✅ **CORREGIDO** |
| **F-05** | Regex que no cubría nombre solo, inicio de texto, teléfono, email, DNI | `TextRedactor` + docblock corregido | `TextRedactionTest` (18, adversariales) | ✅ **CORREGIDO** (con límite documentado) |
| **F-06** | JWT 24 h; ventana temporal solo en frontend | Gate autoritativo en `join()`; `exp` acotado a la ventana | `JitsiAccessWindowTest` (12) | ✅ **CORREGIDO** |
| **F-07** | `jitsi_password` generado y almacenado sin uso | Migración que elimina la columna; fuera de `$fillable`/`$hidden` | Ciclo MySQL up→rollback→reapply verificado | ✅ **CORREGIDO** |
| **F-08** | `reversed`/`reversal` sin etiqueta en UI | Etiquetas en castellano + explicación + badge | Verificado en navegador: muestra "Revertida" | ✅ **CORREGIDO** |
| **F-09** | Estado muerto con UI viva | Eliminado del enum (guard de aborto) y de `statusColors.js` | Ciclo MySQL verificado; búsqueda global limpia | ✅ **CORREGIDO** |
| **F-10** | Marcaba como avisado sin avisar; destinatario incompleto | `ClassRequest::eligibleTeacherUsers()` compartido; no marca sin destinatario | `ReminderConcurrencyTest` (6 de los 11) | ✅ **CORREGIDO** |
| **F-11** | `paid` leído como estado financiero verificado | Documentado en el modelo; **sin refactor** | `CrossTenantAccessTest::only_the_owning_parent_can_confirm_payment` | ✅ **DOCUMENTADO** (refactor pospuesto a propósito) |
| **F-12** | Sin guard de doble submit en 3 acciones | `cancelling`/`rescheduling`/`rejecting` + botones deshabilitados | Build limpio; patrón uniforme en 5 páginas | ✅ **CORREGIDO** |
| **F-13** | `confirm()`/`prompt()` nativos en aprobación de dinero | Modal con datos, efecto y motivo validado | Verificado en navegador (modal real + aprobación end-to-end) | ✅ **CORREGIDO** |
| **F-14** | `qa/check-twilio.mjs` huérfano | Eliminado | Búsqueda global: solo quedan comentarios históricos | ✅ **CORREGIDO** |
| **F-15** | `MOVA_MASTER_CONTEXT.md` decía meet.jit.si | Reescrito a JaaS/8x8.vc + ventana + nota F-02 | Búsqueda global de `meet.jit.si` en docs vivos | ✅ **CORREGIDO** |
| **F-16** | "1 crédito = S/ 2.00" escrito a mano en Vue | `price_per_credit` derivado en el servidor | Build limpio; el literal ya no existe | ✅ **CORREGIDO** |
| **F-17** | `before()=true` + métodos `false` | Cada método declara su regla; `before()` eliminado | `FinancialConcurrencyTest::only_admins_can_approve_reject_or_reverse` | ✅ **CORREGIDO** |

| Gap | Cobertura añadida | Resultado |
|---|---|---|
| GAP-01 scheduler | `SchedulerConfigurationTest` — comando, flags, overlap, entorno | ✅ |
| GAP-02 IDOR | `CrossTenantAccessTest` — 14 accesos cruzados reales | ✅ **cero IDOR encontrado** |
| GAP-03 concurrencia | `FinancialConcurrencyTest` — 8 escenarios + reconciliación tras cada uno | ✅ (con limitación declarada) |
| GAP-04 borrado preserva ledger | `FinancialHistoryDurabilityTest` | ✅ |
| GAP-05 fake en producción | `ProviderGuardTest` | ✅ |
| GAP-06 anonimización | `TextRedactionTest` | ✅ |
| GAP-07 recordatorios sin responder | `ReminderConcurrencyTest` | ✅ |
| GAP-08 frontend E2E | **No ampliado** | ⏳ **ABIERTO** |

**Tests: 248 → 346** (+98). 1074 aserciones. Suite completamente en verde.

---

## 1. F-01 — Corrección de un error de la auditoría anterior

Esto merece destacarse porque **la Fase 2 se equivocó y hay que decirlo**.

`MOVA_FULL_AUDIT.md` clasificó F-01 como 🔴 CRITICAL: "`DELETE` en `users` destruye el ledger por cascada". Esa conclusión salió de leer las migraciones `CREATE TABLE`, donde efectivamente las FK son `cascadeOnDelete()`.

**Era incorrecto.** La migración `2026_07_10_000002_protect_monetization_history.php` convierte esas seis FK a `RESTRICT`. Verificado no leyendo la migración, sino **consultando `information_schema` en la base MySQL real**:

```
credit_transactions  teacher_profile_id  -> teacher_profiles   RESTRICT
recharge_requests    teacher_profile_id  -> teacher_profiles   RESTRICT
classes              teacher_profile_id  -> teacher_profiles   RESTRICT
classes              student_id          -> students           RESTRICT
class_requests       student_id          -> students           RESTRICT
credit_transactions  lesson_id           -> classes            RESTRICT
```

En producción el ledger **ya estaba protegido**. La auditoría anterior no leyó lo suficiente.

**El hueco real, que sí existía:** esa migración hace `return` temprano si el driver no es MySQL, y la suite corre sobre SQLite. Es decir, **la protección de producción no era observable por ningún test**, y en el entorno de pruebas un `$user->delete()` sí arrasaba el ledger sin que nada lo señalara.

**Lo implementado:** guard `User::booted()::deleting` que rechaza el borrado si `hasProtectedHistory()`, más el traslado de esa lógica desde `ProfileController` al modelo para que **todo** camino de borrado la respete. Funciona en cualquier driver, convierte un error crudo de constraint en un mensaje accionable, y es testeable.

**Clasificación honesta: 🟡 PARCIALMENTE CORREGIDO.** El guard de aplicación es nuevo y está probado; las FK ya estaban bien. Lo que **no** se hizo (y por qué): no se revisó FK por FK toda la política `CASCADE/RESTRICT/SET NULL/ANONYMIZE` del inventario completo que pediste. Se inventarió y verificó el subconjunto financiero; el resto (`student_diagnostics`, `lesson_reports`, `diagnostic_recommendations`) sigue en `CASCADE` y **no se auditó caso por caso**. Para diagnósticos de menores, `CASCADE` es probablemente lo correcto (borrar al padre debe borrar los diagnósticos), pero es una decisión que no tomé por ti.

---

## 2. F-02 — Qué hay que hacer para cerrar C-1

El código está listo. **La decisión de negocio no está tomada, y no la tomé yo.**

```bash
php artisan mova:settle-lessons --dry-run
```

Revisa esa salida con criterio humano. Si es correcta, activa en producción:

```
LESSON_SETTLEMENT_MODE=live
```

Hasta entonces `mova:health-check` reporta `SETTLEMENT_DRY_RUN_IN_PRODUCTION` como configuración peligrosa y devuelve exit code 1 — enganchable a un monitor o a un paso de despliegue, que es exactamente la protección que pediste contra "que alguien vuelva a desplegar MOVA dentro de 6 meses y reactive el problema".

**No se cambió el valor por defecto.** Sigue siendo `dry_run`: activar la liquidación real por accidente sería peor que tenerla apagada.

---

## 3. Migraciones nuevas (verificadas sobre MySQL real)

| Migración | Ciclo up → verify → rollback → verify → reapply → verify |
|---|---|
| `2026_08_24_000001_drop_jitsi_password_from_classes_table` | ✅ Columna eliminada / restaurada / eliminada |
| `2026_08_24_000002_remove_in_progress_from_classes_status_enum` | ✅ Enum recortado / restaurado / recortado |

Ambas son idempotentes (comprueban el estado antes de actuar) y la de `in_progress` **aborta** si encuentra filas en ese estado, en lugar de dejar que MySQL las convierta en cadena vacía — mismo criterio que las migraciones financieras existentes.

Se verificó que había **0 filas** con `in_progress` antes de recortar el enum.

---

## 4. Archivos nuevos

**Código:**
- `app/Support/ProviderGuard.php` — resolución fail-closed de proveedores
- `app/Support/SettlementMode.php` — modo operativo de C-1
- `app/Support/TextRedactor.php` — redacción de PII con advertencia explícita de sus límites
- `app/Console/Commands/HealthCheck.php` — `mova:health-check`

**Tests (98 nuevos):**
- `ProviderGuardTest` (14) · `SchedulerConfigurationTest` (12) · `ReminderConcurrencyTest` (11)
- `FinancialHistoryDurabilityTest` (9) · `TextRedactionTest` (18) · `JitsiAccessWindowTest` (12)
- `CrossTenantAccessTest` (14) · `FinancialConcurrencyTest` (8)

**Eliminado:** `qa/check-twilio.mjs`

---

## 5. Rendimiento — regresión revisada

Comprobado explícitamente que las correcciones **no** introdujeron los problemas que advertiste:

- **Ninguna llamada externa dentro de una transacción.** El claim de `SendClassReminders` es un `UPDATE` condicional de una sola sentencia; las notificaciones se despachan **después**, fuera de cualquier transacción. Se eligió deliberadamente frente a `lockForUpdate()` precisamente para no mantener un lock abierto mientras se generan jobs.
- **Sin N+1 nuevo.** `eligibleTeacherUsers()` hace una consulta con `with('user')`; el `eager load` de `SendClassReminders` se amplió con `teacherProfile.user` para cubrir el caso de referido sin consultas adicionales.
- **Sin locks más amplios.** No se añadió ningún `lockForUpdate()` nuevo.
- **`join()`** añade dos comparaciones de fechas en memoria; cero consultas nuevas.

---

## 6. Verificación final

| Comando | Resultado |
|---|---|
| `php artisan test` | **346 passed** (1074 assertions) |
| `npm run build` | ✅ limpio |
| `php artisan migrate:status` | 71/71 `Ran` |
| `php artisan mova:reconcile-ledger --json` | **`healthy: true`** |
| `php artisan mova:reconcile-whatsapp --json` | **`healthy: true`** |
| `php artisan mova:health-check` | **GREEN** (entorno local) |
| `git diff --check` | ✅ limpio |
| Verificación en navegador | Modal de aprobación + etiqueta "Revertida" + aprobación end-to-end (1 asiento, 5 créditos) |

**Ninguna degradación financiera:** el ledger sigue explicando todos los saldos, sin anomalías ni desajustes de perfil.

---

## 7. Re-búsqueda forense (no se asumió que desapareciera)

| Patrón buscado | Resultado |
|---|---|
| `default => new Fake…` | Eliminado; `match` sin rama `default` |
| `--dry-run` hardcodeado en Kernel | Ahora condicional a `SettlementMode` |
| `in_progress` en código vivo | **Cero ocurrencias** |
| `jitsi_password` generado o leído | **Cero ocurrencias** (solo un comentario histórico) |
| `window.confirm` / `prompt` | **Cero ocurrencias** — se encontró y corrigió además uno en `Students/Index.vue` que estaba fuera del alcance original de F-13 (borrado de datos de un menor) |
| Twilio en código | Solo comentarios que documentan la migración |
| Precio de crédito hardcodeado | Eliminado |
| Mutación directa de `CreditTransaction` | **Cero `update`/`delete`** — el ledger sigue siendo append-only |
| Scheduler sin `withoutOverlapping` | Ninguno |
| Marcar el envío después de notificar | Ninguno |

---

## 8. Riesgos que siguen abiertos

**⏳ BLOQUEADO POR DECISIÓN EXTERNA**
- **F-02** — Activar `LESSON_SETTLEMENT_MODE=live` es tuyo, no mío. Mientras siga en `dry_run`, **C-1 no está operativo** y MOVA no debería describirse como financieramente lista para producción.
- **UNKNOWN-01** — ¿`DIAGNOSTIC_AI_ENABLED` está activo en producción? Determina si F-05 fue una exposición real o solo de diseño. No leí el `.env` de producción.
- **UNKNOWN-03** — Cómo trata JaaS un `exp` que vence con la llamada en curso. Por eso la gracia por defecto es de **2 h**, deliberadamente generosa: reducirla exige un smoke test real contra JaaS. No inventé una cifra agresiva.

**❌ NO CORREGIDO**
- **GAP-08** — La suite Playwright sigue teniendo un único spec. No la amplié: hacerlo bien (auth, créditos, recarga, clases, reprogramación, cancelación, admin, reseñas) es una fase de QA propia, y meterla aquí habría producido tests superficiales que dan falsa confianza.
- **F-01 (resto del inventario FK)** — Ver §1: el subconjunto financiero está verificado; `student_diagnostics`, `lesson_reports` y `diagnostic_recommendations` siguen en `CASCADE` sin auditar caso por caso.

**🟡 ACEPTADO CONSCIENTEMENTE**
- **F-05** — `TextRedactor` cubre mucho más que antes, pero un nombre de pila en minúscula sin marcador de parentesco sigue siendo indetectable. Está **probado como límite conocido** (`test_a_lowercase_first_name_without_any_marker_is_a_documented_limitation`) y documentado en la clase. La protección real es la minimización más el feature flag, no la redacción.
- **F-11** — `paid` sigue siendo semánticamente ambiguo. Documentado exhaustivamente en `Lesson`; separar `lesson_status` de `payment_confirmation_status` es una migración de riesgo alto que requiere decisión de producto.

---

## 9. Lo que esta fase NO demuestra

Por honestidad, y porque tú mismo dijiste que no confiarías en un "all green":

- **346 tests en verde no prueban ausencia de bugs.** Los 248 anteriores tampoco detectaron que C-1 llevaba meses apagado ni que la ventana de Jitsi no existía en el backend.
- **Los tests de concurrencia son de serialización, no de paralelismo real.** SQLite en memoria, un proceso. Lo que sí demuestran es que la garantía la da el motor (UNIQUE, UPDATE condicional) y no la temporización del código.
- **El frontend sigue sin auditarse página por página.** Se corrigieron las superficies con acciones sensibles y se encontró un `confirm()` adicional al re-buscar, lo que sugiere que quedan cosas en las 55 páginas.
- **Nada se probó contra Culqi ni Meta reales.** No hay credenciales y no se inventaron.

La siguiente auditoría debería empezar asumiendo que este documento también contiene errores — como los contenía el anterior.
