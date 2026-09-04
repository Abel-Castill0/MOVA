# MOVA — Guía de deploy en Railway

## Por qué Railway

Railway detecta PHP automáticamente con **Railpack** (sin Dockerfile), soporta MySQL como plugin nativo y permite múltiples servicios por proyecto (web + worker + scheduler), lo que encaja exactamente con la arquitectura de MOVA.

> **Nota (Railpack reality check):** este documento mencionaba "Nixpacks"
> en versiones anteriores. La documentación actual de Railway
> Config-as-Code ya no lista `NIXPACKS` como valor válido de
> `[build].builder` (solo `RAILPACK` y `DOCKERFILE`) — Railpack es el
> builder real, confirmado además leyendo el código fuente de
> `railwayapp/railpack` (no solo su documentación prosa, que en algún
> punto se contradice con el propio script que genera). Los tres
> `railway*.toml` de este repo ya declaran `builder = "RAILPACK"`.

> **⚠️ PRODUCTION BLOCKER (longevidad, no bloquea esta fase): Config as
> Code está deprecado.** Confirmado contra la documentación oficial de
> Railway (banner literal en `docs.railway.com/reference/config-as-code`,
> verificado dos veces de forma independiente en esta sesión): *"Config as
> Code is deprecated. Prefer Infrastructure as Code. Existing files keep
> working for legacy services until 2026-12-01; migrate with the guide in
> Infrastructure as Code."* Los tres `railway*.toml` de este repo siguen
> funcionando como servicios legacy **solo hasta esa fecha** (hoy:
> 2026-09-04 — quedan menos de 3 meses). Ningún plan de producción debe
> asumir que estos archivos son la solución a largo plazo.
>
> **No se migra en este checkpoint.** Los TOML se mantienen como fuente de
> verdad legacy hasta que exista un equivalente probado en IaC — no se
> borran a la ligera.
>
> **Intento de migración a IaC realizado (2026-09-04): BLOQUEADO para
> paridad 1:1 solo-repo.** Ver sección "Migración a Infrastructure as Code
> (IaC)" más abajo para la evidencia completa. Resumen preciso: Railway
> **como plataforma** sigue teniendo Pre-Deploy Commands — la funcionalidad
> no desapareció. El gap verificado es más estrecho: el nuevo DSL de IaC
> (`service()` en `.railway/railway.ts`) **todavía no expone un campo
> equivalente a `preDeployCommand`** — confirmado explícitamente contra la
> documentación oficial. Esto significa que una migración 1:1,
> representada enteramente en el repo vía `.railway/railway.ts`, no puede
> hoy preservar el invariante "migrate --force corre una vez por deploy,
> nunca en cada restart". No significa que Railway carezca de forma segura
> de correr migraciones, ni que no exista ningún camino — significa que el
> camino repo-only vía IaC todavía no está disponible. No se creó ningún
> `.railway/railway.ts`: un archivo que "parece" una migración completa
> pero no puede preservar ese invariante sería más peligroso que no migrar
> todavía. El seguimiento de esto es trabajo activo, no una espera pasiva
> — ver "Cronograma" en la sección de IaC.

---

## Arquitectura de servicios en Railway

```
Proyecto MOVA en Railway
├── mova-web          ← Servicio PHP/Laravel (web)    → railway.toml
├── mova-queue        ← Worker para colas             → railway.queue.toml
├── mova-scheduler    ← Scheduler de recordatorios    → railway.scheduler.toml
└── MySQL Plugin      ← Base de datos MySQL administrada
```

Cada servicio conecta al mismo repositorio GitHub pero apunta a un archivo de configuración distinto. Esto es necesario porque Railway usa config-as-code: el `railway.toml` embebido en la imagen sobreescribe el Start Command del dashboard.

| Servicio | Config file | Pre-Deploy Command | Start Command |
|---|---|---|---|
| mova-web | `/railway.toml` | `php artisan config:clear && php artisan migrate --force` | *(sin definir — ver "Servidor web" abajo)* |
| mova-queue | `/railway.queue.toml` | — | `php artisan config:clear && php artisan queue:work --queue=default --sleep=3 --tries=3 --timeout=60 --backoff=5 --no-interaction` |
| mova-scheduler | `/railway.scheduler.toml` | — | `php artisan config:clear && php artisan schedule:work -v` |

`railpack.json` (raíz del repo) fija la versión de PHP a `8.3` para los tres servicios — ver sección "PHP: versión y soporte del framework" abajo.

---

## Servidor web — por qué NO hay `startCommand` en `railway.toml`

Hasta la fase de habilitación de producción, `mova-web` corría
`php artisan serve --host=0.0.0.0 --port=$PORT` — el servidor de
desarrollo de Laravel: mono-hilo, bloqueante, nunca pensado para tráfico
concurrente real. Además, `migrate --force` estaba embebido en ese mismo
comando, así que corría en CADA arranque/restart del proceso web, no una
sola vez por deploy.

Ahora:

- **`migrate --force` vive en `preDeployCommand`** (`[deploy]` de
  `railway.toml`). Railway lo corre una única vez por deploy, en un
  contenedor aparte, antes de reemplazar el deploy anterior — nunca en
  cada restart/réplica del proceso web.
- **`startCommand` se deja sin definir a propósito.** El **Railpack** PHP
  provider (el builder real de los tres servicios — ver nota arriba)
  detecta Laravel automáticamente (archivo `artisan`) y arranca
  **FrankenPHP vía Caddy** por su cuenta — sin Dockerfile ni
  configuración de Nginx/PHP-FPM propia en el repo. Confirmado leyendo el
  código fuente de `railwayapp/railpack`
  (`core/providers/php/start-container.sh`): el comando final es
  `docker-php-entrypoint --config /Caddyfile --adapter caddyfile`.
