import { test, expect } from '@playwright/test';
import path from 'node:path';
import { runPhp } from '../lib/run-php.mjs';

const root = path.resolve(import.meta.dirname, '../..');

/**
 * Entrada a la sala de clase (JaaS) — lógica PROPIA de MOVA, con el script
 * external_api.js de 8x8.vc SIMULADO.
 *
 * Qué prueba: permisos y ventana de entrada del servidor (403 / 200), el JWT que
 * emite (sala, expiración, moderador solo el profesor, sin grabación), lo que
 * hace el cliente con él (nombre de sala con prefijo del tenant, jwt, sin deep
 * links en móvil), salir y volver a entrar, el fallo al cargar el script de
 * 8x8.vc y los avisos de cámara/micrófono.
 *
 * Qué NO prueba (y no debe presentarse como probado): que JaaS acepte el JWT, ni
 * audio/video reales entre dos personas. Eso exige credenciales reales de JaaS:
 * ver jaas-real-meeting.spec.js y `scripts/sandbox-smoke.sh jaas`.
 */

const SENTINEL = '__MOVA_TINKER_OK__';
const php = (code) => {
  const out = runPhp(['artisan', 'tinker', `--execute=${code} echo '${SENTINEL}';`], { cwd: root, encoding: 'utf8' });
  if (!out.includes(SENTINEL)) throw new Error(`Tinker no completó el fixture:\n${out.trim()}`);
  return out.slice(0, out.indexOf(SENTINEL)).trim();
};

// external_api.js SIMULADO: registra cómo lo usa MOVA y permite disparar eventos.
const STUB = `
window.__jitsiCalls = [];
window.__jitsiDisposed = 0;
window.JitsiMeetExternalAPI = function (domain, opts) {
  const listeners = {};
  window.__jitsiCalls.push({
    domain,
    roomName: opts.roomName,
    jwt: opts.jwt,
    displayName: opts.userInfo && opts.userInfo.displayName,
    disableDeepLinking: !!(opts.configOverwrite && opts.configOverwrite.disableDeepLinking),
    prejoin: opts.configOverwrite && opts.configOverwrite.prejoinPageEnabled,
  });
  const el = document.createElement('div');
  el.id = 'jitsi-stub';
  el.textContent = 'SALA-SIMULADA';
  opts.parentNode.appendChild(el);
  this.addEventListener = (name, fn) => { (listeners[name] = listeners[name] || []).push(fn); };
  this.dispose = () => { el.remove(); window.__jitsiDisposed += 1; };
  window.__jitsiEmit = (name, payload) => (listeners[name] || []).forEach((fn) => fn(payload));
};
`;

let lessonId;
let originalStart;

const setStart = (minutesFromNow) =>
  php(`App\\Models\\Lesson::whereKey(${lessonId})->update(['start_time' => now()->addMinutes(${minutesFromNow})]);`);

test.beforeAll(() => {
  if (!process.env.DB_DATABASE?.endsWith('phase2b-e2e.sqlite') || process.env.BASE_URL !== 'http://127.0.0.1:8012') {
    throw new Error('Use qa/run-stabilization.ps1');
  }
  const row = php(
    "$l = App\\Models\\Lesson::where('status', 'scheduled')"
    + "->whereHas('teacherProfile.user', fn($q) => $q->where('email', 'profesor@mova.test'))"
    + "->orderBy('start_time')->firstOrFail();"
    + "echo $l->id.'|'.$l->start_time->toDateTimeString();"
  );
  [lessonId, originalStart] = row.split('|');
});

test.afterAll(() => {
  if (lessonId && originalStart) {
    php(`App\\Models\\Lesson::whereKey(${lessonId})->update(['start_time' => '${originalStart}']);`);
  }
});

async function login(page, email) {
  await page.goto('/login');
  const cookie = page.getByRole('button', { name: 'Entendido', exact: true });
  if (await cookie.isVisible()) await cookie.click();
  await page.getByLabel('Correo electrónico').fill(email);
  await page.getByLabel('Contraseña', { exact: true }).fill('password123');
  await page.getByRole('button', { name: 'Iniciar sesión', exact: true }).click();
  await page.waitForURL(/\/dashboard/);
}

