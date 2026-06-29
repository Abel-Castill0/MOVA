# MOVA Professional Polish Audit

**Fecha:** 2026-06-29  
**Auditor:** análisis estático de código fuente  
**Archivos revisados:** 35 vistas Vue + layouts + tailwind.config.js  
**No se modificó código. No se hizo deploy. No se activó IA.**

---

## Estado general

- **Funcionalmente final:** sí — 126/126 QA, todos los flujos operativos, Railway OK
- **Profesional/elegante:** parcialmente — landing y dashboards están bien; hay 5 brechas críticas que hacen que la app se vea inacabada
- **Principal brecha de percepción:** la pantalla de login está en inglés
- **Recomendación ejecutiva:** antes de mostrar MOVA a usuarios reales, resolver los 5 problemas críticos y los 4 altos. Son cambios de texto y color — bajo riesgo, alto impacto. Tiempo estimado: 2–3 horas de trabajo, sin riesgo de romper producción.

---

## Resumen de hallazgos

| Área | Estado actual | Problema | Impacto | Prioridad |
|---|---|---|---|---|
| Login | ⚠️ Crítico | Toda la UI está en inglés | Primera experiencia del usuario — inaceptable para producto en español | P0 |
| GuestLayout footer | ⚠️ Crítico | Dice "MOVA Beta 2026" | Beta language visible en marketplace, teacher profiles, register — contradice eliminación de beta | P0 |
| Footer landing — email ficticio | ⚠️ Crítico | `hola@mova.education` — dominio no existe | Usuarios que escriban a ese email no recibirán respuesta | P0 |
| Footer landing — links rotos | ⚠️ Crítico | "Privacidad" y "Términos de uso" apuntan a `#` | No se puede acceder a los legales desde el footer de la landing | P0 |
| Social media links | ⚠️ Crítico | Facebook/Instagram/LinkedIn apuntan a `#` | Se ve abandonado y poco profesional | P0 |
| Color inconsistency | 🔴 Alto | `indigo-600` vs `brand-600` en forms y admin | Inconsistencia visible entre páginas — se nota al navegar | P1 |
| Admin sidebar incompleto | 🔴 Alto | 4 páginas admin sin acceso desde sidebar | Admin no puede navegar a /admin/requests, /lessons, /reviews, /ai-usage desde el menú | P1 |
| WhatsApp prometido (desactivado) | 🔴 Alto | 2 lugares afirman notificación por WhatsApp | Promesa que no se cumple con WHATSAPP_ENABLED=false | P1 |
| Input border-radius inconsistency | 🟡 Medio | `rounded-lg` en Register vs `rounded-xl` resto | Perceptible al comparar páginas | P2 |
| Precios: € en ClassRequests/Create | 🟡 Medio | Muestra `€` en vez de `S/` | Error de copy en un form de alta conversión | P2 |
| Login desconectado del diseño | 🟡 Medio | Login es el template Breeze sin customización visual | Contraste fuerte con el resto de la app | P2 |
| "España · Online" en footer | 🟡 Medio | App es para mercado peruano (S/, +51) | Confuso para usuarios peruanos | P2 |
| Landing — profesores destacados | 🟢 Bien | Cards, rating, verified badge OK | — | — |
| Marketplace | 🟢 Bien | Filtros, chips, empty states OK | — | — |
| Dashboards padre/profesor | 🟢 Bien | Welcome banner, stats, acciones rápidas, empty states | — | — |
| Diagnóstico (5 pasos) | 🟢 Bien | Wizard claro, progress bar, mobile-friendly | — | — |
| Perfil público profesor | 🟢 Bien | Avatar, verified badge, reviews, trust section | — | — |
| Reviews/Create | 🟢 Bien | Star picker, anon note, brand colors OK | — | — |
| LessonReports/Create | 🟢 Bien | Form estructurado, placeholders descriptivos | — | — |
| AppLayout (sidebar) | 🟢 Bien | Logo, nav activo, user info, mobile overlay | — | — |
| Landing hero | 🟢 Bien | Animaciones, stats, 3D card, CTA claro | — | — |
| About page | 🟢 Bien | Founders, MVV, historia — profesional | — | — |
| Admin Dashboard | 🟢 Bien | Stats claros, acciones, recent users | — | — |
| Admin Users | 🟢 Bien | Suspend modal con confirmación, paginación | — | — |
| Admin PendingTeachers | 🟢 Bien | Reject modal con motivo, safe reject | — | — |

