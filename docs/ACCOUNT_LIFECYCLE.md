# Perfil, configuración y eliminación automática de cuenta

Estado del código: 2026-10-23. «Eliminar mi cuenta» **cierra y suprime la cuenta de forma automática y de autoservicio**, sin aprobación de soporte. Sustituye al flujo provisional anterior («solicitud pendiente de revisión»), que ya no se genera. Las evidencias de validación y de despliegue están al final y en `release/DEPLOY_HISTORY.md`.

## Recorrido del usuario

Menú del avatar → **Mi perfil** → **Privacidad y cuenta** → **Eliminar mi cuenta** → consecuencias reales (clases futuras, solicitudes, créditos, alumnos) → identidad (contraseña actual o código de un solo uso al correo verificado) → aceptación expresa (y, si hay créditos que se pierden, aceptación expresa de esa pérdida) → procesamiento automático → pantalla **«Tu cuenta de MOVA ha sido cerrada»** con «Crear una cuenta nueva» y «Volver al inicio». La sesión termina en el mismo paso. Nada se elimina con un GET ni con un clic accidental: es `DELETE /profile` con CSRF, `confirmation`, `scope=account`, reautenticación y límite de frecuencia (5/min; código 3 cada 10 min).

Pueden hacerlo usuarios con rol `parent` o `teacher` (ambos roles = una sola identidad, cierre completo). Un `admin` nunca puede (protege el último acceso administrativo).

## Arquitectura

`AccountLifecycleService::closeAccount()` ejecuta **una transacción** con locks en el orden global `ClassRequest → Student → Lesson → User → TeacherProfile` (el mismo que usan agendar, contraofertar y cancelar). Las lecturas de alcance son sin bloqueo y **fuera** de la transacción: así no se fija un snapshot REPEATABLE READ anterior a lo que otro proceso confirme mientras se esperan sus locks (hallazgo real de las pruebas multiproceso: sin esto, una clase o recarga confirmada justo antes del cierre no se veía). Es idempotente (una solicitud `completed` se devuelve tal cual; dos peticiones simultáneas se serializan por el lock del usuario) y reintenta deadlocks (5 intentos).

Pasos dentro de la transacción: (1) cierre de identidad (`account_closed_at`; cualquier alta concurrente ve la cuenta cerrada al obtener su lock) → (2) cancelación de clases futuras con la devolución de reserva existente (`LessonSettlementService::refund`; una anomalía de ledger no se fabrica: queda como incidencia) → (3) solicitudes → (4) créditos → (5) supresión de datos personales → (6) constancia `completed` con el resumen del tratamiento (`outcome`).

Después del commit (best-effort, reintentable, sin bloquear el cierre): avisos a la otra parte (`ClassCancelledNotification`, `TeacherClosedAccountNotification`), avisos a profesores elegibles de solicitudes republicadas, alertas operativas (`OperationalAlert`) y el job `FinishAccountClosureCleanup` (borrado del avatar propio en Cloudinary; `cleanup_status` = `not_required|pending|completed|failed`). `php artisan mova:account-closure:finish` (cada hora) reintenta limpiezas y avisa de saldo aparecido tras el cierre. La interfaz muestra la limpieza pendiente de forma honesta y no promete borrado absoluto.

## Clases y solicitudes

- **Profesor que cierra:** perfil despublicado (`is_verified=false`, ofertas inactivas, materias y disponibilidad retiradas), clases futuras canceladas con su reserva devuelta y aviso al padre. Solicitudes **generales** (sin profesor/oferta dirigida) vuelven a `open` con misma materia, duración y preferencias, `republished_at` (para que el plazo de expiración de 24 h cuente desde ahí) y se avisa a los profesores elegibles. Solicitudes **dirigidas** a ese profesor (código u oferta) pasan a `expired` y se avisa al **padre** para que confirme una nueva; no al administrador. Nunca se republica la solicitud de un padre con cuenta cerrada.
- **Padre que cierra:** sus solicitudes activas pasan a `expired` (nunca republicadas), sus clases futuras se cancelan con aviso al profesor y devolución de la reserva; el texto libre de sus solicitudes se anonimiza.
- Clases terminadas, asistencia, consumos, reportes y reclamaciones **no se alteran**.
- Esquema: un alumno tiene un único `parent_user_id`; no existe segundo tutor autorizado en el modelo, por lo que no hay transferencia ni mantenimiento de clases bajo otro tutor.

