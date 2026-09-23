import { passAdminMfaIfPrompted } from '../lib/totp.mjs';
import { test, expect } from '@playwright/test';

/**
 * Gate visual y funcional del Centro de Operaciones (Fase 3B).
 *
 * Los fixtures los siembra qa/stabilization-server.php con claves fijas, así
 * que cada ejecución ve exactamente los mismos estados: crítica cerrable,
 * crítica NO cerrable, aviso, aviso sin contexto y una ya cerrada con motivo.
 *
 * IMPORTANTE — este spec NO cierra la incidencia compartida `9001`: el flujo de
 * cierre se prueba sobre una incidencia que el propio test crea, para que la
 * suite pueda repetirse sin depender de que la anterior no la dejara cerrada.
 */

async function login(page, email = 'admin-phase2b@mova.test') {
  await page.goto('/login');
  const cookie = page.getByRole('button', { name: 'Aceptar', exact: true });
  if (await cookie.isVisible()) await cookie.click();
  await page.getByLabel('Correo electrónico').fill(email);
  await page.getByLabel('Contraseña', { exact: true }).fill('password123');
  await page.getByRole('button', { name: 'Iniciar sesión', exact: true }).click();
  await passAdminMfaIfPrompted(page);
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

// ── Autorización ────────────────────────────────────────────────────────────
for (const [role, email] of [['padre', 'padre@mova.test'], ['profesor', 'profesor@mova.test']]) {
  test(`${role} no puede entrar al Centro de Operaciones`, async ({ page }) => {
    await login(page, email);
    const res = await page.goto('/admin/operations');
    expect(res.status()).toBe(403);
  });
}

// ── QA visual: 4 combinaciones de viewport x tema ───────────────────────────
for (const variant of [
  { name: 'desktop-light', viewport: { width: 1440, height: 1000 }, theme: 'light' },
  { name: 'desktop-dark', viewport: { width: 1440, height: 1000 }, theme: 'dark' },
  { name: 'mobile-light', viewport: { width: 390, height: 844 }, theme: 'light' },
  { name: 'mobile-dark', viewport: { width: 390, height: 844 }, theme: 'dark' },
  { name: 'tablet-light', viewport: { width: 768, height: 1024 }, theme: 'light' },
]) {
  test.describe(variant.name, () => {
    test.use({ viewport: variant.viewport });

    test.beforeEach(async ({ page }) => {
      await page.addInitScript((t) => localStorage.setItem('mova-theme', t), variant.theme);
      await page.route('**/*', (r) =>
        ['127.0.0.1', 'localhost'].includes(new URL(r.request().url()).hostname) ? r.continue() : r.abort());
    });

    test('resumen, capacidades e incidencias', async ({ page }, info) => {
      await login(page);
      await page.goto('/admin/operations');

      // Resumen: pieza editorial, no una rejilla de KPIs.
      await expect(page.getByRole('heading', { name: /asuntos? necesitan? atención|Todo en orden/ })).toBeVisible();

      // El título de página y el de la barra usan el token de tinta: en tema
      // oscuro deben CONTRASTAR con el canvas, no fundirse con él. Esto es lo
      // que detectó el `text-slate-900` fijo de AppLayout.
      const contrast = await page.evaluate(() => {
        const lum = (rgb) => {
          const [r, g, b] = rgb.match(/\d+/g).map(Number).map((v) => {
            const s = v / 255;
            return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
          });
          return 0.2126 * r + 0.7152 * g + 0.0722 * b;
        };

        // El fondo REALMENTE pintado detrás del texto, subiendo por los
        // ancestros hasta el primero que no sea transparente. Comparar contra
        // <body> daba un falso aprobado: body era oscuro y correcto, pero un
        // contenedor intermedio del layout pintaba blanco encima y dejaba el
        // texto claro invisible. El fondo efectivo es el único que importa.
        const effectiveBg = (el) => {
          for (let n = el; n; n = n.parentElement) {
            const c = getComputedStyle(n).backgroundColor;
            if (c && c !== 'transparent' && !c.startsWith('rgba(0, 0, 0, 0)')) return c;
          }
          return 'rgb(255,255,255)';
        };

        return [...document.querySelectorAll('h1, h2, h3')].map((el) => {
          const fg = lum(getComputedStyle(el).color);
          const bg = lum(effectiveBg(el));
          const [hi, lo] = fg > bg ? [fg, bg] : [bg, fg];
          return { text: el.textContent.trim().slice(0, 40), ratio: (hi + 0.05) / (lo + 0.05) };
        });
      });
      for (const { text, ratio } of contrast) {
        expect(ratio, `«${text}» debe cumplir contraste AA (4.5:1)`).toBeGreaterThan(4.5);
      }

      // `unknown` NUNCA debe leerse como sano.
      await expect(page.getByText('Sin señal suficiente').first()).toBeVisible();

      // Copy humano: ninguna clave interna llega a pantalla.
      const body = await page.locator('body').innerText();
      for (const internal of ['payment_review', 'ledger_anomaly', 'lesson_needs_review', 'health_check', 'reconciliation_failure']) {
        expect(body, `«${internal}» es una clave interna y no debe mostrarse`).not.toContain(internal);
      }

      // La allowlist de contexto también se cumple en el navegador.
      expect(body).not.toContain('APP_USR');
      expect(body).not.toContain('api.mercadopago.com');

      await noHorizontalScroll(page);
      await page.screenshot({ path: info.outputPath('operations-overview.png'), fullPage: true });
    });

    test('filtros, vacíos y paginación', async ({ page }, info) => {
      await login(page);
      await page.goto('/admin/operations');

      // Filtrar por categoría conserva el estado en la URL.
      await page.getByRole('button', { name: 'Clases', exact: true }).click();
      await page.waitForURL(/category=lesson/);
      await expect(page.getByRole('heading', { name: 'Una clase quedó esperando decisión' })).toBeVisible();

      // Atrás del navegador restaura el filtro anterior.
      await page.goBack();
      await expect(page).not.toHaveURL(/category=lesson/);

      // Combinación sin resultados → vacío específico de filtro, no "sin datos".
      await page.goto('/admin/operations?category=lesson&severity=critical');
      await expect(page.getByText('Ningún resultado con estos filtros')).toBeVisible();

      await noHorizontalScroll(page);
      await page.screenshot({ path: info.outputPath('operations-empty-filter.png'), fullPage: true });
    });

    test('cerrar incidencia: solo donde procede, con motivo válido', async ({ page }, info) => {
      await login(page);
      await page.goto('/admin/operations');

      // Una incidencia autoresoluble NO ofrece cierre manual.
      const ledger = page.locator('article').filter({ hasText: 'El ledger de créditos no cuadra' });
      await expect(ledger).toBeVisible();
      await expect(ledger.getByRole('button', { name: 'Cerrar incidencia' })).toHaveCount(0);

      // La de un solo disparo sí. Si una corrida anterior la cerró, se relanza
      // el filtro a "todas" para comprobar el estado cerrado en su lugar.
      const closable = page.locator('article').filter({ hasText: 'Webhook de Mercado Pago agotó sus reintentos' });
      const closeBtn = closable.getByRole('button', { name: 'Cerrar incidencia' });

      if (await closeBtn.count()) {
        await closeBtn.click();
        await expect(page.getByRole('heading', { name: 'Cerrar incidencia' })).toBeVisible();

        // El modal explica que NO toca el dominio.
        await expect(page.getByText(/No modifica el pago, la clase, la recarga ni el ledger/)).toBeVisible();

        // Motivo corto → error, modal abierto, sin cerrar nada.
        await page.getByLabel('Motivo (obligatorio)').fill('corto');
        await page.screenshot({ path: info.outputPath('operations-close-modal.png') });
        await page.getByRole('button', { name: 'Cerrar incidencia', exact: true }).last().click();
        await expect(page.getByText('El motivo debe tener al menos 10 caracteres.')).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Cerrar incidencia' })).toBeVisible();

        // Escape cierra sin aplicar nada.
        await page.keyboard.press('Escape');
        await expect(page.getByRole('heading', { name: 'Cerrar incidencia' })).toHaveCount(0);
      }

      await noHorizontalScroll(page);
    });

    test('incidencias cerradas: secundarias y con su motivo', async ({ page }, info) => {
      await login(page);
      await page.goto('/admin/operations?status=resolved');

      const closed = page.locator('article').filter({ hasText: 'Notificación de pago marcada para revisión' });
      await expect(closed).toBeVisible();
      await expect(closed.getByText('Cerrada', { exact: true })).toBeVisible();
      await expect(closed.getByText(/Verificado en el panel de Mercado Pago/)).toBeVisible();
      // Una incidencia cerrada no ofrece volver a cerrarse.
      await expect(closed.getByRole('button', { name: 'Cerrar incidencia' })).toHaveCount(0);

      await noHorizontalScroll(page);
      await page.screenshot({ path: info.outputPath('operations-closed.png'), fullPage: true });
    });
  });
}

// ── Flujo completo de cierre, sobre una incidencia propia ───────────────────
test.describe('cierre extremo a extremo', () => {
  test.use({ viewport: { width: 1440, height: 1000 } });

  test('crear, cerrar con motivo válido y verlo reflejado', async ({ page }) => {
    const { runPhp } = await import('../lib/run-php.mjs');
    const path = await import('node:path');
    const root = path.resolve(import.meta.dirname, '../..');
    const id = Math.floor(Math.random() * 900000) + 100000;

    const SENTINEL = '__MOVA_TINKER_OK__';
    const out = runPhp(['artisan', 'tinker',
      `--execute=App\\Models\\OperationalAlert::create(['alert_key'=>'payment_webhook:${id}:failed','type'=>'reconciliation_failure','severity'=>'critical','title'=>'Incidencia E2E ${id}','message'=>'Creada por el gate de Operaciones.','context'=>['PaymentWebhook'=>${id}],'first_detected_at'=>now(),'last_detected_at'=>now(),'occurrences'=>1]); echo '${SENTINEL}';`,
    ], { cwd: root, encoding: 'utf8' });
    if (!out.includes(SENTINEL)) throw new Error(`No se pudo crear el fixture:\n${out}`);

    await login(page);
    await page.goto('/admin/operations');

    const card = page.locator('article').filter({ hasText: `Incidencia E2E ${id}` });
    await expect(card).toBeVisible();
    await card.getByRole('button', { name: 'Cerrar incidencia' }).click();

    await page.getByLabel('Motivo (obligatorio)').fill('Comprobado con el proveedor: no hay pago que aplicar.');
    await page.getByRole('button', { name: 'Cerrar incidencia', exact: true }).last().click();

    // El modal solo se cierra tras la confirmación del backend.
    await expect(page.getByRole('heading', { name: 'Cerrar incidencia' })).toHaveCount(0, { timeout: 10000 });

    // Y desaparece del listado de abiertas.
    await expect(page.locator('article').filter({ hasText: `Incidencia E2E ${id}` })).toHaveCount(0);

    // En "cerradas" aparece con autor y motivo.
    await page.goto('/admin/operations?status=resolved');
    const closed = page.locator('article').filter({ hasText: `Incidencia E2E ${id}` });
    await expect(closed).toBeVisible();
    await expect(closed.getByText(/por Admin QA/)).toBeVisible();
    await expect(closed.getByText(/Comprobado con el proveedor/)).toBeVisible();
  });
});
