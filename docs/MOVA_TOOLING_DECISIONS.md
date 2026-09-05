> **HISTORICAL SNAPSHOT** — verify against `docs/MOVA_SYSTEM_MAP.md` and current code.
> Documento de una fase anterior. Conservado como registro de decisiones, NO como
> descripcion fiable del comportamiento actual.

# MOVA — Análisis y decisiones sobre herramientas, skills, librerías y prácticas

**Fecha:** 2026-08-27. Cubre cada ítem de la lista que pasaste (~180 entradas: skills de Claude Code, librerías npm, repos de GitHub, MCPs, sitios de inspiración, checklists de SEO/seguridad/UX, comandos, y consideraciones legales). Ninguno se omitió — los que no requerían investigación profunda (sitios de inspiración, libros, conceptos de diseño) se clasifican igual, sin research extenso, porque no hay nada que "instalar" en ellos.

**Antes de la lista: un marco de 4 categorías, porque tu lista mezcla cosas de naturaleza muy distinta:**

| Categoría | Qué significa | Ejemplo de tu lista |
|---|---|---|
| **(A) Skill/comando de Claude Code** | Algo que YO uso para ayudarte — vive en mi configuración, no en el código de MOVA | `ui-ux-pro-max`, `/ultrareview`, `seo-audit` |
| **(B) Librería/dependencia de MOVA** | Algo que se instala en `package.json`/`composer.json` y queda en el código del producto | GSAP, Swiper, Cloudinary |
| **(C) Sitio de referencia/inspiración** | Nada que instalar — es para mirar y decidir un estilo | motionsites.ai, 21st.dev, ui.shadcn.com |
| **(D) Servicio externo/infraestructura** | Requiere una cuenta o servidor que tú administras, yo no puedo crearlo por ti | Google Search Console, n8n, Polar.sh |

---

## 🟢 Hallazgo más importante: ya estaba hecho

Antes de listar nada, esto merece ir primero porque cambia el panorama: **investigué el código real (no asumí) y las 4 cosas de tu sección LEGAL ya están implementadas**, con buen nivel de detalle:

| Pedido | Estado real, verificado |
|---|---|
| Declarar uso de IA en privacidad/landing | ✅ `Privacy.vue` §5 y `Terms.vue` §8 — mencionan Google Gemini explícitamente, qué datos se envían (anónimos), y que ninguna decisión es 100% automatizada |
| Cláusula de arbitraje en Términos | ✅ `Terms.vue` §13 — con matiz legal real: arbitraje para profesores (contratistas independientes), y explícitamente **opcional, nunca sustituye INDECOPI/Poder Judicial** para padres (consumidores), citando el Código de Protección al Consumidor |
| Declarar uso de píxeles/analytics en privacidad | ✅ `Privacy.vue` §7 — declara explícitamente que **no** se usa Meta Pixel ni Google Analytics hoy, con promesa de actualizar la sección antes de activar cualquiera |
| Cláusula de retiro de contenido subido por usuarios (para que la responsabilidad legal recaiga en quien sube, no en MOVA) | ✅ `Terms.vue` §12, "Política de Retiro de Contenido" — cita el Decreto Legislativo 822 (Ley de Derecho de Autor), establece contractualmente obligaciones y responsabilidad del usuario respecto del contenido que sube, y define un proceso de solicitud de retiro — esto no se interpreta como inmunidad automática de MOVA frente a obligaciones legales aplicables frente a terceros o autoridades |

**No se tocó nada de esto** — ya está bien escrito, no hacía falta mejorarlo. Section referenced: [Privacy.vue](../resources/js/Pages/Legal/Privacy.vue), [Terms.vue](../resources/js/Pages/Legal/Terms.vue).

---

## ✅ Implementado en esta sesión

