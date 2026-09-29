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

if (!source.includes('Asistente educativo')) {
  console.error('check-movi-availability-status: FAIL — falta la copia neutral esperada ("Asistente educativo").');
  failed++;
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
