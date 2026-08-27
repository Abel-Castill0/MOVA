# MOVA — Fase 0: Auditoría Frontend / UX / UI / Performance

> Generado por lectura directa de código (`resources/js/`), sin ejecutar el build ni interactuar con el navegador salvo donde se indica. No se modificó ningún archivo del proyecto.
> Confirmado contra `docs/MOVA_SYSTEM_KNOWLEDGE.md` §18-20: el frontend **no había sido auditado en detalle** en pasadas anteriores — esta es la primera pasada real.

---

## 1. Inventario de páginas/componentes

**77 archivos** bajo `resources/js/` (52-55 páginas Vue + 19 componentes + 2 composables + 4 utils + 3 layouts + `app.js`/`bootstrap.js`/`echo.js`).

### Layouts (3, no 2 como decía `MOVA_MASTER_CONTEXT.md` — desactualizado)
| Layout | Rol |
|---|---|
| `AppLayout.vue` (214 líneas) | Layout autenticado real: sidebar por rol, topbar, flash messages, toast de Echo/Pusher en tiempo real. Nav items hardcodeados por rol (`admin`/`teacher`/`parent` fallback) dentro del propio componente. |
| `GuestLayout.vue` (112 líneas) | Login/registro, panel de marca dependiente de `role` prop. |
| `PublicPageLayout.vue` (97 líneas, nuevo — commit reciente) | Marketplace/perfil público, auth-aware (mismo layout para invitado y logueado, decisión de diseño documentada en comentario del propio archivo). |
| `AuthenticatedLayout.vue` (mencionado en `MOVA_MASTER_CONTEXT.md` como "vestigial, 2 páginas") | **Ya no existe** en el árbol actual — el documento maestro está desactualizado en este punto, no es un hallazgo del código. |

### Páginas más grandes (líneas — señal de posible sobrecarga de responsabilidades)
1. `Pages/Welcome.vue` — 461 líneas (landing pública, usa GSAP + Swiper)
2. `Pages/Dashboard/Parent.vue` — 443 líneas (usa GSAP también)
3. `Pages/Teacher/Credits/Index.vue` — 329 líneas
4. `Pages/Dashboard/Teacher.vue` — 267 líneas
5. `Pages/Auth/Register.vue` — 251 líneas (wizard de 5-6 pasos)
6. `Pages/Lessons/ParentIndex.vue` — 229 líneas
7. `Pages/Diagnostics/Create.vue` — 228 líneas (wizard de 5 pasos)
8. `Pages/Admin/Lessons.vue` — 219 líneas
9. `Layouts/AppLayout.vue` — 214 líneas
10. `Pages/Lessons/TeacherIndex.vue` — 211 líneas

Ninguna cruza el umbral de 400 líneas que suele indicar componente "dios" real; todas las revisadas en detalle mantienen una sola responsabilidad de página. No se encontró un componente verdaderamente sobrecargado — **matiz sobre el criterio del prompt**: por tamaño de línea no hay señal fuerte de "componente gigante" en este código.

### Composables y utils (buena señal arquitectónica)
- `Composables/useJitsiMeet.js` — encapsula todo el ciclo de vida de Jitsi (carga de script externo, apertura/cierre, JWT desde backend). Bien comentado, sin lógica de negocio filtrada al componente que lo usa.
- `Composables/useLessonsViewMode.js` — toggle lista/calendario.
- `utils/statusColors.js` — **fuente única** de color/etiqueta para estados de `Lesson`/`ClassRequest` (documentado explícitamente en el propio archivo como extraído para evitar tercera duplicación). Consumido por 10 archivos.
- `utils/lessonJoin.js`, `utils/roleLabels.js`, `utils/weekGrouping.js` — utilidades pequeñas y de responsabilidad única.

Esto contradice parcialmente la expectativa por defecto de "lógica de negocio dispersa": para el dominio de **clases/solicitudes**, la extracción a `statusColors.js` ya pasó. El problema real (ver §2) es que esa disciplina **no se extendió** al dominio de créditos/recargas.

---

## 2. Hallazgos técnicos de FRONTEND

