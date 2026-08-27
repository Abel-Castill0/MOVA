# MOVA — QA Tooling Baseline (Playwright / E2E)

**Fecha:** 2026-08-26
**Alcance:** saneamiento de la infraestructura de QA (F-24A/F-24B/F-25/F-26/F-27) — NO es GAP-08. No se tocó el código de la aplicación Laravel/Vue, no se creó cobertura E2E nueva de producto, y **no se ejecutó ningún test contra producción real** en ningún momento de este proceso.

Ver también [`MOVA_QA_SECURITY_POLICY.md`](MOVA_QA_SECURITY_POLICY.md) para la política LOCAL/STAGING/PRODUCTION, y **[`MOVA_CREDENTIAL_EXPOSURE.md`](MOVA_CREDENTIAL_EXPOSURE.md) — documento dedicado con el inventario completo de F-26/F-27**, la metodología de búsqueda, y qué NO se encontró (para que la ausencia de evidencia no se lea como "no se buscó").

---

## 🔴 F-26 / F-27 — hallazgo más grave de toda esta serie de rondas

Encontrado al repetir, con más profundidad, la búsqueda de referencias muertas de F-24A.1 (pedida explícitamente para confirmar que la limpieza anterior había sido completa). Resumen — **ver [`MOVA_CREDENTIAL_EXPOSURE.md`](MOVA_CREDENTIAL_EXPOSURE.md) para el detalle completo, metodología, y qué se descartó**:

- **F-26 🔴 CRITICAL** — la misma contraseña RAW de la base de datos MySQL de producción apareció hardcodeada en **6 archivos distintos** a lo largo del historial (no 1, como una ronda anterior había documentado — ver "Corrección de alcance" en `MOVA_CREDENTIAL_EXPOSURE.md`), repartidos en 5 commits y tracked/pusheados. Acceso a base de datos es un compromiso total, bypasea toda autorización de la aplicación. **Los 6 ya están eliminados del working tree** (uno de ellos, `qa/end-to-end-welcome-email.mjs`, seguía tracked en HEAD hasta esta misma ronda).
- **F-27 🟠 histórico / 🟢 actual** — `qa/auth/admin.json`, un `storageState` de Playwright con cookies reales de sesión de admin de producción, tracked y pusheado (commit `117dc36`). Expiración verificada con precisión: **2026-06-25**, ya pasada. **Eliminado del working tree**, `qa/auth/` en `.gitignore`.
- **Búsqueda ampliada tras estos hallazgos**: se buscó también por patrón de forma (no solo por valor conocido) — claves privadas RSA/OpenSSH, claves `AKIA`/`sk-` de AWS/OpenAI, secretos de Meta/Culqi/Pusher/Sentry, otros `storageState`/artefactos de sesión, el root `.env` de la aplicación, y reportes de Playwright. No se encontraron coincidencias con esos patrones — un match de 4 letras `AKIA` en un bundle JS minificado fue descartado explícitamente como falso positivo (no calzaba con el patrón completo de una clave de AWS). Como demostró el propio F-26 al recontarse de 1 a 6 archivos, "no se encontró nada más" describe el resultado de esta pasada, no una garantía permanente.

**Orden de rotación:** primero la contraseña de la base de datos (mayor alcance de compromiso), después la contraseña compartida de las cuentas QA — nunca al revés.

---

## F-24B — Referencias muertas en `qa/package.json` (FIXED)

`qa/package.json` definía 5 scripts npm (`test:admin`, `test:teacher`, `test:parent`, `test:marketplace`, `test:verify-teacher`) apuntando a archivos que no existen. Corregido: eliminados, se añadió `test:local` (y, en esta ronda, `test:security`). Ningún spec ficticio creado.

---

## F-24A — QA apuntaba a producción real por defecto, con credenciales reales

**Estado agregado en las capas de este documento — no una sola etiqueta:**

| Capa | Estado |
|---|---|
| **Technical** (código de tooling) | ✅ COMPLETE — guard de targeting + firewall de mutaciones, verificados en ejecución real |
| **Operational** (credenciales reales ya expuestas) | ❌ OPEN — 🔴 ACTION REQUIRED (contraseña de BD + contraseña compartida de cuentas QA) |
| **Business/Legal** | N/A para este hallazgo |
| **Production** | ❌ no aplica todavía — nada usa `playwright.production-readonly.config.js` hoy |

