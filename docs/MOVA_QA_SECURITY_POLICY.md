# MOVA — Política de seguridad de QA E2E (Playwright)

**Fecha:** 2026-08-26. Nace de F-24A — la infraestructura de Playwright apuntaba por defecto a producción real con credenciales reales, sin ningún guard a nivel de configuración que lo impidiera. Ampliada con F-25 tras una segunda revisión: proteger el **target** (a qué host se apunta) no es lo mismo que proteger la **acción** (qué se le permite hacer a ese host) — son dos controles independientes, y esta política ahora cubre ambos. Ampliada de nuevo con **F-26/F-27** — ver [`MOVA_CREDENTIAL_EXPOSURE.md`](MOVA_CREDENTIAL_EXPOSURE.md): la búsqueda de referencias muertas encontró credenciales reales (contraseña de base de datos de producción, cookies de sesión de admin) que habían llegado al historial de git.

**Regla central, sin excepciones:** ningún comando estándar de QA (`npm test`, `npm run test:local`) puede tocar producción por accidente, y ningún override remoto autoriza por sí solo una mutación. MOVA administra créditos, recargas, clases y datos de menores — el costo de un error de targeting o de una mutación accidental no es hipotético.

---

## Los dos controles, independientes entre sí

| Control | Pregunta que responde | Dónde vive |
|---|---|---|
| **Target safety** (F-24A) | ¿A qué host estoy apuntando, y está permitido? | `qa/lib/enforce-safe-target.mjs` — se llama al cargar cualquier config, antes de listar o correr un solo test |
| **Mutation firewall** (F-25) | ¿Qué se le permite hacer a ese host una vez que apunto ahí? | `qa/lib/enforce-read-only.mjs` + `qa/lib/fixtures.mjs` — se instala por fixture en `playwright.production-readonly.config.js`, no dentro de cada spec individualmente |

**Corrección (2026-08-27):** la frase anterior de esta tabla decía que el firewall se instala "nunca a discreción de un spec individual" — es más absoluto de lo que el mecanismo real garantiza. El firewall se activa porque `playwright.production-readonly.config.js` usa el fixture `page` extendido de `qa/lib/fixtures.mjs`; **si un spec futuro importara `test`/`page` directamente de `@playwright/test` en vez de desde `qa/lib/fixtures.mjs`, correría sin el firewall instalado**, aunque usara ese mismo config de producción-solo-lectura. Hoy esto es un caso hipotético (0 specs usan ese config todavía), pero la garantía real depende de que cada spec futuro importe desde el módulo correcto — no es automática a nivel de config, es una convención que hay que seguir. Un override de target remoto (`I_UNDERSTAND_E2E_REMOTE_TARGET_IS_DANGEROUS`) **no** implica automáticamente que las mutaciones estén permitidas ahí — para eso, la config de producción-solo-lectura instala el firewall de mutaciones vía fixture, siempre que el spec importe desde ese fixture.

---

## Los tres entornos

### LOCAL (por defecto, siempre disponible)
- **Target:** `http://localhost:8000` (o `127.0.0.1`, o `[::1]`), servido por `php artisan serve` contra MySQL local.
- **Datos:** exclusivamente los de `LocalTestDataSeeder` — cuentas ficticias (`padre@mova.test`, `profesor@mova.test`, password `password123`), nunca cuentas ni datos reales. Estas credenciales viven hardcodeadas en el propio spec (`flujo-completo.spec.js`), no en `qa/.env.qa` — una sola fuente de verdad, la del seeder.
- **Permitido:** cobertura E2E completa, incluidas mutaciones destructivas — es el único entorno donde "romper algo" no tiene consecuencia real.
- **Config:** `playwright.local.config.js` (o el default `playwright.config.js`, que ahora también apunta aquí por defecto).

### STAGING (no existe todavía para MOVA)
- No hay un entorno de staging real hoy. Si se crea en el futuro, debe tener datos sintéticos propios (nunca una copia de producción con datos reales de usuarios/menores) y su propia config (`playwright.staging.config.js`), añadida a la allowlist de `qa/lib/enforce-safe-target.mjs` explícitamente por su hostname — nunca por un patrón amplio.
- Permitiría cobertura E2E completa, igual que local, incluidas mutaciones — es sintético, no real.