- El document root (`public/`, no la raíz del repo) se detecta **solo**
  cuando hay un archivo `artisan` — confirmado en
  `core/providers/php/php.go` (`getConfigFiles()`): sin ese archivo cae a
  `/app`, con él cae automáticamente a `/app/public`. MOVA sí tiene
  `artisan` en la raíz, así que **no hace falta declarar ninguna variable
  de root manualmente** (ni `NIXPACKS_PHP_ROOT_DIR`, que es del builder
  retirado, ni `RAILPACK_PHP_ROOT_DIR`, que solo existiría para
  sobreescribir la detección automática si algún día hiciera falta).

### CRÍTICO — `RAILPACK_SKIP_MIGRATIONS=true` es obligatoria

El script de arranque que el propio Railpack genera para apps Laravel
(`/start-container.sh`, no un archivo de este repo) corre él mismo
`php artisan migrate --force` **en cada arranque del contenedor**, salvo
que la variable `RAILPACK_SKIP_MIGRATIONS=true` esté puesta:

```bash
# Contenido real de /start-container.sh (Railpack), confirmado contra el
# código fuente de railwayapp/railpack:
if [ "$IS_LARAVEL" = "true" ]; then
  if [ "$RAILPACK_SKIP_MIGRATIONS" != "true" ]; then
    php artisan migrate --force
  fi
  php artisan storage:link
  php artisan optimize:clear
  php artisan optimize
fi
docker-php-entrypoint --config /Caddyfile --adapter caddyfile
```

Sin `RAILPACK_SKIP_MIGRATIONS=true` en `mova-web`, el mismo problema que
`preDeployCommand` vino a resolver (migración en cada restart, no una vez
por deploy) **reaparece por otra vía** — y `railway.toml`/`railway.json`
(Config-as-Code) no tiene sección de variables de entorno propia
(confirmado en su documentación) para forzarla desde ahí.

**SOURCE OF TRUTH: `railpack.json` → `deploy.variables`.** Esta variable
**ya está repo-enforced**, no es una tarea manual pendiente de dashboard:

```json
{
  "$schema": "https://schema.railpack.com",
  "packages": { "php": "8.3" },
  "deploy": {
    "variables": { "RAILPACK_SKIP_MIGRATIONS": "true" }
  }
}
```

Verificado tres veces de forma independiente en la sesión que introdujo
esto (`ef43601`): schema oficial de Railpack (`schema.railpack.com`), el
struct Go real (`core/config`) y el código de merge real
(`core/generate/context.go`: `maps.Copy(c.Deploy.Variables,
c.Config.Deploy.Variables)`) — ese campo de `railpack.json` sí se traduce
en una variable de entorno real del contenedor de deploy (como
`APP_ENV`/`IS_LARAVEL`), no solo en sustitución de plantillas. El repo se
basta a sí mismo; no depende de que un humano recuerde configurarla en el
dashboard de Railway.

**POST-DEPLOY VERIFICATION** (pendiente, no bloqueante de código): la
única verificación que falta es que el **valor efectivo en runtime** del
contenedor sea `true` — no confirmado contra un deploy real esta fase
(fuera de alcance, no se desplegó nada). Ver checklist al final del
documento.

(`storage:link`, `optimize:clear` y `optimize` SIEMPRE corren, con o sin
`RAILPACK_SKIP_MIGRATIONS`. Esto además reemplaza el rol que
`php artisan config:clear` cumplía manualmente en el `startCommand`
anterior: `optimize` recachea config/rutas/vistas con las variables de
entorno reales del contenedor en ese momento, cada vez que arranca —
`mova-queue`/`mova-scheduler` conservan su propio `config:clear` manual
porque tienen `startCommand` explícito y nunca pasan por este script.)

**No confirmado contra un deploy real** (fuera de alcance de esta fase —
no se desplegó nada). **Verificar en el primer deploy real de
`mova-web`:**
1. Los logs muestran a Caddy/FrankenPHP arrancando — nunca
   `Server running on [http://...]` (eso sería `php artisan serve`).
2. Con `RAILPACK_SKIP_MIGRATIONS=true` puesta, los logs de un *restart*
   (no de un deploy) no muestran "Running migrations" de nuevo.
3. `/healthz` responde `200`.

Si algo no calza, revertir `railway.toml` a la versión anterior
(`git log -- railway.toml`) es un solo commit.

---

## Paso 1 — Crear proyecto en Railway