## Créditos

Nunca un `UPDATE` a cero: el ledger es append-only. Primero se devuelven las reservas de las clases canceladas. Del saldo disponible se calcula la parte **reembolsable** (créditos de recargas aprobadas dentro de la ventana de 7 días de `release/MOVA_V1_DECISIONS.md` §3, acotada al saldo) y la parte **perdible**. La perdible se da de baja con un asiento `forfeiture` de importe negativo (idempotencia `account-closure:{user}:forfeiture`; `LedgerReconciliation::derivedAvailableFor` lo suma) **solo si el titular lo aceptó expresamente** (`accept_credit_loss`; validado en servidor bajo lock). La parte reembolsable **no se pierde**: queda en la billetera cerrada, inutilizable, y genera una incidencia para tramitar la devolución por el proveedor (única vía de reembolso, decisión vigente). Los créditos de una cuenta cerrada no se transfieren ni se restauran al registrarse de nuevo. Un pago confirmado **después** del cierre acredita por el servicio normal y queda marcado como incidencia (`balance-after-closure`); no es utilizable. No se tocan recargas, órdenes, webhooks ni depósitos históricos.

## Datos personales

| Clase | Dato | Tratamiento |
|---|---|---|
| A. Suprimible | sesiones, tokens API, tokens de recuperación, notificaciones in-app, registros de uso de IA, disponibilidad y materias del profesor, avatar (Cloudinary, job) | se elimina |
| B. Anonimizable | `users` (nombre «Cuenta eliminada», `deleted-{id}@mova.invalid`, sin teléfono ni verificación ni 2FA ni WhatsApp), alumnos (nombre, fecha de nacimiento, colegio; fila ocultada), texto de solicitudes, diagnósticos del alumno, IP/agente de consentimientos, `bio`/Yape/Plin del profesor, descripción de ofertas | se anonimiza de forma irreversible |
| C. Dato de terceros | clases, reportes pedagógicos del profesor, reseñas | se conservan vinculados a la fila anonimizada |
| D. Conservación justificada | ledger de créditos, recargas, órdenes de pago (Culqi / Mercado Pago histórico), constancias de aceptación legal, reclamaciones (Libro), eventos de clases y presencia | se conservan con acceso restringido; ya no contienen la identidad en `users` |
| E. Obligación pendiente | créditos reembolsables, clases con anomalía de ledger | cierre inmediato; incidencia automática (`OperationalAlert`), sin bloquear |

La fila `users` se conserva únicamente como ancla de integridad (FKs de ledger y clases) con identificador propio.

## Cuentas suspendidas o con deuda

Pueden cerrarse automáticamente como cualquier otra (decisión del propietario, 2026-10-23), pero el cierre no sirve para evadir la restricción: su correo y su teléfono quedan en la lista de bloqueo `closure_blocklist` como **hashes HMAC** (clave de la aplicación; nunca se guarda el dato en claro; razón `suspended` o `debt`). El registro normal, el alta con Google y la verificación de teléfono rechazan esos datos con un mensaje genérico que remite a soporte. La deuda (saldo negativo) permanece en el ledger de la fila anonimizada; no se perdona ni se compensa. Un cierre normal no entra en la lista y su correo sigue pudiendo reutilizarse.

## Reutilización del correo

