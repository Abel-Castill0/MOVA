// @ts-check
import { defineConfig, devices } from '@playwright/test';
import { enforceSafeTarget } from './lib/enforce-safe-target.mjs';

// F-24A / F-25 — config deliberadamente restrictiva para el ÚNICO escenario
// legítimo de E2E contra producción real: un smoke test de solo lectura. No
// existe hoy ningún spec escrito para usar este config — se construye antes
// de que exista la necesidad, como capa de defensa, no como una feature que
// alguien pidió usar ya.
//
// Tres controles independientes, cada uno insuficiente por sí solo:
//   1. enforceSafeTarget() (F-24A) — el host debe estar explícitamente
//      autorizado vía I_UNDERSTAND_E2E_REMOTE_TARGET_IS_DANGEROUS, y solo
//      sobre HTTPS.
//   2. E2E_CONFIRM_PRODUCTION — una segunda variable, distinta de la de
//      arriba, que además debe calzar EXACTO con la frase esperada. Un
//      desarrollador que solo exportó la variable de host (para otro
//      propósito) no activa esto por accidente.
//   3. installMutationFirewall() (F-25, vía lib/fixtures.mjs) — instalado
//      automáticamente en TODA spec que use este config, sin que el spec
//      tenga que acordarse de nada. Ningún POST/PUT/PATCH/DELETE puede salir
//      hacia la red, sin importar qué haga el código del spec.
//
// Si en el futuro un spec real necesita este config, debe importar `test`
// desde qa/lib/fixtures.mjs (no desde '@playwright/test' directamente) para
// heredar el firewall — de lo contrario el fixture no se instala.
const CONFIRM_ENV_VAR = 'E2E_CONFIRM_PRODUCTION';
const CONFIRM_PHRASE = 'YES_I_UNDERSTAND_THIS_TARGETS_REAL_PRODUCTION';

if (process.env[CONFIRM_ENV_VAR] !== CONFIRM_PHRASE) {
  throw new Error(
    `\n[QA SAFETY] Este config es exclusivamente para smoke tests de SOLO LECTURA contra producción real.\n` +
    `Falta la confirmación explícita. Exporta:\n` +
    `  ${CONFIRM_ENV_VAR}=${CONFIRM_PHRASE}\n` +
    `además del override de host que ya exige enforceSafeTarget() (ver qa/lib/enforce-safe-target.mjs).\n` +
    `Ambas variables son intencionalmente distintas: una autoriza EL HOST, la otra autoriza LA INTENCIÓN.\n`
  );
}

const baseURL = process.env.BASE_URL;
if (!baseURL) {
  throw new Error(
    `[QA SAFETY] Este config no tiene un BASE_URL por defecto — debes exportarlo explícitamente ` +
    `(debe ser HTTPS y estar autorizado vía enforceSafeTarget()).`
  );
}
enforceSafeTarget(baseURL);

export default defineConfig({
  testDir: './tests',
  timeout: 30_000,
  retries: 0,
  workers: 1,
  reporter: [['list']],
  use: {
    baseURL,
    headless: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    locale: 'es-PE',
  },
  projects: [
    {
      name: 'chrome',
      use: { ...devices['Desktop Chrome'], channel: 'chrome' },
    },
  ],
});
