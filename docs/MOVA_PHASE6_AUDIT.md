# MOVA — Fase 6: consentimiento explícito y cierre de gaps operativos

**Fecha:** 2026-08-24
**Objetivo:** cerrar el defecto real señalado sobre el modelo de consentimiento de WhatsApp, completar la matriz de timeouts de colas, cerrar la auditoría de privacidad de IA (headers/URL/logs/Sentry), y añadir salida estructurada a F-02.

**Hallazgo confirmado real: el "opt-in" de la Fase 5 no era consentimiento explícito.** Era `phone_verified_at` con otro nombre.

---

## 0. Baseline real

| Comando | Resultado |
|---|---|
| `php artisan test` | **415 passed** (1214 assertions) |
| `php artisan migrate:status` | 73/73 `Ran` |
| `php artisan route:list --except-vendor` | 106 rutas |
| `npm run build` | limpio |
| `mova:reconcile-ledger --json` | `healthy: true` |
| `mova:reconcile-whatsapp --json` | `healthy: true` |
| `mova:health-check --json` | `healthy: true` |
| `git diff --check` | limpio |

Progresión: 248 → 364 → 375 → 406 → **415**.

---

## 1. El consentimiento de WhatsApp — corregido de raíz

Leí el código antes de defenderlo, y tenías razón: `PhoneVerificationController::verify()` fijaba `whatsapp_opt_in_at = now()` como efecto colateral automático de completar el OTP. Ningún checkbox, ninguna decisión visible del usuario. Era exactamente lo que advertiste: *«verificar el número = consentir notificaciones»*, una decisión de producto disfrazada de detalle técnico.

**Peor:** la migración de la Fase 5 hacía backfill silencioso —`whatsapp_opt_in_at = phone_verified_at`— para **todos** los usuarios que ya tenían el teléfono verificado. En la base local afectaba a 1 cuenta; en producción habría afectado a cada usuario preexistente, sin que nadie lo decidiera.

### La corrección

Como el migration y el auto-set nacieron en esta misma sesión (nunca desplegados), los edité directamente en vez de apilar un parche encima:

1. **Migración sin backfill.** `whatsapp_opt_in_at` queda `NULL` para todo usuario existente. Verificado: tras re-ejecutar, `0` usuarios tienen opt-in (antes: `1`, el mismo que tenía el teléfono verificado).
2. **`verify()` ya no fija el opt-in automáticamente.** Ahora valida un campo `whatsapp_notifications` real (`sometimes|boolean`) y solo activa el consentimiento si llega en `true`.
3. **Casilla explícita en `Auth/PhoneVerification.vue`**, **sin marcar por defecto**: *«Quiero recibir recordatorios y actualizaciones de mis clases por WhatsApp»*, con una línea aclarando que no afecta al código de verificación.
4. El OTP sigue sin depender de esto — nunca dependió, y los tests lo siguen fijando explícitamente.

### Consecuencia operativa, dicha sin rodeos

**Los usuarios que ya tenían el teléfono verificado antes de este cambio dejan de recibir notificaciones WhatsApp** (recordatorios, confirmaciones) hasta que activen la casilla desde su perfil. Es un recorte de alcance deliberado: no existe forma honesta de inferir consentimiento retroactivo. Documentado en el docblock de la migración para que quien la lea en seis meses entienda por qué, no lo confunda con un bug.

### Tests — incluida la carrera que pediste

`WhatsAppConsentTest` pasó de 14 a **18 tests**. Los cuatro nuevos son exactamente los escenarios que señalaste:

- `verifying_the_phone_alone_does_not_grant_consent` — el caso central de tu crítica.
- `existing_verified_users_are_not_silently_opted_in` — el backfill que se eliminó.
- **`an_opt_out_between_queueing_and_processing_prevents_the_send`** y su simétrico `opt_in` — el caso de la carrera (20:00 se encola, 20:01 se da de baja, 20:02 el worker procesa). Forzado contra el driver `database` real, no el `sync` de los tests, para pasar por el mismo ciclo de serialización que un worker de producción: el `notifiable` se reconstruye desde su `ModelIdentifier`, es decir, se **relee de la base de datos** en el momento de ejecutar, no con el estado que tenía al encolarse.

**Un fallo en mi propio test, encontrado y corregido en el camino:** mi primera versión usaba `Queue::pop()` una sola vez. Pero `via()` encola **un job por canal** (database, mail, whatsapp — el mismo hallazgo de fases anteriores sobre `NotificationSender::queueNotification()`), así que un solo `pop()` procesaba el job de `database` y el test de opt-out "pasaba" **sin haber tocado siquiera el job de WhatsApp** — verde por la razón equivocada. Corregido para drenar toda la cola. También reemplacé `$this->artisan('queue:work', ...)` (30s+ de duración, contaminaba el orden de otros tests) por extraer y ejecutar los jobs directamente — mismo ciclo de serialización, sin el bucle de un worker real.

**Estado: ✅ FIXED**

---

## 2. Matriz de timeouts — completada

Pediste no limitar la comprobación a `timeout < retry_after` del worker, sino verificar toda la cadena.

**Búsqueda global de timeouts declarados por Job/Listener/Notification:** cero. Ninguna clase declara `$timeout`, `$tries` ni `$backoff` propios — todas heredan el default del worker (`--timeout=60`, `--tries=3`, `--backoff=5` en `railway.queue.toml`).

**Timeouts HTTP salientes, verificados uno por uno:**

| Origen | Timeout | ¿Cabe bajo el worker (60s)? |
|---|---|---|
| `MetaCloudApiProvider` (WhatsApp) | 10s | ✅ |
| `DiagnosticAiEnrichmentService` (IA) | 8s (config, `DIAGNOSTIC_AI_TIMEOUT`) | ✅ |
| `GmailApiMailService` | 15s | ✅ |

