# MOVA — Handoff Final

**Fecha:** 26 de julio de 2026
**Estado:** listo para desplegar en un entorno de staging/producción real, pendiente de credenciales de terceros y revisión legal (ver abajo).

Este documento es el punto de partida para quien retome el proyecto — dev, otro agente, o el propio equipo tras una pausa. No repite el detalle de auditorías anteriores en `docs/` (algunas datan de junio 2026 y ya no reflejan el estado actual); este es el resumen vigente.

---

## 1. Estado actual del proyecto

### Verificación técnica (a la fecha de este documento)

| Check | Resultado |
|---|---|
| `php artisan test` | **74/74** ✅ |
| `npx playwright test --config=qa/playwright.local.config.js` | **2/2** ✅ |
| `npm run build` | limpio, sin errores ✅ |
| Secretos hardcodeados en código versionado | ninguno encontrado ✅ |
| Rate limiting en rutas financieras | completo (ver §Auditoría de seguridad) ✅ |
| `jitsi_room`/`jitsi_password` ocultos en listados | ✅ (`Lesson::$hidden`) |

### Features completadas

**Flujo crítico (padre ↔ profesor ↔ clase), probado de punta a punta:**
Solicitud de clase → profesor acepta y agenda → padre confirma pago (offline, Yape/Plin) → profesor sube reporte pedagógico → padre califica → clase pasa a `completed` → crédito se consume del ledger. Automatizado en `qa/tests/flujo-completo.spec.js` (Playwright), corre de forma idempotente contra `LocalTestDataSeeder`.

**Videollamadas (Jitsi Meet):**
Reemplazó una integración de Zoom que nunca llegó a completarse (solo quedaban textos residuales, ya corregidos en todo el frontend). Sala + password se generan al agendar. Endpoint dedicado `GET /lessons/{id}/join` valida autorización (dueño del alumno o profesor asignado, vía `LessonPolicy::view()`) y estado de la clase antes de revelar las credenciales — `jitsi_room`/`jitsi_password` nunca viajan en las respuestas de listado (`Lesson::$hidden`).

**Sistema de créditos:**
1 crédito = 1 clase dictada (ajustado desde 2 créditos por decisión del dueño del producto). Ledger append-only (`credit_transactions`: `reservation` → `consumption`/`refund`, más `deposit` por recargas aprobadas), con `idempotency_key` único e integridad cubierta por `tests/Feature/MonetizationIntegrityTest.php`. Recargas manuales vía Yape/Plin: el profesor registra su número de operación, un admin aprueba/rechaza — sin pasarela de pago automatizada (ver §2).

**Diseño premium (Spatial UI):**
Dashboard del padre y del profesor, páginas secundarias (`Students`, `ClassRequests`, `Lessons`, `ClassOffers`, `Teacher/Credits`), landing (`Welcome.vue`) y páginas legales, todas alineadas al mismo lenguaje visual: paleta `brand` (azul), `rounded-2xl`, sombras con tinte de marca, tipografía Inter, animaciones GSAP con guard de `prefers-reduced-motion`.

**Landing con datos reales:**
`WelcomeController` ya conectaba `subjects`/`featuredTeachers`/`stats` a la BD; se completó con `avg_rating` real por profesor (badge "Nuevo en MOVA" si aún no tiene reseñas, en vez de 5★ falsas) y testimonios reales desde `teacher_reviews` (filtrando ruido de corridas E2E locales) en lugar de los 3 testimonios inventados que había antes.

**Autenticación con Google (Socialite):**
`GET /auth/google` → `GET /auth/google/callback`. Vincula por email (`firstOrCreate`): si el correo de Google ya existe en `users`, entra directo a esa cuenta; si no, crea una nueva con rol `parent` por defecto. Requiere `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET` reales para funcionar (ver §4) — sin ellas, el botón es visible pero Google rechaza el intento; no bloquea el resto de la plataforma.

**Fotos de perfil (Cloudinary con fallback):**
`CloudinaryService` sube a Cloudinary si `CLOUDINARY_URL` está configurada; si no, guarda en disco local (`storage/app/public`, servido vía `php artisan storage:link`) sin romper la función. `users.avatar_url` guarda la URL resultante sea cual sea el origen.

