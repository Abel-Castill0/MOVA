# MOVA Monetization Audit

Auditoría estática y QA local aislado sobre `master`, commit `aa5c9426c5f435089c55ecd7e8070ff7b734cad8`, realizada el 10 de julio de 2026. El estado de corrección se actualizó en la rama `fix/monetization-integrity` el 10 de julio de 2026.

Alcance: créditos, recargas, reservas, consumos, cancelaciones, aceptación de clases, precios por experiencia y acompañamiento continuo. No se consultaron ni modificaron datos de producción.

## Estado general

- **Créditos implementados:** parcial, con la integridad crítica de Fase 1 aplicada. Existen saldos, ledger contextual, bono, recargas, reservas, consumos y devoluciones, pero todavía no hay reconciliación automática.
- **Seguros para cobrar dinero real:** no.
- **Principal riesgo pendiente:** los saldos siguen siendo contadores mutables sin reconciliación, el costo permanece fijo en 2 créditos y faltan pruebas de concurrencia reales sobre MySQL.
- **Recomendación ejecutiva:** mantener `RECHARGES_ENABLED=false` hasta completar Monetización 2, ejecutar pruebas MySQL y configurar un destino de pago real fuera del repositorio.

### Estado después de Monetización Fase 1

| Hallazgo | Estado | Evidencia de corrección |
|---|---|---|
| MON-001: comprobante reutilizable | **Corregido** | Normalización, validación y UNIQUE compuesto por método/operación |
| MON-002: borrado destructivo | **Corregido** | Restricciones MySQL y anonimización/suspensión cuando existe historial |
| MON-003: teléfono/QR ficticios | **Corregido** | Recargas desactivadas y UI sin destino ficticio |
| MON-004: reporte completa sin consumo | **Corregido** | Reporte exige `completed` y no modifica clase ni ledger |
| MON-005: acciones del padre sin estado/lock | **Corregido** | Solo `pending_parent_approval`, dentro de transacción y lock |
| MON-007: ledger sin contexto/idempotencia | **Corregido en eventos críticos** | Claves únicas y referencias a clase/recarga |
| MON-012: completar clase futura | **Corregido** | Backend exige que la hora de finalización haya pasado |
| MON-013: ausencia de tests financieros | **Corregido parcialmente** | 20 casos feature reales en SQLite; concurrencia MySQL pendiente |
| MON-017: revisión de recarga no auditable | **Corregido** | Aprobador, fechas y motivo obligatorio persistidos |
| MON-024: catálogo duplicado | **Corregido** | Catálogo único en `config/credits.php` enviado a Inertia |

Los demás hallazgos permanecen pendientes salvo indicación expresa. Los tres hallazgos originalmente CRITICAL están cerrados en código, pero el módulo completo continúa clasificado como no apto para dinero real hasta Monetización 2 y la validación MySQL.

### Decisiones comerciales aprobadas

- 1 crédito MOVA representa 60 minutos.
- Se permitirán fracciones de 0.5 créditos para 30 minutos.
- 1 crédito cuesta S/ 2.00.
- La conversión exacta por duración queda para Monetización 2; Fase 1 conserva temporalmente 2 créditos fijos por clase.
- Las recargas reales permanecen desactivadas hasta configurar y validar el destino de pago.

### Resumen de hallazgos

| Severidad | Cantidad |
|---|---:|
| CRITICAL abiertos | 0 |
| HIGH abiertos | 6 |
| MEDIUM abiertos | 8 |
| LOW abiertos | 2 |
| Corregidos o mitigados en Fase 1 | 10 |
| **Total** | **26** |

### Controles positivos comprobados

