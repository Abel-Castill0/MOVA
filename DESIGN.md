# Design

Complementa `PRODUCT.md` (personalidad, anti-referencias, principios). Este
documento es el sistema ejecutable: tokens, componentes, y las decisiones
verificadas detrás de cada uno. Se actualiza en cada fase del rediseño
"Native Premium" — ver `docs/MOVA_AUDIT_PHASE0.md` para el hallazgo original
y el plan completo.

## Filosofía

Gramática de interacción de iOS (capas, feedback táctil, hojas contextuales,
movimiento físico) aplicada sobre la identidad propia de MOVA — nunca una
copia de Apple. Dos decisiones explícitas al respecto:

- **No se adopta `-apple-system`/SF Pro.** Esa pila resuelve a una tipografía
  distinta por sistema operativo (SF en Apple, Segoe UI en Windows, Roboto en
  Android) — lo opuesto a la coherencia buscada. Plus Jakarta Sans,
  auto-hospedada desde el manual de marca, es la identidad tipográfica real
  de MOVA en los tres sistemas.
- **No se usan SF Symbols.** Licenciados solo para plataformas Apple; Lucide
  (MIT, stroke de peso único) es el equivalente correcto para web.

## Tokens semánticos

**Fuente de verdad única: variables CSS en `resources/css/app.css`.**
`tailwind.config.js` solo las expone como utilidades (`bg-canvas`,
`text-ink`, `border-line`, etc.). Ningún componente usa `dark:` de Tailwind
ni un color hardcodeado para decidir apariencia por tema — el valor cambia
solo, en la variable, según `prefers-color-scheme` o la elección manual
guardada por `useTheme.js`. Esa es la regla que evita terminar con tokens
CSS + clases `dark:` + variables duplicadas compitiendo entre sí.

El modo oscuro se diseñó, no se invirtió: cada valor se decidió y verificó
por separado (ratio de contraste WCAG calculado con la fórmula real de
luminancia relativa, no estimado a ojo).

### Canvas / superficie / elevación

| Token | Claro | Oscuro | Nota |
|---|---|---|---|
| `--canvas` | `#F8FAFC` (slate-50) | `#0B1220` | fondo de página |
| `--surface` | `#FFFFFF` | `#121A2B` | tarjeta — en oscuro un paso de **luz** sobre canvas |
| `--surface-raised` | `#FFFFFF` | `#19233A` | elevada — en claro la elevación es solo `boxShadow`; en oscuro la sombra casi no se ve sobre fondo oscuro, así que el paso real de elevación es más luz, no más sombra |

### Texto

| Token | Claro | Ratio | Oscuro | Ratio |
|---|---|---|---|---|
| `--ink` | `#0F172A` | 17.85:1 | `#F2F5FA` | 17.13:1 |
| `--ink-muted` | `#64748B` | 4.76:1 (AA cuerpo) | `#A8B3C7` | 8.86:1 |
| `--ink-subtle` | `#94A3B8` | **2.56:1** | `#828DA6` | 5.63:1 |

`--ink-subtle` en claro **no alcanza AA ni para texto grande** (necesita
3:1). Es intencional pero restringido: solo para elementos decorativos donde
la información ya está disponible por otro medio (un icono junto a texto que
dice lo mismo, un contador secundario) — **nunca** para texto que sea la
única fuente de una información. En oscuro sí alcanza AA (5.63:1) por cómo
cae la escala tonal ahí; la restricción de uso se mantiene igual en ambos
temas por consistencia de API, no porque en oscuro haga falta.

### Bordes

| Token | Claro | Ratio vs. superficie | Oscuro | Ratio |
|---|---|---|---|---|
| `--line` | `#F3F4F6` (gray-100) | ~1:1 (decorativo) | `#2A3650` | ~1.5:1 (decorativo) |
| `--line-strong` | `#94A3B8` (slate-400) | 2.56:1 | `#5A6BA0` | **3.35:1** |