| Ítem de tu lista | Acción tomada |
|---|---|
| "Skill Vercel para Claude" (reglas de diseño de Vercel), Context7 | Instalé **Context7 MCP** (`claude mcp add context7 -- npx -y @upstash/context7-mcp`) — trae documentación actualizada de librerías (Laravel, Vue, Inertia) en vez de depender de mi conocimiento de entrenamiento, que puede estar desactualizado para versiones recientes |
| "sitemap.xml", "robots.txt" | **Gap real encontrado**: existía `robots.txt` pero ningún `sitemap.xml`, y `robots.txt` no referenciaba ninguno. Implementé ambos como rutas dinámicas (no archivos estáticos) — el sitemap lista páginas públicas + perfiles de profesor **verificados únicamente** (un perfil sin verificar da 404, listarlo sería un error de SEO). 3 tests nuevos, 473/473 en verde. |
| "lee mi CLAUDE.md... sepárame las reglas que siempre aplican de las que solo a veces" | Reorganizado `CLAUDE.md` en dos secciones claras: **Siempre** (patrón de código, seguridad, razonamiento, barra de diseño) y **Condicionales** (UI compleja → invocar skills; cambios sensibles → revisión de seguridad; Jitsi/Student → revisión de seguridad; páginas públicas → SEO; `/handoff`; auditorías → Audit Snapshot Contract). Ningún contenido se perdió, solo se reorganizó. |
| "handoff, antes de cerrar sesión..." | Ya existía en `CLAUDE.md` (`/handoff` → genera `docs/SESSION_HANDOFF.md`) — confirmado, no se tocó. |
| Modelo Opus para lo difícil, Sonnet para lo normal, Haiku para lo rápido | Esto ya es cómo funciona Claude Code — tú eliges el modelo activo (`/model`), yo no puedo cambiarlo por ti a mitad de sesión salvo que lo pidas explícitamente. Ver sección de comandos más abajo. |

---

## 🧩 Skills de Claude Code que YA tienes disponibles (nada que instalar)

Confirmado contra la lista de skills real de este entorno — están ahí, listas para invocarse con `Skill(nombre)`:

| Ítem de tu lista | Skill real disponible | Para qué sirve en MOVA |
|---|---|---|
| Uiux promax | `ui-ux-pro-max` | Ya referenciada en `CLAUDE.md` — diseño de componentes Vue/Tailwind |
| Impeccable | `impeccable` | Ya referenciada en `CLAUDE.md` — pulido de UI compleja, comando `/polish` |
| Front end design skill | `frontend-design` | Dirección estética antes de construir una vista nueva |
| Claude code seo-audit | `searchfit-seo:seo-audit` | Auditoría SEO técnica — útil para revisar Marketplace/perfiles de profesor |
| Skill Vercel para Claude | `web-interface-guidelines` | Checklist de accesibilidad/interacción de Vercel — complementa `impeccable` |
| MCP builder | `mcp-server-dev:build-mcp-server`, `anthropic-skills:mcp-builder` | Si algún día necesitas un MCP propio para MOVA (poco probable) |
| Skill creator | `anthropic-skills:skill-creator` | Para crear un skill nuevo específico de MOVA si hiciera falta |
| Superpowers skills | `superpowers:*` (brainstorming, systematic-debugging, writing-plans, etc.) | Ya activo — es el marco que ya rige cómo trabajo contigo esta sesión |
| Emil Kowalski (×2 menciones) | `emil-design-eng`, `emil-animate`, `emil-improve-animations`, etc. | Filosofía de pulido de interacción/motion — complementa `impeccable` |
| design-html, canvas-design | `anthropic-skills:canvas-design` | Para artefactos visuales puntuales, no para el código de producto |
| brand-guidelines, theme-factory | `anthropic-skills:brand-guidelines`, `anthropic-skills:theme-factory` | Si en algún momento formalizas una guía de marca de MOVA |
| humanise-text | `humanizalo` | Útil para copy de marketing/legal que no debe sonar "hecho por IA" |
| review, security-review, verify-work | Ya usados extensamente esta sesión (la auditoría completa de F-01 a N1) | — |
| /simplify | `simplify` | Ya disponible como skill de limpieza de código |
| cyber-neo | `cyber-neo-audit` | **Ya instalado** (confirmado en la lista de skills de este entorno) — auditoría de seguridad OWASP 2025 + CWE Top 25 |
| Agent browser | Tools `mcp__Claude_Browser__*` de este entorno | Ya lo usé para verificar cambios visuales en sesiones anteriores |
| Claude web kit / claude web builder | `anthropic-skills:web-artifacts-builder` | Para artefactos HTML, no para el código de producto de MOVA |
| chrome live tabs | `mcp__claude-in-chrome__*` (Claude en tu Chrome real) | Disponible si algún día necesitas que interactúe con tu navegador real, no el del sandbox |
| Web search MCP | La tool `WebSearch` que usé para investigar este mismo documento | Ya disponible, no es un MCP externo separado |
| "Departamento de Legal/Finanzas/Operaciones" (contract-review, sop-builder, dcf-model, pitch-deck, xlsx, docx, sql-queries, etc.) | `legal:*`, `finance:*`, `operations:*`, `anthropic-skills:xlsx`, `anthropic-skills:docx` | **Importante:** estos son skills que YO uso cuando ME PIDES una tarea de ese tipo (ej. "revisa este contrato de un profesor", "arma un pitch deck") — no son código que se integra en MOVA. Ya están disponibles, no hay nada que instalar. |

