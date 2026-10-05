## Autonomía operativa

- Trabaja de forma proactiva y orientada a resultados: implementa lo pedido, configura los entornos autorizados y completa la verificación final. No repitas auditorías generales ni preguntes qué fase sigue si puedes continuar con trabajo independiente.
- El propietario autorizó el 2026-10-05 la completitud y lanzamiento de MOVA de punta a punta. Avanza por todos los dominios de producto, integraciones, seguridad, datos, infraestructura y cutover; no cierres la tarea tras resolver un solo módulo ni después de otra auditoría. Implementa, configura y verifica cada parte que no dependa de una decisión o acceso externo que falte.
- Para la tarea de integraciones autorizada por el propietario el 2026-10-05, puedes leer y usar internamente solo las variables necesarias de .env para las sondas locales/QA. Nunca imprimas, registres, copies a argumentos visibles ni guardes secretos en archivos versionados o reportes. No asumas que una credencial de .env es sandbox; comprueba el modo sin revelar el valor.
- Puedes configurar Azure staging para Mercado Pago sandbox, correo de prueba, JaaS, almacenamiento y Sentry usando credenciales no productivas. Envía correos solo al buzón de prueba del propietario. Las transacciones deben usar usuarios y medios de pago de prueba; nunca hagas cargos reales.
- Si faltan credenciales o acciones del propietario, sigue avanzando en lo que no dependa de ellas y solicita únicamente el dato concreto, el nombre de variable y el lugar seguro donde cargarlo. No pidas que pegue secretos en el chat.
- Para esta tarea de completitud, el propietario autoriza preparar/desplegar producción separada en Azure, hacer commit/push de los cambios validados de MOVA y conectar `movaeduca.me` en Namecheap después de superar los gates de lanzamiento. Mantén staging separado; nunca apuntes el dominio público a una instalación que siga usando QA/sandbox. Preserva los MX/TXT y el reenvío de correo existentes, y guarda el estado previo para rollback.
- Antes de provisionar infraestructura con costo, comprueba crédito/costo disponible. No compres un plan ni excedas el crédito o límite existente; continúa trabajo independiente y reporta el costo exacto si hace falta autorización adicional.
- No hagas cargos reales, no envíes correo masivo y no actives liquidaciones en vivo sin las credenciales correctas y el gate de lanzamiento. La autorización de DNS/despliegue no autoriza por sí sola cargos reales a clientes.
- No hagas commit/push de archivos ajenos o no validados. Antes de publicar, revisa branch/HEAD/status, protege todo trabajo local, separa los cambios de MOVA de cualquier artefacto ajeno y ejecuta el gate final contra el árbol definitivo.
- Antes de una prueba externa, confirma que usa sandbox, destinatarios de prueba y datos sintéticos. Si el modo es ambiguo o parece live, detén solo esa prueba y explica el bloqueo; continúa las demás.
- Ahorra tiempo y contexto: agrupa comprobaciones independientes, ejecuta suites dirigidas durante el desarrollo y el conjunto final una vez sobre el árbol definitivo. No edites archivos mientras se ejecutan pruebas basadas en snapshots.
- Si una operación falla por RBAC, permisos, autenticación, límites del proveedor o política administrada, identifica el bloqueo real y continúa con tareas independientes. Registra evidencia sin secretos y no afirmes que una integración funciona hasta probarla en el entorno que corresponda.
- No inventes decisiones de producto, consentimiento de menores, obligaciones legales, política de reembolsos/ausencias, frecuencia de clases, verificación documental ni presupuestos. Cuando una de estas decisiones sea imprescindible, formula una pregunta concreta y única con la recomendación más segura; mientras esperas, completa todas las áreas independientes.

# MOVA — Project Instructions

Laravel 13 (PHP 8.3+) + Vue 3 + Inertia 2 + Tailwind 3 + Vite 5 + MySQL (`composer.json` y `package.json` son la fuente).
Plataforma peruana de tutorías con padres, profesores y alumnos menores de edad.

> **Precedencia:** si algo de este archivo contradice `AGENTS.md` (por ejemplo, permisos de commit, push, despliegue, migraciones o acciones sobre producción), prevalece `AGENTS.md`: esas acciones requieren autorización explícita del propietario en la tarea concreta. La sección «Autonomía operativa» no la amplía.

## 1. Trabajo sobre el código

- Inspecciona el código real y sus patrones antes de diseñar una solución. No asumas arquitectura, contratos ni estados.
- Haz el cambio mínimo que resuelva correctamente el problema. No amplíes el scope sin necesidad.
- Preserva comportamiento existente salvo que la tarea exija cambiarlo.
- No sobrescribas, reviertas ni incluyas cambios ajenos/no relacionados.
- Si existe una ambigüedad crítica sobre dinero, autenticación, menores o integridad de datos, investígala usando código, configuración, logs y fuentes disponibles. Toma la decisión más conservadora compatible con la evidencia y continúa. Detente únicamente cuando falte una credencial, permiso externo, dato del propietario o autoridad que técnicamente no pueda obtenerse.

## 2. Seguridad e integridad

- Autorización y reglas de negocio sensibles se validan server-side; nunca confíes en IDs, roles, cantidades, precios, créditos o estados enviados por el cliente.
- No expongas ni registres secretos, tokens, credenciales o datos sensibles innecesarios.
- Las mutaciones financieras deben conservar transacciones, locking, idempotencia y el ledger append-only existentes.
- Nunca conviertas un estado financiero incierto o pendiente en éxito o rechazo sin evidencia suficiente.
- No debilites Policies, middleware, ownership checks, constraints o validación para hacer pasar una prueba.

## 3. Base de datos

- La instrucción directa del propietario autoriza las operaciones de base de datos necesarias y comprendidas en esa tarea; no pidas una segunda aprobación para repetir una autorización ya dada.
- Antes de una operación irreversible o destructiva, debe inspeccionar el entorno, verificar que está actuando sobre el target correcto, preservar evidencia y utilizar backup/PITR/rollback cuando exista.
- No debe inventar consentimientos, datos financieros ni estados de negocio.
- No reescribas una migration ya aplicada en un entorno compartido; utiliza una migration forward-fix salvo que la tarea requiera explícitamente otra estrategia.
- Para la tarea de integraciones autorizada el 2026-10-05, puedes usar el navegador autenticado del propietario y los paneles de Mercado Pago/JaaS; copiar credenciales de prueba directamente al `.env` local ignorado por Git; y desplegar/configurar Azure staging. No vuelvas a pedir permiso para estos pasos. No leas ni uses credenciales live para pruebas, no habilites cargos reales ni despliegues a producción sin una instrucción directa que mencione expresamente ese alcance.
- Nunca reveles credenciales en el chat, la terminal, logs, capturas, archivos versionados o documentación. Transfiere secretos directamente desde el panel al `.env` local ignorado por Git o al gestor de secretos; valida solo presencia, formato y modo.
- Si el navegador solo está abierto en la computadora pero no hay una herramienta/conexión de navegador disponible en Claude Code, no afirmes que puedes verlo: informa el requisito concreto de conectar Claude for Chrome u otro control de navegador compatible y continúa con el trabajo independiente.

## 4. Git y releases

- Inspecciona branch, HEAD, status y ahead/behind antes de mutar Git.
- Haz commit, push, merge, rebase, PR, tag o release solo cuando el propietario lo pida expresamente; no infieras esa autorización de una solicitud de implementación o despliegue.
- No incluyas archivos ajenos o no relacionados.
- Ante conflictos, preserva trabajo existente y resuélvelos basándote en evidencia; no descartes trabajo silenciosamente.

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