- Aprobar o rechazar una recarga bloquea la fila de `recharge_requests` y vuelve a comprobar `status=pending` dentro de `DB::transaction` (`app/Http/Controllers/Admin/RechargeController.php:26-79`). Dos administradores no deberían acreditar la misma fila dos veces.
- La aceptación bloquea `class_requests` y `teacher_profiles`; una solicitud que permanece en `open` solo puede ser ganada por un profesor (`app/Http/Controllers/LessonController.php:57-103`).
- Completar y cancelar bloquean la clase y el perfil, y validan de nuevo el estado dentro de la transacción (`LessonController.php:176-207`, `229-268`; `AdminController.php:160-199`).
- La aceptación comprueba saldo antes de reservar y capacidad de mentoría bajo el lock del perfil.
- Si `ZoomService::createMeeting()` lanza una excepción, Laravel revierte saldo, ledger, clase y solicitud porque la llamada ocurre dentro de la transacción.
- Los paquetes recibidos del navegador se contrastan contra un catálogo del servidor y se almacenan usando los valores del servidor (`Teacher/CreditController.php:15-19`, `50-72`).
- El bono se protege contra doble verificación concurrente mediante lock del perfil y búsqueda previa del depósito (`AdminController.php:44-75`).
- Los límites S/20 y S/25 se validan tanto al crear como al editar perfiles y ofertas.

## Inventario

| Componente | Existe | Archivo | Estado |
|---|---|---|---|
| Saldos disponibles/reservados | Sí | `database/migrations/2026_07_08_000002_add_credits_to_teacher_profiles_table.php` | Implementado como contadores mutables en `teacher_profiles` |
| Ledger | Sí | `CreditTransaction.php`, migración `000003` | Parcial; sin contexto de clase/recarga/admin ni reconciliación |
| Recarga manual | Sí | `RechargeRequest.php`, migración `000004` | Implementada; comprobante reutilizable |
| Bono de bienvenida | Sí | `AdminController::verifyTeacher` | 5 créditos por perfil verificado, idempotencia lógica frágil |
| Reserva | Sí | `LessonController::store` | 2 créditos fijos por clase |
| Consumo | Sí | `LessonController::complete` | 2 créditos; existe una ruta lateral que lo omite |
| Liberación/devolución | Sí | `LessonController::cancel`, `AdminController::cancelLesson` | Registrada como `refund` |
| Ajuste administrativo | No | Sin endpoint/tipo de ledger | No existe |
| Paquetes | Sí | `Teacher/CreditController.php:15-19` | Inicio 5/S10, Impulso 15/S30, Pro 30/S60 |
| Panel profesor | Sí | `resources/js/Pages/Teacher/Credits/Index.vue` | Funcional, pero contiene destino de pago placeholder |
| Panel admin | Sí | `resources/js/Pages/Admin/Recharges/Index.vue` | Aprueba/rechaza; no captura motivo de rechazo |
| Notificaciones de recarga | Sí | `NewRechargeRequestNotification`, `RechargeApprovedNotification`, `RechargeRejectedNotification` | En cola, solo canal `mail`; `toArray()` no se usa en `via()` |
| Experiencia y precios | Sí | migración `000005`, `TeacherProfile::maxAllowedRate` | Parcial; datos denormalizados sin reconciliación |
| Acompañamiento continuo | Sí | migración `000006`, `TeacherProfile`, `LessonController` | Reserva cupo; no existe liberación ni entidad de relación |
| Auditoría de clase | Sí | `ClassEvent.php`, tabla `class_events` | Parcial; eventos sin claves foráneas y cobertura incompleta |
| Estados `teacher_rejected` | Sí | migración `2026_07_08_000001...` | Permitido realmente en MySQL |
| Feature tests financieros | No | `tests/Feature` | No hay pruebas de saldo, ledger, recarga o concurrencia |
| E2E histórico de monetización | No localizado | `qa/` | Hay E2E de clases/reportes, no auditoría matemática del ledger |

### Archivos inspeccionados