---

## Mejoras obligatorias para verse profesional

### P0 — Críticos (deben hacerse antes de mostrar a usuarios reales)

#### 1. Traducir la pantalla de login al español
**Archivo:** `resources/js/Pages/Auth/Login.vue`

La pantalla de login es la PRIMERA pantalla que un usuario real ve. Actualmente tiene:
- Label `Email` → debe ser `Correo electrónico`
- Label `Password` → debe ser `Contraseña`
- Checkbox `Remember me` → debe ser `Recordarme`
- Link `Forgot your password?` → debe ser `¿Olvidaste tu contraseña?`
- Botón `Log in` → debe ser `Iniciar sesión`
- `<Head title="Log in" />` → debe ser `Iniciar sesión – MOVA`

Además, el formulario usa el componente Breeze estándar sin ningún diseño visual de MOVA. Considerar rediseñarlo al estilo de Register.vue (card centrada, brand colors, sin GuestLayout externo).

#### 2. Eliminar "MOVA Beta 2026" del GuestLayout
**Archivo:** `resources/js/Layouts/GuestLayout.vue:43`

```html
<!-- Actual: -->
<span>MOVA Beta 2026</span>

<!-- Debe ser: -->
<span>MOVA © 2026</span>
```

Este footer aparece en: marketplace (visitantes), teacher profile (visitantes), login, register, terminos, privacidad. Es lo primero que un padre ve al llegar.

#### 3. Corregir email de contacto en LandingFooter
**Archivo:** `resources/js/Components/LandingFooter.vue:59`

```html
<!-- Actual (dominio ficticio): -->
<span class="text-sm text-slate-400">hola@mova.education</span>

<!-- Debe ser: -->
<a href="mailto:abelcastillotrabajo@gmail.com" class="text-sm text-slate-400 hover:text-white transition-colors">
  abelcastillotrabajo@gmail.com
</a>
```

#### 4. Arreglar links legales en el footer de la landing
**Archivo:** `resources/js/Components/LandingFooter.vue:72-75`

```html
<!-- Actual (links rotos): -->
<a href="#" class="text-xs text-slate-500 hover:text-slate-400">Privacidad</a>
<a href="#" class="text-xs text-slate-500 hover:text-slate-400">Términos de uso</a>

<!-- Debe ser: -->
<a href="/privacidad" class="text-xs text-slate-500 hover:text-slate-400 transition-colors">Política de Privacidad</a>
<a href="/terminos" class="text-xs text-slate-500 hover:text-slate-400 transition-colors">Términos y Condiciones</a>
```

#### 5. Manejar social media links sin redes activas
**Archivo:** `resources/js/Components/LandingFooter.vue:19-27`

**Opción A** (recomendada): Eliminar los 3 iconos de redes sociales temporalmente hasta tener perfiles reales. Una landing sin íconos de redes es más profesional que una con links que no van a ningún lugar.

**Opción B**: Ocultar con `v-if="false"` hasta tener URLs reales.

---

### P1 — Altos (hacen antes del primer usuario externo)

#### 6. Unificar colores: reemplazar `indigo-600` por `brand-600`
El sistema de diseño define `brand-600 = #2563EB`. Múltiples archivos aún usan `indigo-600` del template original:

| Archivo | Usos de indigo-600 |
|---|---|
| `GuestLayout.vue:17` | Botón "Registrarse" |
| `GuestLayout.vue:38` | Hover en links legales del footer |
| `Register.vue:11,15,55,58,68` | Role buttons, checkbox, inputs focus, submit button |
| `ClassRequests/Create.vue:14,22,29,38` | Todos los selects, textarea, botón submit |
| `Admin/Users.vue:69` | Paginación activa |
| `Admin/PendingTeachers.vue:22,35,82` | Avatar, badges de materias, botón re-verificar |

Nota: visualmente `indigo-600 (#4F46E5)` es algo más violáceo que `brand-600 (#2563EB)`. La diferencia es perceptible al comparar páginas.

#### 7. Agregar páginas faltantes al sidebar admin
**Archivo:** `resources/js/Layouts/AppLayout.vue:118-124`

