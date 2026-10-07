// Regresión barata para resources/js/Components/ChatbotWidget.vue: el chatbot
// puede estar apagado (CHATBOT_ENABLED=false → 503 genérico) y el frontend no
// tiene forma de saberlo, así que la ventana de Movi nunca debe afirmar que
// está "En línea" — sería un estado falso. Node puro, sin dependencias.
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = join(import.meta.dirname, '..');
const source = readFileSync(join(root, 'resources/js/Components/ChatbotWidget.vue'), 'utf-8');

const misleadingClaims = ['En línea', 'Disponible', 'Activo'];
let failed = 0;

for (const claim of misleadingClaims) {
  if (source.includes(claim)) {
    console.error(`check-movi-availability-status: FAIL — el widget vuelve a afirmar disponibilidad no verificada ("${claim}").`);
    failed++;
  }
}

if (!source.includes('Asistente virtual')) {
  console.error('check-movi-availability-status: FAIL — falta la copia neutral esperada ("Asistente virtual").');
  failed++;
}
// Sin indicador de «conectado» (punto verde): el widget no puede verificar el estado del backend.
if (/bg-emerald-(400|500)[^"]*"[^>]*aria-hidden/.test(source)) {
  console.error('check-movi-availability-status: FAIL — el widget muestra un indicador de estado en línea no verificado.');
  failed++;
}
// Los layouts también deben montar Movi solo cuando el backend lo declara disponible (shared prop movi.enabled).
for (const layout of ['resources/js/Layouts/AppLayout.vue', 'resources/js/Layouts/PublicPageLayout.vue']) {
  const src = readFileSync(join(root, layout), 'utf-8');
  const tags = src.match(/<ChatbotWidget\b[^>]*>/g) ?? [];
  if (tags.length === 0 || tags.some((t) => !/v-if="\$page\.props\.movi\?\.enabled"/.test(t))) {
    console.error(`check-movi-availability-status: FAIL — ${layout} debe montar ChatbotWidget con v-if="$page.props.movi?.enabled".`);
    failed++;
  }
}

// La home solo debe montar Movi cuando el backend lo declara disponible
// (WelcomeController → chatbotEnabled = ChatbotService::isAvailable()).
const welcome = readFileSync(join(root, 'resources/js/Pages/Welcome.vue'), 'utf-8');
const mounts = welcome.match(/<ChatbotWidget\b[^>]*>/g) ?? [];
if (mounts.length === 0 && welcome.includes('ChatbotWidget')) {
  console.error('check-movi-availability-status: FAIL — no se pudo localizar el montaje de ChatbotWidget en Welcome.vue.');
  failed++;
}
if (mounts.some((tag) => !/v-if="chatbotEnabled"/.test(tag))) {
  console.error('check-movi-availability-status: FAIL — Welcome.vue monta ChatbotWidget sin v-if="chatbotEnabled".');
  failed++;
}

if (failed) process.exit(1);
console.log('check-movi-availability-status: PASS (sin afirmaciones de disponibilidad no verificadas).');
