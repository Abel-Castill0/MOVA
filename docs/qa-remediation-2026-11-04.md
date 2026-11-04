# MOVA — Plan de corrección de la auditoría QA (04/11/2026)

**Rama:** `fix/qa-audit-20261104` · **base:** `2170a690` (`master`)
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
