// DESACTIVADO (no registrado en settings.json): el A/B de 2026-10-22 no mostró ahorro ni mejora medible; ver docs/CLAUDE_CODE_AGENTS.md §8.
// Para activarlo: añadir en .claude/settings.json hooks.UserPromptSubmit -> node "${CLAUDE_PROJECT_DIR}/.claude/hooks/optional/prompt-hint.mjs".
// UserPromptSubmit: orientación mínima (≤ 45 palabras) para instrucciones informales y ambiguas.
// - Clasificación determinista por palabras clave (sin LLM, sin red, sin logs, sin estado).
// - No altera ni bloquea el prompt: solo `additionalContext`, y únicamente si aporta algo.
// - Silencio en: slash commands, mensajes no humanos (tareas programadas, notificaciones de
//   subagentes/sesiones, recordatorios), prompts largos o ya específicos (rutas/archivos/código),
//   mensajes cortos de conversación.
import { readInput, context } from '../lib.mjs';

const input = readInput();
const raw = String(input.prompt ?? '').trim();
if (!raw) process.exit(0);

// 1) Eventos que no son una instrucción humana informal.
if (/^[/!#]/.test(raw)) process.exit(0);
if (/<(?:pasted_content|task-notification|system-reminder|side-sessions-event|ci-monitor-event|command-name|scheduled|local-command)/i.test(raw)) process.exit(0);
if (raw.length > 400 || raw.split('\n').length > 6) process.exit(0); // ya es un prompt elaborado

// 2) Ya específico: menciona archivo/ruta/identificador de código o usa backticks.
if (/`|(?:^|[\s(])[\w.-]+\/[\w./-]+|\b[\w-]+\.(?:php|vue|js|mjs|json|md|sql|ya?ml|blade)\b|\b[A-Z][a-z0-9]+(?:[A-Z][a-z0-9]+)+(?:::|\(|\b)/.test(raw)) process.exit(0);

const text = raw.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
if (text.split(/\s+/).length < 4) process.exit(0); // "ok", "sí, hazlo", "gracias"

// 3) Categorías por prioridad (la primera que coincide gana; una sola orientación).
const rules = [
  ['dinero', /\b(mercado ?pago|yape|culqi|pago|pagos|cobro|cobros|checkout|webhook|recarga|recargas|reembolso|devoluc\w*|credito|creditos|saldo|ledger|liquidaci\w*|reserva de credito\w*|comision\w*)\b/,
    'Dinero/créditos: diagnostica con evidencia (archivo:línea) y respeta ledger, transacción, lock e idempotencia. No inventes políticas de devolución, montos ni métodos de pago: si falta una decisión de negocio, repórtala. Cierre con mova-security-finance.'],
  ['seguridad', /\b(seguridad|vulnerab\w*|permiso|permisos|autoriza\w*|mfa|2fa|idor|secreto\w*|token\w*|contrasena\w*|menores?|privacidad)\b/,
    'Seguridad: ownership/policies server-side, sin exponer secretos ni datos de menores. Si toca auth o menores, revisión final con mova-security-finance.'],
  ['git', /\b(commit|push|merge|rama|ramas|branch|rebase|pull request|release|despliega\w*|deploy)\b/,
    'Git/release: inspecciona rama, HEAD y status antes de actuar; sin push, force ni deploy salvo orden explícita. Para publicar: /mova-release-check.'],
  ['arquitectura', /\b(planifica\w*|plan para|disena\w*|arquitectura|funcionalidad|quiero que (?:el|la|los|las|se|puedan?)|haz que (?:el|la|los|las)|agrega\w* (?:una?|el|la)|nueva? (?:pantalla|modulo|flujo|funcion))\b/,
    'Funcionalidad: decide tamaño S/M/L. Si cruza varias capas usa /mova-feature-workflow con un plan breve por dependencias; si piden solo planificar, no implementes.'],
  ['depuracion', /\b(error|errores|falla|fallando|fallo|bug|roto|no funciona|no anda|arregla\w*|corrige\w*|excepcion|500|se cae|por que (?:esta|falla|no))\b/,
    'Depuración: localiza la causa con evidencia (archivo:línea) antes de editar; corrige la causa, no el síntoma; añade o ejecuta el test dirigido.'],
  ['backend', /\b(controlador\w*|servicio\w*|migraci\w*|modelo\w*|endpoint\w*|ruta\w*|cola\w*|scheduler|policy|policies|job\w*|base de datos|query|consulta sql)\b/,
    'Backend: ubica primero el servicio/policy existente con grep; migración forward-only; test con --filter, no la suite completa.'],
  ['frontend', /\b(vue|dashboard|pantalla|pagina|diseno|feo|fea|ui|ux|boton\w*|responsive|movil|css|tailwind|estilo\w*|modal|formulario\w*|navbar|componente\w*)\b/,
    'Frontend: cambio mínimo en la página/componente existente, reutiliza Components/, comprueba móvil y teclado. mova-vue-reviewer solo si el cambio es amplio.'],
];
const hit = rules.find(([, re]) => re.test(text));
if (!hit) process.exit(0);

context('UserPromptSubmit', hit[2]);
