---
name: mova-audit
description: Auditoría formal de seguridad/integridad de MOVA con contrato de snapshot explícito. Invocación manual únicamente vía /mova-audit <scope>.
disable-model-invocation: true
context: fork
background: false
argument-hint: "[scope]"
---

# /mova-audit $ARGUMENTS

Audita exactamente el alcance recibido en `$ARGUMENTS`. Este comando es de solo lectura/diagnóstico: reporta hallazgos, no los corrige — una corrección es una tarea aparte, posterior y explícita.

Una auditoría anterior de esta base de código encontró, de forma verificada, que un checkout aislado (`git worktree` desde `HEAD`) puede describir un estado del código distinto al del directorio de trabajo real cuando hay cambios sin commitear — y que confundir ambos produjo un hallazgo de seguridad reportado como abierto cuando ya estaba corregido. Esta skill existe para que eso no se repita.

## Antes de cualquier conclusión

Declara explícitamente el snapshot exacto contra el que se audita `$ARGUMENTS`:

- `HEAD` (hash)
- `origin/<rama>` (hash, y si diverge de `HEAD`)
- si se usó un checkout aislado o el directorio de trabajo real
- estado del working tree (limpio / N archivos modificados-untracked)
- timestamp

Nunca declares algo "verificado" o "corregido" sin decir contra qué snapshot. Nunca mezcles evidencia de un checkout aislado con evidencia del directorio de trabajo real sin señalarlo explícitamente.

## Exposición de secretos — cinco dimensiones separadas

No las mezcles bajo una sola cifra:

1. Exposición histórica (todo lo que alguna vez lo contuvo, en cualquier commit).
2. Exposición en `HEAD` local.
3. Exposición en el remoto actual.
4. Exposición en el working tree (sin commitear).
5. Validez de la credencial (¿sigue activa?).

## Controles — no son sustitutos entre sí

Rotar una credencial, limpiar el remoto actual (commit+push hacia adelante), y purgar el historial de git actúan sobre superficies distintas. Ejecutar uno no implica que los otros ya no hagan falta — decláralos como controles independientes con su propio estado (hecho / pendiente / no aplica).

## Alcance de `$ARGUMENTS`

Si `$ARGUMENTS` llega vacío, pide que se acote (p. ej. "pagos", "auth", "todo el repo") antes de empezar — una auditoría sin alcance declarado no es reproducible. No delegar esta auditoría a un subagente tipo `Explore`: el Audit Snapshot Contract exige el estado real de Git/working-tree, y `Explore` omite deliberadamente parte de ese contexto de proyecto.