F-24A **no se marca FIXED como conjunto** mientras la capa Operational siga abierta — cerrar el código no cierra el hallazgo completo.

### Qué se corrigió (código de tooling) — targeting

1. **Guard central**: [`qa/lib/enforce-safe-target.mjs`](../qa/lib/enforce-safe-target.mjs) — allowlist `localhost` / `127.0.0.1` / `[::1]` (se retiró `0.0.0.0`: es una dirección de bind, no un target real de navegación). Cualquier otro host falla el arranque de Playwright por completo, salvo un override explícito **`I_UNDERSTAND_E2E_REMOTE_TARGET_IS_DANGEROUS=<hostname>`** (renombrado esta ronda desde `E2E_ALLOW_REMOTE_TARGET` — deliberadamente incómodo de escribir sin pensar), que además exige HTTPS.
2. **`playwright.config.js` / `playwright.local.config.js`**: `baseURL` centralizado y seguro por defecto (`localhost:8000`), ambos llaman al guard antes de construir la config.
3. **`qa/.env.qa`**: `BASE_URL` corregido a local. **Las variables `QA_ADMIN_EMAIL/PASSWORD`, `QA_TEACHER_EMAIL/PASSWORD`, `QA_PARENT_EMAIL/PASSWORD` y `QA_PHONE` se ELIMINARON del archivo** — no solo se les cambió el valor. Antes de esta ronda, el target ya apuntaba a `localhost` pero las credenciales seguían siendo las reales de producción (`TARGET=local, CREDENTIALS=production` — una incoherencia señalada correctamente en la revisión que originó esta ronda). Ahora no hay ninguna credencial de producción en el archivo, y el único spec activo usa directamente las cuentas de `LocalTestDataSeeder`.
4. **`qa/.env.qa.example` (nuevo)** — plantilla sin secretos, committeable (excepción añadida a `.gitignore` para este archivo puntual).
5. **`flujo-completo.spec.js`** refactorizado a rutas relativas contra el `baseURL` centralizado.
6. **`global-setup.js` — ELIMINADO** (no solo blindado, como se hizo en la ronda anterior). Era huérfano, su único propósito era autenticarse contra cuentas reales de producción, y las variables que consumía ya no existen. Dejarlo "por si acaso" era la misma higiene que permitió que este hallazgo ocurriera.
7. **`qa/tests/target-safety.spec.js`** — **12 tests, ejecutados de verdad (no solo `--list`)**. Incluye casos nuevos: IPv6 (`[::1]`), `0.0.0.0` explícitamente rechazado, normalización de URL (trailing slash, ruta), y el requisito de HTTPS para el override. **Un bug real se encontró corriendo este archivo, no leyéndolo**: `new URL('http://[::1]:8000').hostname` devuelve `'[::1]'` (con corchetes), no `'::1'` — la primera versión de la allowlist no lo contemplaba y el test de IPv6 falló hasta corregirlo.

### F-25 (nuevo, esta ronda) — el guard de targeting no protegía la ACCIÓN

Una revisión señaló, correctamente: `enforceSafeTarget()` responde "¿puedo apuntar aquí?", no "¿es seguro que este spec mute algo aquí?". Un override remoto futuro (p. ej. para un smoke test) no debería, por sí solo, autorizar un `POST`/`PUT`/`PATCH`/`DELETE`.