### PRODUCTION (excepcional, nunca por defecto, y ahora con firewall de mutaciones obligatorio)
- **Ningún script de `package.json` apunta aquí.** No existe hoy un `test:production` ni equivalente, ni ningún spec escrito para usarlo — deliberado, no un olvido. `playwright.production-readonly.config.js` existe como capa de defensa lista para cuando (si) se necesite, no porque ya se use.
- Si alguna vez se necesita un smoke test contra producción real, ese config exige, en capas independientes:
  1. `I_UNDERSTAND_E2E_REMOTE_TARGET_IS_DANGEROUS=<hostname exacto>` — autoriza el TARGET (F-24A).
  2. `E2E_CONFIRM_PRODUCTION=YES_I_UNDERSTAND_THIS_TARGETS_REAL_PRODUCTION` — autoriza la INTENCIÓN, una variable deliberadamente distinta de la anterior.
  3. HTTPS obligatorio — el override de target falla si el protocolo no es `https:`.
  4. El firewall de mutaciones instalado automáticamente vía fixture — GET/HEAD/OPTIONS pasan, POST/PUT/PATCH/DELETE se abortan antes de salir a la red (por `fetch`, `XMLHttpRequest`, `<form>` nativo, o `navigator.sendBeacon`), sin que el spec pueda desactivarlo. `OPTIONS` se permite porque nunca ejecuta la mutación en sí — es el preflight CORS que la precede; no debe leerse como "OPTIONS = modificación permitida".
- Ejecutado por una persona consciente de que está operando contra producción, nunca disparado por un script genérico o una tarea automatizada/CI sin revisión humana.
- **Capa de protección distinta, verificada y documentada — no una laguna del firewall**: `page.request.*` (la API de peticiones HTTP directas de Playwright) corre desde el proceso Node del test runner, nunca por el navegador, así que `page.route()` no está diseñado para verla. Esto no es un hueco en el firewall existente sino una superficie distinta que, si algún día se necesitara cubrir, requeriría su propio mecanismo de protección (nunca una extensión de `installMutationFirewall`, que por diseño solo ve tráfico de navegador). Ningún spec de producto debería usar `page.request` para simular una acción de usuario (usa clicks/forms reales, que sí pasan por el navegador); si alguno lo hiciera, necesitaría esa protección separada antes de poder correr contra un target no local.

## El firewall de mutaciones no sustituye la autorización de la aplicación

**Esto es una salvaguarda de la herramienta de QA, no una capa de seguridad de MOVA.** `installMutationFirewall` protege contra que el propio tooling de pruebas dispare una mutación por accidente contra un host que no debería recibirla — nada más. No reemplaza, ni debilita la necesidad de, la única defensa real que importa en producción: Auth, Policies (`app/Policies/`), Middleware, validación de formularios, y las reglas de negocio del backend. Un atacante real no pasa por Playwright ni respeta `page.route()` — pasa directamente por HTTP contra la aplicación. Que este firewall exista y pase sus 25 tests de seguridad no dice nada sobre si un endpoint concreto está bien autorizado; esa pregunta se responde revisando la Policy y el controlador correspondientes, no la infraestructura de QA.

## Qué SE cambió en esta ronda y qué no

