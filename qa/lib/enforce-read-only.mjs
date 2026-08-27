// F-25 — firewall de mutaciones para cualquier corrida E2E contra un target
// remoto no local.
//
// enforceSafeTarget() (F-24A) responde "¿es seguro APUNTAR a este host?".
// Esta es una pregunta distinta: "¿es seguro EJECUTAR ESTA ACCIÓN contra
// este host?" — un override remoto legítimo (p. ej. un futuro smoke test de
// solo lectura contra producción) no debería, por sí solo, autorizar a un
// spec a hacer POST/PUT/PATCH/DELETE. Confiar en que "el spec sea
// responsable" es exactamente el mismo error de diseño que ya causó F-24A:
// la protección debe vivir en un solo lugar central, no en la buena
// voluntad de cada archivo nuevo.
//
// installMutationFirewall(page) intercepta TODAS las requests de red que
// origina esa página — Playwright las ve todas a nivel de red, sin importar
// la API del navegador que las generó: fetch, XHR, un <form> nativo
// (incluido multipart/form-data, p. ej. una subida de avatar), un click que
// dispara un submit, sendBeacon, etc. No es opcional por spec: los configs
// de "solo lectura" lo instalan en un fixture compartido para que ningún
// test pueda olvidarlo — ver qa/tests/mutation-firewall.spec.js, que
// verifica esto contra varias de esas vías, no solo fetch().
//
// Política de métodos, decidida explícitamente (no un default sin pensar):
//   GET, HEAD, OPTIONS → permitidos. OPTIONS nunca es en sí mismo una
//   mutación — es el preflight CORS que el navegador dispara antes de un
//   fetch con headers/credenciales no triviales; bloquearlo rompería
//   lecturas legítimas sin ganar nada en seguridad (el request real que
//   sigue, si es POST/PUT/PATCH/DELETE, se bloquea de todos modos).
//   POST, PUT, PATCH, DELETE → bloqueados, sin excepción.
const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);

export async function installMutationFirewall(page) {
  await page.route('**/*', (route) => {
    const method = route.request().method().toUpperCase();

    if (SAFE_METHODS.has(method)) {
      return route.continue();
    }

    console.warn(
      `[QA SAFETY] Mutación bloqueada por el firewall de solo lectura: ` +
      `${method} ${route.request().url()}`
    );

    return route.abort('accessdenied');
  });
}
