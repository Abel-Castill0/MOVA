// @ts-check
import { defineConfig, devices } from '@playwright/test';
import dotenv from 'dotenv';
import path from 'path';
import { fileURLToPath } from 'url';
import { enforceSafeTarget } from './lib/enforce-safe-target.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
dotenv.config({ path: path.join(__dirname, '.env.qa') });

// F-24A: este es el config que corre `npm test` (el comando que un
// desarrollador escribiría sin pensarlo). Antes, su BASE_URL por defecto
// era la Railway de producción real — bastaba con no exportar BASE_URL (o
// con que qa/.env.qa la definiera, como ya hacía) para que "npm test"
// apuntara a producción con cuentas reales. Ahora el default es SIEMPRE
// local, y enforceSafeTarget() falla el arranque de Playwright por completo
// si el host resuelto no está en la allowlist — no depende de que cada spec
// traiga su propio guard.
const baseURL = process.env.BASE_URL || 'http://localhost:8000';
enforceSafeTarget(baseURL);

export default defineConfig({
  testDir: './tests',
  timeout: 60_000,
  retries: 0,
  workers: 1,
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'reports' }]],
  use: {
    baseURL,
    headless: false,
    viewport: { width: 1280, height: 720 },
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    trace: 'retain-on-failure',
    locale: 'es-PE',
  },
  projects: [
    {
      name: 'chrome',
      use: {
        ...devices['Desktop Chrome'],
        channel: 'chrome',
      },
    },
  ],
});