**Páginas legales (Términos + Privacidad):**
Rediseñadas al estándar premium. Incluyen cláusula de resolución de disputas (arbitraje **opcional**, nunca impuesto a padres/consumidores — evita el riesgo de INDECOPI que tendría una cláusula obligatoria) y una "Política de Retiro de Contenido" (equivalente peruano de un Safe Harbor, citando el Decreto Legislativo 822 en vez de la ley DMCA de EE.UU., que no aplica aquí). **Importante:** este contenido partió de `docs/LEGAL_REQUIREMENTS_DRAFT.md`, que advierte explícitamente que no es un documento legal válido sin revisión de un abogado colegiado en Perú — eso sigue pendiente. No se declara uso de Meta Pixel ni Google Analytics porque, verificado por grep sobre el código, ninguno de los dos está integrado.

### Deuda técnica resuelta en esta ronda

- Textos de Zoom residuales en 9 archivos Vue (incluida la Política de Privacidad, que declaraba Zoom como proveedor de datos siendo falso).
- Costo de crédito desalineado con la decisión de negocio (2 → 1), incluida una constante duplicada en `AdminController` que podía desincronizarse silenciosamente de `Lesson::CLASS_CREDIT_COST_PER_CLASS`.
- `jitsi_room`/`jitsi_password` expuestos en texto plano en cualquier listado de clases, sin importar si la clase era hoy o en 5 días.
- Dos rutas de cancelación de clase (`lessons.cancel`, `admin.lessons.cancel`) que reembolsan créditos reales sin rate limiting — corregido en esta misma sesión (ver §Auditoría de seguridad).
- Inconsistencia visual entre el dashboard del padre/profesor (ya premium) y sus páginas secundarias (estilo antiguo `indigo`/`gray`/`rounded-xl`).

---

## 2. Lo que NO está implementado (y por qué)

| Feature | Estado | Motivo |
|---|---|---|
| **MercadoPago / pasarela de pago automatizada** | No implementado — por diseño | MOVA nunca cobra en línea: el padre paga al profesor directamente por Yape/Plin, fuera de la plataforma, y confirma con un botón ("Ya pagué"). Integrar una pasarela sería una decisión de negocio nueva (KYC, comisiones, custodia de fondos de terceros), no deuda técnica pendiente. |
| **WhatsApp en modo producción** | Implementado, pero apagado (`WHATSAPP_ENABLED=false`) | El canal funciona técnicamente vía Twilio pero sigue en **sandbox** — requiere que Twilio apruebe la cuenta de WhatsApp Business API, un trámite externo con el proveedor, no código. Email + notificaciones in-app son los canales principales y ya cubren el flujo completo sin WhatsApp. Detalle: `docs/WHATSAPP_PRODUCTION_NOTES.md`. |
| **Scheduler como proceso corriendo en producción** | Código implementado, no desplegado como servicio activo | `SendClassReminders` (recordatorios 24h/2h/10min, alertas de reporte pendiente y solicitud sin responder) y el `Kernel::schedule()` que lo registra `everyMinute()` ya existen y están probados localmente (`docs/SCHEDULER_LOCAL.md`). Lo que falta es un proceso siempre activo que llame `php artisan schedule:run` cada minuto en el hosting elegido — un cron real (Oracle Cloud), un servicio dedicado (`mova-scheduler` en Railway, ya documentado en `docs/DEPLOY_RAILWAY.md`), o un proceso `[processes]` en Fly.io. Es un paso de infraestructura de despliegue, no una feature pendiente de código. |

---

## 3. Desplegar en producción

### Railway (documentado en detalle)

Ver **`docs/DEPLOY_RAILWAY.md`** — guía completa ya existente con arquitectura de 3 servicios (`mova-web`, `mova-queue`, `mova-scheduler`), MySQL como plugin nativo, variables de entorno y troubleshooting. Es la opción con menos fricción: Railway detecta PHP automáticamente vía Nixpacks (sin Dockerfile) y el repo ya trae `railway.toml`, `railway.queue.toml`, `railway.scheduler.toml`.

### Fly.io

