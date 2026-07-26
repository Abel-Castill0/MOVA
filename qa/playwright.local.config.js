// @ts-check
import { defineConfig } from '@playwright/test';

// Config mínima para specs que corren contra el entorno LOCAL (no producción)
// y no dependen del Chrome de sistema que usa playwright.config.js — usa el
// Chromium propio de Playwright, instalable sin privilegios de administrador.
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
    headless: true,
    viewport: { width: 1280, height: 720 },
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    locale: 'es-PE',
  },
});