El correo (`deleted-{id}@mova.invalid`) y el teléfono verificado quedan libres. Se puede registrar una cuenta **nueva** con el mismo correo, como padre o como profesor, con identidad de aplicación nueva, sin roles, clases, solicitudes, créditos, sesiones, tokens ni privilegios anteriores, y pasa por el registro normal (términos vigentes, verificación de correo). No hay conexión automática con pagos o saldos antiguos.

## Cuentas del flujo anterior

Una cuenta cerrada con el flujo provisional (`account_closed_at` sin `account_erased_at`) conserva acceso restringido a `/cuenta/solicitud`, que muestra la reconfirmación con las consecuencias nuevas. Con contraseña o código, `DELETE /profile` (única ruta de escritura exenta, junto al código y la cancelación de clases) completa **el mismo servicio**: la constancia pasa de `pending_review|responded` a `completed` y se registran los eventos `requested`, `reconfirmed`, `erased`. No se elimina nada de estas cuentas sin esa reconfirmación y no depende de soporte. Los administradores solo pueden dejar una nota visible para el titular mientras la solicitud siga heredada; una constancia completada no admite acciones.

## Administración

`/admin/account-deletions` (admin + MFA) lista los cierres: resumen del tratamiento, incidencias excepcionales (limpieza fallida, clases sin cancelar, créditos dentro del plazo de devolución) y solicitudes heredadas. No hay aprobación, reactivación ni botón de borrado: la identidad suprimida no se restaura. Las constancias no impiden que el correo se registre de nuevo.

## Seguridad

- Reautenticación: contraseña actual o código de 6 dígitos de un solo uso (hash en caché, atado a usuario+sesión, caduca a los 10 min, se consume al intentar, nunca en logs ni colas; rechaza transporte `log`/`array` y correos fuera de la lista permitida). La identidad se vuelve a comprobar bajo el lock.
- Autorización en servidor (rol, ownership y admin excluidos); la constancia sale de la sesión autenticada o de una referencia de sesión de 30 minutos, nunca de un id de la URL.
- Tras el cierre: sesiones, tokens, recordatorio y recuperación revocados; `EnsureAccountIsOpen` bloquea web y API; `PreventClosedAccountNotifications` y el guard del job de WhatsApp impiden avisos; las altas concurrentes se rechazan por `lockOpenParticipants`.

## Pruebas y límites

`AccountLifecycleTest` (40 pruebas), perfil, financieras afectadas y 15 escenarios multiproceso en MySQL (`scripts/qa-account-closure-race.php`): doble cierre, cierre vs agendar/solicitar (ambos órdenes), profesor vs aprobación admin y vs reserva, recarga acreditada antes/después del cierre, liquidación del scheduler con notificaciones reales, cancelación vs cierre. Todo con datos sintéticos; nunca cuentas reales.

Pendiente de decisión legal/comercial (no bloquea la función): texto de Términos que mencione la pérdida del saldo no usado (hoy se acepta expresamente en el modal), validación de la retención de ledger/recargas y reclamaciones frente al Reglamento de la Ley 29733 y a las obligaciones tributarias, y la política para devolución de créditos reembolsables (hoy: incidencia y reembolso por el proveedor). No se afirma «eliminado definitivamente» donde hay retención justificada.

## Esquema y recuperación

`2026_10_23_060000_create_account_deletion_requests` y `2026_10_23_070000_automatic_account_closure` (aditivas: `users.account_erased_at`, `class_requests.republished_at`, columnas de `account_deletion_requests`, tipo de ledger `forfeiture`). `down()` es un no-op deliberado: la estrategia es hacia adelante, no `migrate:rollback`. Un rollback de imagen anterior a esta versión **no** revierte supresiones ya hechas (son irreversibles por diseño) y el código viejo ignoraría `forfeiture` en la conciliación del ledger; preferir corregir hacia adelante. Un fallo secundario (Cloudinary) se reintenta solo cada hora y queda visible en el panel; ningún fallo secundario reabre la cuenta.
