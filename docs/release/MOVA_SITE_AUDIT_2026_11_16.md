# MOVA — Auditoría transversal de interfaz y experiencia (16/11/2026)

> **Estado:** correcciones de código en rama de auditoría; **NO es una certificación E2E ni un despliegue**. Base: PR #18 `feat/mova-lima-realtime-ux-20261116`. No fusionar ni publicar hasta completar los gates de este documento.

## 1. Alcance y evidencia real

- Fuente de verdad: `AGENTS.md`, `CLAUDE.md`, `docs/AI_HANDOFF.md`, `DESIGN.md`, `docs/WEB_INTERFACE_GUIDELINES.md`, `resources/css/app.css`, `tailwind.config.js`, componentes/rutas actuales.
- Se enumeraron y se sometieron a inspección **estática** 69 vistas `resources/js/Pages/**/*.vue` y 36 componentes `resources/js/Components/**/*.vue` (105 archivos), con segunda lectura detallada de las zonas sensibles.
- Se revisaron los riesgos de botones, acciones destructivas, etiquetas, estado/foco, tamaños táctiles, overlays, tokens, horarios, avisos y estados vacíos. Los resultados de expresiones regulares son **pistas de revisión**, no fallos demostrados por navegación.
- La herramienta web y el contenedor no pudieron acceder a `https://movaeduca.me` (DNS/acceso limitado); navegador conectado de Chat no disponible (requiere contexto de navegador originario). **No hubo clics reales**, capturas, prueba de viewport, Lighthouse, axe ni prueba de usuarios autenticados de este lote.
- Repo accesible por conector GitHub, que permitió leer y publicar commits. No hubo Docker, Composer, checkout completo con dependencias, permisos Azure/ACR/GHCR operables desde esta sesión.

## 2. Hallazgos confirmados en código y correcciones de esta rama

| ID | Severidad | Archivo / área | Causa observada y cambio |
|---|---|---|---|
| UX-01 | Alta | `NotificationController.php` / `NotificationBell.vue` | La campana contaba como no leídas solo las últimas 20 notificaciones consultadas; las anteriores desaparecían del contador. Añadido header de total del usuario autenticado, actualización segura sin duplicación y test de privacidad con 25+ avisos. |
| UX-02 | Alta | `ParentLessonCard.vue` | CTA «Calificar y Confirmar» blanco sobre `yellow-500`: **1,92:1**; reemplazado por blanco sobre `amber-700`: **5,02:1**. Ningún cambio de confirmación financiera. |
| UX-03 | Media | `ParentLessonCard.vue`, `TeacherLessonCard.vue` | Acciones con textos ámbar/red-500 demasiado claros, `text-xs` y áreas pequeñas. Se usan colores más oscuros, texto de 14 px y altura mínima de 44 px. |
| UX-04 | Media | `Reviews/Create.vue` | Estrellas sin nombre accesible ni estado de selección; textarea sin asociación `label[for]`; envío susceptible a doble clic. Se agregaron etiquetas/pressed, objetivos 44 px, guard y errores anunciados. |
| UX-05 | Alta | `Diagnostics/Create.vue` | Error del servidor en campo de un paso anterior se ocultaba en paso 5. Se navega al paso afectado, se etiquetan ambas áreas de texto y se preserva entrada. |
| UX-06 | Media | `AppLayout.vue` | Falta de enlace para saltar navegación; agregado enlace visible al enfocar hacia `main#mova-main`. |
| UX-07 | Media | `Credits/Index.vue` | Las dos pestañas se anunciaban como ARIA tablist sin teclado de flechas; se usa grupo de botones con `aria-pressed`. Una edición introdujo atributos `type` duplicados y se corrigieron explícitamente antes de enviar. |
| UX-08 | Media | `CookieConsent.vue` | Aviso informativo no es diálogo modal; ajustado a `region`, traslado a esquina izquierda en escritorio para no competir con Movi, restauración de padding previo. |
| UX-09 | Media | `ChatbotWidget.vue`, `NotificationBell.vue` | Chat modal sin bloqueo coordinado de scroll; ahora usa lock compartido. Escape en campana restaura foco al botón de apertura. |
| UX-10 | Baja-media | `Admin/AiUsage.vue`, `Dashboard/Admin.vue` | Textos de poco contraste, fechas locales ambiguas y filas extensas; color de texto elevado, fecha Lima explícita y `flex-wrap`/cortes de texto. |
| UX-11 | Media | `Welcome.vue`, `LandingNavbar.vue` | Flechas del carrusel solo SVG sin nombre accesible; añadidos `aria-label` + `type=button`; objetivo táctil del menú aumentado a 44 px. |
| UX-12 | Alta | `Admin/Users.vue`, `Admin/Lessons.vue`, `Admin/Reviews.vue`, `Admin/PendingTeachers.vue` | Había confirmaciones impropias e interfaces de moderación customizadas: **fueron corregidas en commits simultáneos del PR #18 por el flujo de trabajo concurrente**, no reescritas aquí. Hay que revisar juntos ambos PR y sus cambios. |

