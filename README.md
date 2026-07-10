# MOVA - Plataforma de clases particulares en línea

MOVA conecta familias con profesores particulares para clases en vivo por videollamada. Los padres registran alumnos, buscan profesores en el marketplace, solicitan clases y reciben confirmaciones mediante los canales configurados por entorno.

## Stack Tecnológico

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.1+ · Laravel 10 |
| Frontend | Vue 3 · Inertia.js |
| Estilos | Tailwind CSS |
| Bundler | Vite 5 |
| Base de datos | MySQL |
| Autenticación | Laravel Breeze |
| Roles | Spatie Laravel Permission |
| Videollamadas | Zoom API, según variables de entorno |
| Email | Proveedor definido por entorno |
| Cola | Laravel Queue |
| Producción | Railway |

## Rama De Producción

La rama de publicación es `master`.

## Instalación Local

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
```

No ejecutes seeders, migraciones contra producción ni cambios de variables sin autorización explícita.

## Ejecución Local

```bash
php artisan serve --port=8001
npm run dev
php artisan queue:work --sleep=3 --tries=3 --timeout=60
php artisan schedule:work
```

## QA Seguro

La suite Laravel segura debe usar SQLite en memoria. `phpunit.xml` fuerza:

- `APP_ENV=testing`
- `DB_CONNECTION=sqlite`
- `DB_DATABASE=:memory:`
- `CACHE_DRIVER=array`
- `SESSION_DRIVER=array`
- `QUEUE_CONNECTION=sync`
- `MAIL_MAILER=array`
- IA, WhatsApp, Zoom, Gmail API, OpenAI, Gemini y Sentry desactivados o sin credenciales en testing.

Comandos disponibles:

```bash
composer qa:safe
npm run qa:build
npm run qa:frontend
npm run qa:production-readonly
```

`composer qa:safe` ejecuta `composer validate`, tests Laravel aislados, build frontend y `git diff --check`.

`npm run qa:frontend` ejecuta build y solo corre Playwright si `PLAYWRIGHT_BASE_URL` apunta a un servidor local.

`npm run qa:production-readonly` consulta endpoints públicos con GET. No envía formularios ni modifica datos.

## Railway

El repositorio tiene configuración Railway:

- `railway.toml`: servicio web principal.
- `railway.queue.toml`: worker de cola.
- `railway.scheduler.toml`: scheduler.

El servicio web usa `/healthz` como healthcheck. El arranque de Railway ejecuta `php artisan migrate --force`; por eso todo deploy debe revisar migraciones antes de publicarse.

No cambies variables Railway ni despliegues sin autorización explícita.

## Estructura Principal

```text
app/Http/Controllers
app/Models
app/Notifications
app/Services
database/migrations
resources/js/Pages
routes/web.php
tests
qa/tests
```

## Seguridad Operativa

- No imprimir secretos ni valores de `.env`.
- No modificar `.env` real sin autorización.
- No hacer commit, push ni deploy sin autorización.
- No ejecutar Playwright contra producción sin autorización.
- No activar IA, WhatsApp, pagos ni correo masivo sin autorización.
- Para dinero, créditos y recargas usar transacciones, locks y ledger de auditoría.
