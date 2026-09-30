# MOVA - Plataforma de clases particulares en línea

MOVA conecta familias con profesores particulares para clases en vivo por videollamada. Los padres registran alumnos y crean solicitudes de clase (abiertas a los profesores verificados de la materia, o dirigidas a un profesor con su código); el profesor acepta o propone otro horario usando créditos MOVA.

## Stack Tecnológico

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.3+ · Laravel 13 |
| Frontend | Vue 3 · Inertia.js |
| Estilos | Tailwind CSS |
| Bundler | Vite 5 |
| Base de datos | MySQL 8.4 (Azure Database for MySQL – Flexible Server) |
| Autenticación | Laravel Breeze |
| Roles | Spatie Laravel Permission |
| Videollamadas | JaaS (8x8 / Jitsi) con JWT por sala |
| Email | Proveedor según `MAIL_MAILER`; Gmail API documentada para la producción actual, Azure staging usa `array` |
| Pagos de créditos | Mercado Pago (proveedor automático previsto, apagado por flags) + recarga manual revisada |
| Cola | Laravel Queue |
| Producción actual | Railway hasta el cutover de dominio |
| Destino de producción | Azure Container Apps + Azure MySQL (`infra/azure`) |

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
- IA, WhatsApp, JaaS, Gmail API, OpenAI, Gemini, Mercado Pago y Sentry desactivados o sin credenciales en testing.

Con PHP local < 8.3, correr PHPUnit dentro de `php_qa` (`docker-compose.qa.yml`); ver `docs/release/MOVA_V1_STATE.md`.

Comandos disponibles:

```bash
composer qa:safe
npm run qa:build
npm run qa:frontend
npm run qa:production-readonly
```

`composer qa:safe` ejecuta `composer validate`, tests Laravel aislados, build frontend y `git diff --check`.

`npm run qa:frontend` ejecuta build y solo corre Playwright si `PLAYWRIGHT_BASE_URL` apunta a un servidor local.

`npm run qa:production-readonly` consulta endpoints públicos con GET contra `MOVA_PRODUCTION_URL` (obligatoria). No envía formularios ni modifica datos.

## Infraestructura

Destino de producción: **Azure Container Apps + Azure Database for MySQL**, modelado en `infra/azure` (ver su README). Estado de lanzamiento y gates pendientes: `docs/release/MOVA_V1_COMPLETION_LEDGER.md`.

### Railway (producción actual / rollback)

Railway sigue como producción actual y rollback hasta el cutover de dominio (C-P1-DOMAIN-CUTOVER). El repositorio mantiene su configuración:

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
