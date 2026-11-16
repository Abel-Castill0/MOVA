# MOVA — Revisión de accesibilidad, UX/UI y flujos administrativos (16/11/2026)

## Naturaleza de la evidencia
Revisión **del código publicado en la rama** `feat/mova-lima-realtime-ux-20261116`, contrastada con `master`; no es auditoría visual de producción certificada. Inventario del repositorio: 69 vistas Vue, 36 componentes Vue, múltiples rutas autenticadas por rol. Se inspeccionaron las vistas principales y un subconjunto de componentes y flujos sensibles. No afirmar que se visitó cada vista, modal o botón en navegador.

**Bloqueos instrumentales verificados:** el dominio `https://movaeduca.me`, `/login` y `/healthz` no pudieron abrirse desde la herramienta web; el contenedor no resuelve DNS externo; la lectura de pestañas exige la sesión de navegador de origen. No hay un checkout Laravel completo ni acceso operativo a Azure para Vite, PHPUnit, E2E o desplegar por digest desde esta sesión.

## Fallos confirmados por el código y correcciones

| Riesgo | Causa raíz observada | Corrección en PR #18 |
|---|---|---|
| P0 — suspensión no intencionada | Enlace Inertia `POST` usado como activador de la confirmación de suspensión | Activador `button` de solo UI; POST exclusivamente al confirmar modal, guard contra doble envío y error visible |
| P1 — diálogos que no se cierran | `onSuccess` invocaba funciones de cierre bloqueadas por flags `processing/rejecting` todavía activos | Limpieza directa del modal tras éxito; se mantiene el bloqueo solo mientras hay petición |
| P1 — horario incorrecto fuera de Perú | `datetime-local` leído con `new Date(value)`, que interpreta la zona horaria del navegador | Funciones compartidas de conversión `America/Lima`, entrada validada, minuto mínimo del servicio y transporte UTC |
| P1 — verificación de profesor accidental | Enlace Inertia `POST` sin diálogo de confirmación | Confirmación explícita y accesible, deshabilitación durante solicitud y errores recuperables |
| P1 — moderación desaparece tras error | `v-else` asociado al banner de error ocultaba toda la lista al fallar reactivación | Lista independiente `v-if=reviews.data.length`, error sin ocultar registros |
| P2 — identificadores duplicados | Dos instancias de campana renderizaban `id=notification-panel` al mismo tiempo | Un id por `placement` y `aria-controls` sincronizado |
| P2 — modales administrativos inconsistentes | Overlays independientes sin el contrato compartido de foco, Escape y bloqueo de scroll | Uso de `Modal.vue`, etiquetas asociadas, botones y estados visibles |
| P2 — estados y lectura | Jerarquía irregular, filtros poco claros y fuentes pequeñas en varias vistas | Iteraciones en solicitudes padre/docente, créditos, sidebar, calendario y avisos |

**Alcance financiero:** no se tocó el ledger ni la lógica de cobros, conciliación, reembolsos, reservas ni saldo. No se realizaron operaciones financieras reales.

## Evidencia de pruebas disponible

En un entorno auxiliar Node 22 se ejecutaron pruebas puras reproducidas de `peruDate`, `weekGrouping`, `pusherHosts` y `bodyScrollLock`: **8 casos × 3 zonas horarias (UTC, America/Lima, Asia/Tokyo) = 24 PASS, 0 FAIL**. No fue la suite de la aplicación completa; estos resultados no demuestran una compilación Vue válida ni que el backend o producción funcionen. Las pruebas fuente quedan versionadas en `tests/Frontend/` con guardas adicionales contra regresiones administrativas.

## Candidatos de diseño a verificar mediante inspección visual real