- Modelos: `TeacherProfile`, `CreditTransaction`, `RechargeRequest`, `ClassRequest`, `Lesson`, `ClassEvent`, `ClassOffer`, `User`.
- Controladores: `LessonController`, `LessonReportController`, `ClassRequestController`, `ClassOfferController`, `TeacherProfileController`, `Teacher/CreditController`, `Admin/RechargeController`, `AdminController`, `DiagnosticsController`, `ProfileController`, `MarketplaceController`, `TeacherPublicController`.
- Migraciones base de `teacher_profiles`, `class_requests`, `classes`, `class_offers`, `lesson_reports`, `class_events` y migraciones `2026_07_08_000001` a `000006`.
- Rutas de padre, profesor y administrador en `routes/web.php`.
- Vistas `Teacher/Credits/Index.vue`, `Admin/Recharges/Index.vue`, marketplace, solicitudes, clases, ofertas y perfiles.
- Notificaciones de recarga y listeners de solicitudes/confirmaciones.
- PHPUnit en `tests/Feature` y Playwright histórico en `qa/tests`.
- Scheduler `SendClassReminders`; no existe settlement automático de clases abandonadas.

## Flujo financiero actual

Todos los `amount` del ledger se guardan positivos. El efecto depende de `type`; no existe convención de signo contable.

| Paso | Acción | Saldo disponible | Saldo reservado | Transacción |
|---|---|---:|---:|---|
| Alta | Crear perfil | 0 | 0 | Ninguna |
| Bienvenida | Admin verifica por primera vez | +5 | 0 | `deposit`, 5, “Bono de bienvenida MOVA” |
| Recarga | Admin aprueba paquete N | +N | 0 | `deposit`, N |
| Aceptación | Profesor acepta cualquier duración | -2 | +2 | `reservation`, 2 |
| Finalización normal | Profesor usa `/complete` | 0 | -2 | `consumption`, 2 |
| Cancelación | Padre/profesor/admin cancela | +2 | -2 | `refund`, 2 |
| Reporte lateral | Profesor reporta clase `scheduled` directamente | 0 | 0 | **Ninguna; la clase pasa a `completed`** |
| Rechazo de solicitud | Padre o profesor rechaza | 0 | 0 | Ninguna |
| Abandono | Clase queda `scheduled` indefinidamente | 0 | 0 | Ninguna; los 2 reservados quedan bloqueados |

### Fuente de verdad

La fuente operativa es `teacher_profiles.credits_available` y `credits_reserved`: UI y validaciones leen esos campos. El ledger no se usa para calcular el saldo.

En teoría, partiendo de cero, podría comprobarse:

```text
available = deposits - reservations + refunds
reserved  = reservations - consumptions - refunds
```

No existe servicio, comando, job, test ni constraint que ejecute esa reconciliación. Tampoco se puede reconciliar por clase o recarga porque `credit_transactions` solo contiene `teacher_profile_id`, `type`, `amount` y una descripción libre.

Los tipos reales son `deposit`, `reservation`, `consumption` y `refund`. No existen `release`, `admin_adjustment`, `chargeback` ni reversos enlazados. `refund` representa la liberación de una reserva, no necesariamente una devolución monetaria.

## Máquina de estados

### ClassRequest

Estados permitidos por MySQL:

```text
pending_parent_approval | open | accepted | rejected | teacher_rejected | completed
```

| Estado actual | Acción | Estado siguiente | Protección real |
|---|---|---|---|
| creación | control parental activo | `pending_parent_approval` | Validación de propietario del alumno |
| creación | sin control parental | `open` | Validación de propietario del alumno |
| `pending_parent_approval` esperado | padre aprueba | `open` | Propiedad sí; **estado no validado, sin lock** |
| `pending_parent_approval` esperado | padre rechaza | `rejected` | Propiedad sí; **estado no validado, sin lock** |
| `open` | profesor acepta | `accepted` | Lock + comprobación interna de `open` |
| `open` | profesor rechaza | `teacher_rejected` | Comprobación previa; sin transacción/lock |
| `accepted` | completar/cancelar clase | permanece `accepted` | No hay sincronización |
| cualquiera del padre | aprobar/rechazar | `open`/`rejected` | Actualmente posible por falta de guard |
| cualquiera | transición a `completed` | — | Estado permitido pero nunca escrito |

