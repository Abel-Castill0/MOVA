# MOVA — Estado final

MOVA conecta padres y profesores para clases particulares online en Perú, con un sistema de créditos prepagos, diagnóstico inicial del alumno, salas de videollamada integradas y reseñas.

**Stack:** Laravel 10 + Vue 3 (Inertia) + Tailwind, MySQL, Jitsi Meet para videollamadas, Cloudinary para imágenes.

**Tests:** 74/74 (274 assertions) + 2/2 E2E con Playwright.

**Para desplegar:** subir a Railway / Fly.io / Oracle Cloud (ver `docs/HANDOFF_FINAL.md` y `docs/DEPLOY_RAILWAY.md`), correr `php artisan migrate --force`, `php artisan db:seed --class=ProductionSeeder`, y dejar corriendo `queue:work` + el scheduler (`schedule:run` cada minuto, vía cron o supervisor).

**Credenciales a configurar:** base de datos, `APP_KEY`, `ADMIN_EMAIL`/`ADMIN_PASSWORD`, credenciales de Cloudinary, Google OAuth (opcional), `RECHARGE_PAYMENT_DESTINATION` (Yape/Plin real) — ver `.env.example` para la lista completa.

**Documentación completa:** `docs/HANDOFF_FINAL.md` (estado, pendientes, deploy paso a paso, variables de entorno, comandos, usuarios de prueba).
