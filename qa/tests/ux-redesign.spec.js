// Rediseño de Solicitudes (profesor y padre), sidebar y Mis créditos: comprobación en navegador real a varios anchos.
// No es una prueba de reglas de negocio (eso lo cubre PHPUnit): fija que las pantallas cargan sin errores de JavaScript,
// sin scroll horizontal, con sus acciones accesibles, y deja capturas para revisión visual (QA_SHOT_DIR).
import { test, expect } from '@playwright/test';
import path from 'node:path';
import fs from 'node:fs';

const PARENT = { email: 'padre@mova.test', password: 'password123' };
const TEACHER = { email: 'profesor@mova.test', password: 'password123' };
const SHOT_DIR = process.env.QA_SHOT_DIR || path.resolve(import.meta.dirname, '../reports/ux');
const VIEWPORTS = [
  { name: 'mobile-320', width: 320, height: 640 },
  { name: 'mobile-390', width: 390, height: 844 },
  { name: 'tablet-768', width: 768, height: 1024 },
  { name: 'desktop-1440', width: 1440, height: 900 },
];

async function login(page, { email, password }) {
  await page.goto('/login');
  await page.locator('#email').fill(email);
  await page.locator('#password').fill(password);
  await page.getByRole('button', { name: 'Iniciar sesión' }).click();
  await page.waitForURL(/\/dashboard/, { timeout: 30000 });
  // El aviso de cookies de la primera visita ocupa la parte baja de la pantalla: se acepta como haría la persona.
  const cookies = page.getByRole('button', { name: 'Entendido' });
  if (await cookies.isVisible().catch(() => false)) await cookies.click();
}

async function shot(page, name) {
  fs.mkdirSync(SHOT_DIR, { recursive: true });
  await page.screenshot({ path: path.join(SHOT_DIR, `${name}.png`), fullPage: true });
}

async function expectNoHorizontalScroll(page, label) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow, `${label}: scroll horizontal de ${overflow}px`).toBeLessThanOrEqual(1);
}

function trackErrors(page) {
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error' && !/favicon|Failed to load resource/.test(m.text())) errors.push(m.text()); });

  return errors;
}

for (const vp of VIEWPORTS) {
  test.describe(`${vp.name}`, () => {
    test.use({ viewport: { width: vp.width, height: vp.height } });

    test(`profesor: solicitudes, créditos y navegación (${vp.name})`, async ({ page }) => {
      const errors = trackErrors(page);
      await login(page, TEACHER);

      await page.goto('/teacher/requests');
      await expect(page.getByRole('heading', { name: 'Solicitudes', level: 1 })).toBeVisible();
      await expect(page.getByRole('tab', { name: /Abiertas/ })).toHaveAttribute('aria-selected', 'true');
      await expect(page.getByRole('tab', { name: /Contraofertas/ })).toBeVisible();
      await expect(page.getByRole('tab', { name: /Rechazadas/ })).toBeVisible();
      await expectNoHorizontalScroll(page, 'solicitudes profesor');
      await shot(page, `teacher-requests-${vp.name}`);

      const cards = page.locator('[data-testid^="request-card-"]');
      if (await cards.count()) {
        await cards.first().click();
        // Escritorio: panel lateral. Móvil/tablet: hoja con foco atrapado que se cierra con Escape.
        const accept = page.getByTestId('accept-request').locator('visible=true');
        await expect(accept.first()).toBeVisible();
        await expect(accept.first()).toHaveAttribute('href', /\/teacher\/requests\/\d+\/accept/);
        await expect(page.getByTestId('propose-time').locator('visible=true').first()).toBeVisible();
        await expect(page.getByTestId('reject-request').locator('visible=true').first()).toBeVisible();
        await shot(page, `teacher-request-detail-${vp.name}`);

        // Proponer otro horario: abre un diálogo con nombre accesible y Escape lo cierra sin enviar nada.
        await page.getByTestId('propose-time').locator('visible=true').first().click();
        const dialog = page.getByRole('dialog').filter({ hasText: 'Proponer otro horario' });
        await expect(dialog).toBeVisible();
        await expect(dialog.getByLabel(/Fecha y hora que propones/)).toBeVisible();
        await shot(page, `teacher-counteroffer-${vp.name}`);
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(page.getByRole('dialog')).toHaveCount(0);
      } else {
        await expect(page.getByTestId('empty-state')).toBeVisible();
      }

      // Pestañas con teclado
      await page.getByRole('tab', { name: /Abiertas/ }).focus();
      await page.keyboard.press('ArrowRight');
      await expect(page.getByRole('tab', { name: /Contraofertas/ })).toHaveAttribute('aria-selected', 'true');

      await page.goto('/teacher/credits');
      await expect(page.getByRole('heading', { name: 'Mis créditos', level: 1 })).toBeVisible();
      await expect(page.getByTestId('credits-available')).toBeVisible();
      await expect(page.getByTestId('credits-reserved')).toBeVisible();
      await expect(page.locator('[data-testid^="package-"]')).toHaveCount(5);
      await expectNoHorizontalScroll(page, 'créditos');
      await shot(page, `teacher-credits-${vp.name}`);

      // Navegación: en móvil el menú es un drawer que se cierra con Escape y devuelve el foco.
      if (vp.width < 1024) {
        const opener = page.getByRole('button', { name: 'Abrir menú' });
        await opener.click();
        await expect(page.getByRole('button', { name: 'Cerrar menú' })).toBeFocused();
        await shot(page, `teacher-drawer-${vp.name}`);
        await page.keyboard.press('Escape');
        await expect(opener).toBeFocused();
      } else {
        const active = page.locator('#app-sidebar a[aria-current="page"]');
        await expect(active).toHaveCount(1);
        await expect(active).toContainText('Mis créditos');
      }

      expect(errors, errors.join('\n')).toEqual([]);
    });

    test(`padre: mis solicitudes y crear solicitud (${vp.name})`, async ({ page }) => {
      const errors = trackErrors(page);
      await login(page, PARENT);

      await page.goto('/class-requests');
      await expect(page.getByRole('heading', { name: 'Mis solicitudes', level: 1 })).toBeVisible();
      await expectNoHorizontalScroll(page, 'mis solicitudes');
      await shot(page, `parent-requests-${vp.name}`);

      const cards = page.locator('[data-testid^="request-"]');
      if (await cards.count()) {
        await expect(page.locator('[data-testid^="next-step-"]').first()).toBeVisible();
        await page.getByTestId('filter-closed').click();
        await expect(page.getByTestId('filter-closed')).toHaveAttribute('aria-pressed', 'true');
        await page.getByTestId('filter-all').click();
      }

      await page.goto('/class-requests/create');
      await expectNoHorizontalScroll(page, 'crear solicitud');
      await shot(page, `parent-create-${vp.name}`);

      await page.goto('/my-classes');
      await expectNoHorizontalScroll(page, 'mis clases');
      if (vp.width >= 1024) {
        await expect(page.locator('#app-sidebar a[aria-current="page"]')).toContainText('Clases');
      }

      expect(errors, errors.join('\n')).toEqual([]);
    });
  });
}