Fly.io no autodetecta Laravel/PHP como Railway — necesita un `Dockerfile` (usar una imagen `php:8.1-fpm` + nginx, o adaptar el template oficial `fly launch --from laravel`, que genera uno funcional). Pasos:

```bash
# 1. Instalar flyctl y autenticarse
fly auth login

# 2. Generar Dockerfile + fly.toml (usa el template Laravel oficial)
fly launch --from laravel
#    - Elegir región (gru = São Paulo, la más cercana a Perú entre las disponibles)
#    - Aceptar crear una base de datos Postgres SI se migra el driver a pgsql,
#      o declinar y usar un MySQL externo (Fly no tiene MySQL administrado nativo;
#      opciones: PlanetScale, o una app MySQL propia en Fly con volumen persistente)

# 3. Configurar variables sensibles (nunca en fly.toml, que sí se versiona)
fly secrets set APP_KEY=$(php artisan key:generate --show) \
  DB_HOST=... DB_DATABASE=... DB_USERNAME=... DB_PASSWORD=... \
  GOOGLE_CLIENT_ID=... GOOGLE_CLIENT_SECRET=... CLOUDINARY_URL=...

# 4. Deploy
fly deploy

# 5. Migrar (una sola vez, y en cada deploy con cambios de esquema)
fly ssh console -C "php artisan migrate --force"
fly ssh console -C "php artisan db:seed --class=ProductionSeeder --force"
```

Para el worker de colas y el scheduler, Fly.io usa **grupos de proceso** en `fly.toml`:

```toml
[processes]
  web       = "php-fpm"
  queue     = "php artisan queue:work --sleep=3 --tries=3 --max-time=3600"
  scheduler = "sh -c 'while true; do php artisan schedule:run; sleep 60; done'"
```

Cada grupo se escala independientemente (`fly scale count queue=1 scheduler=1`).

### Oracle Cloud (Always Free tier)

Sin plataforma de despliegue declarativa: es una VM tradicional, más control pero más pasos manuales.

```bash
# 1. Provisionar una instancia Compute "Always Free" (Ampere A1, 4 OCPU / 24GB
#    es el tier gratuito más generoso; Ubuntu 22.04 recomendado)

# 2. En la instancia:
sudo apt update && sudo apt install -y php8.1-fpm php8.1-mysql php8.1-mbstring \
  php8.1-xml php8.1-curl php8.1-gd nginx mysql-server composer nodejs npm

# 3. Clonar el repo, instalar dependencias
git clone <repo> /var/www/mova && cd /var/www/mova
composer install --optimize-autoloader --no-dev
npm ci && npm run build
cp .env.example .env   # completar valores reales
php artisan key:generate
php artisan storage:link
php artisan migrate --force
php artisan db:seed --class=ProductionSeeder --force

# 4. nginx: apuntar server_block a /var/www/mova/public, con el bloque PHP-FPM
#    estándar de Laravel (fastcgi_pass a php8.1-fpm.sock)

# 5. Cola y scheduler como servicios permanentes (aquí SÍ aplica cron real,
#    a diferencia de Railway/Fly que usan procesos declarativos):
sudo apt install -y supervisor
#    → configurar /etc/supervisor/conf.d/mova-queue.conf para `queue:work`

crontab -e
#    agregar: * * * * * cd /var/www/mova && php artisan schedule:run >> /dev/null 2>&1
```

**Nota común a las 3 opciones:** el disco local para avatares (fallback sin Cloudinary) NO persiste entre deploys en Railway/Fly.io a menos que se monte un volumen — para producción real se recomienda configurar `CLOUDINARY_URL` desde el día uno en esas plataformas. En Oracle Cloud (VM persistente) el fallback local sí es viable a largo plazo.

---

## 4. Variables de entorno necesarias

`.env.example` ya las documenta todas con comentarios explicando dónde obtener cada credencial. Resumen de las que **requieren acción humana** antes de producción real (todas están vacías por diseño):