### F1 — [P3] Lógica de negocio duplicada: badges de estado de crédito/recarga
`statusColors.js` centraliza estados de `Lesson`/`ClassRequest`, pero **no** los de `CreditTransaction.type` (`deposit/reservation/consumption/refund`) ni de `RechargeRequest.status` (`pending/approved/rejected`). Esa lógica está copiada de forma casi idéntica en al menos 4 archivos:
- `Pages/Teacher/Credits/Index.vue:290-328` (`transactionLabel`, `transactionBadge`, `rechargeLabel`, `rechargeBadge`)
- `Pages/Admin/Recharges/Index.vue:162-170` (`statusBadge` — mismo mapeo `pending→amber-50/700`, `approved→green-50/700`, `rejected→red-50/700`, carácter por carácter igual al de arriba)
- `Pages/Dashboard/Teacher.vue` (mapeo propio para checklist/labels)
- `Pages/Admin/Requests.vue`

Riesgo: si se agrega un estado nuevo (p. ej. `reversed`, que ya existe en `RechargeRequest.status` según `MOVA_MASTER_CONTEXT.md` §26 pero **no aparece manejado en ningún badge del frontend** — cae al fallback gris genérico en ambos archivos), hay que recordar tocar 3-4 archivos en vez de uno.
**Propuesta concreta**: extraer `RECHARGE_STATUS_STYLES` y `CREDIT_TX_STYLES` a `utils/statusColors.js` (mismo patrón ya usado), consumidos por los 4 archivos.

### F2 — [P3] Estado de wizard multi-paso no sobrevive refresh/back-forward
Dos flujos clave (`Pages/Auth/Register.vue` y `Pages/Diagnostics/Create.vue`) implementan wizards de 5-6 pasos con `const step = ref(1)` **puramente local**, sin reflejarlo en la URL ni en `sessionStorage`. Consecuencia verificable por lectura de código (no requiere navegador):
- Un refresh accidental a mitad de paso 3-4 (p. ej. tras escribir un párrafo largo en `difficulty_text` o llenar nombre/correo/teléfono) borra todo sin aviso — vuelve a paso 1.
- El botón atrás del navegador no tiene ningún handler de `popstate`; al no cambiar la URL entre pasos, atrás del navegador saca de la página completa (al `Marketplace` o donde vino), no retrocede un paso del wizard — comportamiento sorprendente para el usuario que espera que "atrás" lo lleve al paso anterior.
Esto es exactamente el patrón "problemas de navegación con Inertia" que pide el brief.
**Propuesta concreta**: reflejar `step` en un query param (`?step=3`) vía `router.get(..., { preserveState: true, replace: true })` o, más simple, persistir `form` + `step` en `sessionStorage` con un `watch` y restaurarlo en `onMounted`, limpiándolo solo en `onSuccess` del submit final.

### F3 — [P3] Validación cliente incompleta en `Register.vue` paso 3
`isCurrentStepValid` (líneas 206-217) valida el email con regex pero **no valida el teléfono en absoluto** en el mismo paso donde se pide (`form.phone`, línea 92). El usuario puede avanzar con el campo de teléfono vacío o con un formato inválido y solo se entera al enviar el formulario completo (paso 6), teniendo que retroceder. El backend sí normaliza/valida el teléfono peruano (`User::normalizePhone()`, confirmado en `MOVA_MASTER_CONTEXT.md`), pero esa validación no está replicada ni siquiera de forma básica (longitud/dígitos) en el frontend — inconsistencia cliente↔servidor que el propio CLAUDE.md pide evitar ("formularios frágiles").
**Propuesta concreta**: añadir a `isCurrentStepValid` para `step === 3`: `/^(\+?51)?9\d{8}$/.test(form.phone.replace(/\s/g, ''))` (ajustar al regex real de backend) antes de habilitar "Continuar".

### F4 — [P4] Acciones de moderación sin estado de carga (`Admin/Reviews.vue`)
`submitHide()` y `restoreReview()` (líneas 133-143) llaman `router.post(...)` sin ningún guard de `processing`/`submitting`, a diferencia de `Admin/Recharges/Index.vue` (que sí usa `processing.value = { id, action }`) o `Admin/Lessons.vue` (`submitting.value = true`). El botón "Confirmar" para ocultar una reseña queda clickeable durante todo el round-trip, sin spinner ni `disabled`, permitiendo doble envío en clic rápido o red lenta. Bajo severidad porque la operación es casi-idempotente en backend, pero es inconsistente con el patrón ya usado 3 líneas de código más abajo en el proyecto.