**Implementado y verificado con navegador real** (no solo `--list`):
- [`qa/lib/enforce-read-only.mjs`](../qa/lib/enforce-read-only.mjs) — `installMutationFirewall(page)` intercepta toda request de la página; deja pasar `GET`/`HEAD`, aborta cualquier otro método antes de que salga a la red.
- [`qa/lib/fixtures.mjs`](../qa/lib/fixtures.mjs) — extiende el fixture `page` de Playwright para instalar el firewall automáticamente, sin que un spec pueda "olvidarlo".
- [`qa/playwright.production-readonly.config.js`](../qa/playwright.production-readonly.config.js) — el único lugar donde un futuro smoke test de producción tendría sentido. Exige, en capas independientes y cada una verificada fallando cerrado por separado: (1) el override de target de F-24A sobre HTTPS, (2) una segunda variable `E2E_CONFIRM_PRODUCTION=YES_I_UNDERSTAND_THIS_TARGETS_REAL_PRODUCTION` — deliberadamente distinta de la anterior, para que autorizar el host no autorice también la intención, (3) el firewall de mutaciones activo por fixture.
- [`qa/tests/mutation-firewall.spec.js`](../qa/tests/mutation-firewall.spec.js) — **13 tests, ejecutados con un navegador Chromium real** contra un servidor HTTP propio en `127.0.0.1` (nunca contra un host externo). Cubre las vías de mutación pedidas explícitamente: `POST`/`PUT`/`PATCH`/`DELETE` vía `fetch`, vía `XMLHttpRequest`, vía un `<form>` HTML nativo con `enctype="multipart/form-data"` (submit real del navegador, no JS), y vía `navigator.sendBeacon` — las cuatro vías se abortan igual. Tres **controles negativos** confirman que el bloqueo es real, no un artefacto del entorno. **Política de métodos, decidida explícitamente**: `GET`/`HEAD`/`OPTIONS` pasan (`OPTIONS` nunca ejecuta la mutación en sí — es el preflight CORS que precede a un fetch con headers no triviales), `POST`/`PUT`/`PATCH`/`DELETE` se bloquean siempre. **Límite real, verificado y documentado, no asumido**: `page.request.*` (la API de peticiones HTTP directas de Playwright, que corre desde el proceso Node del test runner) **NO pasa por este firewall** — `page.route()` solo intercepta tráfico que el navegador origina. Esto no es una laguna relevante para un smoke test real (ningún spec simula acciones de usuario con `page.request`, usa clicks/forms reales), pero queda probado y documentado explícitamente para que nadie asuma cobertura total.
- No se escribió ningún script `test:production*` en `package.json` — no existe hoy ningún spec que use este config, y añadir un script sin un spec real repetiría exactamente el error de F-24B (referencias que apuntan a nada).

### Estado de las credenciales reales — 🔴 ACTION REQUIRED, no una recomendación

`qa/.env.qa` nunca fue commiteado a este repositorio (`git log --all -- qa/.env.qa`: cero resultados, en toda la historia). Sin embargo, buscando las credenciales por su VALOR (no por el nombre del archivo):

| Secreto buscado | ¿Apareció en git history? | ¿Tracked HOY en HEAD/`origin/master`? | Dónde |
|---|---|---|---|
| **Contraseña RAW de la base de datos MySQL de producción** | **SÍ** — 5 commits | **SÍ, TODAVÍA** — corrección: una versión anterior de esta fila decía "hasta esta ronda", dando a entender que ya se había resuelto; verificado con `git cat-file -e HEAD:<ruta>` que sigue presente en HEAD, que es idéntico a `origin/master` | `qa/test-wizard-flow.mjs`, `qa/end-to-end-welcome-email.mjs` — **eliminados solo del disco (working tree)**, nunca comiteada su eliminación |
| **Cookies de sesión real de admin de producción (`storageState`)** | **SÍ** — 1 commit | **SÍ, TODAVÍA** — misma corrección que la fila anterior | `qa/auth/admin.json` — **eliminado solo del disco**, nunca comiteada su eliminación; expiración de la cookie verificada: 2026-06-25, ya pasada (pero el archivo en sí sigue en HEAD/`origin/master`) |
| Contraseña compartida de las cuentas QA admin/teacher/parent | **SÍ** — ~14 commits | No (ya se había limpiado de los archivos actuales en una ronda anterior) | Specs históricos ya eliminados del working tree (`qa/tests/16-*.spec.js` … `25-*.spec.js`), `qa/test-wizard-flow.mjs`, `qa/end-to-end-welcome-email.mjs`, y brevemente en `docs/HANDOFF_FINAL.md` (redactada después) |
| Emails de las 3 cuentas QA | Sí, mismos archivos | No | Direcciones reales del propio equipo — no un secreto por sí solas, pero confirman a qué cuentas corresponde la contraseña |
| Client secret / refresh token de Gmail (QA) | No — cero resultados | — | — |
| SID / auth token de Twilio (QA) | No — cero resultados | — | — |