**Hallazgo honesto, no escondido:** `--line-strong` en modo claro (2.56:1) es
una mejora real sobre el `border-gray-300` que hoy usan `TextInput`/`Checkbox`
(1.47:1), pero **no alcanza el 3:1** que pide WCAG 1.4.11 para límites de
componente funcional. Alcanzar 3:1 en claro exigiría un borde tan oscuro
(`slate-500`, `#64748B`) que se vería como un contorno pesado en cada input
de la app — un cambio de peso visual que este pase de fundaciones no asume
unilateralmente. La mitigación real hoy es el anillo de foco (`--focus-ring`,
alto contraste, verificado) y el `<label>` visible en cada campo, que es lo
que efectivamente comunica el límite del control. En oscuro `--line-strong`
sí alcanza 3:1 real (3.35:1) porque la escala tonal ahí lo permite sin ese
costo visual. Se documenta como mejora pendiente, no como "resuelto".

**Regla de uso explícita (para no reservar `--line-strong` de forma
inconsistente página por página):**
- `--line` (decorativo, ~1:1) → separadores dentro de una superficie que ya
  tiene su propio borde/sombra de tarjeta (divisores de lista, filas de
  tabla) — el contexto ya comunica la estructura.
- `--line-strong` (funcional) → borde de reposo de controles interactivos
  (input, select, checkbox) que dependen del foco + label, no del borde en
  sí, para comunicar el límite — nunca el único canal.
- Nunca usar `--line` donde el elemento sea la única señal de un límite
  interactivo (eso es trabajo de `--line-strong` + foco + label juntos, no
  de un separador decorativo).

### Foco

`--focus-ring`: `brand-500` (`#246ACC`) en claro, `brand-400` (`#4F8ADB`) en
oscuro — mismo azul de marca en ambos temas, nunca el indigo heredado de
Laravel Breeze que hoy usan `Checkbox`/`SecondaryButton`/otros. Verificado
como texto/anillo sobre su canvas: 5.34:1 en oscuro.

### Estados (success / warning / danger / info)

Mismo patrón tinte-claro + texto-oscuro que `AppLayout.vue` ya usa en sus
mensajes flash (`bg-green-50 border-green-200 text-green-800`) — se
centraliza en tokens en vez de reinventarse. Contraste texto-sobre-tinte
verificado en los cuatro casos, ambos temas: **todos ≥6.3:1**, muy por
encima de AA.

## Sistema de estados semánticos

**Regla:** ningún color de estado se elige "porque se ve bien" — cada estado
de negocio se asigna a un **rol semántico**, y el rol determina el color, no
al revés. Siete roles, verificados contra los enums reales del código
(`database/migrations/*_classes_table.php`, `*_class_requests_table.php`),
no inventados:

| Rol | Uso | Color |
|---|---|---|
| `brand` | acciones primarias, marca | `brand-600` |
| `neutral` | sin opinión fuerte, por defecto | `slate`/`gray` |
| `info` | programado/informativo, nada requerido del usuario todavía | `blue` |
| `success` | terminal positivo | `green`/`emerald` |
| `warning` | requiere atención/acción del usuario | `amber`/`orange` |
| `danger` | terminal negativo | `red`/`rose` |
| `accent` | en curso, con un hecho de negocio distintivo que el `info` genérico no comunica (ver justificación por estado abajo) | `violet` / `cyan` — dos tonos de acento, uno por dominio, nunca el mismo para dos conceptos distintos |

### Ciclo de vida real de `Lesson` (`classes.status`)

Verificado en `2026_08_24_000002_remove_in_progress_from_classes_status_enum.php`:
`scheduled → paid → pending_parent_confirmation → completed`, con
`cancelled`/`needs_admin_review` como ramas de excepción.