### F5 — [P4] `<img>` sin `loading="lazy"` en toda la app
Ningún `<img>` del proyecto usa el atributo `loading="lazy"` (10 ocurrencias revisadas: avatares en sidebar/topbar/navbar, grid de `Marketplace/Index.vue`, `Teachers/Show.vue`, preview de `UpdateAvatarForm.vue`). Impacto real limitado porque son avatares pequeños (32-48px) mayormente arriba del pliegue, pero el grid paginado de `Marketplace/Index.vue` (hasta ~9-12 tarjetas con imagen por página) sería el caso de mayor beneficio si crece.

---

## 3. Hallazgos de UX

### UX1 — [P2] `window.confirm()` nativo para acciones irreversibles
`Pages/Students/Index.vue:50` (`if (confirm('¿Eliminar este estudiante?'))`) y `Pages/Admin/Recharges/Index.vue:114` (aprobar una recarga de dinero real) usan el diálogo nativo del navegador, no un modal propio de la app. Contradice directamente la regla de diseño del CLAUDE.md del proyecto ("todo componente debe verse elegante, profesional y caro") — el `confirm()` nativo rompe la identidad visual justo en dos de las acciones más sensibles del sistema (borrar datos de un menor de edad; mover dinero real). Ya existe `Modal.vue` reutilizable en el propio proyecto y se usa para confirmaciones en otros lugares (`Admin/PendingTeachers.vue`, `Admin/Reviews.vue` con modal de "Ocultar reseña con motivo") — el patrón correcto ya existe, solo falta aplicarlo aquí también.
**Propuesta concreta**: reemplazar ambos `confirm()` por `<Modal>` con texto explicativo + botón de confirmación explícito, igual al patrón ya usado en `Admin/Reviews.vue` (modal "Ocultar reseña").

### UX2 — [P3] Wizard sin persistencia (ver F2) es también un problema de UX, no solo técnico
Un padre completando el diagnóstico de 5 pasos (describe el problema de su hijo en un textarea largo) que sufre un refresh accidental (recarga del navegador, pérdida de conexión) pierde todo el texto escrito sin ningún aviso de "tienes cambios sin guardar". Fricción real en uno de los flujos que el propio brief pide revisar ("reportar"/diagnóstico).

### UX3 — [P4] Búsqueda de código de profesor en `ClassRequests/Create.vue` bien resuelta
Mención positiva, no un hallazgo negativo: el lookup de código de profesor (debounce 400ms, estados `checking/found/not-found`, aclaración de qué pasa si se deja vacío) está bien pensado y evita el error más obvio (código mal tipeado silencioso). Se documenta explícitamente en el código que el backend re-valida y nunca confía en este resultado — buen patrón de seguridad UX.

### UX4 — [UNKNOWN] Fricción real de los flujos en dispositivo real
No se pudo confirmar con interacción real cuánta fricción producen los wizards de 5-6 pasos en la práctica (¿se sienten largos o se perciben como progreso claro gracias a la barra de progreso?) — esto requiere prueba con usuarios o al menos recorrido manual cronometrado, fuera del alcance de lectura de código.

---

## 4. Hallazgos de UI

### UI1 — [P2] Contraste insuficiente: `text-slate-400` / `text-gray-400` sobre fondo blanco
Cálculo de contraste WCAG (fórmula de luminancia relativa estándar) para los colores Tailwind usados:
- `slate-400` (`#94a3b8`) sobre blanco (`#ffffff`) → **contraste ≈ 2.57:1**
- `slate-500` (`#64748b`) sobre blanco → contraste ≈ 4.75:1 (pasa AA para texto normal)

`text-slate-400`/`text-gray-400` se usa **151 veces en 44 archivos** de `resources/js/Pages` y `Components`, muchas veces para texto informativo real (no decorativo): ejemplo, `AppLayout.vue:54,95` (rol del usuario logueado, texto visible permanentemente en sidebar/topbar), `WeeklyCalendar.vue:28` (instrucción de navegación en el estado vacío del calendario), `Auth/Register.vue:11,25,28` ("Paso X de Y", aclaraciones bajo el botón de Google). Todos estos casos están por debajo del mínimo WCAG AA de 4.5:1 para texto normal (y del propio 3:1 de "texto grande", ya que ninguno de estos usos es ≥18pt/24px).
**Propuesta concreta**: sustituir `text-slate-400`→`text-slate-500` (o `text-gray-400`→`text-gray-500`) en todo uso que sea texto informativo real, no decorativo (iconos, separadores `·`, o placeholders de `<input>`, que sí tienen una regla de contraste distinta/más laxa). Dado el volumen (151 ocurrencias), conviene un `sed`/find-replace dirigido archivo por archivo revisando cada caso, no un reemplazo ciego (algunos usos son legítimamente decorativos).