Diagrama nominal:

```text
creación → pending_parent_approval → aprobación padre → open → aceptación profesor → accepted
creación → open → rechazo profesor → teacher_rejected
pending_parent_approval → rechazo padre → rejected
```

Diagrama efectivo no deseado:

```text
accepted → aprobación padre → open → segunda aceptación → segunda clase/reserva
teacher_rejected/rejected/completed → aprobación padre → open
```

Para solicitudes genéricas, `teacher_rejected` es global: un profesor la retira para todos. No hay tabla de rechazos por profesor.

### Lesson

Estados permitidos:

```text
scheduled | in_progress | completed | cancelled
```

| Estado actual | Acción | Estado siguiente | Protección real |
|---|---|---|---|
| creación desde solicitud | aceptar | `scheduled` | Transacción, locks, reserva y Zoom |
| `scheduled` | `/complete` | `completed` | Lock, consumo y contador |
| `scheduled` | crear reporte directamente | `completed` | **Sin lock, consumo ni contador** |
| `scheduled` | cancelar | `cancelled` | Lock, refund y evento |
| `scheduled` | reprogramar | `scheduled` | Estado solo antes de escribir; sin lock/transacción |
| cualquiera | iniciar | `in_progress` | No existe transición |

Completar y cancelar simultáneamente están serializados por el lock de `classes`. Reprogramar no participa en esa serialización y puede escribir horario/metadatos después de una cancelación o finalización concurrente.

### RechargeRequest

```text
creación → pending → aprobación admin → approved
creación → pending → rechazo admin → rejected
```

Ambas decisiones bloquean la fila y revalidan `pending` dentro de la transacción. Una segunda aprobación de la misma fila no duplica el depósito. El sistema no impide crear otra fila con el mismo `operation_number`.

## Reglas actuales de créditos

- Se reservan al aceptar, antes de crear `classes` y antes de cambiar la solicitud a `accepted`.
- Se reservan exactamente **2 créditos**, sin importar `duration_minutes`.
- No hay redondeo: 30, 60, 90 o 240 minutos cuestan lo mismo.
- Se consumen cuando el profesor invoca `/lessons/{lesson}/complete`.
- Se liberan totalmente al cancelar por padre, profesor o admin; no hay penalidades diferenciadas.
- Rechazar una solicitud no mueve créditos porque aún no existe reserva.
- Si Zoom falla con excepción, la transacción revierte los movimientos internos.
- Si Zoom se crea y una escritura posterior falla, puede quedar una reunión externa huérfana porque la API no participa en el rollback.
- Si el reporte no se crea tras `/complete`, el consumo ya quedó confirmado y solo se envía un recordatorio posterior.
- Si el reporte se crea directamente sobre una clase `scheduled`, la clase se completa sin consumo.
- Una clase abandonada no se liquida ni expira; mantiene la reserva indefinidamente.
- Sin créditos suficientes, la aceptación aborta con 422 antes de Zoom.
- La regla actual **no coincide** con `1 crédito = 1 hora completada`.

## Hallazgos de la línea base

La tabla conserva la evidencia encontrada en `master` antes de Fase 1. El estado vigente de cada corrección está en **Estado después de Monetización Fase 1**; no debe interpretarse esta tabla como descripción del código corregido de la rama.