Las siguientes páginas admin no están en el menú lateral:
- `/admin/requests` → "Solicitudes globales"
- `/admin/lessons` → "Clases globales"  
- `/admin/reviews` → "Reseñas"
- `/admin/ai-usage` → "Uso de IA"

```javascript
// Agregar al array admin navItems:
{ href: '/admin/requests',         icon: '📋', label: 'Solicitudes' },
{ href: '/admin/lessons',          icon: '📅', label: 'Clases' },
{ href: '/admin/reviews',          icon: '⭐', label: 'Reseñas' },
{ href: '/admin/ai-usage',         icon: '🤖', label: 'Uso de IA' },
```

#### 8. Corregir promesas de WhatsApp con feature desactivado
**Archivos afectados:**

`resources/js/Pages/Teachers/Show.vue:189`:
```html
<!-- Actual: -->
<p class="text-xs text-slate-500 mt-0.5">Te avisamos por email y WhatsApp antes de cada clase.</p>

<!-- Debe ser: -->
<p class="text-xs text-slate-500 mt-0.5">Te avisamos por email antes de cada clase.</p>
```

`resources/js/Pages/Auth/Register.vue:34-36`:
```html
<!-- Actual: -->
<span class="text-gray-400 font-normal">(para recordatorios por WhatsApp)</span>
<p class="text-xs text-gray-400 mt-1">Perú: 9 dígitos. Internacional: incluye el código de país (+51, +1…)</p>

<!-- Debe ser: -->
<span class="text-gray-400 font-normal">(opcional — para recordatorios)</span>
<!-- Mantener la instrucción de formato de teléfono -->
```

---

## Mejoras opcionales premium

Estas mejoras no son necesarias para lanzar, pero elevarían la percepción a nivel de producto SaaS maduro.

1. **Rediseñar Login al estilo de Register** — Reemplazar el template Breeze por un card centrada similar a Register.vue, con brand colors, MOVA logo, y sin GuestLayout externo. (Impacto: alto / Riesgo: bajo)

2. **Corregir input border-radius en Register** — `rounded-lg` → `rounded-xl` para consistencia con el resto de la app (5 inputs). (Impacto: bajo / Riesgo: ninguno)

3. **Corregir símbolo de moneda en ClassRequests/Create** — `€{{ price }}/h` → `S/ {{ price }}/h` (Impacto: medio / Riesgo: ninguno — solo template, no lógica)

4. **Corregir "España · Online" en LandingFooter** — Si el mercado primario es Perú, cambiar a "Perú · Online" o "Latinoamérica · Online". (Impacto: bajo)

5. **Placeholder de avatar de profesor** — Las iniciales con fondo gradiente son funcionales. Para un polish premium: soporte para foto de perfil uploadeable. (Impacto: medio / Esfuerzo: alto)

6. **Skeleton loading states** — Inertia hace SSR por lo que la ausencia de skeletons no es tan visible, pero en conexiones lentas hay flash de contenido. Agregar skeleton en marketplace y admin tables. (Impacto: bajo / Esfuerzo: medio)

7. **Confirmación post-acción más visual** — Actualmente solo flash message verde. Agregar un estado de éxito más elaborado en la solicitud de clase. (Impacto: bajo)

8. **Quick action "Ver mis clases" en dashboard padre** — El dashboard del padre tiene acciones rápidas de "Buscar profesor", "Gestionar hijos" y "Reportes" pero no tiene acceso directo a "Mis clases". (Impacto: bajo)

9. **Focus visible en botones táctiles** — Algunos botones de acción no tienen `focus:ring` explícito. Mejora de accesibilidad básica. (Impacto: bajo)

---

## Quick wins de alto impacto

Cambios de <10 minutos cada uno con máximo retorno de percepción profesional:

