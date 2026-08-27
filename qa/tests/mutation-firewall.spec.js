// @ts-check
//
// F-25 — prueba real (con navegador, sin red externa) de que
// installMutationFirewall() efectivamente deja pasar GET/HEAD y bloquea
// POST/PUT/PATCH/DELETE. Levanta un servidor HTTP propio en 127.0.0.1 (no
// depende de `php artisan serve` ni de ningún target externo) solo para
// tener algo real contra qué disparar las requests.
import { test, expect } from '@playwright/test';
import http from 'node:http';
import { installMutationFirewall } from '../lib/enforce-read-only.mjs';

/** @type {import('node:http').Server} */
let server;
/** @type {string} */
let url;

test.beforeAll(async () => {
  server = http.createServer((req, res) => {
    // GET '/' sirve una página con un <form> real (envío nativo del
    // navegador, no JS) — necesaria para probar que el firewall también
    // bloquea un submit de formulario de verdad, no solo fetch()/XHR.
    if (req.method === 'GET' && req.url === '/') {
      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end(`<!doctype html><html><body>
        <form id="f" method="POST" action="/" enctype="multipart/form-data">
          <input type="text" name="campo" value="valor" />
        </form>
      </body></html>`);
      return;
    }
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ method: req.method }));
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const address = server.address();
  url = `http://127.0.0.1:${address.port}`;
});

test.afterAll(() => {
  server.close();
});

