import { test, expect, devices } from '@playwright/test';
import path from 'node:path';
import { runPhp } from '../lib/run-php.mjs';

const root = path.resolve(import.meta.dirname, '../..');

/**
 * REUNIÓN REAL en JaaS (8x8.vc): docente (escritorio) y alumno/padre (móvil)
 * entran a LA MISMA sala con cámara y micrófono sintéticos de Chromium.
 *
 * SOLO corre con credenciales REALES de JaaS (JAAS_REAL=1, que fija
 * `bash scripts/sandbox-smoke.sh jaas`). Sin ellas se omite: no hay forma de
 * validar contra 8x8.vc sin la clave que firma el JWT.
 *
 * Qué demuestra: que JaaS acepta los JWT que emite MOVA, que ambas personas
 * quedan en la misma conferencia (2 participantes visibles para ambos), que
 * cámara y micrófono quedan activos y que salir y volver a entrar funciona.
 * Qué NO demuestra: que una persona REAL vea y oiga a la otra con calidad
 * aceptable (los dispositivos son sintéticos y el vídeo está dentro de un iframe
 * de otro origen). Eso lo confirma una prueba humana con dos dispositivos.
 */

test.skip(process.env.JAAS_REAL !== '1', 'Requiere credenciales reales de JaaS (bash scripts/sandbox-smoke.sh jaas).');