| # | Cambio | Archivo | Tiempo |
|---|---|---|---|
| 1 | "Log in" → "Iniciar sesión" + resto en español | `Auth/Login.vue` | 5 min |
| 2 | Eliminar "MOVA Beta 2026" del footer | `Layouts/GuestLayout.vue:43` | 1 min |
| 3 | Email real en LandingFooter | `Components/LandingFooter.vue:59` | 2 min |
| 4 | Links legales apuntando a /terminos y /privacidad | `Components/LandingFooter.vue:73-74` | 2 min |
| 5 | Eliminar/ocultar social icons vacíos | `Components/LandingFooter.vue:19-27` | 3 min |
| 6 | 4 páginas admin faltantes en sidebar | `Layouts/AppLayout.vue:118-124` | 5 min |
| 7 | "WhatsApp" → "email" en trust section | `Pages/Teachers/Show.vue:189` | 1 min |
| 8 | Phone label sin mencionar WhatsApp | `Pages/Auth/Register.vue:35` | 1 min |
| **Total** | | | **~20 min** |

---

## Plan de implementación recomendado

| Fase | Objetivo | Archivos | Riesgo | QA requerido | Tiempo estimado |
|---|---|---|---|---|---|
| **Fase P** (pre-lanzamiento) | Eliminar todos los críticos y altos | `Login.vue`, `GuestLayout.vue`, `LandingFooter.vue`, `AppLayout.vue`, `Teachers/Show.vue`, `Register.vue` | Bajo — solo texto y CSS, sin cambios de lógica | Revisar visualmente en browser: login, register, marketplace (guest), teacher profile (guest), admin sidebar | 2–3 horas |
| **Fase Q** (siguiente sprint) | Color unification `indigo → brand` | `Register.vue`, `ClassRequests/Create.vue`, `Admin/Users.vue`, `Admin/PendingTeachers.vue`, `GuestLayout.vue` | Muy bajo — solo reemplazos de clase CSS | Revisar formularios visualmente | 1–2 horas |
| **Fase R** (opcional) | Login redesign + avatar photos | `Auth/Login.vue` + backend upload | Bajo-medio | Login flow completo | 4–8 horas |

---

## No tocar

Las siguientes áreas están bien y **no deben modificarse** sin necesidad — cambiarlas sin un objetivo claro aumenta el riesgo de regresiones:

| Área | Estado | Por qué no tocar |
|---|---|---|
| Diagnóstico wizard (5 pasos) | ✅ Excelente | Progress bar, opciones, validación, privacidad — todo correcto |
| Dashboard padre | ✅ Profesional | Welcome banner, stats, empty states, Zoom link |
| Dashboard profesor | ✅ Profesional | Pending reports alert, profile checklist |
| Dashboard admin | ✅ Funcional | Stats, quality metrics, recent users |
| Marketplace | ✅ Profesional | Filtros, chips, cards, paginación |
| Perfil público profesor | ✅ Profesional | Reviews, verified badge, trust section |
| LessonReports/Create | ✅ Profesional | Form estructurado, brand colors, placeholders |
| Reviews/Create | ✅ Profesional | Star picker, anon note, UX limpia |
| About page | ✅ Profesional | Founders, historia, MVV |
| AppLayout sidebar | ✅ Funcional | Logo, nav activo, mobile overlay (solo agregar admin items) |
| Tailwind brand tokens | ✅ Correcto | brand-600 = #2563EB bien definido |
| Landing hero y secciones | ✅ Profesional | Animaciones, testimonios, CTA |

---

## Recomendación final

**MOVA puede mostrarse a usuarios reales, pero con condición**: resolver los 5 críticos primero.

El motivo es pragmático: los 5 críticos (login en inglés, "Beta 2026", email ficticio, links legales rotos, social icons vacíos) son detalles que cualquier usuario exigente detectará en los primeros 2 minutos y le restarán confianza a la plataforma. Sin ellos, MOVA parece una app en construcción o un template sin terminar.

Con los 5 críticos resueltos y los 2 altos de P1 más urgentes (sidebar admin + WhatsApp), MOVA tiene un nivel de polish completamente adecuado para usuarios reales beta. El login en inglés es el único que puede generar abandono inmediato — priorizar por encima de todo.

Los dashboards, el diagnóstico, el marketplace y el perfil del profesor ya tienen nivel de producto SaaS comercial. La brecha está concentrada en las páginas "de entrada" (login, register, landing footer) que son justamente las que más ven los nuevos usuarios.

**Tiempo total estimado para resolver P0+P1:** 2–3 horas. Sin riesgo de romper funcionalidad.

---

*Generado por análisis estático de código fuente el 2026-06-29. No se ejecutó código, no se modificó ningún archivo, no se hizo deploy.*