**Se cambió (código de tooling, sin tocar la aplicación):**
- `qa/lib/enforce-safe-target.mjs` — allowlist ahora `localhost`/`127.0.0.1`/`[::1]` (se retiró `0.0.0.0`, que es una dirección de bind, no un target real; nota de implementación real: `new URL('http://[::1]:8000').hostname` devuelve `'[::1]'` con corchetes — encontrado corriendo el test, no asumido). Override remoto renombrado de `E2E_ALLOW_REMOTE_TARGET` a `I_UNDERSTAND_E2E_REMOTE_TARGET_IS_DANGEROUS` — deliberadamente incómodo de escribir sin pensar. Ahora exige HTTPS cuando se usa el override.
- **`qa/lib/enforce-read-only.mjs` + `qa/lib/fixtures.mjs` (nuevos, F-25)** — firewall de mutaciones instalable por fixture; verificado con navegador real contra un servidor HTTP local propio (no contra ningún host externo): GET/HEAD pasan, POST/PUT/PATCH/DELETE se abortan, con un control negativo confirmando que el bloqueo viene del firewall y no del entorno.
- **`playwright.production-readonly.config.js` (nuevo, F-25)** — el único lugar donde correr algo contra un host remoto tendría sentido; exige las 4 capas de la lista de arriba, verificadas una por una fallando cerrado cuando falta cualquiera.
- `playwright.config.js` y `playwright.local.config.js` — `baseURL` centralizado, ambos llaman al guard de target al cargar la config.
- `qa/.env.qa` (local, gitignored) — `BASE_URL` a `localhost`; **las variables `QA_ADMIN_EMAIL/PASSWORD`, `QA_TEACHER_EMAIL/PASSWORD`, `QA_PARENT_EMAIL/PASSWORD` y `QA_PHONE` se ELIMINARON del archivo** (no solo se les cambió el valor) — su único consumidor era `global-setup.js`, que también se eliminó. Nada en la suite activa las necesita.
- **`qa/.env.qa.example` (nuevo)** — plantilla sin secretos, committeable (se añadió una excepción explícita en `.gitignore` para este archivo puntual).
- `flujo-completo.spec.js` — rutas relativas contra el `baseURL` centralizado.
- **`global-setup.js` — ELIMINADO**, no solo blindado. Era huérfano (ningún config lo invocaba), su único propósito era autenticarse contra cuentas reales de producción, y dejarlo "por si acaso" era precisamente el tipo de higiene que permitió que F-24A ocurriera. Si en el futuro GAP-08 necesita un patrón de storageState reutilizable, se escribe de nuevo contra cuentas locales del seeder, no se reactiva este archivo.
- `qa/tests/target-safety.spec.js` — 12 tests, incluidos los casos de IPv6, normalización de URL con trailing slash/ruta, y el requisito de HTTPS para el override.
- `qa/tests/mutation-firewall.spec.js` — 13 tests, ejecutados con navegador real. Cubre `fetch`, `XMLHttpRequest`, `<form>` HTML nativo multipart, y `navigator.sendBeacon` — con tres controles negativos, y el límite documentado de `page.request.*`.
- **F-26 🔴 CRITICAL — 6 archivos con la misma contraseña RAW de la base de datos MySQL de producción, eliminados solo del disco (working tree), TODAVÍA presentes en HEAD y en `origin/master`**: `qa/test-wizard-flow.mjs` y 5 archivos más encontrados en una segunda pasada de búsqueda más profunda, repartidos en 5 commits. **Corrección importante**: ninguna de estas eliminaciones se comiteó — verificado con `git cat-file -e HEAD:<ruta>` que los 3 archivos que aún existían en disco antes de esta sesión (`qa/test-wizard-flow.mjs`, `qa/auth/admin.json`, `qa/end-to-end-welcome-email.mjs`) siguen presentes con contenido completo en el commit HEAD, idéntico a `origin/master` sin divergencia. Ningún acceso a la base de datos se intentó para verificar si sigue activa. Ver la tabla de 4 capas en `MOVA_CREDENTIAL_EXPOSURE.md`.
- **F-27 — `qa/auth/admin.json`, eliminado solo del disco, TODAVÍA en HEAD/`origin/master`**: un `storageState` de Playwright, tracked y pusheado, con cookies reales de sesión de admin de producción. Verificado: las cookies ya expiraron (2026-06-25), pero el archivo en sí no se ha comiteado como eliminado. `qa/auth/` añadido a `.gitignore` (previene una reincidencia futura, no afecta lo ya tracked).
- **Búsqueda ampliada de secretos por patrón de forma** (no solo por valor ya conocido): claves privadas RSA/OpenSSH, claves de AWS/OpenAI/Meta/Culqi/Pusher/Sentry, otros `storageState`, el root `.env` de la aplicación, reportes de Playwright. Ver [`MOVA_CREDENTIAL_EXPOSURE.md`](MOVA_CREDENTIAL_EXPOSURE.md) para la metodología completa y qué se descartó (incluido un falso positivo de `AKIA` en un bundle JS minificado).

**NO se cambió (decisiones que no le corresponde tomar a esta sesión):**
- **Ninguna credencial real se rotó.** Esto incluye la contraseña compartida de las cuentas QA Y la contraseña de la base de datos MySQL de producción (F-26, mayor severidad) — ver el orden de rotación recomendado en `docs/MOVA_CREDENTIAL_EXPOSURE.md`.
- **No se intentó verificar si alguna de las credenciales sigue activa** — ni contra la base de datos, ni contra producción con la contraseña de cuenta. Su estado se documenta como `UNKNOWN`, no como "probablemente inactiva".
- **No se purgó el git history** (`git filter-repo`/BFG) — reescritura destructiva que requeriría force-push y coordinación; el orden correcto es rotar primero, purgar después (si se decide hacerlo), nunca al revés.
- **No se creó un entorno de staging.**

## Cómo verificar que la protección sigue vigente

```bash
cd qa
npm run test:security
```

Corre `target-safety.spec.js` (12 tests) y `mutation-firewall.spec.js` (13 tests) — 25 en total, sin necesitar `php artisan serve` ni ningún servidor externo. Si algún cambio futuro a cualquiera de los configs o a `qa/lib/enforce-safe-target.mjs` / `qa/lib/enforce-read-only.mjs` hace que esto falle, **no continuar** hasta entender por qué.
