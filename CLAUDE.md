# MOVA — Project Instructions

Laravel 10 + Vue 3 + Inertia + Tailwind.
Plataforma peruana de tutorías con padres, profesores y alumnos menores de edad.

## 1. Trabajo sobre el código

- Inspecciona el código real y sus patrones antes de diseñar una solución. No asumas arquitectura, contratos ni estados.
- Haz el cambio mínimo que resuelva correctamente el problema. No amplíes el scope sin necesidad.
- Preserva comportamiento existente salvo que la tarea exija cambiarlo.
- No sobrescribas, reviertas ni incluyas cambios ajenos/no relacionados.
- Si una ambigüedad crítica sobre dinero, autenticación, menores o integridad de datos no puede resolverse leyendo el código, detente y repórtala antes de modificar comportamiento.

## 2. Seguridad e integridad

- Autorización y reglas de negocio sensibles se validan server-side; nunca confíes en IDs, roles, cantidades, precios, créditos o estados enviados por el cliente.
- No expongas ni registres secretos, tokens, credenciales o datos sensibles innecesarios.
- Las mutaciones financieras deben conservar transacciones, locking, idempotencia y el ledger append-only existentes.
- Nunca conviertas un estado financiero incierto o pendiente en éxito o rechazo sin evidencia suficiente.
- No debilites Policies, middleware, ownership checks, constraints o validación para hacer pasar una prueba.

## 3. Base de datos

- Nunca ejecutes `migrate:fresh`, `migrate:reset`, `db:wipe`, truncados o acciones destructivas sobre la DB normal sin autorización explícita.
- Las pruebas destructivas pertenecen a una base QA dedicada.
- No reescribas una migration que pudo ejecutarse en un entorno compartido; usa una migration forward-fix.

## 4. Git

- Antes de una tarea que modifica archivos, confirma `git status --short`.
- No incluyas archivos inesperados en commits.
- No hagas `git push` sin aprobación explícita del usuario.
- No uses reset/checkout/clean destructivos para resolver conflictos de scope.

## 5. Dependencias

- No añadas una dependencia si el stack existente o unas pocas líneas mantenibles resuelven el problema.
- Si una dependencia es necesaria, revisa duplicación, mantenimiento, licencia, seguridad, compatibilidad y coste de bundle/runtime antes de instalarla.

## 6. Validación

- Empieza por tests dirigidos al código modificado.
- Evita repetir suites completas sin cambios de código.
- Ejecuta la suite completa una sola vez en el gate final cuando el cambio lo justifique.
- Una afirmación de “verificado”, “corregido” o “seguro” debe estar respaldada por evidencia ejecutada contra el snapshot correcto.

## 7. Instrucciones especializadas

Las reglas específicas de Laravel, Vue/UI y páginas públicas viven en `.claude/rules/`.

Para Mercado Pago usa la skill `mova-mercadopago`.

Para una auditoría formal usa `/mova-audit`.

Antes de cerrar una fase o hacer `/clear`, usa `/handoff`.