---

## 📦 Librerías que MOVA ya usa (confirmado en `package.json`, no se reinstalaron)

| Ítem de tu lista | Estado real |
|---|---|
| GSAP | ✅ Ya en `package.json` (`gsap ^3.15.0`), usado en `Welcome.vue` y `Dashboard/Parent.vue` |
| ScrollTrigger | Es un plugin de GSAP — viene con el paquete ya instalado, solo falta importarlo donde se necesite (`gsap/ScrollTrigger`) |
| Swiper.js | ✅ Ya en `package.json` (`swiper ^14.0.6`) |
| Playwright | ✅ Extensamente configurado en `qa/` (con todo el trabajo de seguridad F-24A/F-25 de esta misma auditoría) |
| Cloudinary | ✅ Ya integrado (`cloudinary-labs/cloudinary-laravel`), usado para avatares con fallback a disco local |
| Google OAuth2 | ✅ Ya implementado (`GoogleAuthController`, gateado por `GOOGLE_LOGIN_ENABLED`) |

**"Anime.js"** — tu lista lo pide para "scroll animations, drag and drops, timelines encadenados". **Recomiendo NO añadirlo**: GSAP (ya instalado) cubre exactamente esos tres casos (`ScrollTrigger`, `Draggable`, `gsap.timeline()`), y tener dos librerías de animación compitiendo por el mismo trabajo es peso de bundle y superficie de mantenimiento sin beneficio real. Si GSAP se queda corto en algo concreto, dímelo y lo evalúo puntualmente.

---

## ⏭️ Explícitamente descartado para MOVA (con la razón, no solo "no aplica")

