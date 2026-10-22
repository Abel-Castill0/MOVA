# Claude Code en MOVA — agentes, skills y hooks

> Estado: CURRENT · 2026-10-21 · Claude Code 2.1.304. Complementa `CLAUDE.md` (reglas) y
> `AGENTS.md` (autorizaciones); no las repite. Referencia estudiada:
> `worldflowai/everything-claude-code` (ECC, commit `432485b`, clonado en un directorio
> temporal fuera del repo; no se instaló nada ni se ejecutó código de terceros).

## 1. Qué hay (inventario)

**Añadidos** (todo en `.claude/`; ninguna lógica de negocio, Azure ni secretos tocados):

| Componente | Archivo | Rol |
|---|---|---|
| Agente `mova-architect` (sonnet, high) | `agents/mova-architect.md` | Diseño previo multicapa: contratos, migraciones, roles, riesgos |
| Agente `mova-laravel-reviewer` (sonnet) | `agents/mova-laravel-reviewer.md` | Revisión backend: policies, transacciones, migraciones MySQL+SQLite, jobs |
| Agente `mova-vue-reviewer` (sonnet) | `agents/mova-vue-reviewer.md` | Revisión UI: Inertia, a11y, ciclo de vida de SDKs, SEO, design system |
| Agente `mova-security-finance` (opus, high) | `agents/mova-security-finance.md` | Dinero/ledger/MP/webhooks, auth, MFA, menores, secretos |
| Agente `mova-qa-reviewer` (sonnet) | `agents/mova-qa-reviewer.md` | Plan mínimo de pruebas (SQLite/MySQL QA/npm/Playwright), huecos |
| Skill `/mova-feature-workflow` | `skills/mova-feature-workflow/` | Investigar→planificar→implementar→revisar→verificar, con umbral S/M/L |
| Skill `/mova-safe-qa` | `skills/mova-safe-qa/` | Selección y ejecución de pruebas aisladas + informe de ≤15 líneas |
| Skill `/mova-release-check` (solo manual) | `skills/mova-release-check/` | GO/NO-GO previo a push/release; solo lectura |
| Hooks + config | `settings.json`, `hooks/*.mjs` | Ver §4 |

**Modificados (mínimo):** `CLAUDE.md` §7 (+1 párrafo de punteros), `docs/README.md` (+1 fila), `docs/PROMPTS_CLAUDE_CODE.md` (regla 8), `.gitignore` (+`/.claude/state/`).
**Reutilizados sin cambios:** `/handoff`, `/mova-audit`, skill `mova-mercadopago`, `.claude/rules/*`, `launch.json`. Los agentes **no** duplican rules ni skills: las referencian.

Todos los agentes son **solo lectura** (`tools: Read, Grep, Glob`, sin Bash/Edit/Write); la sesión principal edita y ejecuta. No hay dos agentes con el mismo trabajo ni agentes que escriban en los mismos archivos.

## 2. Qué se tomó de Everything Claude Code

| Clasificación | Componentes ECC | Decisión |
|---|---|---|
| Adaptable | `architect`, `code-reviewer`, `security-reviewer`, `e2e-runner`/`tdd-guide` (ideas), `verification-loop`, `strategic-compact`, hook de avisos pre-push, `session-start/pre-compact/session-end` | Reescritos para Laravel/Vue/MOVA, solo lectura y con rutas reales |
| Duplicado por lo existente | `rules/*` (coding-style, security, git-workflow, testing), `commands/verify`, `checkpoint`, `update-docs`, `/handoff` | Ya cubierto por `CLAUDE.md`, `.claude/rules/`, `/mova-audit`, `/handoff` |
| No recomendable | Hooks de tmux, Prettier/`tsc`/`console.log` (JS/TS), `block-md-files` (choca con la convención de docs de MOVA), `evaluate-session`/`continuous-learning` (escriben estado global, fuera de alcance), `clickhouse-io`, `backend-patterns`/`frontend-patterns` genéricos (Node/React), `mcp-configs` (activan MCP innecesarios), agentes con `Write/Edit/Bash` y `model: opus` por defecto | Descartados: costo/ruido sin valor en MOVA |
| Reutilizable tal cual | Ninguno | ECC está orientado a Node/TS; su formato de hooks (`tool == "Bash"`) es de plugin y no el actual de `settings.json` |

