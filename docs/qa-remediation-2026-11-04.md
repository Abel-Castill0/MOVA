# MOVA — Plan de corrección de la auditoría QA (04/11/2026)

**Rama:** `fix/qa-audit-20261104` · **base original:** `2170a690` (`master`); el 05/11 se integró en la rama de trabajo el avance de `master` hasta `51fb40b`.
**Política:** ningún cambio de datos reales, activación de cobros ni despliegue automático a producción durante esta corrección. Los tests mutantes solo utilizan fixtures sintéticos de QA.

## Alcance, cambios y criterios de aceptación

| ID | Implementación de código | Prueba y criterio de cierre | Trabajo operativo restante |
|---|---|---|---|
| QA-001 Menú móvil | Eliminar el solapamiento, Escape, diálogo con foco, restauración de scroll y cierre al pasar a desktop | `qa/tests/qa-remediation.spec.js`: cerrar con X/Escape, comprobar foco y overflow en 390 px | Smoke en producción después de despliegue; otros tamaños y navegadores |
| QA-002 Docentes QA públicos | Campo independiente `teacher_profiles.is_publicly_listed` (por defecto true para preservar disponibilidad); endpoint admin MFA, diálogo de confirmación, filtros coherentes en home/marketplace/perfil/About/sitemap | `PublicTeacherListingTest`: ocultar no revoca `is_verified`, elimina ficha y URL pública; solo admin puede publicar | **Identificar por ID** y despublicar selectivamente `Docente Abel (verificación)` y `Profesor QA MOVA` en `/admin/users` después de la migración, sin eliminar cuentas ni historial. Comprobar si otros perfiles de prueba están verificados |
| QA-003 Materias duplicadas | `SubjectNormalizer` identifica `Matemáticas`/`Matemática`; `Subject::firstOrCreateByName` reaprovecha variantes históricas y aplica `Álgebra`/`Matemática` a nuevos registros | `SubjectAliasTest`: idempotencia, legado y presentación editorial | **Fusión histórica aún pendiente.** No actualizar IDs ni borrar filas sin snapshot, conteos de FK, conflictos `teacher_subject.specific_rate`, ofertas/solicitudes/diagnósticos y backup/restauración ensayada |
| QA-004 Suspensión accidental | El primer clic es `<button type=button>`; únicamente el botón del modal envía POST, con MFA existente; reactivación también confirma | E2E asegura 0 POST al abrir/cancelar/Escape; `AdminActionsTest` conserva validaciones del servidor | Validar en staging con administrador sintético |
| QA-005 Registro prematuro | `submit()` solo envía en paso final, Enter intermedio avanza solo si ese paso es válido | E2E Enter en paso 2 no hace POST | E2E para ambos roles y validaciones de todos los pasos |
| QA-006 Datos internos en Inertia | Props de usuarios y listas de solicitudes/clases reducidas a DTOs de tabla; no se serializan modelos completos de padres y menores | `AdminUserListExposureTest` y `AdminListPrivacyTest`: allowlists exactos y ausencia de hash OTP | Revisar otras páginas privadas y API con pruebas ASVS/IDOR |
| QA-007 Modal inaccesible | Usuarios, acciones de clases y rechazo docente reutilizan `Modal.vue` (rol, Escape, ciclo de foco, restauración) | Prueba E2E de cerrar suspensión; ampliar a lector de pantalla/teclado para las otras acciones | Regresión en móvil y escritorio |
| QA-008 Compras | **Sin cambio de autorización comercial**: Culqi tiene configuración reciente, pero cobros nuevos siguen cerrados | Repetir sandbox end-to-end y pruebas de webhooks/concurrencia antes de cualquier apertura | Bloqueo legal/comercial/hosting y piloto live documentados en `payments-culqi.md`; no abrir pagos aquí |

## Secuencia de release segura

