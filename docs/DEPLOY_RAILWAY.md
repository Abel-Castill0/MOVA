# MOVA — Guía de deploy en Railway

## Por qué Railway

Railway detecta PHP automáticamente con Nixpacks (sin Dockerfile), soporta MySQL como plugin nativo y permite múltiples servicios por proyecto (web + worker + scheduler), lo que encaja exactamente con la arquitectura de MOVA.

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

| Servicio | Config file | Start Command |
|---|---|---|
| mova-web | `/railway.toml` | `php artisan config:clear && php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=$PORT` |
| mova-queue | `/railway.queue.toml` | `php artisan config:clear && php artisan queue:work --sleep=3 --tries=3 --timeout=60 --max-time=3600` |
| mova-scheduler | `/railway.scheduler.toml` | `while true; do php artisan config:clear && php artisan schedule:run --verbose --no-interaction; sleep 60; done` |

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
| mova-web | `Server running on [http://0.0.0.0:...]` |
| mova-queue | `Processing jobs from the [default] queue` |
| mova-scheduler | `Running scheduled command: ...` cada ~60s |

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

# Web
php artisan config:clear && php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=$PORT

# Queue worker
php artisan config:clear && php artisan queue:work --sleep=3 --tries=3 --timeout=60 --max-time=3600

# Scheduler (loop continuo)
while true; do php artisan config:clear && php artisan schedule:run --verbose --no-interaction; sleep 60; done

# Seed de producción (idempotente)
php artisan db:seed --class=ProductionSeeder --force
```

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

---

## Riesgos antes del primer deploy

| Riesgo | Severidad | Mitigación |
|---|---|---|
| `APP_KEY` vacío en producción | Alta | Generar y pegar antes del deploy |
| `APP_DEBUG=true` accidentalmente | Alta | Verificar siempre antes del push |
| Config file path no configurado en mova-queue/scheduler | Alta | Seguir Pasos 5 y 6 exactamente |
| `SENTRY_TRACES_SAMPLE_RATE=1.0` en prod | Media | Usar 0.1 para no agotar cuota |
| Queue worker sin supervisión | Media | Monitorear logs en Railway |

---

## Checklist pre-deploy

- [ ] `APP_KEY` generado y configurado en Railway
- [ ] `APP_DEBUG=false` en variables de Railway
- [ ] `APP_ENV=production`
- [ ] `APP_URL` apunta al dominio de Railway
- [ ] Variables DB vinculadas con `${{MySQL.MYSQLHOST}}` etc.
- [ ] Variables de correo configuradas (Gmail App Password)
- [ ] Variables Zoom configuradas
- [ ] Variables Twilio configuradas
- [ ] `SENTRY_LARAVEL_DSN` configurado
- [ ] `SENTRY_TRACES_SAMPLE_RATE=0.1`
- [ ] `ADMIN_EMAIL` y `ADMIN_PASSWORD` configurados
- [ ] mova-queue → Config File Path → `/railway.queue.toml`
- [ ] mova-scheduler → Config File Path → `/railway.scheduler.toml`
- [ ] ProductionSeeder ejecutado tras primer deploy