| Estado | Rol | Por qué (semántico, no estético) |
|---|---|---|
| `scheduled` | `info` | Programada, nada pendiente del padre todavía — es literalmente información. |
| `paid` | **`accent` (violet)** | No es "info" ni "success": el dinero ya se movió (evento de negocio de primera clase en todo el ledger de créditos) pero la clase todavía no ocurrió ni se confirmó — mezclarlo con `scheduled` (nada pagado) le escondería al padre precisamente el dato que más le importa ver de un vistazo ("esta ya la pagué"). Mezclarlo con `completed` (verde) sería peor: afirmaría que la clase ya ocurrió cuando no es cierto. Necesita un rol propio. |
| `pending_parent_confirmation` | `warning` | Acción requerida del padre (confirmar). |
| `needs_admin_review` | `warning` (tono `orange`, más urgente que `amber`) | Requiere intervención humana — más urgente que una confirmación de rutina. |
| `completed` | `success` | Terminal positivo. |
| `cancelled` | `danger` | Terminal negativo. |

### Ciclo de vida real de `ClassRequest` (`class_requests.status`)

Verificado en `2026_07_08_000001_update_class_requests_status_enum.php`:
`pending_parent_approval → open → accepted/rejected/teacher_rejected → completed`.
Es un **dominio distinto** de `Lesson` — una solicitud precede a una clase, y
ambos pueden aparecer juntos en el mismo dashboard del padre (ver
`Dashboard/Parent.vue`, que muestra solicitudes pendientes y clases próximas
en la misma pantalla). Por eso `open` no puede reutilizar el mismo `accent`
que `paid`: si compartieran color, un padre viendo ambos módulos a la vez no
podría distinguir "tengo una solicitud buscando profesor" de "tengo una
clase ya pagada" solo por el color — precisamente el tipo de ambigüedad que
un sistema de color semántico existe para evitar.

| Estado | Rol | Por qué |
|---|---|---|
| `pending_parent_approval` | `warning` | Acción requerida del padre. |
| `open` | **`accent` (cyan)** | En curso (buscando profesor), pero es información del dominio *solicitud*, no *clase* — necesita distinguirse de `paid`/`scheduled` para que ambos módulos convivan sin ambigüedad en el mismo dashboard. |
| `accepted` | `success` | Terminal positivo. |
| `rejected` / `teacher_rejected` | `danger` (el segundo con un tono distinto, `rose`, porque ya existía así en el código antes de esta sesión y sigue siendo una decisión válida: distingue "rechazada" en general de "un profesor específico la rechazó") | Terminal negativo. |
| `completed` | `success` | Terminal positivo. |

**Consecuencia práctica — resuelta:** los cuatro sitios que pintan estos
colores (`utils/statusColors.js`, `Dashboard/Parent.vue::dotColor()`,
`TeacherLessonCard.vue`, `ParentLessonCard.vue`) ya importan `statusStyle()`
en vez de repetir el mapeo — un solo lugar que cambiar si un estado necesita
otro color. `statusColors.js` expone `stripe`/`dot` (pesos sólidos) junto al
`color`/`ring` (tinte de badge) que ya tenía, todos derivados del mismo hue
por estado.

**Verificado, no asumido — el color nunca es el único canal:** todo
consumidor de `statusStyle()` que muestra el color también muestra
`.label` como texto real junto a él (`StatusBadge.vue` siempre renderiza
`{{ style.label }}`; los timelines con solo un punto de color —
`Dashboard/Parent.vue`, `Dashboard/Teacher.vue` — siempre lo muestran junto
a un `<StatusBadge>` en la misma fila, nunca el punto solo). Un usuario con
daltonismo o alto contraste identifica el estado por texto sin depender del
color — confirmado leyendo cada template, no supuesto.

## Border radius

Escala con nombre para uso deliberado en primitivos y páginas migradas —
`rounded-chip` 8px (chips/badges) · `rounded-control` 12px (inputs, botones
secundarios) · `rounded-card` 16px (tarjetas, botón primario) ·
`rounded-elevated` 20px (tarjetas elevadas, modales) · `rounded-pill`
(botones tipo pastilla, avatares).