Formatos confirmados con la documentación vigente de Claude Code (subagents, skills, hooks): frontmatter de agentes (`tools`, `model`, `effort`, `maxTurns`, `color`), `SKILL.md` (`disable-model-invocation`, `argument-hint`), hooks `PreToolUse/PostToolUse/SessionStart/PreCompact/SessionEnd` con `permissionDecision` y `additionalContext`.

## 3. Guía de uso

| Situación | Qué hacer | Subagentes |
|---|---|---|
| Fix de 1–2 archivos, copy, estilos | Trabajar directo | **0** |
| Feature multicapa | `/mova-feature-workflow <qué>` | `mova-architect` + revisor(es) de capa |
| Toca dinero/auth/menores/JaaS/webhooks | igual + revisión obligatoria | + `mova-security-finance` |
| Antes de cerrar | `/mova-safe-qa` (dirigido; `final` una vez) | `mova-qa-reviewer` solo si hay dudas de cobertura |
| Antes de push/release | `/mova-release-check` | `mova-security-finance` si no hay informe del HEAD |
| Auditoría formal | `/mova-audit <alcance>` (existente) | — |
| Cerrar sesión/fase | `/handoff` (existente) | — |

Ejemplos concretos:

1. **«Permitir que el padre reprograme una clase ya aceptada»** (L por tocar clases/estados y posible liquidación): `/mova-feature-workflow reprogramación de clase aceptada por el padre` → `mova-architect` (estados en `03-state-machines.md`, `LessonSchedulingService`, `RescheduleTest`) → implementar → `mova-laravel-reviewer` + `mova-vue-reviewer` en paralelo → `mova-security-finance` (ownership padre↔alumno, liberación de crédito sin doble liberación) → `/mova-safe-qa`.
2. **«Mostrar la reseña en el perfil público»** (M, UI + props): `mova-vue-reviewer` sobre `Teachers/Show.vue` (SEO y exposición de datos); sin architect.
3. **«Cambiar el color de un botón»** (S): sin agentes, sin skill.
4. **«Nueva columna en `payment_orders`»** (L): architect (migración forward-only, MySQL+SQLite) → `mova-security-finance` → `/mova-safe-qa` con perfil MySQL.

## 4. Hooks (`.claude/settings.json` → `.claude/hooks/`)

Node ≥ 18 (sin dependencias), ejecutados con `node` desde Git Bash o PowerShell; ninguno hace push, deploy ni red; ninguno imprime valores de secretos (solo el tipo de patrón).

| Evento | Script | Comportamiento |
|---|---|---|
| PreToolUse `Bash` | `guard-bash.mjs` | **Deniega** `git commit` si lo staged (o `-a`) incluye `.env*`, `qa/auth/`, `*.local.env`, `auth.json`, claves PEM o tokens reconocibles (Mercado Pago, GitHub, AWS, Google, Slack, Sentry DSN, Cloudinary…). **Pide confirmación** en `git reset --hard`, force push, `git clean -f`, `checkout/restore .`, `branch -D`, `rm -rf`, `Remove-Item -Recurse -Force`, `migrate:fresh/reset/rollback`, `db:wipe`, `db:seed`, `DROP/TRUNCATE`, y al imprimir un `.env` real (no `*.example`). Ignora el texto entre comillas/heredocs (mensajes de commit). |
| PreToolUse `Edit/Write/MultiEdit/NotebookEdit` | `guard-files.mjs` | **Pide confirmación** al editar `.env*`/`qa/auth`, o una migración ya existente (forward-fix). **Deniega** escribir secretos reconocibles en archivos versionables. Migración nueva: pasa. |
| PostToolUse `Edit/Write/MultiEdit` | `post-edit.mjs` | `php -l`, `node --check`, JSON válido (solo informa, nunca bloquea; avisa si el PHP local es <8.3). Recordatorio **una vez por sesión y zona** (dinero, auth, menores/video, migración) de invocar el revisor. Nunca corre suites. |
| SessionStart / PreCompact / SessionEnd | `snapshot.mjs` | Guarda `.claude/state/session-snapshot.json` (ignorado por Git: rama, HEAD, conteos y **nombres** de archivos modificados) y al iniciar imprime ≤4 líneas (rama@HEAD, sin commit, ahead/behind, aviso si cambió el estado y si existe `docs/SESSION_HANDOFF.md`). |
| `permissions.ask` | `settings.json` | `Read` de `.env`, `.env.production`, `.env.backup`, `.env.claude.local`, `qa/.env.qa`, `qa/auth/**` pide confirmación. |