**Todos estos commits ya están pusheados a `origin`** (repositorio de GitHub confirmado **PRIVADO** vía `gh repo view`; `HEAD` sincronizado 0/0 con `origin/master`). Los dos hallazgos marcados "SÍ, hasta esta ronda" estaban en el árbol de trabajo actual al empezar esta sesión — no solo en commits antiguos — y se eliminaron recién ahora. Los cuatro secretos siguen siendo recuperables por cualquiera con acceso al repositorio vía `git log -p` o `git show` sobre los commits antiguos, sin importar que ya no estén en el estado actual. Un repositorio privado limita el radio de exposición (colaboradores, no el público), **no la elimina**.

**🔴 ACTION REQUIRED — pendiente, no ejecutable por esta sesión, en este orden:**
1. **Rotar la contraseña de la base de datos MySQL de producción — primero, por ser la de mayor alcance de compromiso** (acceso directo a toda la base de datos, no solo a 3 cuentas de aplicación). Vía el panel de Railway.
2. **Rotar la contraseña compartida de las 3 cuentas QA (admin/teacher/parent).** Es una acción sobre cuentas reales de producción — requiere el panel de administración o acceso directo a la base de datos, ninguno de los cuales corresponde ejecutar aquí sin la participación directa del dueño de esas cuentas.
3. **Usar contraseñas separadas por rol al recrearlas** (`QA_ADMIN_PASSWORD` ≠ `QA_TEACHER_PASSWORD` ≠ `QA_PARENT_PASSWORD`) — la contraseña compartida actual significa que comprometer una cuenta compromete las tres.
4. **No es necesario invalidar manualmente las cookies de `qa/auth/admin.json`** — ya expiraron (verificado, no asumido); rotar la contraseña de la cuenta de todos modos invalida cualquier sesión activa que dependiera de ella.
5. Después de rotar ambas credenciales (no antes): decidir si vale la pena purgar el historial de git (`git filter-repo`/BFG). Es una reescritura destructiva que exige force-push y coordinación con cualquier otro colaborador con el repo clonado — y purgar el historial sin haber rotado antes no reduce el riesgo real, solo lo esconde.

**No se intentó verificar si la contraseña de la base de datos o de las cuentas QA siguen activas** — hacerlo requeriría conectarse a la base de datos real o iniciar sesión en producción con una credencial potencialmente comprometida, exactamente la acción insegura que esta ronda existe para evitar. El estado correcto a documentar es `UNKNOWN`, no `activa` ni `inactiva`, hasta que el dueño de las cuentas lo confirme.

**No se rotó ninguna credencial ni se reescribió el historial de git en esta sesión** — ambas acciones corresponden al dueño de las cuentas y del repositorio, no a una pasada de saneamiento de tooling.

### `check:gmail` — clasificado, no modificado

`qa/check-gmail-inbox.mjs` usa un refresh token OAuth con scope `gmail.readonly` contra una cuenta de Gmail dedicada al proyecto (no una cuenta personal de alto valor) — sin exposición en git history. Riesgo: **BAJO-MEDIO**. No modificado.

---

## Inventario completo

### Scripts de `qa/package.json`

| Script | Comando | Estado |
|---|---|---|
| `test` | `playwright test --config=playwright.config.js` | ✅ Seguro por defecto — apunta a `localhost:8000`, protegido por el guard |
| `test:local` | `playwright test --config=playwright.local.config.js tests/flujo-completo.spec.js` | ✅ |
| `test:security` | `playwright test --config=playwright.local.config.js tests/target-safety.spec.js tests/mutation-firewall.spec.js` | ✅ **25/25 passed** |
| `check:gmail` | `node check-gmail-inbox.mjs` | ✅ Válido; riesgo clasificado arriba |

### Specs reales (`qa/tests/`)