## 3. Jerarquía de color y contraste

- Color primario: `brand-600` para acción principal, `brand-700/800` para estados de hover y fondos oscuros; no crear una tercera paleta azul.
- Confirmación de reseña: `amber-700` sobre blanco, contraste 5,02:1; jamás `yellow-500` con texto blanco (1,92:1).
- Rojo informativo/destructivo: `red-700` sobre blanco ~6,47:1. El antiguo `red-500` sobre blanco ~3,76:1 no alcanza 4,5:1 para texto normal.
- `slate-400` en blanco ~2,56:1: conservar solo para iconos/decoración no informativa. Los metadatos deben usar tinta `slate-500` o más oscura, considerando el fondo real.
- `DESIGN.md` documenta `--ink-subtle` no AA en tema claro y que `--line-strong` no alcanza 3:1; **no están globalmente resueltos**. No habilitar tema oscuro automático hasta migrar páginas duras (`bg-white`/colores hex) sin mezclas de superficies.
- Colores de estados deben seguir `resources/js/utils/statusColors.js` y los tokens semánticos; no confundir clase pagada con completada ni solicitud abierta con aceptada.

## 4. Matriz de recorrido real aún obligatoria

| Área | Casos por verificar con navegador real |
|---|---|
| Pública: Inicio, Acerca, servicios, profesores, catálogo | Navegación, anchors, carruseles prev/next, teclado, CTA, imágenes, ancho 320/375/390/768/1024/1440, contraste WCAG, tamaño de texto, menús y reduced motion |
| Registro y autenticación | Formulario por pasos, validaciones, Google/email/teléfono, 2FA admin, errores, foco, cerrar sesión |
| Padre | Dashboard, alumnos CRUD, solicitud general/dirigida, wizard, contraoferta, clases, video Jitsi, evaluación, diagnóstico, avisos, privacidad |
| Profesor | Bandeja elegible, seleccionar/aceptar/contraofertar/rechazar, perfil/verificación, clases, reportes, créditos y detalles de recarga |
| Administración | Usuarios suspender/reactivar; verificación/rechazo; moderar reseña; clases cancelar/forzar cierre/devolver (sin ejecutar movimientos live); operaciones, pagos y reporte IA |
| Componentes compartidos | Sidebar y menú público, todos los modales: Escape, overlay, foco/trampa/restauración, z-index, scroll; campana, chat, carga, red offline, estados vacíos |
| Integraciones | Pusher de canales privados o fallback, hora Perú, API/ledger Culqi sin compras reales, notificaciones WhatsApp solo controladas, Azure readyz/healthz |

**Prohibido** probar acciones financieras reales, cuentas de terceros o notificaciones externas sin entorno controlado. Los datos de prueba no deben tocar usuarios reales.

## 5. Gates y publicación sin GitHub Actions

1. Confirmar revisión de ambos PR (PR #18 y PR de auditoría), verificar si `master` cambió concurrentemente. Conservar todos los commits ajenos.
2. En checkout completo: `git diff --check`, `npm ci`, `npm run check:realtime-ux`, `npm run check:ui-contract`, `npm run build`, `php artisan test` y pruebas de concurrencia financiera. El test nuevo de backend requiere `php artisan test --filter=NotificationBellCountTest`. Registrar resultados, fallos y correcciones.
3. Chromium/Playwright: matriz por ruta y rol, 320–1440 px, screenshots comparables, axe, contraste y foco del teclado. Dos sesiones padre/profesor separadas y flujo real sin F5.
4. Verificar presupuesto efectivo, canales realtime y Azure/RG/prod; construir digest GHCR inmutable **sin Actions** conforme `docs/release/MOVA_PRODUCTION_RUNBOOK.md`. No añadir recursos de pago.
5. Solo cuando todo sea PASS: merge en `master`, publicar imagen comprobada con rollback, verificar HTTP + sesión, revisar errores y registrar digest/revisiones en `docs/release/DEPLOY_HISTORY.md`.

**Estado al escribir este informe:** cambios `push` a rama, no merge, no deploy. Último despliegue comprobado es anterior a estos cambios.
