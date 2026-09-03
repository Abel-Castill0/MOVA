---
name: handoff
description: Genera docs/SESSION_HANDOFF.md con el estado de la sesión actual. Invocación manual únicamente vía /handoff.
disable-model-invocation: true
---

# /handoff

## Antes de escribir nada

`.git/info/exclude` no viaja con `git clone` — en un clon nuevo puede no tener la línea que mantiene `docs/SESSION_HANDOFF.md` fuera de `git status`. Antes de generar el archivo, revisa `.git/info/exclude` y, si la línea exacta `docs/SESSION_HANDOFF.md` no está ya presente, agrégala (una sola vez — no duplicar si ya existe). Nunca uses `.gitignore` para esto.

## Generar el handoff

Genera o reemplaza por completo `docs/SESSION_HANDOFF.md` (no acumules versiones anteriores) con exactamente estas secciones:

1. **Goal** — objetivo de esta sesión/fase, en 1-3 líneas.
2. **Snapshot** — estado exacto del repo en este momento:
   - `HEAD` (hash + resumen del commit)
   - `origin/master` (hash, y si diverge de `HEAD`) si hay remoto configurado
   - working tree: limpio, o lista de archivos modificados/untracked
   - timestamp actual
3. **Completed** — qué se terminó y quedó verificado en esta sesión.
4. **Validation** — qué evidencia respalda "completed" (tests corridos, comandos, resultado).
5. **Decisions** — decisiones de diseño/arquitectura tomadas y por qué.
6. **Failed approaches worth remembering** — qué se intentó y no funcionó, para no repetirlo.
7. **Remaining blockers** — qué falta y qué lo bloquea.
8. **Next exact action** — el siguiente paso concreto y accionable, no una lista de ideas.

## Reglas

- Nunca incluir: secretos, tokens/access tokens, PAN/CVV, respuestas crudas de proveedores (Mercado Pago, etc.), logs largos, o discusiones ya superadas por decisiones posteriores.
- Sé conciso — este documento es para retomar rápido, no un registro exhaustivo.