| Ítem | Por qué no |
|---|---|
| Three.js, WebGL, "UI espacial" (3D flotante), efectos inmersivos, `.glb`, img2threejs | MOVA es una plataforma de confianza para padres inscribiendo a sus hijos con un tutor — el objetivo es "elegante, profesional, caro" (tu propio `CLAUDE.md`), no un portafolio creativo tipo Awwwwards. Un efecto 3D vistoso añade peso de carga, riesgo de accesibilidad, y trabajo de mantenimiento sin servir a la conversión de un padre decidiendo si confiar en la plataforma. Reservaría esto solo si algún día MOVA tiene una landing puramente de marca separada del producto. |
| Framer Motion | Es una librería de **React** — MOVA es Vue 3. No es instalable en este stack. |
| Firebase — **REJECT FOR CURRENT ARCHITECTURE** | MOVA usa Laravel + MySQL como backend/BD — Firebase sería una base de datos/backend paralela sin ningún encaje con la arquitectura actual. No es un rechazo permanente de la herramienta en sí: si algún día MOVA reconstruyera su backend desde cero, podría volver a evaluarse; hoy simplemente no encaja con lo que existe. |
| Supabase — **REJECT FOR CURRENT ARCHITECTURE** | Mismo caso — MOVA no usa Postgres/Supabase, usa MySQL gestionado en Railway. Misma salvedad: rechazo contra la arquitectura actual, no una sentencia de que Supabase "no sirve". |
| Polar.sh — **REJECT FOR CURRENT ARCHITECTURE** | MOVA ya está construyendo Culqi como pasarela de pago (fundación ya commiteada esta sesión) — añadir una segunda pasarela sin necesidad de negocio no aporta nada hoy. Se reabre solo si Culqi deja de cubrir un caso real. |
| React Native skills | MOVA es una app web (Inertia), no tiene ni planea una app móvil nativa. |
| n8n / n8n-mcp — **OPTIONAL / FUTURE ARCHITECTURE DECISION** | Corrección: el criterio no es "¿tenemos un servidor n8n?" (no lo tenemos) sino "¿existe una necesidad operacional que n8n resuelva mejor que Laravel Queues/Scheduler?". MOVA podría terminar necesitando recordatorios de clases, recuperación de pagos, workflows administrativos — pero Laravel Queues + Scheduler, que ya forman parte del stack, podrían resolver eso mejor que añadir una pieza de infraestructura nueva. No se descarta n8n de forma permanente; se evalúa contra esa pregunta correcta cuando la necesidad aparezca, no antes. |
| Playwright MCP (Microsoft) | Redundante: este mismo entorno de Claude Code ya tiene un Browser pane equivalente (`mcp__Claude_Browser__*`) para navegación/verificación interactiva — no hace falta una segunda herramienta que hace lo mismo. |
| claude-mem (memoria persistente de terceros) | Este entorno ya tiene su propio sistema de memoria persistente entre sesiones (archivos en `~/.claude/projects/.../memory/`, ya en uso). Añadir un segundo sistema de memoria competiría con el que ya existe y usa. |
| "cult-ui MCP" | Busqué específicamente esto y no encontré ningún MCP con ese nombre — existe una librería de componentes React llamada `cult-ui` (no relacionada con MOVA, que es Vue), pero ningún MCP. Probablemente una confusión de nombres en tus notas. |
| Apify, Firecrawl, Scrapling, Scrapy | Herramientas de web-scraping — MOVA no tiene ninguna necesidad de negocio de extraer datos de otros sitios web. No aplica hoy. |
| Google Flow, Antigravity, Remotion, Hyperframes, Higgsfield, Imagegen | Herramientas de generación de video/imagen con IA (marketing de contenido, no código de producto) — útiles si en algún momento produces videos promocionales para redes, pero no son parte del desarrollo de MOVA en sí. |
| gstack, ponytail, ARCADS, "the architect", "agency agents", marketing-skills repos | Son configuraciones alternativas de Claude Code de otras personas/equipos (Garry Tan, etc.) — algunas se solapan con lo que ya tienes (superpowers, ui-ux-pro-max), otras son para flujos que no son los tuyos (marketing de video con Arcads, un "equipo virtual" completo de gstack). Instalar varias configuraciones superpuestas de "personalidad de Claude" a la vez generaría conflicto de instrucciones, no beneficio — mejor quedarse con la configuración ya establecida y probada esta sesión (superpowers + los skills específicos que ya usas). |
| OmniRoute, 9Router | Gateways gratuitos para enrutar a modelos de IA alternativos/gratuitos — no aplica: ya tienes acceso directo a Claude, no hay necesidad de un proxy para "ahorrar tokens" en este contexto. |
| WhatsApp AgentKit | MOVA ya tiene su propia integración de WhatsApp construida a medida esta sesión (Meta Cloud API directo, con toda la arquitectura de consentimiento/auditoría) — un "agent kit" genérico no encaja con esa arquitectura ya hecha. |
| ui.shadcn.com, 21st.dev, React Bits, Magic MCP (21st.dev) | Son librerías/generadores de componentes para **React** — MOVA es Vue. Hay un puerto no oficial de shadcn para Vue, pero no es necesario: el proyecto ya tiene su propio sistema de componentes Tailwind establecido. |
| "Build by nvidia" | NVIDIA tiene una plataforma de APIs de modelos de IA (`build.nvidia.com`) — no hay ningún caso de uso de MOVA que la necesite hoy (el enriquecimiento de diagnóstico ya usa Gemini/OpenAI). |

