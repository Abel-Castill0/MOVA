// @ts-check
//
// Regresión dirigida: el submit REAL de Yape en Checkout.vue debe enviar a
// MOVA `{ payment_method: 'yape', token }` — nunca `{ token }` a solas. Este
// es exactamente el contrato que rompió el commit que introdujo el Card
// Payment Brick (hizo `payment_method` obligatorio en el backend sin
// actualizar la rama Yape existente): un Feature test PHP que llama al
// controller con el payload ya correcto nunca lo habría detectado, porque el
// bug vivía en lo que el FRONTEND construye antes de llamar a axios, no en
// el backend. Este spec ejercita ese submit real, en el navegador, contra el
// componente Vue compilado — no una reimplementación aparte del payload.
//
// Solo corre local (mismo entorno que flujo-completo.spec.js — ver ese
// archivo para los requisitos y por qué playwright.local.config.js es la
// config correcta). NUNCA llama a la API real de Mercado Pago:
//   - window.MercadoPago se reemplaza por un stub ANTES de que cargue
//     cualquier script de la página (page.addInitScript), así que
//     loadMercadoPagoSdk() lo encuentra ya presente y nunca inyecta el
//     <script src="https://sdk.mercadopago.com/js/v2">.
//   - Por defensa en profundidad, cualquier request que igual saliera hacia
//     sdk.mercadopago.com o api.mercadopago.com se aborta a nivel de red.
//   - El POST real de MOVA a `.../checkout/{id}/pay` se intercepta
//     (page.route) ANTES de llegar a Laravel: se lee su body para la
//     aserción y se responde con un fixture sintético — así el backend
//     jamás llega a intentar `createPaymentAttempt()` contra Mercado Pago,
//     sin importar qué proveedor esté configurado.
//
// No depende de PAYMENTS_ENABLED/PAYMENT_PROVIDER/credenciales MP reales en
// el entorno — ni locales ni en CI, que corre `docker compose run --rm
// e2e_qa` a secas, sin flags extra (ver .github/workflows/ci.yml, job `e2e`)
// contra un `.env` efímero sin ningún secreto de Mercado Pago. Poner
// PAYMENTS_ENABLED=true/PAYMENT_PROVIDER=mercadopago a nivel de todo el
// proceso arriesgaría además cambiar el comportamiento de los otros ~60
// tests que ya corren en la misma suite (mercadoPagoCheckoutEnabled()
// pasaría a 'true' en cualquier página que lo consulte). En vez de eso, la
// respuesta HTML inicial de ESTA request (`GET .../checkout/{id}`) se
// intercepta y se le reescribe SOLO el atributo `data-page` que Inertia
// embebe (ver `@inertia` en resources/views/app.blade.php) — forzando
// `checkoutEnabled`/`mercadoPagoPublicKey` únicamente para este test, sin
// tocar config ni backend. Todo lo demás de la respuesta (HTML, assets,
// Vue compilado) es el real.
//
// Corrida sin Docker (requiere PHP 8.3 en PATH — ver flujo-completo.spec.js
// para MySQL local; este spec usa la misma DB/seeder):
//   cd qa && npx playwright test --config=playwright.local.config.js tests/mercadopago-yape-checkout.spec.js
//
// Corrida en el container e2e_qa (PHP 8.3 garantizado, SQLite efímera —
// mismo comando que usa CI, sin flags extra):
//   docker compose -f docker-compose.qa.yml run --rm e2e_qa tests/mercadopago-yape-checkout.spec.js

import { test, expect } from '@playwright/test';
import path from 'node:path';
import { runPhp } from '../lib/run-php.mjs';

const TEACHER_EMAIL = 'profesor@mova.test';
const TEACHER_PASSWORD = 'password123';
const PROJECT_ROOT = path.resolve(import.meta.dirname, '../..');
const MARKER = `E2E-YAPE-${Date.now()}`;

