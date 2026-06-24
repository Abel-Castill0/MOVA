# MOVA — Guía de deploy en Railway

## Por qué Railway

Railway detecta PHP automáticamente con Nixpacks (sin Dockerfile), soporta MySQL como plugin nativo y permite múltiples servicios por proyecto (web + worker + scheduler), lo que encaja exactamente con la arquitectura de MOVA.

---

## Arquitectura de servicios en Railway

```
Proyecto MOVA en Railway
├── mova-web          ← Servicio PHP/Laravel (web)
├── mova-queue        ← Worker para colas (emails, WhatsApp)
├── mova-scheduler    ← Scheduler de recordatorios (loop 60s)
└── MySQL Plugin      ← Base de datos MySQL administrada
```

> Cada servicio se conecta al mismo repositorio GitHub pero con comandos de inicio diferentes.

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

MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=              # tu Gmail
MAIL_PASSWORD=              # App Password de 16 chars
MAIL_FROM_ADDRESS=          # igual que MAIL_USERNAME
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
```

---

## Paso 4 — Ejecutar migraciones (primer deploy)

Después del primer deploy exitoso del servicio web, abrir la terminal del servicio en Railway y ejecutar:

```bash
php artisan migrate --force
php artisan db:seed
```

> Railway permite ejecutar comandos desde el dashboard: servicio → pestaña **Shell**.

---

## Paso 5 — Crear servicio Queue Worker

1. Dentro del mismo proyecto Railway, clic en **+ New Service → GitHub Repo**
2. Conectar el mismo repo `Abel-Castill0/MOVA`
3. En **Settings → Deploy → Start Command**, reemplazar por:
   ```
   php artisan queue:work --sleep=3 --tries=3 --timeout=60 --max-time=3600
   ```
4. En **Settings → Deploy → Build Command**:
   ```
   composer install --no-dev --optimize-autoloader
   ```
5. Agregar las **mismas variables de entorno** que el servicio web (especialmente DB y QUEUE_CONNECTION)

---

## Paso 6 — Crear servicio Scheduler

### Por qué no usar Railway Cron

MOVA ejecuta `classmate:send-reminders` cada minuto (recordatorios 10 min antes de clase). Railway Cron tiene un **mínimo de 1 minuto** pero cada ejecución es un contenedor nuevo con cold start de ~10-30s. Para recordatorios puntuales, es preferible un proceso en loop continuo.

### Servicio scheduler (proceso persistente)

1. Crear un tercer servicio desde el mismo repo
2. **Start Command**:
   ```bash
   while true; do php artisan schedule:run --verbose --no-interaction; sleep 60; done
   ```
3. **Build Command**:
   ```
   composer install --no-dev --optimize-autoloader
   ```
4. Agregar las mismas variables de entorno que el servicio web

> Este proceso mantiene el scheduler corriendo sin cold starts. Monitorear en logs que no haya errores silenciosos.

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
php artisan serve --host=0.0.0.0 --port=$PORT

# Queue worker
php artisan queue:work --sleep=3 --tries=3 --timeout=60 --max-time=3600

# Scheduler (loop continuo)
while true; do php artisan schedule:run --verbose --no-interaction; sleep 60; done

# Migraciones (solo primera vez o al agregar migraciones nuevas)
php artisan migrate --force
```

---

## Variables pendientes de configurar manualmente en Railway

Las siguientes variables contienen secretos y **nunca deben estar en el repositorio**. Configurar exclusivamente en el dashboard de Railway:

| Variable | Dónde obtenerla |
|---|---|
| `APP_KEY` | `php artisan key:generate --show` |
| `MAIL_PASSWORD` | Gmail → myaccount.google.com/apppasswords |
| `ZOOM_ACCOUNT_ID` | marketplace.zoom.us → tu app Server-to-Server |
| `ZOOM_CLIENT_ID` | idem |
| `ZOOM_CLIENT_SECRET` | idem |
| `TWILIO_SID` | console.twilio.com |
| `TWILIO_AUTH_TOKEN` | console.twilio.com |
| `SENTRY_LARAVEL_DSN` | sentry.io → proyecto php-laravel → DSN |

---

## Riesgos antes del primer deploy

| Riesgo | Severidad | Mitigación |
|---|---|---|
| `APP_KEY` vacío en producción | Alta | Generar y pegar antes del deploy |
| `APP_DEBUG=true` accidentalmente | Alta | Verificar siempre antes del push |
| Migraciones sin `--force` | Media | Railway no pasa `--force` solo |
| `storage/` no tiene permisos de escritura | Media | `php artisan storage:link` en build |
| Queue worker sin supervisión | Media | Monitorear logs en Railway |
| Scheduler detenido silenciosamente | Media | Activar alertas en Sentry |
| `SENTRY_TRACES_SAMPLE_RATE=1.0` en prod | Baja | Usar 0.1 para no agotar cuota |

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
- [ ] Servicio worker creado y con variables
- [ ] Servicio scheduler creado y con variables
- [ ] Migraciones ejecutadas tras primer deploy
- [ ] `db:seed` ejecutado si se necesitan roles/admin inicial
