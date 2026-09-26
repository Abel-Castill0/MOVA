// Regresión barata del formateador de Movi (resources/js/utils/chatbotFormat.js):
// texto hostil del proveedor debe salir ESCAPADO, nunca como HTML ejecutable.
// Node puro, sin dependencias. La prueba en navegador real vive en
// qa/tests/chatbot-xss.spec.js.
import { formatMessageText } from '../resources/js/utils/chatbotFormat.js';

const cases = [
  '<script>alert(1)</script>',
  '<img src=x onerror=alert(1)>',
  '<a href="javascript:alert(1)">x</a>',
  '**<img src=x onerror=alert(1)>**',
  '"><svg onload=alert(1)>',
  "'><iframe src=javascript:alert(1)>",
];

let failed = 0;
for (const input of cases) {
  const out = formatMessageText(input);
  // Invariante real: fuera de las etiquetas que el formateador genera él
  // mismo (<strong>/<em> y las pills con HTML fijo), no puede quedar NINGÚN
  // '<' crudo. Texto como "onerror=" o "javascript:" dentro de contenido ya
  // escapado es inofensivo — lo que no puede existir es una etiqueta viva.
  const withoutOwnTags = out
    .replace(/<\/?strong( class="[^"<>]*")?>/g, '')
    .replace(/<\/?em( class="[^"<>]*")?>/g, '');
  if (withoutOwnTags.includes('<') || withoutOwnTags.includes('>')) {
    console.error(`check-chatbot-escape: FAIL para ${JSON.stringify(input)} -> ${out}`);
    failed++;
  }
}

const pill = formatMessageText('Pide una [Solicitar Clase] ahora');
if (!pill.includes('href="/class-requests/create"')) {
  console.error('check-chatbot-escape: FAIL — la pill conocida [Solicitar Clase] ya no se genera.');
  failed++;
}

if (failed) process.exit(1);
console.log(`check-chatbot-escape: PASS (${cases.length} payloads escapados, pills conocidas intactas).`);