- Colores duros y tipografía micro: `About.vue`, `Welcome.vue`, registro, bandeja de solicitudes y pantallas admin contienen numerosas clases directas, `text-xs` o textos de 10–11 px. Requieren medición de contraste WCAG y revisión a 320/375/390/768/1024/1440 px; la presencia de clases NO prueba por sí sola incumplimiento.
- Arquitectura de información: revisar orden visual de prioridades en padre/profesor/admin, foco visible al navegar por teclado y estado de botones de acción. Probar estados sin resultados, errores 4xx/5xx, pagos pendientes y reconexión.
- Interacciones: revisar todos los diálogos con Enter, Escape, Tab/Shift+Tab, clic fuera y envío duplicado; overlay móvil/sidebar, scroll, inputs y errores de servidor. No declarar PASS sin navegador.
- Revisión visual por rol y captura comparativa: visitante, padre, docente verificado/no verificado y administrador con MFA; preservar privacidad de menores.
- Revisión de componentes compartidos: `Modal.vue`, `AppLayout.vue`, `NotificationBell.vue`, tarjetas, calendario, formularios y validaciones Inertia.

## Bloqueadores de merge/despliegue

1. Recuperar checkout íntegro e instalar dependencias en entorno autorizado; ejecutar `npm ci`, `npm run check:realtime-ux`, `npm run build`, `php artisan test` y MySQL QA.
2. Ejecutar Playwright real en móvil/escritorio y sesiones independientes de padre, profesor y administrador, sin contaminar ni cobrar cuentas reales.
3. Revisar la configuración efectiva de tiempo real; el código de Echo/Pusher no es Microsoft SignalR. Probar desconexión/reconexión y aislamiento entre usuarios.
4. Validar todos los flujos financieros sin contabilizar dinero dos veces; cualquier prueba live debe seguir `AGENTS.md`.
5. Solo con los gates en PASS, fusionar el PR, producir imagen GHCR inmutable sin GitHub Actions, verificar digest/rollback, desplegar los tres Container Apps según `docs/release/MOVA_PRODUCTION_RUNBOOK.md`, observar `/healthz`/`/readyz` y registrar evidencias en `DEPLOY_HISTORY.md`.

**Estado: NO-GO para producción** hasta completar las pruebas y la publicación verificable. Esta documentación no reemplaza evidencias reales.

## Segunda iteración UX/UI (misma rama, 16/11/2026)

- Se incorporó `Components/PageHeader.vue`, usado en solicitudes docente, solicitudes del padre y créditos. Orden de título: `AppLayout` tiene el `h1` de la topbar; cada `PageHeader` conserva `h2`; módulos internos tienen `h3`.
- Se mantuvieron acciones y rutas existentes. Las solicitudes del padre que requieren aprobación/rechazo ahora abren un modal de confirmación accesible y emiten el POST únicamente después de confirmar, con guard de doble envío y error de servidor.
- En créditos, se eliminó el distintivo fijo `pack.code === 'pro'`: el aviso de mayor ahorro se deriva de `saving_pen` entregado por Laravel. Se distinguen correctamente importes positivos, operaciones de pago manual y compras en línea sin número de operación. El texto de Yape no promete una acreditación antes de confirmación.
- En registro se sustituyeron radios ARIA personalizados por inputs radio nativos con tarjeta visible, labels y foco. El wizard y submit rechazan pasos no válidos o envíos simultáneos; condiciones externas incluyen `noopener noreferrer`.
- El token de borde de controles `--line-strong` cambió a `#768395`: contraste calculado 3.85:1 sobre `#fff`, frente al 2.56:1 anterior. Sigue sin demostrar AA integral sin auditoría de navegador y estados.
- `npm run check:realtime-ux` incluye ahora las pruebas fuente de diseño/flujo; estas son guardas de regresión y no sustituyen el compilador Vue ni Playwright. La revisión de código de las rutas modificadas (8 verificaciones en el conector de GitHub) fue satisfactoria. **No se ejecutó el build real ni se publicó esta rama.**

**Seguimiento necesario:** ejecutar el build y E2E de roles con backend y entornos de prueba antes de fusionar o desplegar. La información real de costo de Azure y estado activo del proveedor WebSocket no está disponible en esta sesión; no se habilitaron recursos ni pagos.