test.use({
  launchOptions: { args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'] },
});
test.setTimeout(240_000);

const SENTINEL = '__MOVA_TINKER_OK__';
const php = (code) => {
  const out = runPhp(['artisan', 'tinker', `--execute=${code} echo '${SENTINEL}';`], { cwd: root, encoding: 'utf8' });
  if (!out.includes(SENTINEL)) throw new Error(`Tinker no completó el fixture:\n${out.trim()}`);
  return out.slice(0, out.indexOf(SENTINEL)).trim();
};

let lessonId;
let originalStart;

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
  php(`App\\Models\\Lesson::whereKey(${lessonId})->update(['start_time' => now()->addMinutes(5)]);`);
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

// Guarda cada instancia de JitsiMeetExternalAPI para poder consultarla desde el test.
const INSTRUMENT = () => {
  let real;
  Object.defineProperty(window, 'JitsiMeetExternalAPI', {
    configurable: true,
    get: () => real,
    set: (Ctor) => {
      real = function (...args) {
        const api = new Ctor(...args);
        (window.__jitsi = window.__jitsi || []).push(api);
        api.addEventListener('videoConferenceJoined', () => { window.__joined = true; });
        api.addEventListener('cameraError', (e) => { window.__cameraError = e; });
        api.addEventListener('micError', (e) => { window.__micError = e; });
        return api;
      };
      real.prototype = Ctor.prototype;
    },
  });
};

// Diagnóstico sin secretos: consola, peticiones fallidas y texto visible (el JWT va en la URL del
// iframe, así que NUNCA se vuelcan URLs completas, solo host + estado).
const trace = new WeakMap();
function watch(page, label) {
  const log = [];
  trace.set(page, { label, log });
  page.on('console', (m) => { if (['error', 'warning'].includes(m.type())) log.push(`console.${m.type()}: ${m.text().slice(0, 160)}`); });
  page.on('pageerror', (e) => log.push(`pageerror: ${String(e.message).slice(0, 160)}`));
  page.on('requestfailed', (r) => log.push(`requestfailed: ${new URL(r.url()).host} ${r.failure()?.errorText ?? ''}`));
  page.on('response', (r) => { if (r.status() >= 400) log.push(`http ${r.status()}: ${new URL(r.url()).host}${new URL(r.url()).pathname.slice(0, 40)}`); });
}

async function enterRoom(page, listPath) {
  await page.goto(listPath);
  const listTab = page.getByRole('tab', { name: 'Lista', exact: true });
  if (await listTab.isVisible()) await listTab.click();
  await page.getByRole('button', { name: /Ingresar a la Sala Virtual/ }).first().click();
  try {
    // JaaS muestra su pantalla previa («Join meeting») aunque MOVA pida prejoinPageEnabled:false:
    // una persona real también debe pulsarla. Está en el iframe de 8x8.vc.
    const joinBtn = page.frameLocator('iframe[src*="8x8.vc"]').getByRole('button', { name: /^Join meeting$|^Unirse a la reuni[oó]n$/i }).first();
    await joinBtn.click({ timeout: 60_000 });
    await page.waitForFunction(() => window.__joined === true, null, { timeout: 90_000 });
  } catch (e) {
    const t = trace.get(page);
    const visible = (await page.locator('body').innerText().catch(() => '')).replace(/\s+/g, ' ').slice(0, 500);
    const state = await page.evaluate(() => ({
      apiCreated: (window.__jitsi || []).length,
      scriptDefined: typeof window.JitsiMeetExternalAPI,
      iframes: [...document.querySelectorAll('iframe')].map((f) => new URL(f.src, location.href).host),
    })).catch(() => ({}));
    // Playwright sí puede leer el texto de un iframe de otro origen (p. ej. «no autorizado»).
    const frame = page.frames().find((f) => f.url().includes('8x8.vc'));
    const frameText = frame ? (await frame.locator('body').innerText({ timeout: 5000 }).catch(() => '(ilegible)')).replace(/\s+/g, ' ').slice(0, 400) : '(sin iframe)';
    throw new Error(`${t?.label}: no entró a la sala.\nestado=${JSON.stringify(state)}\niframe=${frameText}\nvisible=${visible.slice(0, 120)}\nlog=${JSON.stringify((t?.log ?? []).slice(-12))}`);
  }
}

const participants = (page) => page.evaluate(() => window.__jitsi[window.__jitsi.length - 1].getNumberOfParticipants());

test('docente (escritorio) y padre (móvil): misma sala, 2 participantes, audio y video activos, y reingreso', async ({ browser }) => {
  const teacherCtx = await browser.newContext({ viewport: { width: 1280, height: 800 }, permissions: ['camera', 'microphone'] });
  const parentCtx = await browser.newContext({ ...devices['Pixel 7'], permissions: ['camera', 'microphone'] });
  const teacher = await teacherCtx.newPage();
  const parent = await parentCtx.newPage();
  watch(teacher, 'docente'); watch(parent, 'padre');
  await teacher.addInitScript(INSTRUMENT);
  await parent.addInitScript(INSTRUMENT);

  try {
    await login(teacher, 'profesor@mova.test');
    await login(parent, 'padre@mova.test');

    await enterRoom(teacher, '/teacher/classes');
    await enterRoom(parent, '/my-classes');

    // Misma conferencia: cada uno ve a los dos participantes.
    await expect.poll(() => participants(teacher), { timeout: 60_000 }).toBe(2);
    await expect.poll(() => participants(parent), { timeout: 60_000 }).toBe(2);

    // Audio y video activos en ambos lados (dispositivos sintéticos).
    for (const page of [teacher, parent]) {
      expect(await page.evaluate(() => window.__jitsi[0].isAudioMuted())).toBe(false);
      expect(await page.evaluate(() => window.__jitsi[0].isVideoMuted())).toBe(false);
      expect(await page.evaluate(() => window.__cameraError ?? null)).toBeNull();
      expect(await page.evaluate(() => window.__micError ?? null)).toBeNull();
    }

    // Reconexión: el padre sale desde MOVA y vuelve a entrar; el docente lo ve salir y volver.
    await parent.getByRole('button', { name: /Cerrar sala y volver a MOVA/ }).click();
    await expect.poll(() => participants(teacher), { timeout: 60_000 }).toBe(1);

    await parent.evaluate(() => { window.__joined = false; });
    await enterRoom(parent, '/my-classes');
    await expect.poll(() => participants(teacher), { timeout: 60_000 }).toBe(2);
  } finally {
    await teacherCtx.close();
    await parentCtx.close();
  }
});