1. **Código y pruebas:** `git diff --check`; compilar Vue/Vite; PHPUnit SQLite/MySQL, Playwright contra SQLite sintética; revisión de `mova-security-finance`.
2. **CI y revisión:** abrir PR contra `master`; todos los trabajos `frontend/composer/sqlite/mysql/e2e` verdes; inspeccionar y corregir fallos sin desactivar guardas.
3. **Migración forward-only:** revisar la nueva columna, respaldar datos y ejecutar únicamente por el Job de migraciones del runbook, no al arrancar web.
4. **Curación de contenido:** admin con MFA oculta perfiles QA identificados por ID y constata que solo desaparecen de las superficies públicas. Ningún borrado de cuentas ni datos de clases.
5. **Materia histórica:** producir inventario de duplicados, historial y conflictos. Planificar una fusión transaccional con rollback tras validación de negocio y backup, sin cambios automáticos sobre producción.
6. **Smoke de despliegue:** menú 320/375/390/768 px, modales teclado y cierres, registro parcial sin POST, roles, logs/console, `sitemap.xml`, stats, catálogo; comparar commit desplegado. No realizar POST real de suspensión/pago en producción.
7. **Gate de aceptación:** no certificar 100 % ni activar compras hasta completar cuentas de prueba autenticadas, accesibilidad, seguridad por rol y las integraciones externas.

## Riesgos/decisiones residuales

- `is_publicly_listed` es **publicación comercial**, no habilitación para enseñar; mantener `is_verified` para autorizaciones operativas. Su default `true` conserva la visibilidad actual hasta que un administrador actúe sobre los perfiles QA.
- `Matemáticas` y `Matemática` pueden tener asociaciones distintas con docentes y tarifas específicas. Una migración de fusión automática sin revisar conflictos sería potencialmente destructiva.
- La configuración de webhooks o llaves Culqi no es prueba de pagos reales ni subsana la restricción comercial del hosting.
- El catálogo y los flujos de usuarios autenticados deben comprobarse nuevamente **después** del despliegue; pasar CI en el commit fuente no certifica el sitio publicado.

## Segunda pasada — sesiones reales en Brave (05/11/2026)

El propietario dejó iniciadas una pestaña de administrador (con MFA ya superado) y otra de profesor en `movaeduca.me`. **No se cerró sesión, no se leyó el código MFA, no se ingresaron credenciales ni se ejecutó una transacción.** La pestaña administrativa quedó disponible para el propietario.

### QA manual observado en producción, exclusivamente sin modificaciones

| Superficie | Verificación y resultado | Limitación |
|---|---|---|
| Admin dashboard | Carga autenticada. Mostró **5** por verificar mientras la lista pendiente mostró **4** y 1 rechazado: defecto de métrica confirmado por `DashboardController` | Se corrige en esta rama, falta desplegar |
| Usuarios | Lista accesible; se inspeccionó presencia de Suspender/Reactivar. No se activaron | En producción el primer clic aún puede provocar POST antes del modal: QA-004 de este PR |
| Docentes | Modal «Rechazar» abre y «Cancelar» cierra sin mutación; modal desplegado carece de `role=dialog` | QA-007 de este PR; Verificar/Re-verificar no pulsados |
| Solicitudes y clases | Rutas y filtros administrativos accesibles; sin casos reales suficientes para validar transiciones completas | Pruebas de ciclo con fixtures sintéticos pendientes |
| Recargas | Listado accesible; aparece un intento de pago histórico fallido pendiente/revisado | No se realizaron aprobaciones ni cobros; conciliar estatus sin mutar |
| Operaciones | Se observó `WHATSAPP_PROVIDER_INVALID` al usar `waha`: `HealthCheck` solo permitía `meta`, mientras el binding admite `meta` y `waha` | Corregido en código; el próximo `mova:health-check --alert` del scheduler debe cerrar el falso positivo **después** del despliegue |
| WhatsApp | Panel indica «Conectado», pero el botón de mensaje de prueba estaba deshabilitado | No se enviaron mensajes; estado contradictorio con alerta operativa anterior |
| Reseñas, uso IA, privacidad y reclamaciones | Vistas accesibles, filtros/controles presentes. IA indica «desactivada» | No hay muestra suficiente para probar moderación ni cierre de cuentas |
| Campana y menú de cuenta | Abrir/cerrar por Escape funciona. Se renderizan 20 avisos, pero la campana contaba solo los visibles; elementos sin rol de acción accesible | Nuevo contrato API y accesibilidad en esta rama; aún sin deploy |
| Pestaña de profesor | Mostraba dashboard docente, pero al entrar en «Solicitudes» devolvió 403 | Probable sesión de mismo origen compartida con admin; **no cambiar de usuario** ni invalidar sesión MFA; probar docente en perfil de navegador aislado |

