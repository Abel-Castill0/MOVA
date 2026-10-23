# MOVA - Plataforma de clases particulares en línea

Créditos para profesores: Inicio 5/S/10.00, Esencial 10/S/19.80, Impulso 15/S/29.40,
Avance 20/S/38.80 y Pro 30/S/57.60. Descuentos permanentes por volumen sobre base S/2.
Catálogo `/servicios`; precios autoritativos en `config/credits.php`. [Estado comercial, pruebas y limitaciones de Culqi](docs/culqi-commercial-review.md).
Publicar el catálogo no activa pagos: Culqi live continúa cerrado hasta superar los requisitos operativos y comerciales.

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
| Email | SMTP (Gmail) en producción; Gmail API soportada; staging usa `array` |
| Pagos de créditos | Mercado Pago (live acotado a la cuenta de prueba del titular; sin cobros a terceros) + recarga manual revisada. Culqi (API V2) está **implementado y probado en sandbox**, no activado en producción (`PAYMENT_PROVIDER` elige un proveedor; ver `docs/payments-culqi.md`) |
| Cola | Laravel Queue |
| Producción | **Azure Container Apps + Azure MySQL** sirviendo `https://movaeduca.me` (`infra/azure`) |
| Railway | **Legacy, no operable** (deployments fallidos desde julio de 2026) |

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

No ejecutes seeders ni migraciones contra producción fuera del procedimiento del Runbook.

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

Producción: **Azure Container Apps (`movap-web`, `movap-worker`, `movap-scheduler`) + Azure Database for MySQL**, modelada en `infra/azure` y operada con `docs/release/MOVA_PRODUCTION_RUNBOOK.md`. El estado vigente del lanzamiento está en `docs/release/MOVA_V1_COMPLETION_LEDGER.md` (fuente de verdad). Diagramas y flujos: `docs/architecture/`. Índice de toda la documentación: `docs/README.md`; punto de entrada para agentes de IA: `docs/AI_HANDOFF.md`.

- Despliegue **manual** (build por digest → Job de migración revisada → `apps.bicep` → `/healthz` y `/readyz`); no hay workflow de deploy. Las migraciones **no** corren al arrancar.
- Rollback: `az containerapp revision activate` sobre la revisión anterior.
- Staging (`mova-*`) está a 0 réplicas y sin base de datos para conservar el crédito de Azure.

### Railway (legacy)

Railway ya no es producción ni rollback: sus deployments fallan desde julio de 2026. Se conservan `railway.toml`, `railway.queue.toml`, `railway.scheduler.toml` y `docs/DEPLOY_RAILWAY.md` solo como contexto histórico. No ejecutes deploys en Railway.

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
- Commit, push y deploy: solo dentro de la autorización vigente de `AGENTS.md` y con cambios validados.
- No ejecutar Playwright contra producción sin autorización.
- IA, WhatsApp y liquidaciones reales permanecen apagados; pagos live solo para la cuenta de prueba del titular (ver `AGENTS.md`).
- Para dinero, créditos y recargas usar transacciones, locks y ledger de auditoría.