const decodeJwt = (jwt) => JSON.parse(Buffer.from(jwt.split('.')[1], 'base64url').toString('utf8'));

const ROLES = [
  { role: 'profesor', email: 'profesor@mova.test', list: '/teacher/classes', moderator: true },
  { role: 'padre', email: 'padre@mova.test', list: '/my-classes', moderator: false },
];

async function stubJaas(page) {
  await page.route('https://8x8.vc/**', (route) =>
    route.fulfill({ contentType: 'application/javascript', body: STUB }));
}

async function openRoom(page, list) {
  await page.goto(list);
  // La vista por defecto es el calendario; el botón de entrada vive en la lista.
  const listTab = page.getByRole('tab', { name: 'Lista', exact: true });
  if (await listTab.isVisible()) await listTab.click();
  const join = page.getByRole('button', { name: /Ingresar a la Sala Virtual/ }).first();
  await expect(join).toBeVisible();
  const [response] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/join') && r.request().method() === 'GET'),
    join.click(),
  ]);
  return response;
}

for (const variant of [
  { name: 'escritorio', viewport: { width: 1280, height: 800 } },
  { name: 'móvil', viewport: { width: 390, height: 844 } },
]) {
  test.describe(`entrada a la sala · ${variant.name}`, () => {
    test.use({ viewport: variant.viewport });

    for (const who of ROLES) {
      test(`${who.role}: entra con su JWT, sala del tenant y sin deep links`, async ({ page }) => {
        setStart(5);
        await stubJaas(page);
        await login(page, who.email);

        const response = await openRoom(page, who.list);
        expect(response.status()).toBe(200);
        const body = await response.json();
        expect(response.headers()['cache-control']).toContain('no-store');

        // JWT: sala exacta, moderador solo el profesor, sin grabación ni streaming,
        // y vence cuando cierra la ventana de acceso (no a 24 h).
        const claims = decodeJwt(body.jitsi_token);
        expect(claims.room).toBe(body.jitsi_room);
        expect(claims.context.user.moderator).toBe(who.moderator);
        expect(claims.context.features.recording).toBe(false);
        expect(claims.context.features.livestreaming).toBe(false);
        const secondsLeft = claims.exp - Math.floor(Date.now() / 1000);
        expect(secondsLeft).toBeGreaterThan(0);
        expect(secondsLeft).toBeLessThan(5 * 3600);

        // Cliente: la sala llega al iframe con el prefijo del tenant y el mismo JWT.
        await expect(page.locator('#jitsi-stub')).toBeVisible();
        const call = await page.evaluate(() => window.__jitsiCalls[0]);
        expect(call.domain).toBe('8x8.vc');
        expect(call.roomName).toBe(`${body.jaas_app_id}/${body.jitsi_room}`);
        expect(call.jwt).toBe(body.jitsi_token);
        expect(call.disableDeepLinking).toBe(true);
        expect(call.prejoin).toBe(false);
        expect(call.displayName).toBeTruthy();

        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
      });
    }

    test('cámara y micrófono: avisos con la acción concreta, sin cerrar la clase', async ({ page }) => {
      setStart(5);
      await stubJaas(page);
      await login(page, 'profesor@mova.test');
      await openRoom(page, '/teacher/classes');
      await expect(page.locator('#jitsi-stub')).toBeVisible();

      await page.evaluate(() => window.__jitsiEmit('cameraError', { type: 'gum.permission_denied' }));
      const notice = page.getByTestId('jitsi-media-notice').filter({ hasText: 'permiso para usar la cámara' });
      await expect(notice).toBeVisible();
      // La llamada sigue ahí (aviso NO bloqueante).
      await expect(page.locator('#jitsi-stub')).toBeVisible();

      await page.evaluate(() => window.__jitsiEmit('micError', { type: 'gum.not_found' }));
      await expect(page.getByTestId('jitsi-media-notice').filter({ hasText: 'No encontramos el micrófono' })).toBeVisible();

      await page.evaluate(() => window.__jitsiEmit('micError', { type: 'gum.track_in_use' }));
      await expect(page.getByTestId('jitsi-media-notice')).toContainText('usado por otra aplicación');

      await page.getByTestId('jitsi-media-notice').getByRole('button', { name: 'Entendido' }).click();
      await expect(page.getByTestId('jitsi-media-notice')).toHaveCount(0);
      await expect(page.locator('#jitsi-stub')).toBeVisible();
    });
  });
}