---

## 🔮 Válido, pero requiere tu decisión o tu cuenta (no algo que yo pueda simplemente activar)

| Ítem | Por qué necesita tu acción |
|---|---|
| Google Search Console | Requiere verificar la propiedad del dominio con tu cuenta de Google — no puedo crear ni verificar esto por ti. Cuando tengas la cuenta, puedo añadir el meta tag de verificación al `<head>` en minutos. |
| Google Analytics | Antes de activarlo hay que decidirlo conscientemente: `Privacy.vue` §7 promete explícitamente actualizar esa sección **antes** de activar cualquier analítica de terceros — es una decisión de producto/privacidad, no algo que deba activarse solo porque estaba en una lista de ideas. Dime si quieres que lo prepare (código + actualización de la política) y lo hago. |
| AccessLint | Es una GitHub App que analiza accesibilidad en cada PR — instalarla requiere que el dueño del repositorio (tú) la autorice desde GitHub Marketplace; yo no puedo instalar GitHub Apps en tu cuenta. |
| taste-skill, GSD skill, y otros `npx skills add <paquete>` | Estos comandos ejecutan un paquete de terceros que modifica tu configuración global de Claude Code (no solo el proyecto MOVA). Te doy el comando exacto abajo — si quieres que lo ejecute yo mismo ahora, dímelo explícitamente y lo hago; preferí no ejecutarlo sin que lo confirmes porque es un cambio persistente fuera del propio código de MOVA, no una dependencia normal del proyecto. |

**Comandos exactos, si decides seguir con alguno:**
```bash
npx skills@latest add leonxlnx/taste-skill
```
```bash
npx skills@latest add mattpocock/skills
```
```bash
cd ~/.claude/skills && git clone https://github.com/DietrichGebert/ponytail.git
```

---

## 🖼️ Sitios de inspiración / referencia — nada que instalar, solo para mirar

Estos no son herramientas, son sitios para inspirarte visualmente al diseñar. Los agrupo para que sepas para qué sirve cada uno, sin research individual extenso (no hay nada que "analizar" técnicamente en un sitio de inspiración):

- **motionsites.ai, samu-webart.com, boneyard.vercel.app, refero.design, jitter.video, 60fps.design** — animación/motion design de alto nivel. Útiles cuando lleguemos a la fase de UX/UI premium que ya acordamos para después de cerrar los bugs de negocio.
- **ui.shadcn.com, 21st.dev, react bits** — componentes React (no aplican directamente a Vue, pero sirven como referencia de composición visual).
- **colors-visualizer.vercel.app, designvault.io, shortcuts.design, brandbird.app, itshover.com** — herramientas puntuales de color/iconos/atajos, útiles como utilidades de apoyo al diseñar, no como integraciones de código.
- **getdesign.md, designmd.ai, designmd.app/library, awesome-design.md** — directorios curados de inspiración/plantillas de diseño.
- **tododeia.com/community** — la comunidad que construyó `cyber-neo` (ya instalado).

---

## 📖 Libros/conceptos de diseño y negocio (marco mental, no software)

Estos son metodologías o libros que mencionaste — no se "instalan", son lentes de análisis que ya aplico cuando corresponde (ej. al revisar UX de un flujo):

- **Refactoring UI** (Adam Wathan/Steve Schoger) — principios de jerarquía visual, ya alineado con `impeccable`.
- **Hooked** (Nir Eyal), **Made to Stick**, **The Design of Everyday Things** (Don Norman), **StoryBrand** — marcos de producto/copywriting/UX psicológico. Los aplico cuando trabajemos copy o flujos de onboarding, no requieren instalación.
- **Lean UX**, **UX heuristics (Nielsen)**, **microinteractions**, **web typography**, **SWISS design** — principios de diseño ya incorporados a cómo reviso interfaces (parte de lo que `impeccable`/`ui-ux-pro-max` ya cubren).
- **OODA loop** — marco de decisión (Observar-Orientar-Decidir-Actuar) — es, en esencia, lo que ya seguimos en el proceso de auditoría→hallazgo→decisión→acción de esta sesión.