### Correcciones adicionales (QA-009 a QA-012)

| ID | Cambio | Prueba |
|---|---|---|
| QA-009 Notificaciones | El API devuelve 20 avisos recientes **más** `unread_count` global, marca todas mediante UPDATE filtrado por cuenta; campana con botones de teclado, feedback de error, región accesible, Escape/foco | `NotificationBellTest` (25+ avisos, aislamiento de usuario, leer todos); E2E aislado de región/Escape |
| QA-010 Modal calendario | Los bloques de clase son botones de teclado; detalle con Escape, Tab/Shift+Tab, bloqueo/restauración de scroll y foco | Compilación SFC, pendiente Playwright con una clase fixture |
| QA-011 KPI docentes | «Profesores por verificar» excluye rechazados, en consonancia con «Pendientes» de la lista | `AdminDashboardPendingTeachersTest` |
| QA-012 Falsa alerta WAHA | `HealthCheck` y el registro del proveedor consumen la misma allowlist desde `config/services.php`, incluyendo `waha`; no se relaja el fallo cerrado para valores desconocidos | `SchedulerConfigurationTest`: WAHA permitido / `twilio` inválido; ejecutar health-check programado post-despliegue para resolver alerta persistida |
| QA-013 Verificación accidental | Confirmación antes de Verificar/Re-verificar; el servidor impide verificar sin descripción, tarifa positiva y materia, evitando fichas vacías y tarifas S/0 | `AdminActionsTest` prueba los tres rechazos y conserva el caso válido con aviso |
| QA-014 Docente suspendido en catálogo | El catálogo, la home, About, ficha directa y sitemap excluyen cuentas suspendidas; admin no puede publicarlas mientras dure la suspensión | `PublicTeacherListingTest`: aislamiento de la ficha suspendida y rechazo de publicación |
| QA-015 Readiness WAHA | Si WAHA está habilitado, health-check comprueba la presencia de URL, API key y HMAC; no imprime secretos ni contacta al proveedor | `SchedulerConfigurationTest`: acepta WAHA válido y genera `WAHA_CONFIG_INCOMPLETE` si falta el HMAC |
| QA-016 CI concurrencia de cierre | En CI #153, `admin-approval-vs-teacher-closure` quedó esperando `leader-ready` porque el fixture no tenía `bio` después de endurecer `verifyTeacher`. El fixture sintético ahora cumple los requisitos y se valida **antes** de lanzar procesos; el timeout nombra la barrera faltante. | Repetir `scripts/qa-account-closure-race.php` en MySQL QA: los 15 interleavings deben terminar, sin relajar el control real de verificación. |

**Gates pendientes:** PHPUnit y Playwright contra fixtures (no hay PHP ni Composer en el executor de esta sesión); CI completo del commit actualizado, migración de `is_publicly_listed`, validación de las acciones corregidas en staging y smoke post-despliegue. La verificación real de entrega WhatsApp exige una sonda aislada; `waha_configuration = credenciales presentes` no asegura sesión, red ni entrega. No declarar la aplicación «100 % verificada» hasta completar estos gates y un ensayo de reuniones, cobros sandbox y notificaciones reales controladas.