| ID | Severidad | Hallazgo | Evidencia | Riesgo | Corrección recomendada |
|---|---|---|---|---|---|
| MON-001 | CRITICAL | `operation_number` no es único ni se consulta antes de crear | migración `000004:17-21`; `CreditController:50-72` | Un comprobante puede generar varias filas y depósitos aprobables | Normalizar operación y agregar idempotencia/UNIQUE con alcance definido por negocio |
| MON-002 | CRITICAL | El profesor puede borrar su cuenta y eliminar en cascada ledger, recargas y clases | `ProfileController:46-56`; migraciones base y `000003:13`, `000004:13` | Desaparece la auditoría de dinero y se facilita reingreso para nuevo bono | Prohibir borrado físico financiero; anonimizar usuario y retener ledger inmutable |
| MON-003 | CRITICAL | La UI de pago usaba un teléfono y QR ficticios | `Teacher/Credits/Index.vue` en la línea base | No existía destino real verificable para cobrar dinero | Configurar beneficiario/canal real desde servidor antes de habilitar recargas |
| MON-004 | HIGH | Crear reporte puede completar una clase sin consumo, ledger ni contador | `LessonReportController:36-68` | `credits_reserved` queda bloqueado y saldos/experiencia divergen | Unificar toda finalización en un único servicio transaccional idempotente |
| MON-005 | HIGH | Aprobar/rechazar por padre no valida estado ni bloquea la solicitud | `ClassRequestController:73-86` | Puede reabrir una solicitud aceptada y permitir otra clase/reserva | Permitir solo `pending_parent_approval` dentro de transacción con lock |
| MON-006 | HIGH | El saldo duplicado es fuente operativa y no existe reconciliación con ledger | `CreditController:27-40`; ausencia de reconciliador | Drift silencioso sin detección ni reparación | Definir ledger como fuente o implementar reconciliación automática y alertas |
| MON-007 | HIGH | Las transacciones no enlazan clase, recarga, actor ni clave idempotente | migración `000003:11-20` | No se puede demostrar qué evento originó un movimiento ni prevenir duplicados por entidad | Añadir referencias, actor, idempotency key y metadatos inmutables |
| MON-008 | HIGH | Costo fijo de 2 para duraciones de 30 a 240 minutos | `LessonController:21`, `25-29`, `82-109` | Precio inconsistente con la duración y con la regla 1 crédito/hora | Resolver créditos desde duración con política de redondeo decidida |
| MON-009 | HIGH | No existe settlement/expiración de clases abandonadas | Scheduler solo envía recordatorios | Reservas pueden quedar bloqueadas para siempre | Crear política y job idempotente de expiración, disputa y liberación/consumo |
| MON-010 | HIGH | La mentoría ocupa cupo pero nunca lo libera y no existe entidad de acompañamiento | `LessonController:93-101`; única escritura de `mentorship_slots_taken` | Cupos permanentes por una sola clase; agenda se agota irreversiblemente | Modelar membresía/mentoría y eventos explícitos de inicio/fin/cancelación |
| MON-011 | HIGH | El rechazo de solicitud genérica es global y compite sin lock con aceptación | `ClassRequestController:125-150`; `LessonController:57-63` | Un profesor bloquea a todos o deja clase aceptada con solicitud rechazada por carrera | Registrar rechazos por profesor y bloquear/revalidar al escribir |
| MON-012 | HIGH | El profesor puede completar una clase futura; no se verifica horario ni asistencia | `LessonController:170-207` | Consumo prematuro y gamificación manipulable | Definir evidencia/ventana de finalización y, si aplica, confirmación del padre |
| MON-013 | HIGH | No hay tests financieros reales y los E2E históricos pueden modificar producción por defecto | `MovaCriticalFlowTest`; `qa/playwright.config.js:17`; `qa/tests/10-create-report2.spec.js:19` | Regresiones contables/concurrentes pueden publicarse; ejecutar QA equivocado modifica datos | Crear suite aislada de controladores y concurrencia MySQL; eliminar default productivo |
| MON-014 | MEDIUM | Saldos y `amount` son enteros con signo, sin CHECK de no negatividad/positividad | migraciones `000002:12-13`, `000003:15` | Un nuevo camino de código puede guardar saldos negativos o movimientos inválidos | Usar unsigned/CHECK y validaciones de dominio adicionales |
| MON-015 | MEDIUM | Reprogramar no usa transacción ni lock y valida estado solo antes de escribir | `LessonController:292-335` | Carrera con cancelar/completar produce metadatos/eventos incoherentes | Bloquear clase y revalidar estado/solapamiento dentro de transacción |
| MON-016 | MEDIUM | `ClassRequest` y `Lesson` no sincronizan finalización/cancelación; hay estados inalcanzables | `LessonController:131`, `170-268`; enum `ClassRequest` | Solicitudes quedan `accepted`; `completed` e `in_progress` no representan realidad | Definir una máquina única y actualizar ambas entidades atómicamente |
| MON-017 | MEDIUM | La recarga no persiste aprobador, timestamps de revisión ni motivo de rechazo | migración `000004`; `RechargeController:61-83`; UI envía `{}` | Decisión administrativa no auditable; profesor recibe rechazo sin causa específica | Guardar `reviewed_by`, `reviewed_at`, `rejection_reason` y evento de auditoría |
| MON-018 | MEDIUM | Experiencia usa contador/booleano denormalizados sin reconciliar con clases reales | `TeacherProfile:33-36`; `LessonController:194-206`; `TeacherPublicController:23-27` | Precio desbloqueado/bloqueado distinto del historial visible | Derivar o reconciliar desde clases completadas por la ruta financiera válida |
| MON-019 | MEDIUM | Ofertas históricas sobre el límite pueden seguir activas | validación en `ClassOfferController:27-85`; `toggleActive:90-94`; sin migración de saneamiento | Un registro antiguo evade la política aunque las nuevas ediciones estén protegidas | Auditar/clamp de datos y validar también activación/publicación |
| MON-020 | MEDIUM | El profesor puede bajar `mentorship_slots_total` por debajo de `taken` | `TeacherProfileController:60-81` | Contadores imposibles y UI negativa/llena indefinidamente | Validar `total >= taken` bajo lock |
| MON-021 | MEDIUM | Zoom se crea dentro de una transacción que mantiene locks de DB | `LessonController:57-134` | Locks largos; si Zoom triunfa y el commit falla, reunión externa huérfana | Usar saga/outbox o compensación explícita sin mantener locks durante red |
| MON-022 | MEDIUM | El control de solapamiento no tiene constraint y el lock sobre ausencia no garantiza exclusión | `LessonController:44-76`; tabla `classes` sin índice/constraint temporal | Dos aceptaciones distintas pueden agendar horarios superpuestos | Serializar por profesor/slot y añadir estrategia de constraint verificable en MySQL |
| MON-023 | MEDIUM | Las rutas de profesor no exigen `teacher_profiles.is_verified` | `routes/web.php:80-101`; controladores no verifican perfil | Un perfil no aprobado podría crear ofertas, ver/aceptar solicitudes o pedir recarga | Middleware de profesor verificado para operaciones comerciales |
| MON-024 | LOW | Catálogo duplicado en Vue y PHP | `Teacher/Credits/Index.vue:201-205`; `CreditController:15-19` | Desincronización de UI; hoy el backend sí rechaza combinaciones falsas | Enviar catálogo desde servidor y aceptar solo un `package_id` |
| MON-025 | LOW | `ClassEvent` no usa FKs y no registra aceptación/completado/recargas | migración `class_events:11-19`; `ClassEvent.php` | Eventos huérfanos y trazabilidad parcial | Agregar integridad referencial/retención y eventos de dominio completos |
| MON-026 | LOW | Las notificaciones de recarga solo usan `mail` aunque definen `toArray()` | método `via()` de las tres notificaciones | No queda notificación in-app y un fallo de correo reduce visibilidad operacional | Añadir canal `database` o registrar estado de entrega según política |