test.describe('errores y reconexión', () => {
  test('si 8x8.vc no carga: mensaje claro, sin quedarse "Conectando…" y sin ir a confirmar pago', async ({ page }) => {
    setStart(5);
    await page.route('https://8x8.vc/**', (route) => route.abort());
    await login(page, 'padre@mova.test');

    await openRoom(page, '/my-classes');

    await expect(page.getByText('No pudimos cargar la videollamada')).toBeVisible();
    await expect(page.getByText('Conectando a la sala')).toHaveCount(0);

    await page.getByRole('button', { name: /Cerrar sala y volver a MOVA/ }).click();
    // Cierre por error de acceso: NO manda a confirmar pago ni a escribir un reporte,
    // así que se queda en la lista de clases (un cierre normal aterriza en /dashboard).
    await expect(page).toHaveURL(new RegExp('/my-classes$'));
  });

  test('un error fatal de la conferencia se comunica y el cierre no cuenta como clase terminada', async ({ page }) => {
    setStart(5);
    await stubJaas(page);
    await login(page, 'padre@mova.test');
    await openRoom(page, '/my-classes');
    await expect(page.locator('#jitsi-stub')).toBeVisible();

    await page.evaluate(() => window.__jitsiEmit('errorOccurred', { error: { isFatal: true, name: 'conference.connectionError' } }));
    await expect(page.getByText('La videollamada se interrumpió')).toBeVisible();

    await page.getByRole('button', { name: /Cerrar sala y volver a MOVA/ }).click();
    await expect(page).toHaveURL(new RegExp('/my-classes$'));
  });

  test('salir y volver a entrar: libera la sala y emite un token nuevo', async ({ page }) => {
    setStart(5);
    await stubJaas(page);
    await login(page, 'profesor@mova.test');

    const first = await openRoom(page, '/teacher/classes');
    expect(first.status()).toBe(200);
    await expect(page.locator('#jitsi-stub')).toBeVisible();

    await page.getByRole('button', { name: /Cerrar sala y volver a MOVA/ }).click();
    // Cierre normal: el cliente lleva al dashboard (que gestiona el post-clase).
    await expect(page).toHaveURL(new RegExp('/dashboard'));
    expect(await page.evaluate(() => window.__jitsiDisposed)).toBe(1); // no queda una llamada huérfana

    const second = await openRoom(page, '/teacher/classes');
    expect(second.status()).toBe(200);
    await expect(page.locator('#jitsi-stub')).toBeVisible();
  });
});

test.describe('ventana de entrada (servidor)', () => {
  test('antes de abrir y después de cerrar: 403 con mensaje; dentro de la ventana: 200', async ({ page }) => {
    await stubJaas(page);
    await login(page, 'profesor@mova.test');
    const get = () => page.request.get(`/lessons/${lessonId}/join`, { headers: { Accept: 'application/json' } });

    setStart(120); // empieza en 2 h: la sala abre 15 min antes
    let res = await get();
    expect(res.status()).toBe(403);
    expect((await res.json()).message).toContain('minutos antes');

    setStart(-300); // terminó hace ~4 h: pasó la gracia de 2 h
    res = await get();
    expect(res.status()).toBe(403);
    expect((await res.json()).message).toContain('ya se cerró');

    setStart(10);
    res = await get();
    expect(res.status()).toBe(200);
    expect((await res.json()).jitsi_token).toBeTruthy();
  });

  test('un usuario sin sesión no obtiene token', async ({ request }) => {
    setStart(5);
    const res = await request.get(`/lessons/${lessonId}/join`, { headers: { Accept: 'application/json' }, maxRedirects: 0 });
    expect([302, 401, 403]).toContain(res.status());
    expect(await res.text()).not.toContain('jitsi_token');
  });
});