| Variable | Para qué | Bloqueante si falta |
|---|---|---|
| `APP_KEY` | Cifrado de sesión/cookies | Sí — generar con `php artisan key:generate --show` |
| `DB_*` | Conexión MySQL | Sí |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | Cuenta admin inicial (`ProductionSeeder`) | Sí, para tener un admin desde el día uno |
| `GMAIL_CLIENT_ID/SECRET/REFRESH_TOKEN` o `RESEND_API_KEY` | Envío de correos transaccionales | Sí — sin esto no llegan verificaciones ni notificaciones |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | "Continuar con Google" | No — sin esto el login normal funciona igual, solo falla el botón de Google |
| `CLOUDINARY_URL` | Fotos de perfil en CDN | No — cae a almacenamiento local automáticamente |
| `TWILIO_*` | WhatsApp (sandbox) | No — canal opcional, apagado por defecto |
| `PUSHER_*` | Notificaciones en tiempo real (Echo) | Recomendado, no bloqueante |
| `SENTRY_LARAVEL_DSN` | Monitoreo de errores | No, pero muy recomendado antes de usuarios reales |
| `DIAGNOSTIC_AI_ENABLED` + `OPENAI_API_KEY`/`GEMINI_API_KEY` | Enriquecimiento IA del diagnóstico | No — feature opcional, mantener en `false` hasta validar con key de prueba |
| `RECHARGES_ENABLED` + `RECHARGE_PAYMENT_DESTINATION` | Activa la UI de recarga de créditos | No — mantener `false` hasta tener un destino de pago (Yape/Plin) real y verificado |

---

## 5. Comandos importantes

```bash
# Setup inicial en un entorno nuevo
composer install --optimize-autoloader --no-dev
npm ci && npm run build
php artisan key:generate
php artisan storage:link          # necesario para el fallback local de avatares

# Base de datos
php artisan migrate --force                          # producción
php artisan db:seed --class=ProductionSeeder --force  # crea roles, materias, admin
php artisan db:seed --class=LocalTestDataSeeder        # SOLO local/dev — datos de prueba, nunca en producción

# Cola de trabajos (emails, notificaciones)
php artisan queue:work --sleep=3 --tries=3 --timeout=60 --max-time=3600

# Scheduler — recordatorios de clase (ver docs/SCHEDULER_LOCAL.md para el detalle local)
php artisan schedule:run     # dispara los jobs que estén "due" en este minuto exacto
php artisan schedule:work    # loop de desarrollo — llama a schedule:run cada 60s por ti
php artisan schedule:list    # ver próxima ejecución de cada job registrado

# Tests
php artisan test                                                    # 74 tests
npx playwright test --config=qa/playwright.local.config.js          # 2 tests E2E (requiere server + seed local corriendo)

# Build de producción
npm run build
```

---

## 6. Usuarios de prueba

Creados por `LocalTestDataSeeder` — **solo existen en local**, `ProductionSeeder` no los crea.

| Rol | Email | Password |
|---|---|---|
| Padre | `padre@mova.test` | `password123` |
| Profesor | `profesor@mova.test` | `password123` |

El padre tiene 2 hijos (Mateo, Valentina), varias solicitudes/clases en distintos estados, y 2 clases completadas con reseña — suficiente para navegar el flujo completo sin tener que crear datos desde cero. El profesor está verificado y tiene crédito disponible.

Para sembrar este set de datos en un entorno local nuevo:
```bash
php artisan migrate:fresh
php artisan db:seed --class=LocalTestDataSeeder
```

---

## 7. Pendientes antes de operar con usuarios reales (no bloqueantes para desplegar en staging)

1. **Revisión legal profesional** de Términos y Privacidad (ver §1) — obligatorio antes de que un usuario real acepte estos términos, dado que MOVA maneja datos de menores.
2. Credenciales reales de Google OAuth2, Cloudinary, Gmail/Resend, Sentry.
3. Decidir `RECHARGE_PAYMENT_DESTINATION` real (número de Yape/Plin de MOVA) antes de `RECHARGES_ENABLED=true`.
4. `qa/node_modules/` está versionado en git por un `.gitignore` que solo excluye `/node_modules` en la raíz, no rutas anidadas — no es una fuga de secretos (se verificó), pero conviene limpiarlo (`git rm -r --cached qa/node_modules` + agregar `node_modules` a `qa/.gitignore` o al `.gitignore` raíz sin el `/` inicial) antes de que el repo siga creciendo.