No implementado a propósito: hook `Stop` por turno (ruido y costo), auto-formato (MOVA no usa Prettier; Pint requiere PHP ≥ 8.3), hooks de push/deploy.

Autotest de los hooks (≈2 s, sin red, usa repos temporales): `node .claude/hooks/selftest.mjs`.

## 5. Pruebas realizadas (2026-10-21)

| # | Prueba | Resultado |
|---|---|---|
| 1 | `node .claude/hooks/selftest.mjs`: 35 casos (7 permitidos + 12 que piden confirmación + 4 de commits (limpio/token/sin filtrar valor/`.env`) + 7 de `guard-files` + 3 de `post-edit` + 2 de snapshot) | 35/35 OK. Detectó y se corrigió un bug de regex propio (falso positivo del `.env` en mensajes de commit) |
| 2 | `claude -p` nuevo en el repo: mensaje `init` lista los 5 agentes `mova-*` y las skills `mova-feature-workflow`, `mova-safe-qa`, `mova-release-check`, `mova-audit`, `mova-mercadopago`, `handoff` | Reconocidos |
| 3 | Delegación a `mova-qa-reviewer` desde esa sesión | Recibió el trabajo y respondió; confirmó que no ejecuta comandos |
| 4 | Pregunta de herramientas a `mova-security-finance` | Responde «Read, Grep, Glob; sin Bash ni edición» |
| 5 | `grep -c APP_KEY .env.production` con Bash pre-aprobado | El hook lo rechazó con el motivo `[MOVA hook]` (el comando no se ejecutó) |
| 6 | Tarea simple («rama actual y archivos sin commit») | 0 llamadas a `Agent`; el contexto de SessionStart aparece; costo ≈ US$0,09 |
| 7 | JSON de `settings.json` válido; `git diff --check`; `git status` (solo archivos de configuración/docs) | OK |

**No probado:** el propio flujo de commit con el hook dentro de una sesión interactiva (cubierto por el autotest con repo temporal); `/mova-safe-qa` y `/mova-release-check` no se ejecutaron de punta a punta (el PHP del host es 8.2 y Docker no se levantó en esta tarea); el agente `mova-architect`/`mova-laravel-reviewer`/`mova-vue-reviewer` solo se verificó por registro y definición, no con una tarea real.

## 6. Rendimiento y costo en tokens (estimado)

- Siempre en contexto: las descripciones de 5 agentes + 2 skills invocables por el modelo ≈ **0,8 k tokens** (`mova-release-check` es solo manual: 0). El cuerpo de una skill carga solo al invocarla (~0,8–1 k tokens); cada agente corre en su propio contexto, no en el principal.
- Un revisor devuelve un informe de ≤ 25–40 líneas (≈ 0,5–1 k tokens al hilo principal); su exploración queda fuera del contexto principal. Coste típico de un subagente sonnet: decenas de miles de tokens de entrada en su propio contexto; `mova-security-finance` (opus) es el más caro y solo es obligatorio en cambios L.
- Hooks: 0 tokens salvo cuando avisan (1–2 líneas) y SessionStart (≤4 líneas).
- Tareas S: sobrecarga cero. Una feature L típica: architect + 3 revisores ≈ 4 informes breves en el hilo.

## 7. Riesgos y limitaciones

- Los agentes se cargan al **iniciar la sesión**: una sesión ya abierta no ve agentes nuevos hasta reiniciarla (por eso las pruebas se hicieron en una sesión `-p` nueva).
- Solo lectura implica que `mova-qa-reviewer` no ejecuta pruebas; lo hace la sesión principal con `/mova-safe-qa`.
- Los hooks usan heurísticas (regex): pueden dar falsos negativos (secretos ofuscados, rutas con variables) y raros falsos positivos (se resuelven con la confirmación `ask`; el único bloqueo duro es secreto/archivo sensible en `git commit` o en contenido a versionar). No sustituyen la rotación de credenciales ni `/mova-audit`.
- `php -l` local es 8.2: la sintaxis 8.3 puede marcarse como error (el aviso lo indica). Las pruebas PHP reales siguen requiriendo Docker (`php_qa`) o CI.
- Un agente puede equivocarse: sus hallazgos exigen archivo:línea y la sesión principal debe verificarlos antes de actuar.
- Cambios no commiteados por petición expresa: revisar y commitear los archivos de `.claude/`, `docs/CLAUDE_CODE_AGENTS.md`, `CLAUDE.md`, `.gitignore` y `docs/*` modificados.