| Spec | Tests | Ejecutado de verdad en esta ronda |
|---|---|---|
| `flujo-completo.spec.js` | 3 | ❌ No — requiere levantar el stack local completo (MySQL, `php artisan serve`, seeders). Intentado sin el stack levantado: falló exactamente donde se esperaba (no pudo navegar a `localhost:8000`), confirmando que el fallo es de infraestructura ausente, no de una regresión introducida esta ronda. Primer paso real de GAP-08 |
| `target-safety.spec.js` | 12 | ✅ **Sí** — 12/12 passed |
| `mutation-firewall.spec.js` | 13 | ✅ **Sí** — 13/13 passed, con navegador Chromium real (fetch, XHR, `<form>` nativo multipart, `sendBeacon`, y el límite documentado de `page.request.*`) |

**Cobertura E2E de producto confirmada hoy: 0 corridas.** Cobertura de seguridad de tooling confirmada hoy: **25/25**. Dos afirmaciones distintas — no mezclarlas.

### Tres categorías de test, para que los números no se mezclen

| Categoría | Qué prueba | Dónde | Estado hoy |
|---|---|---|---|
| **QA infrastructure** | Que el tooling en sí (targeting, firewall de mutaciones) no pueda tocar producción por accidente | `target-safety.spec.js`, `mutation-firewall.spec.js` | ✅ 25/25 ejecutados de verdad |
| **E2E de producto** | Que MOVA (login, clases, recargas, admin) funcione de extremo a extremo | `flujo-completo.spec.js` | ❌ 0 corridas — requiere el stack local levantado, primer paso de GAP-08 |
| **Integraciones externas** (Meta, Culqi, JaaS) | Que los proveedores reales respondan como se espera | No existe hoy ningún test — depende de credenciales reales de cada proveedor | ⏳ Fuera de alcance de QA tooling; ver F-06/F-21 en `MOVA_PRODUCTION_READINESS.md` |

### Configuración

| Archivo | Rol |
|---|---|
| `qa/lib/enforce-safe-target.mjs` | Única fuente de verdad sobre qué hosts son seguros (F-24A) |
| `qa/lib/enforce-read-only.mjs` | Firewall de mutaciones (F-25, nuevo) |
| `qa/lib/fixtures.mjs` | Instala el firewall vía fixture (F-25, nuevo) |
| `playwright.config.js` | Config por defecto — segura, con guard |
| `playwright.local.config.js` | Config local — `baseURL` centralizado, con guard |
| `playwright.production-readonly.config.js` | Nuevo (F-25) — sin uso activo todavía, defensa lista para cuando se necesite |
| `qa/.env.qa` | Sin credenciales de producción; `BASE_URL` local |
| `qa/.env.qa.example` | Nuevo — plantilla committeable, sin secretos |
| `global-setup.js` | **Eliminado** (antes: huérfano y blindado; ahora: no existe) |
| `qa/auth/admin.json` | **Eliminado esta ronda** — contenía cookies de sesión real de admin de producción, ya expiradas, pero estaba tracked y pusheado. `qa/auth/` añadido a `.gitignore` |
| `qa/test-wizard-flow.mjs` | **Eliminado esta ronda** — contenía la contraseña raw de la base de datos MySQL de producción, hardcodeada, tracked y pusheada en 5 commits |

### Versión de las herramientas

| Herramienta | Versión |
|---|---|
| `@playwright/test` | 1.61.1 |
| Browser (config por defecto) | Chrome de canal `chrome` (sistema) |
| Browser (config local / tests de seguridad) | Chromium propio de Playwright (ya instalado localmente, confirmado antes de correr nada) |

---

## Recomendación para el equipo (no ejecutada), en orden

1. **🔴 Rotar la contraseña de la base de datos MySQL de producción — primero, mayor alcance de compromiso.**
2. **🔴 Rotar la contraseña compartida de las cuentas QA (admin/teacher/parent).**
3. Al recrearla, usar tres contraseñas distintas, una por rol.
4. Después de rotar ambas: decidir si purgar el historial de git.
5. Cuando GAP-08 empiece de verdad: `npm run test:local` contra un stack local real, para obtener la primera cifra honesta de passing/failing de `flujo-completo.spec.js`.
