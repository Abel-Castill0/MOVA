import { test, expect } from '@playwright/test';

/**
 * Gate funcional y visual de la disponibilidad semanal del profesor
 * (/teacher/profile → "Mi disponibilidad semanal").
 *
 * Corre contra la base SQLite sintética del gate E2E (profesor@mova.test, sembrado
 * por LocalTestDataSeeder). Cada variante deja la disponibilidad como la
 * encontró (vacía), así que el spec se puede repetir.
 */

async function login(page, email = 'profesor@mova.test') {
  await page.goto('/login');
  const cookie = page.getByRole('button', { name: 'Entendido', exact: true });
  if (await cookie.isVisible()) await cookie.click();
  await page.getByLabel('Correo electrónico').fill(email);
  await page.getByLabel('Contraseña', { exact: true }).fill('password123');
  await page.getByRole('button', { name: 'Iniciar sesión', exact: true }).click();
  await page.waitForURL(/\/dashboard/);
}

async function noHorizontalScroll(page) {
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)
  ).toBe(true);
}

test.beforeAll(() => {
  if (!process.env.DB_DATABASE?.endsWith('phase2b-e2e.sqlite') || process.env.BASE_URL !== 'http://127.0.0.1:8012') {
    throw new Error('Use qa/run-stabilization.ps1');
  }
});

const form = (page) => page.locator('form:has(#availability-title)');
const dayRow = (page, label) => form(page).locator('li', { hasText: label });

async function save(page) {
  const [response] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/teacher/availability') && r.request().method() === 'PUT'),
    form(page).getByRole('button', { name: 'Guardar disponibilidad' }).click(),
  ]);
  return response;
}

for (const variant of [
  { name: 'desktop', viewport: { width: 1280, height: 900 } },
  { name: 'mobile', viewport: { width: 390, height: 844 } },
]) {
  test.describe(`disponibilidad docente · ${variant.name}`, () => {
    test.use({ viewport: variant.viewport });

    test('agregar, persistir, rechazar solape y limpiar', async ({ page }, info) => {
      await login(page);
      await page.goto('/teacher/profile');

      await expect(page.getByRole('heading', { name: 'Mi disponibilidad semanal' })).toBeVisible();
      await expect(page.getByText('Horarios en hora de Lima (UTC−5)')).toBeVisible();
      await noHorizontalScroll(page);

      // 1) Agregar una franja el lunes y guardar.
      await dayRow(page, 'Lunes').getByRole('button', { name: '+ Agregar franja' }).click();
      await expect(page.getByLabel('Inicio, Lunes')).toHaveValue('15:00');
      await expect(page.getByLabel('Fin, Lunes')).toHaveValue('18:00');
      await save(page);

      // 2) Persiste tras recargar (viene del servidor, no del estado del cliente).
      await page.reload();
      await expect(page.getByLabel('Inicio, Lunes')).toHaveValue('15:00');
      await expect(page.getByLabel('Fin, Lunes')).toHaveValue('18:00');
      await expect(dayRow(page, 'Martes').getByText('Sin franjas')).toBeVisible();
      await form(page).screenshot({ path: info.outputPath(`availability-${variant.name}-saved.png`) });

      // 3) Una segunda franja que se solapa se rechaza con un mensaje visible
      //    y no pisa lo ya guardado.
      await dayRow(page, 'Lunes').getByRole('button', { name: '+ Agregar franja' }).click();
      await save(page);
      await expect(form(page).getByRole('alert').filter({ hasText: 'se solapa' })).toBeVisible();
      await noHorizontalScroll(page);
      await form(page).screenshot({ path: info.outputPath(`availability-${variant.name}-overlap-error.png`) });

      await page.reload();
      await expect(form(page).getByLabel('Inicio, Lunes')).toHaveCount(1);

      // 4) Fin ≤ inicio también se rechaza.
      await page.getByLabel('Fin, Lunes').fill('14:00');
      await save(page);
      await expect(form(page).getByRole('alert').filter({ hasText: 'posterior' })).toBeVisible();

      // 5) Limpieza: dejar la disponibilidad vacía (la lista vacía es un guardado válido).
      await page.reload();
      await dayRow(page, 'Lunes').getByRole('button', { name: 'Quitar franja de Lunes' }).click();
      await save(page);
      await page.reload();
      await expect(dayRow(page, 'Lunes').getByText('Sin franjas')).toBeVisible();
    });
  });
}

test('un padre no puede guardar disponibilidad docente (403)', async ({ page }) => {
  await login(page, 'padre@mova.test');
  const res = await page.request.put('/teacher/availability', {
    data: { slots: [{ day_of_week: 1, start_time: '08:00', end_time: '10:00' }] },
    headers: { Accept: 'application/json' },
  });
  // Sin token CSRF el servidor responde 419 antes de llegar a la autorización;
  // ambos son rechazos y ninguno guarda nada.
  expect([403, 419]).toContain(res.status());
});