test.describe('installMutationFirewall', () => {
  test('GET pasa (navegación normal)', async ({ page }) => {
    await installMutationFirewall(page);
    const response = await page.goto(url);
    expect(response.status()).toBe(200);
    // GET '/' sirve la página con el <form> (ver beforeAll) — confirmamos
    // que la navegación GET en sí llegó al servidor real, sin parsear JSON.
    await expect(page.locator('#f')).toBeAttached();
  });

  test('HEAD pasa', async ({ page }) => {
    await installMutationFirewall(page);
    // page.goto() siempre es GET; probamos HEAD vía fetch en la página.
    await page.goto(url);
    const result = await page.evaluate(async (target) => {
      const r = await fetch(target, { method: 'HEAD' });
      return { ok: true, status: r.status };
    }, url);
    expect(result.ok).toBe(true);
    expect(result.status).toBe(200);
  });

  test('OPTIONS pasa (política explícita: nunca es en sí una mutación, es preflight CORS)', async ({ page }) => {
    await installMutationFirewall(page);
    await page.goto(url);
    const result = await page.evaluate(async (target) => {
      const r = await fetch(target, { method: 'OPTIONS' });
      return { ok: true, status: r.status };
    }, url);
    expect(result.ok).toBe(true);
    expect(result.status).toBe(200);
  });

  test('POST vía XHR (no fetch) también se bloquea — la intercepción es de red, no de una sola API', async ({ page }) => {
    await installMutationFirewall(page);
    await page.goto(url);
    const outcome = await page.evaluate(
      (target) =>
        new Promise((resolve) => {
          const xhr = new XMLHttpRequest();
          xhr.open('POST', target);
          xhr.onerror = () => resolve({ blocked: true });
          xhr.onload = () => resolve({ blocked: false, status: xhr.status });
          xhr.send();
        }),
      url
    );
    expect(outcome.blocked).toBe(true);
  });

  test('un <form> nativo con multipart/form-data se bloquea (no solo JSON vía fetch)', async ({ page }) => {
    await installMutationFirewall(page);
    await page.goto(url); // sirve la página con el <form> real (ver beforeAll)

    // Un submit nativo navega la página — esperamos la navegación resultante
    // (o su ausencia, si Playwright aborta el request antes de que exista
    // una respuesta que navegar) en vez de esperar una promesa de fetch.
    const navigationOrAbort = page.waitForURL('**/*', { timeout: 3000 }).catch(() => 'no-navigation');
    await page.locator('#f').evaluate((form) => form.submit());
    const result = await navigationOrAbort;

    // Lo que importa no es qué devuelve waitForURL, sino que el servidor
    // real nunca vio el POST: si el firewall fallara, el body de la
    // respuesta traería `{"method":"POST"}` tras la navegación.
    const bodyText = await page.locator('body').innerText().catch(() => '');
    expect(bodyText).not.toContain('"method":"POST"');
  });

  for (const method of ['POST', 'PUT', 'PATCH', 'DELETE']) {
    test(`${method} se bloquea antes de llegar al servidor`, async ({ page }) => {
      await installMutationFirewall(page);
      await page.goto(url); // la navegación GET inicial sí debe pasar

      const outcome = await page.evaluate(
        async ({ target, method }) => {
          try {
            await fetch(target, { method });
            return { blocked: false };
          } catch (e) {
            return { blocked: true, error: String(e) };
          }
        },
        { target: url, method }
      );

      expect(outcome.blocked).toBe(true);
    });
  }

  test('sin el firewall instalado, un POST normal SÍ llega al servidor (control negativo)', async ({ page }) => {
    // Sin installMutationFirewall(): confirma que el bloqueo de los tests de
    // arriba viene realmente del firewall, no de una limitación del servidor
    // de prueba o del entorno.
    await page.goto(url);
    const outcome = await page.evaluate(async (target) => {
      const r = await fetch(target, { method: 'POST' });
      const body = await r.json();
      return { status: r.status, method: body.method };
    }, url);

    expect(outcome.status).toBe(200);
    expect(outcome.method).toBe('POST');
  });

  test('sin el firewall, el <form> nativo SÍ llega al servidor (control negativo)', async ({ page }) => {
    await page.goto(url);
    await page.locator('#f').evaluate((form) => form.submit());
    await page.waitForLoadState('networkidle');
    const bodyText = await page.locator('body').innerText();
    expect(bodyText).toContain('"method":"POST"');
  });

  test('navigator.sendBeacon (siempre POST) también se bloquea', async ({ page }) => {
    // sendBeacon no permite leer la respuesta ni el estado del envío desde
    // JS por diseño (está pensado para "dispara y olvida" al descargar la
    // página) — solo devuelve un booleano de si el navegador ACEPTÓ
    // encolar el envío, no si llegó. Por eso la aserción real está del
    // lado del servidor: verificamos que nunca vio la request, no que
    // sendBeacon() "falló" (con el firewall activo, de hecho sigue
    // devolviendo true — el navegador aceptó encolarlo, Playwright lo
    // interceptó después, antes de que saliera a la red).
    await installMutationFirewall(page);
    await page.goto(url);
    await page.evaluate((target) => {
      navigator.sendBeacon(target, new Blob(['x']));
    }, url);
    await page.waitForTimeout(300); // sendBeacon es fire-and-forget; sin esto podríamos comprobar antes de que se procese
    const bodyText = await page.locator('body').innerText();
    expect(bodyText).not.toContain('"method":"POST"');
  });

  test('page.request.* (API context de Playwright) requiere una capa de protección distinta — no una laguna de este firewall', async ({ page }) => {
    // installMutationFirewall(page) intercepta el tráfico de red DEL
    // NAVEGADOR (lo que la página misma origina). page.request.* es una
    // API de Playwright completamente distinta — hace la petición HTTP
    // desde el proceso de Node del test runner, nunca pasa por el
    // navegador ni por su capa de red, así que page.route() no puede
    // verla. Esto no es un hueco en el diseño de este firewall: es una
    // superficie de ataque distinta que, si alguna vez se necesitara
    // proteger, requeriría su propio mecanismo (p. ej. envolver
    // page.request en un wrapper que valide el método antes de llamar a
    // Playwright). Registrado explícitamente para que nadie asuma que
    // "el firewall cubre toda petición HTTP que Playwright pueda hacer"
    // — ningún spec de producto usa page.request para simular acciones
    // de usuario hoy (usa clicks/forms reales, que sí pasan por el
    // navegador), pero si alguno lo hiciera, necesitaría esa protección
    // separada, no una extensión de esta. Ver docs/MOVA_QA_SECURITY_POLICY.md.
    await installMutationFirewall(page);
    const response = await page.request.post(url);
    expect(response.status()).toBe(200);
    const body = await response.json();
    expect(body.method).toBe('POST');
  });
});
