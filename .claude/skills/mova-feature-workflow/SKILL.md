---
name: mova-feature-workflow
description: Flujo para desarrollar una funcionalidad o fix NO trivial en MOVA (varias capas, flujo de negocio, migración, rol o dinero) — investigar, planificar, implementar, revisar con el subagente adecuado y verificar. Úsalo cuando el usuario pida una feature nueva o un cambio que cruce backend y frontend. No lo uses para cambios de un archivo, copy o estilos menores.
argument-hint: "[descripción de la funcionalidad]"
---

# /mova-feature-workflow $ARGUMENTS

Flujo en 5 pasos. **Decide primero el tamaño** y salta lo que no aplique; una tarea sencilla NO lanza subagentes.

| Tamaño | Criterio | Subagentes |
|---|---|---|
| S | 1–2 archivos, sin dinero/auth/menores/migración | ninguno |
| M | varias capas o una migración | `mova-architect` (si el diseño no es obvio) + 1 revisor por capa tocada |
| L | dinero, créditos, pagos, auth/MFA, menores, JaaS, webhooks | `mova-architect` + `mova-security-finance` (obligatorio) + revisores de capa |

## 1. Investigar (sin editar)
- Lee solo lo necesario: código real, rutas, modelo (`$fillable`/`$casts`), policy y tests vecinos. Estado de entornos: Ledger «Estado actual» por `grep -n`. Nada de leer migraciones enteras ni documentos HISTÓRICOS.
- Búsquedas amplias y ruidosas → `Explore` (devuelve solo conclusiones).
- Confirma el patrón existente a reutilizar (Service, Policy, contrato de proveedor, composable/componente).

## 2. Planificar
- M/L: pide a `mova-architect` el diseño pasando objetivo + archivos candidatos + restricciones; no repitas lo que ya está en `CLAUDE.md`/`AGENTS.md`.
- Resume el plan al usuario en ≤ 10 líneas solo si hay una decisión suya (negocio/legal/precio/consentimiento). Si no, continúa.

## 3. Implementar (solo la sesión principal edita)
- Cambio mínimo, patrones vecinos, migración forward-only, validación y autorización server-side, transacción+lock+idempotencia si hay dinero.
- Añade/ajusta el test dirigido junto al código (caso feliz + caso negativo/ajeno; concurrencia si hay dinero).
- Nunca dos agentes editando los mismos archivos.

## 4. Revisar (en paralelo, solo lo que aplica, pasando la lista de archivos tocados)
- Backend → `mova-laravel-reviewer`; UI → `mova-vue-reviewer`; L → `mova-security-finance`; antes de cerrar M/L → `mova-qa-reviewer` para el plan de pruebas.
- Corrige los hallazgos BLOQUEANTE/ALTO/CRÍTICO; los demás se reportan.

## 5. Verificar
- Usa `/mova-safe-qa` para elegir y ejecutar las pruebas (dirigidas primero; suite completa una vez al final).
- Cierre: archivos modificados, pruebas ejecutadas y resultado, riesgos residuales, qué queda «no verificado». Sin commit/push salvo petición explícita o autorización vigente de `AGENTS.md`.

## Prohibido
Inventar datos legales/consentimientos/estados financieros; tocar `.env`, Azure o proveedores sin que la tarea lo exija; debilitar policies o constraints para que pase un test.