**Deliberadamente no se llaman `sm`/`md`/`lg`/`xl`.** `tailwind.config.js`
declara este scale bajo `theme.extend.borderRadius`, que se *fusiona por
nombre de clave* con la escala por defecto de Tailwind — usar esos nombres
habría sobrescrito silenciosamente `rounded-sm/md/lg/xl` en los 231+140+108+5
usos que ya existen hoy en toda la app (`rounded-xl`×231, `2xl`×140,
`lg`×108, `md`×5), sin tocar ninguna página. Los valores existentes de
`rounded-*` siguen significando exactamente lo mismo hasta que una página se
migre a propósito en una fase posterior — ese es justamente el motivo de
nombrar la escala nueva sin colisión.

## Elevación

Tres niveles (`shadow-elevation-1/2/3`) para canvas plano → contenido con
elevación discreta → capa funcional — nunca una sombra suelta por
componente.

## Movimiento

Jerarquía por nivel, no una duración plana para toda la app:

| Nivel | Qué | Duración |
|---|---|---|
| 0 — Instantáneo | cambios triviales | sin transición |
| 1 — Micro (`duration-micro`) | presión, hover, focus | 100ms |
| 2 — Componente (`duration-ui`) | sheet, modal, expansión de tarjeta | 250ms |
| 3 — Navegación (`duration-nav`) | transición de página | 350ms |
| 4 — Hero | solo páginas públicas, evaluado caso a caso | — |

`prefers-reduced-motion: reduce` es transversal (regla global en `app.css`
para CSS; cada mecanismo JS nuevo — GSAP, Sheet, transición de página — trae
su propio guard con `matchMedia`, igual que ya hacen `Welcome.vue` y
`Dashboard/Parent.vue`). Nunca deja contenido invisible esperando una
animación que no se va a disparar.

## Modo oscuro: infraestructura lista, activación diferida (no es una feature en vivo)

**Estado real, sin redondear:** "dark mode" no debe contarse todavía como una
característica que el usuario tiene — es infraestructura completa (tokens,
escala de contraste verificada, mecanismo de activación manual) a la espera
de que la migración de páginas avance lo suficiente para encenderla sin
producir una app mitad clara/mitad oscura. La activación automática por
`prefers-color-scheme` es la ÚLTIMA fase de la migración de tokens, no una
casilla ya marcada.

`prefers-color-scheme` está **deliberadamente desactivado** en `app.css` por
ahora — no es un olvido. Se encontró verificando en el navegador real (Fase
2, `/login`, sistema en modo oscuro): los primitivos ya tokenizados
(`TextInput`, `Checkbox`) se oscurecían correctamente, pero `Login.vue`
—como el resto de páginas, aún sin migrar— seguía con `bg-white`/`text-gray-*`
hardcodeados. Resultado real: inputs oscuros sobre una página clara, una
costura visual que afectaría hoy mismo a cualquier usuario con el sistema en
oscuro, no solo a una captura de prueba.

Por eso el tema oscuro hoy solo se activa manualmente
(`:root[data-theme="dark"]`, para QA/pruebas) — el `@media
(prefers-color-scheme: dark)` se reactiva como el **último** paso del
rediseño, cuando suficientes páginas estén migradas a los tokens como para
que activarlo no deje ninguna mezcla clara/oscura a medio camino. La
infraestructura (variables, escala, verificación de contraste) ya existe
completa; lo que falta es terminar de migrar el consumo antes de encender el
interruptor automático.

## Estado de este documento

Fase 1 (tokens) completa. Las secciones de primitivos, iconografía y
navegación se añaden a medida que cada fase se ejecuta — ver
`docs/MOVA_AUDIT_PHASE0.md` sección de rediseño para el plan completo y qué
sigue pendiente.
