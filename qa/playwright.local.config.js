// @ts-check
import { defineConfig } from '@playwright/test';
import { enforceSafeTarget } from './lib/enforce-safe-target.mjs';

// Config mínima para specs que corren contra el entorno LOCAL (no producción)
// y no dependen del Chrome de sistema que usa playwright.config.js — usa el
// Chromium propio de Playwright, instalable sin privilegios de administrador.
//
// F-24A: baseURL centralizado aquí (antes cada spec decidía el suyo por su
// cuenta, p. ej. flujo-completo.spec.js hardcodeaba su propia constante
// local). Los specs ahora deben usar rutas relativas (`page.goto('/login')`)
// contra este baseURL, no URLs absolutas propias — así el target real vive
// en un solo lugar. enforceSafeTarget() se llama igual que en el config
// "de producción" por defensa en profundidad, aunque este archivo nunca
// debería recibir nada que no sea local.
const baseURL = process.env.BASE_URL || 'http://localhost:8000';
enforceSafeTarget(baseURL);

export default defineConfig({
  testDir: './tests',
  // 120s: el flujo hace ~8 llamadas a `php artisan tinker` (~0.7s c/u,
  // no es el cuello de botella) más varios logins/logouts completos contra
  // `php artisan serve`, que es de un solo hilo — bajo carga local (varias
  // corridas seguidas, builds, phpunit) el tiempo real varía bastante.
  timeout: 120_000,
  retries: 0,
  workers: 1,
  reporter: [['list']],
  use: {
    baseURL,
    // Headless por defecto (rápido, sin ventana) — pon HEADFUL=1 para ver el
    // navegador real durante una corrida local puntual, ej:
    // HEADFUL=1 npx playwright test --config=playwright.local.config.js
    headless: process.env.HEADFUL !== '1',
    viewport: { width: 1280, height: 720 },
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    locale: 'es-PE',
  },
});