El máximo es 15s, muy por debajo del timeout del worker. `mova:health-check` ahora **repite esta comprobación en cada ejecución** (`max_outbound_http_timeout`) y avisa si algún timeout HTTP llegara a igualar o superar el del worker — el mismo patrón de F-20, aplicado de forma genérica en vez de solo al caso que ya se había encontrado.

**Estado: ✅ FIXED**

---

## 3. Auditoría de privacidad de IA — cerrada en las cuatro vías que pediste

F-05 se había quedado en el *body* del payload. Auditadas las otras tres:

| Vía | Hallazgo | Evidencia |
|---|---|---|
| **URL / query string** | Limpia — endpoint fijo, sin interpolación | `test_no_identifier_travels_in_the_request_url_or_query_string` |
| **Headers** | Solo cabeceras genéricas de una petición JSON autenticada (`Authorization`, `Content-Type`, etc.) | `test_request_headers_carry_only_authentication_no_identifiers` |
| **Logs de error** | `Log::warning()` solo registra `diagnostic_id`, `provider`, `status` — nunca el prompt | `test_exception_logging_never_includes_the_prompt` |
| **Sentry** | Verificado **contra el paquete instalado**, no supuesto: `HttpClientIntegration.php` captura `http.request.body.size` (un número), nunca `getBody()->getContents()`. `send_default_pii=false` y `sql_bindings=false` por defecto | `test_sentry_http_breadcrumbs_only_record_body_size_not_content` |
| **`ai_usage_logs`** | 11 columnas, todas metadata/contadores — ninguna de texto libre | `test_ai_usage_logs_has_no_free_text_columns` |

**Un fallo en mis propios tests, corregido:** las dos primeras aserciones fallaron al ejecutarse porque comparaban `student_id=1` contra la URL/headers completos por substring — `"1"` coincide accidentalmente con `v1` de la ruta de la API o con `Content-Length:1091`. Corregido comparando la URL exacta esperada y la lista cerrada de cabeceras permitidas, en vez de "no contiene este dígito en ningún sitio".

`AiPayloadContractTest` pasó de 9 a **14 tests**. **Estado: 🟡 MITIGATED** (sigue siendo redacción best-effort para el texto libre, no garantía — eso no cambia con esta ronda).

---

## 4. F-02 — salida estructurada

Añadido `--json` a `mova:settle-lessons --dry-run`:

```json
{
    "dry_run": true,
    "would_consume": 0,
    "would_review": 0,
    "invalid": [],
    "safe_to_enable": true
}
```

`invalid` hace un **preflight real**: cuenta las reservas de ledger de cada candidata a liquidar antes de tocar nada. Si alguna no tuviera exactamente 1 reserva, `LessonSettlementService::consume()` la rechazaría con `RuntimeException` durante una ejecución real (`Lesson::reservedCreditAmount()`) — ahora esa anomalía se ve en el preflight, no como un error a mitad de la liquidación real.

`safe_to_enable` es `true` solo si no hay anomalías y ningún error — pero **sigue siendo una lectura del entorno local**, no de producción. No cambia la conclusión de fases anteriores: el dry-run que importa es el que se ejecute contra la base de datos real.

**Estado: ⏳ BUSINESS DECISION** (sin cambios — la herramienta mejoró, la decisión sigue pendiente).

---

## 5. Re-búsqueda global

| Patrón | Resultado |
|---|---|
| `Twilio` | Solo 2 comentarios históricos (`MetaCloudApiProvider.php:13`, `config/services.php:35`) — correctos, documentan la migración |
| `jitsi_password` / `meet.jit.si` | Solo comentarios explicando por qué ya no se usan |
| `in_progress` | Cero |
| `confirm(` / `prompt(` nativos | Cero en código activo — solo 4 comentarios que documentan su eliminación (F-13) |
| `--timeout=90` / `retry_after=90` | Solo el `retry_after=90` legítimo en `config/queue.php`, y el comentario en `railway.queue.toml` que explica por qué el timeout ya NO es 90 |

**Todo limpio.**

---

## 6. Clasificación actualizada

| ID | Estado |
|---|---|
| Consentimiento WhatsApp | ✅ **FIXED** — casilla explícita, sin backfill, carrera probada contra cola real |
| F-20 (matriz de timeouts) | ✅ **FIXED** — extendida a HTTP saliente, vigilada en cada `health-check` |
| Privacidad de IA (headers/URL/logs/Sentry) | ✅ **FIXED** para esas 4 vías · 🟡 el body de texto libre sigue siendo best-effort |
| F-02 | ⏳ **BUSINESS DECISION** — herramienta mejorada (`--json`, preflight), decisión sin cambios |
| F-06 (JaaS real) | ⏳ **EXTERNAL VALIDATION** — sin cambios |
| GAP-08 (frontend visual/a11y/móvil) | ❌ **OPEN** — no abordado esta ronda, deliberadamente: no era el foco que señalaste |

---

## 7. Lo que esta fase confirma, otra vez

El patrón se repitió dos veces en esta misma ronda:

1. **El defecto real que abrió la fase** — código que ya existía, que yo había escrito y defendido como cerrado, y que resultó estar mal en el punto exacto que planteaste: equiparar autenticación con consentimiento.
2. **Un test mío que pasaba por la razón equivocada** — `Queue::pop()` una sola vez, procesando el canal irrelevante, dando un verde que no probaba nada. Se descubrió solo al forzar el driver de cola real en vez de confiar en el `sync` de los tests.

Ninguno de los dos lo habría encontrado ejecutando más veces la misma suite. Los encontró leer el código con la pregunta correcta y forzar el camino real (cola de verdad, no en memoria) en vez del atajo cómodo.