1. Ir a [railway.app](https://railway.app) e iniciar sesión con GitHub
2. Clic en **New Project**
3. Seleccionar **Deploy from GitHub repo**
4. Conectar el repo `Abel-Castill0/MOVA`
5. Railway detectará el `railway.toml` y configurará el servicio web automáticamente

---

## Paso 2 — Agregar MySQL

1. Dentro del proyecto, clic en **+ New Service**
2. Seleccionar **Database → MySQL**
3. Railway provisiona MySQL y expone las variables de conexión automáticamente:
   - `MYSQLHOST`, `MYSQLPORT`, `MYSQLDATABASE`, `MYSQLUSER`, `MYSQLPASSWORD`
4. En el servicio web, agregar estas variables de entorno (ver sección Variables):
   ```
   DB_HOST=${{MySQL.MYSQLHOST}}
   DB_PORT=${{MySQL.MYSQLPORT}}
   DB_DATABASE=${{MySQL.MYSQLDATABASE}}
   DB_USERNAME=${{MySQL.MYSQLUSER}}
   DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}
   ```
   > La sintaxis `${{MySQL.VARIABLE}}` vincula automáticamente las variables entre servicios en Railway.

---

## Paso 3 — Configurar variables de entorno del servicio Web

Agregar manualmente en el dashboard del servicio `mova-web`:

```env
APP_NAME=MOVA
APP_ENV=production
APP_KEY=                        # generar con: php artisan key:generate --show
APP_DEBUG=false
APP_URL=https://tu-dominio.railway.app

DB_CONNECTION=mysql
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}

SESSION_DRIVER=database
CACHE_DRIVER=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local

MAIL_MAILER=gmail_api       # Recomendado para Railway Hobby (HTTPS, sin SMTP)
GMAIL_CLIENT_ID=            # de Google Cloud Console
GMAIL_CLIENT_SECRET=        # secreto — nunca en repo
GMAIL_REFRESH_TOKEN=        # secreto — obtener con: php artisan mova:gmail-auth-url
GMAIL_FROM_ADDRESS=         # tu cuenta Gmail
GMAIL_FROM_NAME=MOVA
MAIL_FROM_ADDRESS=          # igual que GMAIL_FROM_ADDRESS
MAIL_FROM_NAME=MOVA

ZOOM_ACCOUNT_ID=
ZOOM_CLIENT_ID=
ZOOM_CLIENT_SECRET=
ZOOM_EMAIL=
ZOOM_TIMEZONE=America/Lima

TWILIO_SID=
TWILIO_AUTH_TOKEN=
TWILIO_WHATSAPP_FROM=+14155238886

SENTRY_LARAVEL_DSN=
SENTRY_TRACES_SAMPLE_RATE=0.1

ADMIN_NAME=
ADMIN_EMAIL=
ADMIN_PASSWORD=
```

---

## Email en Railway Hobby — sin dominio propio

### Por qué no funciona SMTP en Railway Hobby

Railway Hobby bloquea el tráfico SMTP saliente (puertos 25, 465, 587). Cualquier intento de conectar a `smtp.gmail.com`, `smtp.mailgun.org`, etc., provoca un `TimeoutExceededException` y tumba el job de notificación.

### Solución: Gmail API por HTTPS

La Gmail API envía correos usando llamadas HTTPS (puerto 443), que Railway **no bloquea**. No requiere dominio propio. Solo necesitas una cuenta Gmail y crear una App OAuth en Google Cloud Console.

**Limitaciones**: 500 emails/día por cuenta Gmail gratuita. Suficiente para un MVP.

### Paso a paso para configurar Gmail API

#### 1. Crear credenciales OAuth en Google Cloud Console

1. Ir a [console.cloud.google.com](https://console.cloud.google.com)
2. Crear un proyecto (ej. `MOVA-Email`)
3. Ir a **APIs & Services → Enable APIs** → habilitar **Gmail API**
4. Ir a **APIs & Services → Credentials → Create Credentials → OAuth 2.0 Client ID**
5. Application type: **Web application**
6. Authorized redirect URIs: agregar `http://localhost`
7. Guardar y copiar **Client ID** y **Client Secret**

#### 2. Obtener el Refresh Token

```bash
# Genera la URL de autorización:
php artisan mova:gmail-auth-url

# Abre la URL en el navegador, aprueba el permiso.
# Copia el "code" del parámetro de la URL de redirección.

# Intercambia el código por el refresh token:
php artisan mova:gmail-exchange-code TU_CODE_AQUI
# → Imprime tu GMAIL_REFRESH_TOKEN
```

#### 3. Agregar variables en Railway

En Railway → MOVA → Variables:

```
MAIL_MAILER=gmail_api
GMAIL_CLIENT_ID=        ← de Google Cloud Console
GMAIL_CLIENT_SECRET=    ← de Google Cloud Console (secreto)
GMAIL_REFRESH_TOKEN=    ← del comando mova:gmail-exchange-code (secreto)
GMAIL_FROM_ADDRESS=     ← tu cuenta Gmail (ej. m0v4class@gmail.com)
GMAIL_FROM_NAME=MOVA
```

**Nunca** commitear `GMAIL_CLIENT_SECRET` ni `GMAIL_REFRESH_TOKEN` al repositorio.

#### 4. Si no hay GMAIL_REFRESH_TOKEN configurado

El `SafeMailChannel` detecta la ausencia y registra un warning en el log. Las notificaciones **in-app (database) y WhatsApp siguen funcionando** — el job no falla.

### Cuándo migrar a Resend/Postmark/Mailgun

Cuando tengas un dominio propio verificado, la migración es un cambio de 2 variables en Railway:

```
MAIL_MAILER=resend
RESEND_API_KEY=re_xxxxx
MAIL_FROM_ADDRESS=noreply@tudominio.com
```

---

## Paso 4 — Seed de producción (primera vez)

Después del primer deploy exitoso, ejecutar solo el ProductionSeeder (no `db:seed` completo):

```bash
php artisan db:seed --class=ProductionSeeder --force
```

Esto crea los roles (admin, teacher, parent), las materias y el usuario admin usando las variables `ADMIN_NAME`, `ADMIN_EMAIL` y `ADMIN_PASSWORD`. Es idempotente: no duplica datos si se ejecuta más de una vez.

---

## Paso 5 — Crear servicio Queue Worker

### Crear el servicio

1. Dentro del mismo proyecto Railway, clic en **+ New Service → GitHub Repo**
2. Conectar el mismo repo `Abel-Castill0/MOVA`
3. Nombrar el servicio `mova-queue`

### Apuntar al config file correcto

Railway config-as-code: el Start Command del dashboard es ignorado si el `railway.toml` dentro de la imagen lo sobreescribe. Para que `mova-queue` use su propio comando, configurar el Config File Path:

```
Railway dashboard → mova-queue → Settings → Config File Path → /railway.queue.toml
```

Esto hace que Railway use `railway.queue.toml` en lugar de `railway.toml` para este servicio.

### Variables

Agregar las mismas variables de entorno que el servicio web (especialmente `DB_*`, `QUEUE_CONNECTION=database`, `APP_KEY`, `APP_ENV=production`).

---

## Paso 6 — Crear servicio Scheduler

### Por qué no usar Railway Cron

MOVA ejecuta recordatorios cada minuto (10 min antes de clase). Railway Cron tiene un **mínimo de 5 minutos** y levanta un contenedor nuevo con cold start en cada ejecución. Para recordatorios puntuales, se necesita un proceso en loop continuo.

### Crear el servicio

1. Crear un tercer servicio desde el mismo repo
2. Nombrar el servicio `mova-scheduler`

### Apuntar al config file correcto

```
Railway dashboard → mova-scheduler → Settings → Config File Path → /railway.scheduler.toml
```

### Variables

Agregar las mismas variables de entorno que el servicio web.

---

## Verificar que los servicios usan los comandos correctos

Después de redesplegar, ir a los logs de cada servicio y confirmar:

| Servicio | Log esperado en arranque |
|---|---|
| mova-web | Arranque de Caddy/FrankenPHP (Railpack) — **nunca** `Server running on [http://0.0.0.0:...]` (eso sería `php artisan serve`, señal de que el cambio no tomó) |
| mova-queue | `Processing jobs from the [default] queue` |
| mova-scheduler | Salida de `schedule:work` ejecutando cada comando debido en su minuto exacto, incluyendo `mercadopago:reconcile` cada 5 minutos — ya no hay un log de "vuelta de loop" cada 60s porque dejó de ser un loop de shell |

> **Incidente conocido:** en un intento de deploy anterior, `mova-scheduler`
> quedó en `Failed` sin ningún log de arranque (nunca llegó el marcador
> `Starting Container`, a diferencia de `mova-queue` con el mismo commit y
> pipeline). La causa fue el campo **Config File Path** del dashboard de
> Railway — es exclusivo del dashboard (la Railway CLI no lo expone) y
> Railway solo auto-detecta un `railway.toml` en la raíz por proyecto, así
> que cada servicio adicional (`mova-queue`, `mova-scheduler`) DEBE apuntar
> explícitamente a su propio archivo. Si un servicio nuevo no arranca y los
> logs están vacíos (no hay excepción de PHP ni stack trace), compara su
> Config File Path carácter por carácter contra uno que sí funcione antes
> de sospechar del código.

---

## Scheduler — invariante de réplica única y por qué ya no es un loop de shell

`mova-scheduler` corría `while true; do schedule:run; sleep 60; done` —
un loop de shell escrito a mano que re-arranca Laravel entero en cada
vuelta. Problema real: la siguiente vuelta empieza a
`runtime_del_comando + 60s` desde que TERMINÓ la anterior, no cada 60s de
reloj — si un barrido tarda (ej. `mercadopago:reconcile` con Mercado Pago
lento), la siguiente vuelta llega tarde y puede saltarse por completo el
minuto que le tocaba a otro comando (`classmate:send-reminders` corre
cada minuto).

Ahora usa `php artisan schedule:work` — el mecanismo nativo de Laravel
para exactamente este caso (disponible desde Laravel 8, confirmado
instalado con `php artisan list schedule` sobre Laravel 10.50.2): un
único proceso de foreground que duerme hasta el siguiente límite exacto
de minuto, sin loop de shell propio.

**Invariante — `mova-scheduler` debe correr con EXACTAMENTE 1 réplica.**
`withoutOverlapping()` (usado por los tres comandos agendados) usa como
mutex el cache store por defecto de Laravel (`config('cache.default')`).
Ese lock solo protege contra solapamiento **entre procesos distintos**
si el store es realmente compartido (`database`/`redis`) — el default de
`config/cache.php` y de `.env.example` es `file`, local al contenedor. No
asumir protección cross-réplica sin haber configurado
`CACHE_DRIVER=database` primero (ver checklist) — y aun así, la forma más
simple y ya suficiente para el volumen actual de MOVA es simplemente NO
escalar este servicio, no depender de un lock compartido para
correctitud. No se agregó Redis ni ningún mecanismo nuevo de lock
distribuido para esto — sería sofisticación innecesaria para un
invariante que ya se cumple con la topología por defecto de Railway (1
réplica por servicio salvo que alguien la cambie a mano).

---

## Comandos de referencia

```bash
# Build (ejecutado automáticamente por Railway en cada push)
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link

# Web — pre-deploy (una vez por deploy, contenedor aparte, antes de reemplazar el anterior)
php artisan config:clear && php artisan migrate --force
# Web — start: sin comando propio, lo maneja Railpack (FrankenPHP vía Caddy).
# RAILPACK_SKIP_MIGRATIONS=true es repo-enforced vía railpack.json
# (deploy.variables) — no requiere configuración manual. Ver sección
# "Servidor web" arriba para la verificación post-deploy pendiente.

# Queue worker
php artisan config:clear && php artisan queue:work --queue=default --sleep=3 --tries=3 --timeout=60 --backoff=5 --no-interaction

# Scheduler — proceso único de foreground, no un loop de shell — incluye
# mercadopago:reconcile cada 5 min (app/Console/Kernel.php)
php artisan config:clear && php artisan schedule:work -v

# Recuperación de pagos/webhooks atascados (agendado; también corrible a mano)
php artisan mercadopago:reconcile

# Seed de producción (idempotente)
php artisan db:seed --class=ProductionSeeder --force
```

---

## Migración a Infrastructure as Code (IaC) — hallazgos verificados (2026-09-04)

Intento de migración de los tres `railway*.toml` (Config as Code, deprecado,
corte 2026-12-01) a `.railway/railway.ts` (IaC, formato sucesor). Verificado
contra la documentación oficial de Railway (`railwayapp/docs`, vía Context7,
no memoria) y contra el comportamiento real de la Railway CLI instalada
(`v5.27.0`, proyecto `tranquil-creativity` ya enlazado:
servicios `MOVA`/`mova-queue`/`mova-scheduler` + `MySQL`).

### Tabla de paridad

| Legacy (`railway*.toml`) | Equivalente IaC (`service()`) | Evidencia | Migrado |
|---|---|---|---|
| `builder = "RAILPACK"` | Sin campo `builder` — Railpack es el único builder que `service()` soporta (Dockerfile builder/path **no** existe en IaC) | `infrastructure-as-code/reference.md` (nota explícita) | ✅ implícito |
| `buildCommand` (composer/npm/artisan cache) | `service({ build: "..." })` | `reference.md` — `build` string | ✅ directo |
| *(sin `startCommand`, Railpack detecta Laravel)* | Igual: omitir `start` dentro de lo que Railpack soporta | No verificado si IaC permite omitir `start` para dejarlo a Railpack — no evidenciado en la doc revisada | ⚠️ no confirmado |
| `startCommand` (queue/scheduler) | `service({ start: "..." })` | `reference.md` — `start` string | ✅ directo |
| `healthcheckPath` / `healthcheckTimeout` | `service({ healthcheck, healthcheckTimeout })` | `reference.md` | ✅ directo |
| `preDeployCommand` (`migrate --force`, una vez por deploy, contenedor aparte) | **No existe en `service()`.** Confirmado explícitamente: *"pre-deploy command... are NOT available in the IaC `service()` config — those only exist in the deprecated Config-as-Code format"* | `infrastructure-as-code/reference.md` | ❌ **BLOQUEADO** |
| `restartPolicyType` / `restartPolicyMaxRetries` | **No existe en `service()`** tampoco (misma nota que preDeploy). El default de plataforma es `ON_FAILURE` / 10 reintentos (confirmado vía API GraphQL `ServiceInstanceUpdateInput.apiDefault`) | `reference.md` + `integrations/api/manage-services.md` | ⚠️ parcial — pierde el ajuste fino (web/scheduler usan 3, no 10) pero conserva `ON_FAILURE` |
| Config File Path por servicio (dashboard, apuntando cada uno a su `.toml`) | No aplica: un solo `.railway/railway.ts` define los tres `service()` en el mismo archivo | `infrastructure-as-code.md` | ✅ mejora (elimina la causa raíz del "Incidente conocido" documentado arriba) |
| Variables de entorno (`${{MySQL.MYSQLHOST}}`, secretos a mano en dashboard) | `env: { X: preserve() }` para conservar valores existentes, o `db.env.VAR` para referencias tipadas entre recursos IaC | `reference.md` — `preserve()` | ✅ directo (no evaluado en profundidad — fuera de alcance sin tocar el servicio MySQL real) |
| `mercadopago:reconcile` cada 5 min (`app/Console/Kernel.php`, dentro de `schedule:work`) | Sin cambio — vive en código de la app, no en config Railway | N/A | ✅ no aplica a IaC |

### Alcance exacto del gap (leer con cuidado)

| Capa | Pre-Deploy Command | Estado |
|---|---|---|
| Funcionalidad de la plataforma Railway | Existe y sigue soportada (`docs/deployments/pre-deploy-command.md`) | ✅ |
| Representación en Config-as-Code legacy (`railway.toml`) | `[deploy].preDeployCommand` | ✅ (es lo que usa hoy `mova-web`) |
| Representación en el DSL de IaC (`service()` de `.railway/railway.ts`) | Ningún campo equivalente documentado | ❌ **actualmente** |

Es decir: **Railway no se quedó sin forma segura de correr migraciones** —
la funcionalidad de plataforma sigue ahí. El gap es puntual: el nuevo DSL
repo-only (IaC) todavía no la expone. Esto NO es lo mismo que "no existe
ningún workaround" — significa que los dos workarounds obvios *dentro del
propio repo* no sirven (ver abajo), y que cualquier otro camino (gestión
externa del pre-deploy, un servicio/job dedicado, un gate de CI) es una
opción sin verificar todavía, no descartada por falta de alternativas sino
por falta de evidencia suficiente para elegir una sin probarla primero.

1. **No hay workaround vía `build`**: la red privada de Railway (necesaria
   para llegar a MySQL) **no está disponible durante el build**, solo en
   runtime — confirmado explícitamente (`networking/private-networking/how-it-works.md`:
   *"database migrations requiring internal connectivity should run as part
   of the start command"*). Meter `migrate --force` al final de `build` no
   podría conectar a la base de datos.
2. **No hay workaround vía `start`**: mover `migrate --force` al `start`
   de `mova-web` es exactamente el bug original que `preDeployCommand`
   vino a resolver esta misma sesión — correría en cada restart/réplica,
   no una vez por deploy.
3. **Sin ordenamiento entre servicios en el trigger real de este repo —
   y esto es independiente de CasC vs. IaC**: verificado explícitamente
   que Railway solo ordena despliegues simultáneos vía variables de
   referencia (`service.env` cruzado) para *template deploys, staged
   changes, duplicado de environments y PR environments* — **no** para el
   disparador real de este proyecto: *"When services deploy independently,
   no ordering is performed. This applies to GitHub push deploys, even in
   a monorepo..."* (`deployments/deployment-actions.md`). Consecuencia
   directa: **incluso el `preDeployCommand` que `mova-web` usa HOY (Config
   as Code) no garantiza que `mova-queue`/`mova-scheduler` esperen a que la
   migración termine** antes de arrancar código nuevo — esto ya era cierto
   antes de esta fase, no es una regresión de IaC. Ninguna de las opciones
   evaluadas en el encargo (A/B/C/D) puede apoyarse en un mecanismo nativo
   de orden de Railway para el push-deploy real de
   `mova-web`/`mova-queue`/`mova-scheduler`. La única protección real hoy
   — y la única que sobrevive cualquier elección de formato de config — es
   disciplina de migraciones **expand/contract** a nivel de aplicación:
   el schema debe tolerar código viejo y nuevo corriendo a la vez.
4. **Gestión dual no soportada**: un servicio no puede estar administrado
   por Config as Code e IaC a la vez (*"Services cannot be managed by both
   ... simultaneously"*) — migrar es un corte por servicio, sin red de
   seguridad combinada durante la transición.
5. **IaC sigue "experimental"** (`infrastructure-as-code.md` §Limitations):
   sin historial persistido de changesets, sin flujo "apply-later", y el
   propio formato de archivo generado puede cambiar.

### Limitación de validación offline

Los subcomandos `railway config plan/pull/apply/init` requieren un runner
adicional ("Railway TypeScript SDK" / `railway-iac-ts`) **no instalado en
este entorno** — confirmado al ejecutar `railway config pull --json`:
`Could not find Railway configuration support for this project. Install
the Railway TypeScript SDK...`. No se instaló: (a) la sesión prohíbe
dependencias salvo que la propia herramienta IaC lo exija de forma
verificada — ya verificado que sí lo exige, pero (b) el hallazgo
bloqueante (§ arriba) ya está confirmado con evidencia primaria de la
documentación oficial y no depende de poder ejecutar `plan`/`pull`; instalar
un SDK adicional solo para reconfirmarlo no habría cambiado la
recomendación. `railway --version` (5.27.0) y `railway status` sí se
verificaron sin instalar nada: el proyecto (`tranquil-creativity`) está
enlazado, con servicios `MOVA` (Failed), `mova-queue` (Offline),
`mova-scheduler` (Sleeping) y `MySQL` (Failed) — estados preexistentes, no
tocados ni diagnosticados en esta fase (fuera de alcance: infra-only,
sin deploy).

### Por qué NO usar `railway run` como fallback de migración

Una idea obvia es reemplazar `preDeployCommand` por un paso manual/CI que
corra la migración por fuera. `railway run` **no sirve para esto en la
topología actual de MOVA**, y no por falta de permisos sino por diseño:

- `railway run` está documentado como *"Run a **local** command using
  variables from the active environment"* — confirmado en
  `railway run --help` de la CLI instalada: inyecta las variables de
  Railway en un proceso que corre en la máquina que ejecuta el comando
  (un laptop, un runner de CI), **no** dentro de la red de Railway.
- La red privada de Railway (`*.railway.internal`) — donde vive el
  hostname interno de MySQL — es **runtime-only y solo resoluble desde
  dentro de la red de Railway** (mismo hallazgo de la sección anterior).
  Un proceso local o de CI fuera de Railway no puede resolver ni alcanzar
  ese hostname.
- Consecuencia: `php artisan migrate --force` corrido vía `railway run`
  desde fuera de Railway fallaría al conectar a la base de datos de
  producción de MOVA, salvo que MySQL exponga un endpoint público — **no
  se propone esto**: exponer la base de datos públicamente solo para que
  un fallback de migración funcione sería debilitar la superficie de
  ataque a cambio de conveniencia de tooling, lo contrario de lo que pide
  esta fase.

No se descarta que Railway ofrezca otro mecanismo capaz de ejecutar
*dentro* de la red (p. ej. algo apoyado en `railway ssh`, un servicio
dedicado, o un runner de CI conectado por el proxy TCP/SSH de Railway) —
simplemente no se investigó ni se verificó en esta fase, así que no se
documenta como alternativa hasta probarlo.

### Direcciones futuras posibles (sin elegir — requieren prueba antes de adoptarse)

Ninguna de estas se elige en esta corrección de documentación. Se listan
como opciones a investigar en la próxima fase dedicada, cada una con lo
que haría falta verificar antes de confiar en ella:

- **A. Config as Code temporal hasta que exista un reemplazo** — mantener
  `mova-web` en `railway.toml` (con su `preDeployCommand`) más allá de
  esta fase, aceptando que corre como "servicio legacy" hasta el corte de
  2026-12-01. Requiere confirmar qué pasa exactamente ese día para
  servicios que no migraron (¿deja de desplegar? ¿se congela la config?).
- **B. Pre-deploy gestionado fuera del repo (dashboard) conviviendo con
  IaC** — si Railway permite configurar un pre-deploy a nivel de
  servicio/dashboard para un servicio administrado por IaC. No verificado:
  la documentación revisada dice que CasC e IaC no pueden coadministrar el
  mismo servicio, pero no aclara si un ajuste de dashboard fuera del
  archivo `railway.toml` cuenta como "Config as Code" a estos efectos.
- **C. Servicio/job de release dedicado** — un servicio separado que solo
  corre la migración y termina, disparado explícitamente por el pipeline
  de release. Requiere resolver cómo se dispara de forma confiable en cada
  release (no hay ordenamiento nativo de Railway para push-deploys, ver
  arriba) sin convertirse en una cuarta pieza de infraestructura permanente
  no evidenciada como necesaria.
- **D. Gate de release controlado por CI** — el pipeline de CI/CD ejecuta
  la migración contra producción (con acceso de red legítimo, no
  `railway run` local) como parte del propio proceso de release, antes de
  disparar o confirmar el deploy de los tres servicios. Requiere decidir
  cómo el CI alcanza la red privada de Railway sin exponer MySQL
  públicamente.
- **E. Expand/contract como red de seguridad permanente** — independiente
  de A–D: dado que ni CasC ni IaC garantizan orden entre
  `mova-web`/`mova-queue`/`mova-scheduler` en un push-deploy real (ver
  arriba), las migraciones de schema deben seguir siendo
  backward-compatible entre versiones consecutivas del código sin importar
  qué mecanismo de release se elija. Esto no es opcional ni sustituible
  por ninguna de A–D — es el invariante que ya debería cumplirse hoy.

### Decisión

**No migrar todavía.** Los tres `railway*.toml` siguen siendo la fuente de
verdad — ninguna de las condiciones de arriba se resolvió en esta fase, así
que no hay base para elegir entre A–D todavía.

No se creó `.railway/railway.ts`: un archivo que se ve como una migración
terminada pero no puede expresar el invariante de migración más crítico del
repo es más riesgoso que no tener el archivo — alguien podría aplicarlo
(`railway config apply`) asumiendo paridad completa.

### Cronograma — esto es trabajo activo, no una espera pasiva

- **Corte de Config as Code**: 2026-12-01 (confirmado, oficial).
- **Fecha actual**: 2026-09-04.
- **Margen**: ~3 meses (menos, considerando tiempo de staging/QA antes del
  corte, no hasta el corte mismo).

Esto **no** significa "esperar a que se acerque el 2026-12-01 para
reaccionar". Significa que la próxima fase dedicada debe, cuanto antes:
(1) verificar las opciones A–D con evidencia real (no solo documentación
de prosa, como en esta fase), (2) elegir una con justificación explícita,
y (3) validarla en un entorno de staging con tiempo suficiente de sobra
antes del corte — no en la semana previa. El estado BLOQUEADO de esta
fase es sobre la paridad IaC hoy, no sobre el ritmo de la siguiente fase.

---

## Variables pendientes de configurar manualmente en Railway

Las siguientes variables contienen secretos y **nunca deben estar en el repositorio**. Configurar exclusivamente en el dashboard de Railway:

| Variable | Dónde obtenerla |
|---|---|
| `APP_KEY` | `php artisan key:generate --show` |
| `GMAIL_CLIENT_ID` | console.cloud.google.com → OAuth 2.0 Credentials |
| `GMAIL_CLIENT_SECRET` | idem (secreto) |
| `GMAIL_REFRESH_TOKEN` | `php artisan mova:gmail-auth-url` → `mova:gmail-exchange-code` |
| `ZOOM_ACCOUNT_ID` | marketplace.zoom.us → tu app Server-to-Server |
| `ZOOM_CLIENT_ID` | idem |
| `ZOOM_CLIENT_SECRET` | idem |
| `TWILIO_SID` | console.twilio.com |
| `TWILIO_AUTH_TOKEN` | console.twilio.com |
| `SENTRY_LARAVEL_DSN` | sentry.io → proyecto php-laravel → DSN |
| `ADMIN_EMAIL` | Email del administrador de producción |
| `ADMIN_PASSWORD` | Contraseña segura del admin |

(No hace falta ninguna variable de document root — Railpack detecta `public/` automáticamente al encontrar `artisan`. No usar `NIXPACKS_PHP_ROOT_DIR`, es del builder retirado.)

> **`RAILPACK_SKIP_MIGRATIONS` NO va en esta tabla.** A diferencia de las
> variables de arriba, no es un valor manual de dashboard: está
> repo-enforced vía `railpack.json` → `deploy.variables` (ver sección
> "Servidor web" — CRÍTICO). Lo único pendiente es verificar, tras el
> primer deploy real, que el valor efectivo en el contenedor sea `true`
> (ver checklist).

---

## PHP: versión y soporte del framework

**Versión de PHP.** `composer.json` declara `"php": "^8.1"`. Railpack lee
esa misma constraint (confirmado en `core/providers/php/php.go`): le
quita el `^` y pide exactamente esa versión de imagen
(`dunglas/frankenphp:php8.1-trixie`), verificando en vivo contra Docker
Hub si existe. Si no existe (plausible: PHP 8.1 alcanza su propio fin de
soporte de seguridad en noviembre de 2025), el default interno del
provider es **PHP 8.4** — por encima del techo oficial que Laravel 10
soporta (8.1–8.3), sin que nada lo bloquee ni avise.

`railpack.json` (nuevo, raíz del repo) fija esto explícitamente sin tocar
`composer.json` (mecanismo documentado de Railpack — `packages.php`,
independiente de las dependencias declaradas de la app):

```json
{
  "$schema": "https://schema.railpack.com",
  "packages": { "php": "8.3" }
}
```

Se eligió **8.3** (no 8.1) porque es la versión más alta oficialmente
compatible con Laravel 10 — el mismo margen de seguridad que ya usa el
propio `railway.queue.toml` con los timeouts, preferir el extremo más
sano del rango soportado en vez del mínimo. Esto NO es una migración de
versión de PHP del proyecto (`composer.json` sigue en `^8.1`, sin tocar
dependencias) — es solo fijar qué construye Railpack.

**Laravel 10 — soporte de seguridad ya terminado.**

> **PRODUCTION BLOCKER: framework sin soporte / sin parches de seguridad.**
> Laravel 10 (`composer.lock`: `10.50.2`) dejó de recibir *bug fixes* en
> agosto de 2024 y **dejó de recibir parches de seguridad el
> 2025-02-04**. La fecha actual es 2026. Esto **no** es evidencia de una
> vulnerabilidad explotable concreta hoy — es la ausencia de la red de
> seguridad que un framework con soporte activo sí tiene. No se puede
> declarar MOVA "listo para producción" sin más mientras el framework
> esté fuera de soporte, sin importar cuánto se haya endurecido el código
> de pagos por encima de él.
>
> **Próxima fase (no iniciada, no diseñada aquí):** actualización de
> Laravel a una versión con soporte activo → análisis de compatibilidad
> dirigido (paquetes de terceros, breaking changes relevantes) → suite
> completa en verde → QA de concurrencia MySQL (`mova:qa-mysql-fresh-migrate`)
> → re-evaluación completa de production readiness. Alcance deliberadamente
> NO expandido en este checkpoint.

---

## Riesgos antes del primer deploy

| Riesgo | Severidad | Mitigación |
|---|---|---|
| `APP_KEY` vacío en producción | Alta | Generar y pegar antes del deploy |
| `APP_DEBUG=true` accidentalmente | Alta | Verificar siempre antes del push |
| Config file path no configurado en mova-queue/scheduler | Alta | Seguir Pasos 5 y 6 exactamente — ver "Incidente conocido" arriba |
| Valor efectivo de `RAILPACK_SKIP_MIGRATIONS` en runtime no confirmado | Media (repo-enforced vía `railpack.json`, no una tarea manual pendiente — riesgo es de verificación, no de configuración) | Confirmar en los logs del primer deploy real que no aparezca "Running migrations" en un *restart* (no deploy) — ver sección "Servidor web" |
| `QUEUE_CONNECTION` ausente en algún servicio (cae a `sync` por default de `config/queue.php`) | Alta | `mova:health-check` lo detecta como `MERCADOPAGO_WEBHOOK_QUEUE_SYNC` si el webhook de Mercado Pago está habilitado — ver sección de pagos abajo |
| `CACHE_DRIVER` ausente/`file` con `mova-scheduler` escalado a >1 réplica | Alta | `mova:health-check` lo detecta como `SCHEDULER_LOCK_NOT_SHARED` en producción; mientras tanto, mantener `mova-scheduler` en exactamente 1 réplica (invariante actual, no requiere Redis) |
| `MERCADOPAGO_WEBHOOKS_ENABLED=true` sin la callback URL registrada aún en el panel de Mercado Pago | Alta | No activar el flag hasta configurar el webhook (skill `mp-webhooks`, `save_webhook`) — mientras esté en `false`, el endpoint rechaza todo (404) aunque la firma sea válida |
| `mercadopago:reconcile` agendado pero `mova-scheduler` caído | Alta | El agendado (`app/Console/Kernel.php`) no sustituye verificar que el servicio esté `Online` — confirmar en logs cada ~5 min |
| Laravel 10 sin soporte de seguridad desde 2025-02-04 | Alta (blocker de fase, no de este checkpoint) | Ver sección "PHP: versión y soporte del framework" — próxima fase dedicada, fuera de este diff |
| Config as Code (`railway*.toml`) deprecado, corte duro 2026-12-01 (hoy: 2026-09-04, ~3 meses de margen) | Alta (blocker de longevidad, activo — no esperar al corte) | Ver sección "Migración a Infrastructure as Code (IaC)": migración a `.railway/railway.ts` bloqueada hoy por falta de equivalente a `preDeployCommand` en el DSL de IaC (`railway config init/pull/plan/apply`, no `railway config migrate` — ese comando no existe en la CLI v5.27.0). Próxima fase debe verificar y elegir entre las opciones A–D documentadas, con tiempo de staging antes del corte, no reaccionar cerca de la fecha |
| `SENTRY_TRACES_SAMPLE_RATE=1.0` en prod | Media | Usar 0.1 para no agotar cuota |
| Queue worker sin supervisión | Media | Monitorear logs en Railway |

---

## Checklist pre-deploy

- [ ] `APP_KEY` generado y configurado en Railway
- [ ] `APP_DEBUG=false` en variables de Railway
- [ ] `APP_ENV=production`
- [ ] `APP_URL` apunta al dominio de Railway
- [ ] Variables DB vinculadas con `${{MySQL.MYSQLHOST}}` etc.
- [ ] Variables de correo configuradas (Gmail API/OAuth: `GMAIL_CLIENT_ID`, `GMAIL_CLIENT_SECRET`, `GMAIL_REFRESH_TOKEN`, `GMAIL_FROM_ADDRESS`)
- [ ] Variables Zoom configuradas
- [ ] Variables Twilio configuradas
- [ ] `SENTRY_LARAVEL_DSN` configurado
- [ ] `SENTRY_TRACES_SAMPLE_RATE=0.1`
- [ ] `ADMIN_EMAIL` y `ADMIN_PASSWORD` configurados
- [ ] mova-queue → Config File Path → `/railway.queue.toml`
- [ ] mova-scheduler → Config File Path → `/railway.scheduler.toml`
- [ ] `QUEUE_CONNECTION=database` en los tres servicios
- [ ] `CACHE_DRIVER=database` en los tres servicios (comparte el mutex de `withoutOverlapping()`)
- [ ] `mova-scheduler` en exactamente 1 réplica (no escalar sin adoptar antes un lock distribuido deliberado)
- [ ] `php artisan mova:health-check` en verde antes de habilitar pagos/webhooks reales
- [ ] ProductionSeeder ejecutado tras primer deploy
- [ ] Primer deploy de `mova-web` verificado según la sección "Servidor web" (logs de Caddy/FrankenPHP, `/healthz`, sin migración repetida en un restart)
- [ ] **Post-deploy**: confirmar que el valor efectivo de `RAILPACK_SKIP_MIGRATIONS` en el contenedor de `mova-web` sea `true` en runtime — repo-enforced vía `railpack.json`, ya no requiere configurarse a mano; esto es solo la verificación pendiente (ver "Servidor web" — CRÍTICO)