## Casos de abuso

| Escenario | Resultado actual | Resultado esperado |
|---|---|---|
| Doble clic al aprobar la misma recarga | Una acredita; la otra espera lock y recibe 422 | Correcto, respuesta idempotente podría ser 200/estado actual |
| Dos admins aprueban la misma fila | Una sola acreditación | Correcto |
| Reusar número de operación | Varias solicitudes y varias acreditaciones posibles | Una operación no debe financiar más de una recarga válida |
| Reintentar creación de recarga tras timeout | Crea otra fila | Reutilizar clave idempotente y devolver la solicitud original |
| Dos profesores aceptan solicitud genérica | El lock permite solo una si sigue `open` | Correcto |
| Padre reabre solicitud aceptada | Vuelve a `open`; puede aceptarse otra vez | Rechazar transición terminal |
| Mismo profesor acepta dos veces sin reapertura | Segunda petición falla al ver `accepted` | Correcto |
| Completar dos veces por ruta normal | Segunda falla por estado bajo lock | Correcto |
| Cancelar dos veces | Segunda falla por estado bajo lock | Correcto |
| Cancelar y completar simultáneamente | Una gana el lock; la otra falla | Correcto |
| Reprogramar y cancelar/completar simultáneamente | Puede escribir horario/evento después del estado terminal | Debe serializarse y fallar |
| Crear reporte de clase programada | Marca `completed` sin consumir reserva | Debe usar la finalización financiera única |
| Completar una clase futura | Permitido | Restringir según política de asistencia/tiempo |
| Dos mentorías aceptadas con último cupo | Lock del perfil evita sobreventa | Correcto |
| Cancelar/completar mentoría | Cupo no se libera nunca | Liberar al terminar la relación según política |
| Rechazar solicitud genérica | Desaparece para todos los profesores | Solo excluir al profesor que rechazó |
| Borrar cuenta y registrarse otra vez | Ledger y bono anterior desaparecen; nueva verificación puede bonificar | Conservar historial e identidad de elegibilidad |

