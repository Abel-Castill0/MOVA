import { test, expect } from '@playwright/test';
import { passAdminMfaIfPrompted } from '../lib/totp.mjs';

// Solo la SQLite efímera creada por el runner de estabilización.
// Jamás ejecutar contra movaeduca.me ni contra bases de clientes.
test.beforeAll(() => {
  if (!process.env.DB_DATABASE?.endsWith('phase2b-e2e.sqlite') ||
      process.env.BASE_URL !== 'http://127.0.0.1:8012') {
    throw new Error('Ejecuta qa/run-stabilization.ps1 con SQLite QA aislada.');
  }
});

async function login(page, email) {
  await page.goto('/login');
  const cookie = page.getByRole('button', { name: 'Entendido', exact: true });
  if (await cookie.isVisible()) await cookie.click();
  await page.getByLabel('Correo electrónico').fill(email);
  await page.getByLabel('Contraseña', { exact: true }).fill('password123');
  await page.getByRole('button', { name: 'Iniciar sesión', exact: true }).click();
  await passAdminMfaIfPrompted(page);
}

async function guardMutations(page, pathPattern) {
  const attempts = [];
  await page.route(pathPattern, async route => {
    if (route.request().method() !== 'GET') {
      attempts.push({ method: route.request().method(), path: new URL(route.request().url()).pathname });
      await route.abort();
    } else {
      await route.continue();
    }
  });
  return attempts;
}

async function verifyNoBodyOverflow(page) {
  const layout = await page.evaluate(() => ({
    documentWidth: document.documentElement.scrollWidth,
    screenWidth: window.innerWidth,
  }));
  expect(layout.documentWidth, 'el documento no debe desbordar horizontalmente el móvil')
    .toBeLessThanOrEqual(layout.screenWidth + 1);
}

test('admin: abrir y cancelar Suspender no dispara POST', async ({ page }) => {
  await login(page, 'admin-phase2b@mova.test');
  await page.goto('/admin/users');
  const attempts = await guardMutations(page, /\/admin\/users\/\d+\/suspend(?:\?.*)?$/);
  const openButton = page.getByRole('button', { name: 'Suspender', exact: true }).first();
  test.skip(await openButton.count() === 0, 'sin cuenta sintética suspendible');
  await openButton.click();
  const dialog = page.getByRole('dialog', { name: 'Suspender cuenta' });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByLabel('Motivo de suspensión (opcional)')).toBeVisible();
  await expect.poll(() => attempts.length).toBe(0);
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  expect(attempts).toEqual([]);
  await expect(openButton).toBeFocused();
});

test('admin: rechazar profesor se puede cancelar por Escape', async ({ page }) => {
  await login(page, 'admin-phase2b@mova.test');
  await page.goto('/admin/pending-teachers');
  const attempts = await guardMutations(page, /\/admin\/teachers\/\d+\/reject(?:\?.*)?$/);
  const openButton = page.getByRole('button', { name: 'Rechazar', exact: true }).first();
  test.skip(await openButton.count() === 0, 'sin profesor sintético pendiente');
  await openButton.click();
  const dialog = page.getByRole('dialog', { name: 'Rechazar perfil docente' });
  await expect(dialog).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  expect(attempts).toEqual([]);
});

test('admin: moderación de reseñas no muta con solo abrir diálogo', async ({ page }) => {
  await login(page, 'admin-phase2b@mova.test');
  await page.goto('/admin/reviews');
  const attempts = await guardMutations(page, /\/admin\/reviews\/\d+\/hide(?:\?.*)?$/);
  const openButton = page.getByRole('button', { name: 'Ocultar', exact: true }).first();
  test.skip(await openButton.count() === 0, 'sin reseña sintética moderable');
  await openButton.click();
  const dialog = page.getByRole('dialog', { name: 'Ocultar reseña' });
  await expect(dialog).toBeVisible();
  await dialog.getByRole('button', { name: 'Cancelar' }).click();
  await expect(dialog).toBeHidden();
  expect(attempts).toEqual([]);
});

test('padre: eliminar ficha exige confirmación y Escape no borra', async ({ page }) => {
  await login(page, 'padre@mova.test');
  await page.goto('/students');
  const attempts = await guardMutations(page, /\/students\/\d+(?:\?.*)?$/);
  const openButton = page.getByRole('button', { name: 'Eliminar', exact: true }).first();
  test.skip(await openButton.count() === 0, 'sin estudiante sintético');
  await openButton.click();
  const dialog = page.getByRole('dialog', { name: /Eliminar a/ });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByText(/no se puede deshacer/i)).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  expect(attempts).toEqual([]);
});

for (const variant of [
  { name: 'iPhone SE', width: 375, height: 667 },
  { name: 'móvil 390', width: 390, height: 844 },
  { name: 'tablet 768', width: 768, height: 1024 },
  { name: 'desktop 1440', width: 1440, height: 900 },
]) {
  test.describe('responsive: ' + variant.name, () => {
    test.use({ viewport: { width: variant.width, height: variant.height } });
    test('solicitudes del padre sin scroll lateral de documento', async ({ page }, info) => {
      await login(page, 'padre@mova.test');
      await page.goto('/class-requests');
      await expect(page.getByRole('heading', { name: /Solicitudes de clase/i })).toBeVisible();
      await verifyNoBodyOverflow(page);
      await page.screenshot({ path: info.outputPath('parent-requests.png'), fullPage: true });
    });
    test('profesor: solicitudes y créditos sin scroll lateral de documento', async ({ page }, info) => {
      await login(page, 'profesor@mova.test');
      await page.goto('/teacher/requests');
      await expect(page.getByRole('group', { name: 'Estado de solicitudes' })).toBeVisible();
      await verifyNoBodyOverflow(page);
      await page.goto('/teacher/credits');
      await expect(page.getByRole('group', { name: 'Historial de créditos' })).toBeVisible();
      await verifyNoBodyOverflow(page);
      await page.screenshot({ path: info.outputPath('teacher-credits.png'), fullPage: true });
    });
  });
}
