// Regresión del límite de consulta (polling) del checkout de Mercado Pago (resources/js/lib/checkoutPolling.js +
// resources/js/Pages/Teacher/Credits/Checkout.vue). Node puro, sin dependencias.
//
// Defecto que cubre: el checkout hacía hasta 46 consultas con backoff (~5 min) pero anunciaba «hasta un minuto» y, al
// agotarlas, dejaba el spinner girando para siempre en estado «pendiente». Ahora: el plazo mostrado sale de los mismos
// números que el polling real, y al agotarse hay un estado claro «no pudimos confirmar todavía» que NO es aprobado ni
// fallido, prohíbe volver a pagar y ofrece una consulta segura.
import { readFileSync } from 'node:fs';
import { MAX_POLL_ATTEMPTS, pollBudgetLabel, pollBudgetSeconds, pollIntervalMs, pollOutcome } from '../resources/js/lib/checkoutPolling.js';

let failed = 0;
const ok = (cond, msg) => { if (!cond) { failed++; console.error('FALLA: ' + msg); } };

// 1) Aritmética del presupuesto (46 polls, backoff 3/5/8/10 s).
ok(MAX_POLL_ATTEMPTS === 46, 'MAX_POLL_ATTEMPTS esperado 46');
ok(pollIntervalMs(0) === 3000 && pollIntervalMs(9) === 3000 && pollIntervalMs(10) === 5000 && pollIntervalMs(20) === 8000 && pollIntervalMs(45) === 10000, 'escalones del backoff');
ok(pollBudgetSeconds() === 320, `presupuesto esperado 320 s, real ${pollBudgetSeconds()} s`);

// 2) El plazo anunciado coincide con el presupuesto real (nunca «un minuto» con 5 minutos de polling).
ok(pollBudgetLabel() === '5 minutos', `etiqueta del plazo: ${pollBudgetLabel()}`);

// 3) Salida del polling: se sigue hasta el último poll permitido y luego se declara agotado.
ok(pollOutcome(0) === 'continue' && pollOutcome(45) === 'continue', 'debe continuar antes del último poll');
ok(pollOutcome(46) === 'exhausted' && pollOutcome(47) === 'exhausted', 'debe agotarse tras el último poll');

// 4) Plantilla y lógica del componente.
const vue = readFileSync(new URL('../resources/js/Pages/Teacher/Credits/Checkout.vue', import.meta.url), 'utf8');
ok(!/hasta un minuto/i.test(vue), 'la plantilla no debe prometer «hasta un minuto»');
ok(/Esto puede tardar hasta \{\{ pollBudgetText \}\}/.test(vue), 'el plazo mostrado debe salir de pollBudgetLabel()');
ok(/isVerifying && pollExhausted/.test(vue), 'debe existir una rama propia para el polling agotado antes del spinner');
ok(/No pudimos confirmar tu pago todav/.test(vue), 'texto «no pudimos confirmar todavía»');
ok(/No vuelvas a pagar/.test(vue), 'debe conservar la advertencia de no volver a pagar');
ok(/Consultar estado/.test(vue) && /@click="consultAgain"/.test(vue), 'debe ofrecer una consulta segura');
const fn = (name) => { const i = vue.indexOf(`function ${name}(`); return i < 0 ? '' : vue.slice(i, vue.indexOf('\n}\n', i)); };
ok(!/status\.value\s*=/.test(fn('markPollExhausted')), 'agotar el polling NO debe cambiar status (ni aprobado ni fallido)');
ok(!/status\.value\s*=\s*'(approved|failed)'/.test(fn('consultAgain') + fn('markPollExhausted')), 'sin evidencia no se presenta aprobado/fallido');
ok(/pollExhausted\.value = false/.test(fn('startPolling')), 'startPolling debe limpiar el estado agotado');
ok(!/axios\.post\(route\('teacher\.credits\.checkout\.pay'/.test(fn('consultAgain')), 'la consulta nunca debe llamar al endpoint de pago');

if (failed) { console.error(`${failed} comprobación(es) fallida(s)`); process.exit(1); }
console.log('check-checkout-poll: OK');