## Pruebas existentes

| Test | Ejecuta lógica real | Aislado | Resultado de esta auditoría |
|---|---|---|---|
| `MovaCriticalFlowTest::teacher_registration_dynamic_subject_and_price_limit` | Sí, registro y `ClassOfferController` | Sí, SQLite `:memory:` | Pasa; solo cubre S/20/S/25 |
| `MovaCriticalFlowTest::diagnostic_creates_generic_class_request` | Sí, `DiagnosticsController` | Sí | Pasa; no valida créditos ni concurrencia |
| Suite PHPUnit restante | Auth/perfil básicos | Sí | 27/27, 75 assertions |
| `qa/tests/09-validate-phase2.spec.js` | HTTP real sobre clases/reportes | No garantizado | No ejecutado; no afirma balances |
| `qa/tests/10-create-report2.spec.js` | Completa una clase fija y crea reporte | No; puede apuntar a producción | No ejecutado por ser destructivo |
| E2E matemático de créditos histórico | No localizado en el repositorio | — | No puede considerarse evidencia actual |

Una simulación que replique a mano las sumas de los controladores no prueba rutas, middleware, locks ni transacciones reales y no se considera cobertura suficiente.

## Pruebas faltantes

- Feature tests reales para bono, paquetes, recarga, aprobación, reserva, consumo y refund.
- Test que demuestre que crear reporte no puede omitir consumo.
- Tests de transición para todos los estados de `ClassRequest`, `Lesson` y `RechargeRequest`.
- Tests idempotentes para reintentos HTTP de creación/aprobación/finalización/cancelación.
- Tests de número de operación duplicado.
- Tests de reconciliación aggregate y por entidad del ledger.
- Tests de cancelación/devolución por padre, profesor y admin.
- Tests de clase abandonada y settlement.
- Tests de cupos de mentoría, liberación y reducción de capacidad.
- Tests de experiencia contra clases completadas reales.
- Tests de integridad al eliminar/anonimizar cuentas.
- Tests de concurrencia MySQL para aprobación doble, aceptación genérica, completar/cancelar, solapamientos y cupos. SQLite no valida los locks ni el ENUM de MySQL con fidelidad.

