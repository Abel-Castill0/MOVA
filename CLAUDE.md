## Autonomía operativa

- `AGENTS.md` contiene la autorización permanente del propietario para completar MOVA en staging y producción, usar los paneles autenticados, configurar DNS/integraciones, rotar y cargar secretos directamente en Azure, desplegar y publicar cambios validados. No vuelvas a pedir autorizaciones ya concedidas.
- Sigue los límites de crédito Azure, pagos reales, secretos y decisiones del propietario establecidos en `AGENTS.md`. Si una interfaz exige la acción física del propietario (passkey/2FA, confirmación de titularidad o dato legal), pide solo ese paso concreto y continúa lo demás.
- Ahorra contexto: reutiliza evidencia reciente, agrupa consultas, limita logs a estados/conteos y no repitas suites sin cambios. No cierres tras una auditoría o una lista de pendientes: avanza en todas las tareas independientes y deja como bloqueos solo acciones que requieran físicamente al titular, fondos disponibles, credenciales que no puedan obtenerse por los paneles autorizados, o decisiones de negocio/legal que no se puedan inferir con seguridad. Presenta esas acciones concretas en una lista consolidada y continúa en cuanto se resuelvan.

# MOVA — Project Instructions

Laravel 13 (PHP 8.3+) + Vue 3 + Inertia 2 + Tailwind 3 + Vite 5 + MySQL (`composer.json` y `package.json` son la fuente).
Plataforma peruana de tutorías con padres, profesores y alumnos menores de edad.

> **Precedencia:** `AGENTS.md` es la fuente de autorización operativa. El propietario reafirmó el 2026-10-05 la autorización permanente allí descrita para completar y publicar MOVA en staging y producción. No pidas aprobaciones repetidas para acciones dentro de ese alcance. Conserva los límites de crédito Azure, privacidad, secretos, seguridad financiera y decisiones legales/de producto establecidos en `AGENTS.md`.

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
- Para la tarea permanente de completitud puedes usar las sesiones autenticadas y paneles de los proveedores para staging y producción, obtener/crear y rotar credenciales en el momento indicado por `AGENTS.md`, cargarlas directamente en Azure y configurar los servicios sin mostrar valores. Las credenciales de prueba solo van a staging; las live solo a producción. No pidas una segunda autorización para los pasos ya cubiertos.
- Nunca reveles credenciales en el chat, la terminal, logs, capturas, archivos versionados o documentación. Transfiere secretos directamente desde el panel al `.env` local ignorado por Git o al gestor de secretos; valida solo presencia, formato y modo.
- Si el navegador solo está abierto en la computadora pero no hay una herramienta/conexión de navegador disponible en Claude Code, no afirmes que puedes verlo: informa el requisito concreto de conectar Claude for Chrome u otro control de navegador compatible y continúa con el trabajo independiente.

## 4. Git y releases

- Inspecciona branch, HEAD, status y ahead/behind antes de mutar Git.
- Para completar MOVA, `AGENTS.md` autoriza commit/push a `master` y despliegue de cambios validados que pertenezcan al proyecto. No incluyas archivos ajenos, secretos ni cambios no validados.
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

Agentes de solo lectura (`.claude/agents/`; la edición la hace siempre la sesión principal): `mova-architect` (diseño previo, cambios multicapa), `mova-laravel-reviewer`, `mova-vue-reviewer`, `mova-security-finance` (obligatorio si el cambio toca dinero, auth, menores, JaaS o webhooks) y `mova-qa-reviewer`. Una tarea de 1–2 archivos no lanza ninguno. Skills: `/mova-feature-workflow`, `/mova-safe-qa`, `/mova-release-check`. Hooks de seguridad en `.claude/hooks/` (guía: `docs/CLAUDE_CODE_AGENTS.md`).

## 8. Autonomía, costos y ahorro de contexto

- Usa primero evidencia ya producida; no repitas búsquedas, llamadas a paneles, pruebas o lecturas de logs sin un cambio de estado que lo justifique. Agrupa consultas independientes, limita salidas a conteos/estados, evita pegar logs largos y ejecuta la suite completa una vez contra el árbol final.
- Antes de cualquier recurso/cambio con costo, verifica crédito y costo por recurso. El crédito reportado es US$86 hasta 2027-09-14; prioriza reducir consumo y no excedas el saldo, no actives Pay-As-You-Go y no compres planes de pago. Las alertas no detienen el gasto. Si la arquitectura necesaria no cabe en el crédito, presenta una única comparación breve y continúa todo el trabajo sin costo.
- Puedes eliminar recursos únicamente de staging si confirmas que contienen solo datos sintéticos/recreables, preservas backup/export y verificas que el ahorro supera el impacto en QA. Nunca elimines recursos de producción para ahorrar sin preservar recuperación y continuidad.
- Mantén pagos/webhooks apagados hasta completar integración, renovar secretos expuestos y superar los gates técnicos. Para la prueba live final, sigue `AGENTS.md`: cuenta controlada por el propietario, importe mínimo, una sola transacción y reembolso posterior. No cargues a terceros ni abras liquidaciones reales.
- No inventes datos legales, consentimientos de menores, políticas de reembolso/ausencia, disputas, recurrencia, verificación docente ni presupuesto. Investiga lo que puedas y pide solo decisiones que la autoridad técnica no permita obtener.

## 9. Eficiencia (sin relajar seguridad ni calidad)

- **Pequeña** (1–2 archivos, sin dinero/auth/menores): directo en la sesión principal, sin subagentes ni plan; un test dirigido.
- **Mediana**: localiza con Grep/Glob (`mova-context-scout` solo si la búsqueda es amplia); reutiliza el patrón existente; cambio mínimo.
- **Compleja o sensible**: plan breve por dependencias reales; `/mova-feature-workflow`; `mova-security-finance` si toca dinero, auth, menores, JaaS o webhooks.
- No releas archivos ya leídos ni repitas tests sin cambios; a los subagentes pásales rutas concretas. Sonnet por defecto; Haiku solo para localizar; Opus solo para razonamiento financiero/de seguridad profundo. `/clear` entre tareas independientes; `/handoff` antes de pausas largas. Para instrucciones informales: `/mova-prompt`.

# Compact instructions

Al compactar conserva: objetivo, archivos modificados, decisiones, comandos de prueba con su resultado y bloqueos pendientes; descarta logs, exploraciones descartadas y salidas largas.
