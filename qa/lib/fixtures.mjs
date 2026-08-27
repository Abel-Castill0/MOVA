// F-25 — fixture compartido para cualquier suite "solo lectura" (hoy:
// playwright.production-readonly.config.js). Extiende el `page` base de
// Playwright para instalar installMutationFirewall() ANTES de que el spec
// reciba el fixture — así ningún spec puede "olvidarse" de instalarlo,
// exactamente el mismo principio que ya llevó a centralizar
// enforceSafeTarget() en vez de dejarlo a discreción de cada archivo.
import { test as base, expect } from '@playwright/test';
import { installMutationFirewall } from './enforce-read-only.mjs';

export const test = base.extend({
  page: async ({ page }, use) => {
    await installMutationFirewall(page);
    await use(page);
  },
});

export { expect };
