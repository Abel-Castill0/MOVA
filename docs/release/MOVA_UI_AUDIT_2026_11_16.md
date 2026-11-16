# MOVA — Auditoría UX/UI, accesibilidad y funcionalidad (16/11/2026)

Estado: **CÓDIGO PUBLICADO EN PR #18 — NO APROBADO PARA DESPLIEGUE**.

## Alcance y metodología

- Inventario de GitHub: 69 vistas Vue y 36 componentes Vue de la aplicación; exploración estática de señales de riesgo (modales, controles de teclado, envío prematuro, texto tenue, navegación y tamaños móviles). 54 archivos del PR original y cambios posteriores integrados sobre la misma rama; no se han reemplazado cambios ajenos.
- Se inspeccionaron en detalle las rutas y componentes afectados, sus controladores cuando correspondía, las políticas generales de seguridad, el runbook y los tests existentes.
- **Sin acceso real a navegación en este entorno:** el browser conectado no está disponible desde esta sesión y ni el fetch web ni el contenedor pudieron resolver \`https://movaeduca.me\`. No hay capturas reales nuevas, sesiones autenticadas en producción, Lighthouse, métricas de red ni manipulación de todos los botones en un navegador. Los contadores de código son un inventario estático, no resultados de UX testing.
- Referencia de contraste: WCAG 2.2 AA exige >=4,5:1 en texto normal. Por cálculo sRGB sobre blanco, gray-400 ~2,54:1; slate-400 ~2,56:1; gray-600 y slate-600 ~7,5:1. Estos valores no sustituyen el cálculo del fondo efectivo en cada elemento ni una prueba axe.

## Hallazgos de origen y cambios implementados

| ID | Prioridad | Evidencia del código antes del cambio | Cambio en la rama |
|---|---|---|---|
| UX-01 | Alta | Admin/Users: enlace Inertia con \`method="post"\` y \`@click.prevent\` en el control «Suspender» que también abre confirmación: riesgo de mutación prematura | Control nativo \`type="button"\`; única llamada \`router.post\` al confirmar, error persistente y bloqueo de doble clic |
| UX-02 | Alta | Admin/Lessons, PendingTeachers, Reviews y Users: overlays propios sin la misma gestión de foco, Escape y scroll | Reutilización de \`Modal.vue\`, títulos accesibles, cierre protegido durante peticiones, errores anunciados |
| UX-03 | Media | Reseñas: error de «Mostrar» se escribía en un estado que solo aparecía dentro del modal «Ocultar» | Error visible fuera del modal y reinicio de estado al reintentar |
| UX-04 | Media | Acciones admin en tarjetas estrechas y botones pequeños; subtítulos \`text-gray-400\` sobre blanco | Layout responsive, contraste más alto, botones y paginación de altura útil, etiquetas accesibles |
| UX-05 | Alta | WeeklyCalendar: bloques \`div @click\` sin interacción por teclado, detalle con overlay aislado | \`role=button\`, Tab/Enter/Espacio, diálogo compartido, nombre accesible, Escape y restauración del foco |
| UX-06 | Media | Students/Index y Teacher/Credits: cierre posible durante una operación activa y título de modal sin asociación | \`closeable\` condicionada a \`processing\`, título \`aria-labelledby\`, guard de doble envío |
| UX-07 | Media | Campana: errores al marcar avisos como leídos se ignoraban, y podían solaparse acciones de marcado | Mensajes recuperables visibles y exclusión mutua de las peticiones |
| UX-08 | Baja | Carrusel público: flechas sin nombre accesible | \`aria-label\` y enfoque visible |
| UX-09 | Media | Pagina Users: paginador inyectaba etiquetas del backend con \`v-html\` | Mapeo y retirada explícita de etiquetas HTML, usando interpolación escapada |
| UX-10 | Media | Solicitudes, navegación, crédito y fechas tenían los defectos documentados en \`MOVA_REALTIME_UX_2026_11_16.md\` | Se conservan y amplían las correcciones de ese documento, sin modificar el ledger |

El commit \`ffa0665\` que otro colaborador introdujo en esta rama añadió un coordinador de scroll y foco para overlays; los cambios aquí no lo revierten. Los modales de revisión usan ese componente compartido.

## Evidencia obtenida (sin atribuirle pruebas no ejecutadas)

- Se publicaron commits de implementación y un spec \`qa/tests/ux-role-audit.spec.js\` que comprueba que abrir y cancelar modales no emita mutaciones, y explora padre/profesor a 375, 390, 768 y 1440 px.
- El spec exige \`BASE_URL=http://127.0.0.1:8012\` y una SQLite de pruebas cuyo nombre termine en \`phase2b-e2e.sqlite\`; **no permite su ejecución contra producción**.
- Comprobación estática leyendo los archivos del HEAD de GitHub: **12/12 contratos de estructura comprobados**, incluidas las rutas de suspensión sin POST inicial, modales nombrados, ausencia de overlays antiguos en los archivos modificados, bloqueo durante solicitudes, teclado del calendario y guardas del spec.
- **No se ejecutó Playwright**. El spec no fue compilado ni probado todavía; algunos casos requieren fixtures sintéticos y pueden figurar como SKIP si no existen. Es obligatorio revisar los skips y no tratarlos como un PASS de cobertura.
- No se ejecutaron \`npm ci\`, \`npm run build\`, \`php artisan test\`, auditoría MySQL, axe, revisión visual a 200% de zoom ni pruebas de usuarios padre/profesor/admin reales. Tampoco se verificó el WebSocket ni el cobro Culqi/WAHA de producción.

## Gates imprescindibles antes de publicar

1. En un checkout íntegro: \`git diff --check origin/master...HEAD\`, \`node --test tests/Frontend/*.test.mjs\`, \`npm ci && npm run build\`, \`php artisan test\`; revisar errores de Vue SFC, importaciones y TypeScript/JS.
2. Levantar entorno QA aislado por el runbook \`qa/run-stabilization.ps1\`, verificar marcador SQLite sintético, ejecutar \`cd qa && npm ci && npm run test:ux-audit\`. Ejecutar E2E ya existentes (\`stabilization\`, \`operations\`, flujo padre→profesor) y atender todo SKIP no justificado.
3. Capturas desktop/tablet/móvil en 320, 375, 390, 768, 1024 y 1440, con tema claro/oscuro, teclado, Escape, foco restaurado, contraste real calculado, zoom 200%, navegación y ausencia de scroll lateral a nivel de documento.
4. Verificar que ninguna acción de suspensión, aprobación, rechazo, pago o devolución se ejecute antes de la confirmación; dos cuentas/sesiones independientes; fecha Perú; reconexión WebSocket sin datos privados; ledger y cobros con idempotencia bajo MySQL QA.
5. Solo al pasar QA y revisar el coste de Azure: merge \`master\`, imagen GHCR inmutable sin GitHub Actions, digest verificable, rollback preparado, rollout en Azure Container Apps, /healthz y /readyz y E2E real en \`movaeduca.me\`, luego \`docs/release/DEPLOY_HISTORY.md\`.

## Prioridades residuales

El inventario estático de 69 vistas y 36 componentes **no significa que se hayan pulsado todas las interacciones**. Persisten el gate de compilación, el de capturas y navegación, el QA financiero aislado y la activación/validación de tiempo real en Azure. Sin esos datos, este documento **NO certifica que el diseño esté impecable o que producción contenga el PR**. La versión desplegada de \`master\` permanece sin cambios.