### UI2 — [P3] Botón de cerrar modal como carácter "x" plano
`Pages/Teacher/Credits/Index.vue:138-141`: el botón de cerrar el modal de recarga es literalmente el carácter `x` (no un ícono SVG), con solo un `sr-only` "Cerrar" para accesibilidad. Funcionalmente correcto pero visualmente por debajo del estándar "caro"/consistente que sí se aplica en el resto del proyecto (ej. el ícono SVG de hamburguesa/cerrar en `AppLayout.vue` usa un `<svg>` con trazos, no un carácter de texto). Contraste también flojo (no se especifica color, hereda `text-slate-400` del contenedor padre — ver UI1).
**Propuesta concreta**: reemplazar por el mismo `<svg>` de "X" (stroke, 2 líneas cruzadas) ya usado en `AppLayout.vue:24-26`, manteniendo el `sr-only`.

### UI3 — [P4] Falta de estilos de foco visibles consistentes / accesibilidad de modales
`Components/Modal.vue` (usado en `Teacher/Credits/Index.vue`, `Auth/Register.vue` para el modal de Google, etc.) no implementa `role="dialog"`, `aria-modal="true"`, ni gestión de foco: no mueve el foco al contenido del modal al abrirse, no lo devuelve al elemento que lo abrió al cerrarse, y no atrapa el `Tab` dentro del modal (un usuario de teclado puede tabular "detrás" del modal hacia el contenido de la página oculta tras el overlay). Sí cierra con Escape (`closeOnEscape`, líneas 38-44) — parcial, no completo. De los 77 archivos, solo 13 mencionan `aria-label`/`focus()`/`role="dialog"` en absoluto.
**Propuesta concreta**: agregar `role="dialog"` + `aria-modal="true"` al contenedor del modal, `ref` + `.focus()` sobre el primer elemento interactivo en `watch(show)`, y un trap de `Tab` simple (o adoptar un composable ya usado en la industria) — cambio acotado a `Modal.vue`, se propaga a todos sus consumidores.

### UI4 — [P2] Páginas públicas incumplen la propia regla de SEO del proyecto (Open Graph + structured data)
CLAUDE.md exige explícitamente OG + structured data en `Welcome`, `Marketplace` y perfiles públicos de profesor. Estado real verificado por grep:
- `Pages/Welcome.vue:2-8` — **sí** tiene `og:title`, `og:description`, `og:type` además de `meta description`.
- `Pages/Marketplace/Index.vue:2-4` — **solo** `meta description`, sin ningún `og:*`.
- `Pages/Teachers/Show.vue:2-4` — **solo** `meta description`, sin ningún `og:*`.
- **Ningún archivo** en todo `resources/js` contiene `application/ld+json` ni ninguna variante de "structured data"/`schema.org` — cero JSON-LD en el proyecto, ni siquiera en `Welcome.vue`.
Esto es una brecha directa contra una regla explícita y reciente del propio proyecto (no una opinión de este auditor).
**Propuesta concreta**: (a) añadir `og:title`/`og:description`/`og:type="profile"` a `Teachers/Show.vue` y `og:type="website"` a `Marketplace/Index.vue`, siguiendo el patrón ya usado en `Welcome.vue`; (b) añadir un bloque `<script type="application/ld+json">` con `schema.org/Person` (o `EducationalOrganization`/`Service` según corresponda) en `Teachers/Show.vue` y `schema.org/ItemList` en `Marketplace/Index.vue`.

### UI5 — [UNKNOWN] Verificación visual real en breakpoints 360/390/768/1024
Se revisaron las clases Tailwind usadas (`sm:`/`lg:` predominan; poco uso de `md:` específico) y el patrón de `WeeklyCalendar.vue` (min-width 900px con scroll horizontal en el propio contenedor, no en `<body>` — correcto) y de `AppLayout.vue` (sidebar `-translate-x-full` bajo `lg:`, breakpoint en 1024px — sin estado intermedio para tablet 768-1023px, donde el sidebar se comporta igual que en móvil, ocupando overlay completo en vez de aprovechar el ancho disponible de un iPad en horizontal). No se pudo confirmar visualmente si esto se siente estrecho en 768-1024px sin abrir un navegador real — **requiere verificación manual** en los 5 anchos pedidos por el brief.

