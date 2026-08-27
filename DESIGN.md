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

## Border radius

Escala con nombre — reemplaza los 6 valores usados sin criterio hoy
(`rounded-xl`×231, `2xl`×140, `lg`×108, `full`×71, `md`×5, `3xl`×2):
`sm` 8px (chips/badges) · `md` 12px (inputs, botones secundarios) · `lg` 16px
(tarjetas, botón primario) · `xl` 20px (tarjetas elevadas, modales) · `pill`
(botones tipo pastilla, avatares).

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

## Estado de este documento

Fase 1 (tokens) completa. Las secciones de primitivos, iconografía y
navegación se añaden a medida que cada fase se ejecuta — ver
`docs/MOVA_AUDIT_PHASE0.md` sección de rediseño para el plan completo y qué
sigue pendiente.