---

## ⌨️ Comandos y prompts que mencionaste

| Comando | Estado |
|---|---|
| `/handoff` | Ya implementado en `CLAUDE.md` |
| `/model opus plan`, `/compact`, `/clear`, `/rewind` | Comandos nativos de Claude Code — tú los invocas directamente, no requieren nada de mi parte |
| `/ultrathink`, `ultrathinking` | Ya usado en esta misma respuesta (activado por tu mensaje) |
| `/plan` (plan mode) | Nativo — herramienta `EnterPlanMode`, disponible cuando quieras que planifique antes de ejecutar |
| `/ultrareview`, `ultra review` | Ya documentado en las instrucciones de esta sesión — lanza una revisión multi-agente en la nube, la disparas tú, yo no puedo iniciarla por ti |
| `/security-review`, `/code-review`, `/verify` | Ya usados extensamente en la auditoría F-01→N1 de esta sesión |
| `/goal`, `/agents` | Comandos de gestión de Claude Code, disponibles nativamente |
| `/critic`, `/expand`, `/firstprinciples`, `/stepbystep`, `/simplify`, `/ghost`, `ALT3`, `L99` | Estos son **prompts/técnicas de razonamiento**, no comandos instalables — algunos ("first principles", "step by step", "simplify") ya son técnicas que aplico quando la tarea lo pide, sin necesitar un comando especial que los active |

---

## 📄 Documentos de proceso que mencionaste

PRD, TRD, flujo de app, UI/UX design brief, esquema de backend, plan de implementación — **ya existen, con otros nombres, en este repo**: `MOVA_MASTER_CONTEXT.md` (modelo de negocio + flujos), `docs/MOVA_SYSTEM_KNOWLEDGE.md` (esquema técnico completo), `docs/MOVA_AUDIT_PHASE0.md` (auditoría + roadmap). No hace falta crear documentos nuevos con otro nombre que dupliquen esto — si falta algo específico de alguno de esos formatos, dime cuál y lo agrego a los documentos existentes.

---

## ✅ Checklists cruzados contra el estado real de MOVA (no genérico — verificado)

### SEO
Ya cubierto en `CLAUDE.md` (regla condicional) + Fase 0 (hallazgo UI4: OG/JSON-LD faltante en `Marketplace`/`Teachers/Show`, sigue abierto) + esta sesión (sitemap/robots, cerrado). Páginas de error personalizadas (404, 403, 419, 429, 500, 503) **ya existen**. Breadcrumbs **no existen** — prioridad baja: la navegación de MOVA es mayormente plana (dashboard → una acción), no un catálogo profundo que se beneficie de migas de pan.

### Seguridad
La lista de 20 ítems que pegaste (rate limiting, RLS, env vars, validación, auth, etc.) ya fue el objeto de la auditoría F-01 a N1 de esta misma sesión — Policies, `lockForUpdate()`, throttle en cada mutación, `ProviderGuard` fail-closed, HMAC en webhooks, etc. Las brechas abiertas identificadas hasta ahora en esa lista: secretos en git (F-26/F-27, ya documentada, pendiente de tu rotación) y `PRIV-STUDENT-RETENTION` (P2/OPEN, pendiente de que definas la política de retención). Que el resto de esa lista genérica (RLS, cifrado, query monitoring, bot protection, security headers, upload restrictions, cookie hardening) haya sido objeto de la auditoría no significa que cada punto individual esté óptimo — la lista de hallazgos abiertos en `docs/MOVA_AUDIT_PHASE0.md` es la fuente de verdad, no esta frase.

### UX/animación
La lista de 20 ítems (scroll suave, micro-interacciones, hover states, responsive, transiciones, dark mode, testimonios, etc.) es exactamente el contenido de la **Fase 4 (Frontend/UX/UI)** ya definida en el roadmap de `docs/MOVA_AUDIT_PHASE0.md` — no se ejecuta ahora porque ya acordamos el orden: primero cerrar bugs de negocio/seguridad (hecho), después esta fase. La retomamos cuando digas.

---

