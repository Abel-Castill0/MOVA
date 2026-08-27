// F-24A — guard central de targeting seguro para toda la suite de QA E2E.
//
// Origen: playwright.config.js apuntaba por defecto a la Railway de
// producción real (con credenciales reales de admin/teacher/parent en
// qa/.env.qa), y esa protección dependía únicamente de que CADA spec
// individual tuviera su propio guard (como el que ya traía
// flujo-completo.spec.js). Eso es insuficiente: basta con que un spec nuevo
// no lo copie para que "npm test" pueda tocar producción por accidente.
//
// Esta es la ÚNICA fuente de verdad sobre qué hosts son seguros. Cualquier
// config de Playwright de este proyecto debe llamar a esta función con su
// baseURL resuelto ANTES de construir la config — si el host no está en la
// allowlist, lanza y el proceso de Playwright ni siquiera arranca.
//
// Allowlist explícita (no blacklist): solo lo que reconocemos como
// "definitivamente local" pasa sin fricción. Todo lo demás — incluida
// cualquier variante de *.railway.app, o cualquier dominio real de MOVA —
// requiere un override manual y explícito, nunca un default silencioso.
//
// IMPORTANTE — esto protege el TARGET, no la ACCIÓN: un override remoto
// concedido aquí solo dice "este host es donde vamos a mirar", no "es
// seguro ejecutar cualquier mutación ahí". Para eso existe un control
// separado, deliberadamente independiente: qa/lib/enforce-read-only.mjs
// (F-25) bloquea POST/PUT/PATCH/DELETE a nivel de request, sin importar qué
// target haya aprobado esta función. Un spec que corre contra un remoto
// autorizado por enforceSafeTarget() TODAVÍA puede necesitar
// installMutationFirewall() si ese remoto no es 100% de confianza para
// mutaciones — son dos preguntas distintas y este archivo solo responde una.
//
// 0.0.0.0 deliberadamente FUERA de la allowlist: es una dirección de bind
// ("todas las interfaces"), no un target real de navegación — permitirla
// aquí no tenía un caso de uso concreto y solo ensanchaba la superficie sin
// necesidad. ::1 (loopback IPv6) sí se agrega: es tan "local" como 127.0.0.1.
// Nota real de implementación, encontrada corriendo el test, no asumida:
// `new URL('http://[::1]:8000').hostname` devuelve '[::1]' (con corchetes),
// no '::1' — así serializa Node/WHATWG el hostname de una URL con un literal
// IPv6. Se incluye la forma con corchetes; la forma sin corchetes NUNCA
// aparece como valor de `.hostname` de una URL real, así que no hace falta
// aceptarla también.
const ALLOWED_HOSTNAMES = ['localhost', '127.0.0.1', '[::1]'];

// Nombre deliberadamente incómodo de escribir/copiar sin pensar — a
// diferencia de E2E_ALLOW_REMOTE_TARGET (el nombre original, demasiado
// genérico), este obliga a que quien lo exporte reconozca conscientemente
// el riesgo, no solo "permita" un target.
const OVERRIDE_ENV_VAR = 'I_UNDERSTAND_E2E_REMOTE_TARGET_IS_DANGEROUS';

export function enforceSafeTarget(baseURL) {
  let parsed;
  try {
    parsed = new URL(baseURL);
  } catch {
    throw new Error(
      `[QA SAFETY] BASE_URL inválido o ausente: "${baseURL}". No se puede evaluar si el target es seguro.`
    );
  }

  const hostname = parsed.hostname;

  if (ALLOWED_HOSTNAMES.includes(hostname)) {
    return;
  }

  const override = process.env[OVERRIDE_ENV_VAR];
  if (override && override === hostname) {
    if (parsed.protocol !== 'https:') {
      throw new Error(
        `\n[QA SAFETY] El override remoto para "${hostname}" solo se acepta sobre HTTPS. ` +
        `BASE_URL usa "${parsed.protocol}" — un remoto real nunca debería probarse por HTTP plano.\n`
      );
    }
    // Ruido intencional: un override remoto NUNCA debe pasar en silencio.
    console.warn(
      `\n[QA SAFETY] ⚠️  Corriendo contra un host REMOTO permitido explícitamente: "${hostname}".\n` +
      `Esto no debería apuntar a producción salvo una decisión consciente y documentada.\n` +
      `Recuerda: este guard solo aprueba el TARGET. Si el spec puede mutar datos, combínalo con\n` +
      `installMutationFirewall() de qa/lib/enforce-read-only.mjs (ver F-25) antes de correrlo.\n`
    );
    return;
  }

  throw new Error(
    `\n[QA SAFETY] BASE_URL "${baseURL}" (host: "${hostname}") no está en la allowlist local ` +
    `(${ALLOWED_HOSTNAMES.join(', ')}) y no hay un override explícito.\n` +
    `Si de verdad necesitas correr contra "${hostname}" (por HTTPS), exporta la variable de entorno:\n` +
    `  ${OVERRIDE_ENV_VAR}=${hostname}\n` +
    `antes de correr los tests. Esto NUNCA debe hacerse contra producción sin una decisión ` +
    `explícita y documentada — hay cuentas y datos reales detrás de ese host.\n`
  );
}