---

## 5. Hallazgos de PERFORMANCE

### P1 — [P3] GSAP + Swiper como dependencias globales, sin `manualChunks` explícito
`package.json` declara `gsap` (^3.15) y `swiper` (^14.0.6) como `dependencies` (no `devDependencies`), usadas únicamente en `Pages/Welcome.vue` (ambas) y `Pages/Dashboard/Parent.vue` (solo GSAP). `vite.config.js` no define `build.rollupOptions.output.manualChunks` — depende enteramente del code-splitting automático de Vite vía `import.meta.glob('./Pages/**/*.vue')` en `app.js`. Esto **debería** ya separar cada página en su propio chunk (comportamiento por defecto de Vite con imports dinámicos), lo que limitaría el impacto de GSAP/Swiper a quienes visitan `Welcome`/`Dashboard/Parent` — pero no se ejecutó `npm run build` para confirmar el tamaño real de chunk generado ni si hay una fuga de estas libs al chunk común (`vendor`).
**Marcado UNKNOWN/REQUIERE VERIFICACIÓN**: correr `npm run build` y revisar `dist/manifest.json` o el reporte de tamaño de Vite para confirmar que `gsap`/`swiper` no terminan en un chunk compartido que cargue en *todas* las páginas (incluidas rutas críticas de dinero como `Teacher/Credits/Index.vue` o `Admin/Recharges/Index.vue`).

### P2 — [P4] Sin lazy loading de imágenes (ver F5) — impacto bajo
Ya cubierto en §2 (F5). Repetido aquí solo para completar la categoría del brief: impacto real limitado porque son avatares pequeños, no imágenes hero grandes.

### P3 — [P4] `AppLayout.vue` importa `echo.js` dinámicamente — correcto
Nota positiva: `AppLayout.vue:159` usa `await import('@/echo.js')` dentro de `onMounted`, no en el top-level — Pusher/Laravel Echo (`pusher-js` ^8.5, librería no trivial) no entra en el bundle inicial de ninguna página, se carga bajo demanda solo cuando hay un usuario autenticado montando el layout. Buen patrón ya aplicado, no requiere cambio.

### P4 — [UNKNOWN] Bundle size real / critical path
No se ejecutó `npm run build` en esta pasada (fuera de alcance por ser fase de solo lectura salvo comandos read-only explícitamente permitidos; se priorizó no alterar el estado del working tree). Sin esa corrida no se puede dar un número real de KB por chunk ni confirmar/descartar P1 con certeza — **requiere verificación** con `npm run build` + inspección de `public/build/manifest.json`.

---

## 6. Qué NO se pudo verificar sin interacción manual en navegador

1. **UI5** — comportamiento real en 360/390/768/1024/desktop del sidebar de `AppLayout.vue` entre 768-1023px (posible zona muerta de UX, solo se infiere de las clases, no se vio renderizado).
2. **P1/P4** — tamaño real de bundle/chunks tras `npm run build`, y si GSAP/Swiper contaminan el chunk común.
3. **UX4** — percepción real de fricción/longitud de los wizards de registro (6 pasos) y diagnóstico (5 pasos) en un recorrido real, con tiempos.
4. **UI3** — comportamiento real de foco de teclado en `Modal.vue`/`JitsiModal.vue` con un lector de pantalla o navegación por `Tab` real (el análisis de código confirma la ausencia de manejo explícito, pero no se probó el comportamiento resultante en un navegador).
5. Contraste de color **percibido** en pantalla real (el cálculo de UI1 es matemático sobre los valores hex de Tailwind, no una captura de pantalla verificada con herramienta de accesibilidad como axe/Lighthouse).
6. Cualquier race condition de frontend con **dos pestañas reales** abiertas simultáneamente (p. ej. dos solicitudes de recarga desde el mismo profesor en pestañas distintas) — el backend ya tiene `lockForUpdate()` documentado, pero el comportamiento de UI resultante (¿mensaje de error claro si la segunda pestaña pisa la primera?) no se probó interactivamente.