## Resumen — qué necesitas decidir tú

1. ¿Quiero que ejecute `npx skills add leonxlnx/taste-skill` (o alguno de los otros `npx skills`) ahora mismo, o prefieres evaluarlo primero?
2. ¿Activo Google Analytics (con la actualización correspondiente de `Privacy.vue`) o lo dejamos fuera por ahora?
3. ¿Avanzamos ya a la Fase 4 (frontend/UX/UI premium) del roadmap, o seguimos con Production Parity / la rotación de F-26 primero?

---

## 🔁 Segunda opinión externa (2026-08-27) — qué se ratifica, qué se corrige, qué se adopta

Recibí una revisión externa de este documento (con formato de asistente con navegación web, citas incluidas) proponiendo un "prompt maestro" de gobernanza de tooling de 20 secciones. La evalué punto por punto en vez de adoptarla completa — exactamente el criterio que ese mismo texto pedía aplicar.

**Se ratifica (ya era la decisión, sin cambios):**
- No instalar nada más ahora mismo. Orden de trabajo: seguridad/producción (F-26 → F-27 → Database Privilege Audit → Production Parity) antes que tooling/diseño/marketing.
- "Impleméntalos todos" nunca se interpretó como "instala todo" — ya se filtró por necesidad real desde el primer pase de este documento.
- 473/473 tests en verde no se trató como "MOVA está listo": `PRIV-STUDENT-RETENTION` sigue P2/OPEN a propósito, y ningún hallazgo de Student/JaaS se declaró "hardened" sin cambio de código real. La disciplina que la crítica pide ya estaba en marcha (Audit Snapshot Contract).

**Se adopta (valor real de la propuesta, ahora política permanente):**
- El filtro de "no instalar solo porque es bueno" + la taxonomía **INSTALL NOW / INSTALL LATER / OPTIONAL / REJECT** + el orden de prioridad (seguridad > negocio > QA > performance > UX/UI > SEO > growth) — trasladado a `CLAUDE.md` como regla condicional permanente, para que gobierne cualquier decisión de tooling futura, no solo esta.
- `taste-skill`, `gstack`, `mattpocock/skills` — reclasificados de "requiere tu decisión" a **INSTALL LATER**: válidos en principio, pero se re-evalúan recién al llegar a la Fase 4 (UX/UI), no antes. La cautela que propone la crítica sobre `taste-skill` (discrepancias de nombre de instalación) ya estaba reflejada en la versión anterior de este documento.

**Se corrige (la propuesta externa se equivoca en esto):**
- **Playwright MCP no pasa a "candidato fuerte" — sigue en REJECT.** El argumento de la crítica es que tiene integración oficial documentada y encaja con QA de MOVA — cierto, pero irrelevante: este mismo entorno de Claude Code ya tiene el Browser pane (`mcp__Claude_Browser__*`) para inspección/QA manual durante una sesión. No son idénticas (una es interactiva, la otra automatiza), pero **no justifica hoy una tercera interfaz de browser automation** — MOVA ya dispone de dos capas (Browser pane para QA manual, Playwright como librería en `qa/` para E2E reproducible). Reevaluar únicamente si aparece una necesidad que esas dos capas no puedan cubrir. Y para pruebas E2E repetibles/CI (no interactivas), MOVA ya tiene Playwright como librería en `qa/tests/*.spec.js` — ese es el lugar correcto para los flujos parent→pago→clase→reseña / teacher→disponibilidad→reporte / admin→revisión→auditoría que la crítica describe, no una MCP nueva. La necesidad real (más cobertura E2E por rol) es válida; la herramienta propuesta para resolverla es redundante. Acción: si se retoma QA, ampliar `qa/tests/` con esos 3 flujos usando lo que ya existe.
- **Las citas sobre `gstack`/`mattpocock` en la crítica vienen de fuentes de autoridad baja** (un fork/mirror de GitHub, un README de terceros) — no se tratan como verificación real de madurez/mantenimiento. Si se retoma esa evaluación en la Fase 4, se investiga de nuevo con fuentes propias antes de decidir, no se hereda la cita externa como hecho.