## Bloqueadores antes de cobrar dinero real

1. Hacer único/idempotente el comprobante de recarga y registrar revisión administrativa.
2. Sustituir el destino placeholder por un canal real y verificable.
3. Retener el ledger y las recargas ante borrado de cuenta; no permitir cascadas destructivas.
4. Unificar aceptación, finalización, reporte y cancelación en servicios transaccionales de dominio.
5. Definir ledger contextual e inmutable y reconciliarlo con saldos.
6. Corregir guards/transiciones de padre y rechazos genéricos por profesor.
7. Definir costo por duración y settlement de clases abandonadas.
8. Modelar el ciclo de vida de acompañamiento y liberar cupos.
9. Incorporar pruebas feature, idempotencia y concurrencia MySQL antes de otro deploy financiero.

## Orden recomendado de corrección

1. Congelar recargas reales y cerrar MON-001, MON-002 y MON-003.
2. Introducir un servicio financiero único con idempotencia, contexto y reconciliación.
3. Cerrar la ruta lateral de reportes y formalizar máquinas de estados.
4. Implementar costo por duración, settlement y políticas de cancelación.
5. Rehacer acompañamiento como relación con inicio/fin y cupos liberables.
6. Reconciliar experiencia/precios y sanear ofertas históricas.
7. Crear suite MySQL de concurrencia y ejecutar un backfill/reconciliation auditado antes de habilitar cobros.

## Decisiones de negocio pendientes

- Definir en Monetización 2 el redondeo exacto para 45/90/120+ minutos; 60 minutos y la fracción de 30 minutos ya fueron aprobados.
- ¿Cuándo se considera impartida una clase: acción del profesor, confirmación del padre, fin horario o combinación?
- ¿Qué devolución/penalidad aplica según quién cancela y con cuánta anticipación?
- ¿Qué ocurre con una clase sin confirmar después de su hora: consumo, devolución, disputa o revisión admin?
- Confirmar si, al incorporar nuevos beneficiarios, la unicidad debe incluir también el destino; Fase 1 usa método de pago + operación normalizada.
- ¿Qué evidencia de pago debe conservarse y durante cuánto tiempo?
- ¿Cuánto tiempo debe retenerse el ledger tras borrar/anonimizar una cuenta?
- ¿El bono se entrega por cuenta, persona verificada, teléfono/documento o una sola vez de por vida?
- ¿Qué es una mentoría continua: una clase, una suscripción, un alumno activo o un periodo? Definir cuándo ocupa y libera cupo.
- ¿Las 5 clases de experiencia requieren reporte, confirmación del padre o ausencia de disputa?

## QA ejecutado

- `composer validate`: correcto.
- `composer qa:safe`: PHPUnit correcto; el build incluido requirió repetición fuera del sandbox por `spawn EPERM` de Windows.
- PHPUnit: 50 tests, 151 assertions, SQLite `:memory:`.
- `npm run build`: correcto, 232 módulos transformados en la pasada final.
- `php artisan route:list --no-interaction`: correcto, 103 rutas.
- `git diff --check` antes de crear este reporte: correcto.
- No se ejecutaron Playwright, migraciones, seeders ni consultas de producción.

## Conclusión

Fase 1 cerró los tres riesgos críticos originales, protegió los eventos financieros contra reintentos y añadió cobertura de controladores reales. El módulo todavía no conserva invariantes globales mediante reconciliación ni ha validado concurrencia sobre MySQL. Es adecuado para continuar Monetización 2 con datos de prueba, no para aceptar dinero real.

**Monetización segura para dinero real: no.**
