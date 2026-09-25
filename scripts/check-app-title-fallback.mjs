// Regresión barata para resources/js/app.js: un build de producción SIN
// VITE_APP_NAME definido (exactamente el caso real de la imagen Docker, que
// no pasa esa variable al paso de build de Vite) nunca debe dejar 'Laravel'
// como fallback compilado en el bundle — MOVA es el nombre fijo del
// producto. Corre DESPUÉS de `npm run build`, contra el manifest ya
// generado; no levanta ningún servidor ni añade dependencias.
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = join(import.meta.dirname, '..');
const manifest = JSON.parse(readFileSync(join(root, 'public/build/manifest.json'), 'utf-8'));
const entry = manifest['resources/js/app.js'];

if (!entry) {
  console.error('check-app-title-fallback: resources/js/app.js no aparece en el manifest — ¿corrió `npm run build`?');
  process.exit(1);
}

const bundle = readFileSync(join(root, 'public/build', entry.file), 'utf-8');

if (bundle.includes('Laravel')) {
  console.error("check-app-title-fallback: el bundle de app.js contiene el literal 'Laravel' — el fallback del título volvió a apuntar al scaffold, no a MOVA.");
  process.exit(1);
}

if (!bundle.includes('MOVA')) {
  console.error("check-app-title-fallback: el bundle de app.js no contiene el literal 'MOVA' — el fallback esperado no está presente.");
  process.exit(1);
}

console.log('check-app-title-fallback: PASS (bundle sin fallback "Laravel", con fallback "MOVA").');