// Mismo centinela que flujo-completo.spec.js: `tinker --execute` sale con
// exit code 0 aunque el código lance, así que execFileSync nunca detecta el
// fallo por sí solo — ver el docblock de TINKER_SENTINEL en ese archivo para
// el porqué completo, verificado ahí.
const TINKER_SENTINEL = '__MOVA_TINKER_OK__';
function tinker(code) {
  const out = runPhp(
    ['artisan', 'tinker', `--execute=${code} echo '${TINKER_SENTINEL}';`],
    { cwd: PROJECT_ROOT, encoding: 'utf-8' },
  );

  if (!out.includes(TINKER_SENTINEL)) {
    throw new Error(
      `Tinker no completó el fixture (probable excepción). Salida:
${out.trim()}

Código:
${code}`,
    );
  }

  return out.slice(0, out.indexOf(TINKER_SENTINEL)).trim();
}

async function login(page, email, password) {
  await page.goto('/login');
  await page.locator('#email').fill(email);
  await page.locator('#password').fill(password);
  await page.getByRole('button', { name: 'Iniciar sesión' }).click();
  await page.waitForURL(/\/dashboard/, { timeout: 30000 });
}

test.describe.serial('Checkout Yape — contrato real payment_method (local)', () => {
  test('el submit real de Yape envía payment_method=yape + token, nunca phone/otp', async ({ page }) => {
    test.setTimeout(60_000);

    // Fixture directo por tinker (mismo patrón que flujo-completo.spec.js):
    // crea la RechargeRequest 'pending'/'mercadopago' sin pasar por
    // store(), que además de crear la fila hace su propia redirección Inertia
    // — no aporta nada a lo que este test verifica (el contrato de pay()) y
    // solo añadiría un salto de página innecesario.
    const rechargeId = tinker(
      `$tp = App\\Models\\TeacherProfile::whereHas('user', fn($q) => $q->where('email','${TEACHER_EMAIL}'))->firstOrFail();` +
        `$r = App\\Models\\RechargeRequest::create([` +
        `'teacher_profile_id' => $tp->id,` +
        `'package_code' => 'inicio',` +
        `'package_name' => 'Inicio',` +
        `'credits' => 5,` +
        `'amount_pen' => '10.00',` +
        `'payment_method' => 'mercadopago',` +
        `'operation_number' => null,` +
        `'operation_number_normalized' => null,` +
        `'status' => 'pending',` +
        `]);` +
        `echo $r->id;`,
    );
    expect(rechargeId).toMatch(/^\d+$/);

    // Stub de la tokenización — DEBE instalarse antes de que cualquier
    // script de la página corra (addInitScript, no page.evaluate tras el
    // goto): loadMercadoPagoSdk() solo evita inyectar el <script> real
    // cuando `window.MercadoPago` YA existe en el momento en que se llama.
    // Registra los argumentos recibidos en window.__yapeCreateArgs para que
    // el test pueda comprobar después que el teléfono/OTP sí llegaron aquí
    // (al "SDK"), y nunca al backend.
    await page.addInitScript(() => {
      window.__yapeCreateArgs = null;
      window.MercadoPago = function MercadoPagoStub() {
        this.yape = (args) => {
          window.__yapeCreateArgs = args;
          return {
            create: async () => ({ id: `SYNTHETIC-YAPE-TOKEN-${Math.random().toString(36).slice(2)}` }),
          };
        };
      };
    });

    // Defensa en profundidad: ninguna llamada real a Mercado Pago debe salir
    // de este test, sin importar qué camino la origine.
    await page.route('**://sdk.mercadopago.com/**', (route) => route.abort());
    await page.route('**://api.mercadopago.com/**', (route) => route.abort());
    await page.route('**://*.mercadolibre.com/**', (route) => route.abort());

    // Fuerza checkoutEnabled/mercadoPagoPublicKey SOLO para esta respuesta,
    // sin tocar PAYMENTS_ENABLED/PAYMENT_PROVIDER a nivel de proceso (ver
    // docblock del archivo — esto es lo que evita depender de config externa
    // y de riesgo de colisión con el resto de la suite). Se deja pasar la
    // respuesta real (HTML/Vue compilado reales) y solo se reescribe el JSON
    // que `@inertia` embebe en `data-page` (htmlspecialchars con
    // ENT_QUOTES — ver resources/views/app.blade.php).
    const decodeHtmlAttr = (s) => s
      .replace(/&quot;/g, '"').replace(/&#0?39;/g, "'")
      .replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
    const encodeHtmlAttr = (s) => s
      .replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#039;')
      .replace(/</g, '&lt;').replace(/>/g, '&gt;');

    await page.route(`**/teacher/credits/checkout/${rechargeId}`, async (route) => {
      if (route.request().method() !== 'GET') {
        await route.continue();
        return;
      }

      const response = await route.fetch();
      const body = await response.text();
      const match = body.match(/data-page="([^"]*)"/);
      if (!match) {
        // La respuesta no trae el shell Inertia esperado (p. ej. una
        // redirección) — se deja pasar tal cual para no enmascarar un fallo
        // real distinto del que este test verifica.
        await route.fulfill({ response, body });
        return;
      }

      const inertiaPage = JSON.parse(decodeHtmlAttr(match[1]));
      inertiaPage.props.checkoutEnabled = true;
      inertiaPage.props.mercadoPagoPublicKey = 'TEST-e2e-stub-public-key';
      const patched = body.replace(match[0], `data-page="${encodeHtmlAttr(JSON.stringify(inertiaPage))}"`);
      await route.fulfill({ response, body: patched });
    });

    /** @type {Record<string, unknown> | null} */
    let capturedPayBody = null;

    await login(page, TEACHER_EMAIL, TEACHER_PASSWORD);
    await page.goto(`/teacher/credits/checkout/${rechargeId}`);
    await expect(page.getByText('El pago automático no está disponible')).toHaveCount(0);

    // Intercepta el POST real de MOVA — se lee el body EXACTO que Checkout.vue
    // construyó (esto es lo que habría fallado 422 con el bug: solo `{token}`,
    // sin `payment_method`) y se responde con un fixture sintético, sin dejar
    // que la request siga hacia Laravel/MercadoPagoPaymentProvider.
    await page.route(`**/teacher/credits/checkout/${rechargeId}/pay`, async (route) => {
      capturedPayBody = route.request().postDataJSON();
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'pending',
          message: 'Estamos verificando tu pago. No vuelvas a pagar mientras termina la verificación.',
          credits_credited: false,
          action_required: null,
        }),
      });
    });

    await page.getByRole('button', { name: 'Yape', exact: true }).click();
    await page.locator('input[type="tel"]').fill('111111111');
    await page.locator('input[placeholder="000000"]').fill('123456');
    await page.getByRole('button', { name: 'Pagar', exact: true }).click();

    await expect.poll(() => capturedPayBody, {
      message: 'MOVA nunca recibió el POST de pago (interceptor no disparó)',
      timeout: 10_000,
    }).not.toBeNull();

    // El contrato exacto que rompió el bug: payment_method explícito +
    // token, nada más.
    expect(capturedPayBody).toHaveProperty('payment_method', 'yape');
    expect(typeof capturedPayBody.token).toBe('string');
    expect(capturedPayBody.token.length).toBeGreaterThan(0);
    expect(capturedPayBody).not.toHaveProperty('phoneNumber');
    expect(capturedPayBody).not.toHaveProperty('otp');
    expect(Object.keys(capturedPayBody).sort()).toEqual(['payment_method', 'token']);

    // El teléfono/OTP reales sí llegaron al SDK (stub) — confirma que MOVA
    // los recibe del formulario para tokenizar, pero nunca los reenvía él
    // mismo (la aserción de arriba ya lo prueba sobre el body real).
    const yapeArgs = await page.evaluate(() => window.__yapeCreateArgs);
    expect(yapeArgs).toEqual({ otp: '123456', phoneNumber: '111111111' });
  });
});
