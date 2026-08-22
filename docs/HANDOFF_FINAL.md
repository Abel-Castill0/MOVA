# MOVA — Handoff Final

**Fecha:** 27 de julio de 2026
**Estado:** listo para desplegar en un entorno de staging/producción real, pendiente de credenciales de terceros y revisión legal (ver abajo).

Este documento es el punto de partida para quien retome el proyecto — dev, otro agente, o el propio equipo tras una pausa. No repite el detalle de auditorías anteriores en `docs/` (algunas datan de junio 2026 y ya no reflejan el estado actual); este es el resumen vigente.

---

## ⚠️ Acción de seguridad pendiente antes de desplegar

Los specs `qa/tests/16` al `25` (eliminados en esta sesión, ver §8) tenían
hardcodeada la **contraseña real de admin de producción** (redactada — ver
`git log` de commits previos a esta corrección si necesitas confirmarla),
para la cuenta `abelcastillotrabajo@gmail.com`, como valor por defecto,
apuntando a `mova-production-8750.up.railway.app`. Esa contraseña sigue
en el historial de git en texto plano (los specs eliminados **y** una
versión previa de este mismo documento la citaban directamente) aunque
los archivos ya no existan en el working tree.

**Antes de desplegar o de que esa cuenta vuelva a operar en producción:**
1. Cambiar `ADMIN_PASSWORD` en el `.env` real de producción (Railway/Fly/Oracle) por una contraseña nueva.
2. Iniciar sesión con la cuenta admin y confirmar el cambio.
3. Si esa contraseña se reutiliza en cualquier otro servicio (Gmail, etc.), rotarla ahí también.

En local ya se rotó (ver `.env` → `ADMIN_PASSWORD`, generada para esta sesión y aplicada a `admin@mova.test` en la BD local vía `ProductionSeeder` + actualización directa del hash).

---

## 1. Estado actual del proyecto

### Verificación técnica (a la fecha de este documento)

| Check | Resultado |
|---|---|
| `php artisan test` | **150/150** ✅ (ver §17 para el detalle de C-1, 2026-08-21) |
| `npx playwright test --config=playwright.local.config.js` (desde `qa/`) | **2/2** ✅ |
| `npm run build` | limpio, sin errores ✅ |
| Secretos hardcodeados en código versionado | ninguno encontrado en `app/`/`resources/`; sí en 10 specs QA ya eliminados (ver advertencia arriba) |
| Rate limiting en rutas financieras | completo (ver §Auditoría de seguridad) ✅ |
| `jitsi_room`/`jitsi_password` ocultos en listados | ✅ (`Lesson::$hidden`) |
| Policies cubriendo autorización | `LessonPolicy`, `ClassRequestPolicy`, `TeacherReviewPolicy`, `RechargeRequestPolicy`, `ClassOfferPolicy`, `StudentPolicy`, `StudentDiagnosticPolicy` — 0 `abort_unless` de ownership sueltos en controladores |

### Features completadas

**Flujo crítico (padre ↔ profesor ↔ clase), probado de punta a punta:**
Solicitud de clase → profesor acepta y agenda → padre confirma pago (offline, Yape/Plin) → profesor sube reporte pedagógico → padre califica → clase pasa a `completed` → crédito se consume del ledger. Automatizado en `qa/tests/flujo-completo.spec.js` (Playwright), corre de forma idempotente contra `LocalTestDataSeeder`.

**Videollamadas (Jitsi Meet):**
Reemplazó una integración de Zoom que nunca llegó a completarse (solo quedaban textos residuales, ya corregidos en todo el frontend). Sala + password se generan al agendar. Endpoint dedicado `GET /lessons/{id}/join` valida autorización (dueño del alumno o profesor asignado, vía `LessonPolicy::view()`) y estado de la clase antes de revelar las credenciales — `jitsi_room`/`jitsi_password` nunca viajan en las respuestas de listado (`Lesson::$hidden`).

**Sistema de créditos:**
Cobro por hora (o fracción): 1 crédito por cada hora iniciada de clase, mínimo 1 crédito (una clase de 30 min cuesta lo mismo que una de 60 min). `Lesson::creditCostForMinutes()` centraliza el cálculo: `ceil(duration_minutes / 60) × cost_per_hour` (`cost_per_hour` en `config/credits.php`, actualmente 1). El pago congelado del profesor (`price_frozen_pen`) usa la misma base redondeada — ej. una clase de 2h30 con tarifa S/20/h cuesta 3 créditos y paga S/60, no S/50 (no se prorratea la fracción). Reservas, devoluciones y consumos usan siempre el monto reservado en el ledger (`Lesson::reservedCreditAmount()`), no un recálculo desde `duration_minutes` — así una reprogramación que cambie la duración no descuadra un refund posterior. Ledger append-only (`credit_transactions`: `reservation` → `consumption`/`refund`, más `deposit` por recargas aprobadas), con `idempotency_key` único e integridad cubierta por `tests/Feature/MonetizationIntegrityTest.php`. Recargas manuales vía Yape/Plin: el profesor registra su número de operación, un admin aprueba/rechaza — sin pasarela de pago automatizada (ver §2).

⚠️ **Gap conocido, no bloqueante:** `LessonController::reschedule()` permite cambiar `duration_minutes` sin ajustar `credits_reserved` — hoy es inofensivo porque el refund/consumo usa el monto original del ledger, pero un profesor podría reprogramar una clase de 30 min (1 crédito reservado) a 4h sin pagar créditos extra. No se corrigió en esta ronda porque la solución correcta (¿bloquear el cambio de duración al reprogramar? ¿cobrar la diferencia?) es una decisión de producto, no un bug de implementación.

**Diseño premium (Spatial UI):**
Dashboard del padre y del profesor, páginas secundarias (`Students`, `ClassRequests`, `Lessons`, `ClassOffers`, `Teacher/Credits`), landing (`Welcome.vue`) y páginas legales, todas alineadas al mismo lenguaje visual: paleta `brand` (azul), `rounded-2xl`, sombras con tinte de marca, tipografía Plus Jakarta Sans, animaciones GSAP con guard de `prefers-reduced-motion`.

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

### Fix: 403 "Acceso no permitido" al verificar correo con otra cuenta abierta (02 ago 2026)

**Reporte real:** un usuario se registró con `abelcastillotrabajo@gmail.com`, recibió el correo de verificación, y al hacer clic obtuvo un 403. Se investigó a fondo antes de tocar nada — `APP_URL` ya estaba correcto (`http://localhost:8000`, coincidía con el puerto real), así que **no era un problema de configuración**. Causa real: `Illuminate\Foundation\Auth\EmailVerificationRequest::authorize()` de Laravel devuelve `false` (→ 403 genérico) cuando el `{id}` del enlace no coincide con el usuario autenticado en esa sesión del navegador — típico cuando alguien tiene otra cuenta abierta al hacer clic en su correo. Reproducido de forma controlada (generando el mismo enlace firmado que el correo real contendría, y visitándolo mientras había otra cuenta logueada) antes de escribir cualquier fix.

**Fix:** `app/Http/Requests/Auth/VerifyEmailRequest.php` (nuevo) extiende el `EmailVerificationRequest` de Laravel y sobreescribe `failedAuthorization()` — en vez de lanzar el 403 sin explicación, cierra la sesión equivocada y redirige a `/login` con un mensaje claro ("Ese enlace de verificación es de otra cuenta..."). `VerifyEmailController` ahora usa esta clase. Verificado en vivo de punta a punta: usuario A logueado + enlace de usuario B → logout + mensaje en login (no 403); luego login como B + mismo enlace → verificación exitosa. Test de regresión en `tests/Feature/Auth/EmailVerificationTest.php`. Es el único route con middleware `signed` en toda la app — no hay otro punto con este mismo riesgo.

### Correo remitente cambiado a cuenta dedicada (01 ago 2026)

El remitente de los correos transaccionales de MOVA (verificación, notificaciones de clase, etc.) pasó de la cuenta personal `abelwuarthon3@gmail.com` a la cuenta dedicada **`m0v4class@gmail.com`**, con `MAIL_FROM_NAME="MOVA"` (antes `"Equipo MOVA"`). Alcance del cambio:

- `.env`: `MAIL_USERNAME`/`MAIL_FROM_ADDRESS`/`GMAIL_FROM_ADDRESS` actualizados. De paso se eliminó un bloque `MAIL_*` duplicado que había quedado suelto al final del archivo — `phpdotenv` resolvía silenciosamente la última definición, así que no cambiaba el comportamiento, pero confundía a quien leyera el archivo. Ahora hay un solo bloque, sin duplicados.
- `qa/check-gmail-inbox.mjs`: el filtro `from:(...)` del script que verifica que los correos llegaron se actualizó a la nueva dirección.
- `docs/DEPLOY_RAILWAY.md`: ejemplo de `GMAIL_FROM_ADDRESS` actualizado.
- **Deliberadamente NO se tocó:** `qa/.env.qa` (`QA_ADMIN_EMAIL`) y las direcciones `abelwuarthon3+...@gmail.com` en `qa/end-to-end-welcome-email.mjs`/`qa/search-verify-emails.mjs`. Esas son la cuenta de **login de admin en producción** y el **buzón de prueba** que reciben los correos (no el remitente). Tampoco se tocó `abelcastillotrabajo@gmail.com` (correo de soporte/contacto real, mostrado en Terms/Privacy/footer) — es un correo distinto y a propósito.
- **Actualización 02 ago 2026 — remitente confirmado con evidencia directa:** se regeneró `GMAIL_REFRESH_TOKEN`/`GMAIL_READONLY_REFRESH_TOKEN` (ahora autorizados contra `m0v4class@gmail.com`, ver §8) y se leyó el header `From` real de un correo de verificación recién enviado vía la API de Gmail: `"From":"MOVA <m0v4class@gmail.com>"`. Confirmado a nivel de bytes, no inferido de la config. Hallazgo menor sin resolver: el asunto de ese correo (`Illuminate\Auth\Notifications\VerifyEmail`, el stock de Laravel) sigue en inglés ("Verify Email Address") pese a que el resto de la plataforma está en español — no se tocó por estar fuera del alcance de esta tarea.

### Bugs encontrados y corregidos en la sesión de auditoría en browser (27 jul 2026)

- **Precios en euros (€)** en vez de soles (S/) en `Welcome.vue` y en el formulario de tarifa del profesor.
- **Botón "Ya pagué" visible para clases futuras**: el backend siempre exigió que la clase ya haya terminado para confirmar el pago, pero el botón se mostraba igual y el clic fallaba en silencio (sin mensaje de error). Causa raíz: `Lesson::end_time` es un accessor que nunca estaba en `$appends`, así que el frontend no podía saber si la clase había terminado. Corregido en ambos frentes.
- **Admin > Solicitudes** mostraba "Sin asignar" para solicitudes ya aceptadas cuando venían del flujo de solicitud genérica (sin `class_offer`) — solo miraba esa relación y no la `lesson` asociada.
- **Disponibilidad horaria** del alumno mostrada en inglés crudo ("morning weekday") en la bandeja del profesor.
- **Marketplace vacío en local**: `LocalTestDataSeeder` nunca creaba `ClassOffer`, así que ningún profesor aparecía pese a estar verificado y con materias asignadas.
- **Notificaciones mostrando el `type` crudo** ("class confirmed") en vez del mensaje en español ya generado por cada clase de notificación.
- **Ownership checks sin Policy**: `ClassOfferController`, `StudentController`, `DiagnosticsController` y el lado padre de `ClassRequestController` usaban `abort_unless` ad-hoc en vez del sistema de Policies ya establecido — se crearon `ClassOfferPolicy`, `StudentPolicy`, `StudentDiagnosticPolicy` y se agregó la habilidad `view()` a `ClassRequestPolicy`.
- **`Route::resource('students', ...)` registraba una ruta `show` para un método inexistente** en `StudentController` — 500 garantizado si alguien la visitaba. Excluida con `->except(['show'])`.
- **`qa/tests/` tenía 24 specs históricos** nombrados por fase de desarrollo (no la suite real, que es solo `flujo-completo.spec.js`), rompían el comando documentado de Playwright y 10 de ellos tenían la contraseña real de admin de producción hardcodeada — eliminados (ver advertencia de seguridad arriba).
- Columna `zoom_meeting_id` residual eliminada de la tabla `classes` y de `Lesson::$fillable`.
- `ADMIN_NAME` usaba `env()` fuera de `config/`, inconsistente con `ADMIN_EMAIL`/`ADMIN_PASSWORD` que ya habían sido migrados a `config('app.*')` — alineado.

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
php artisan test                                                    # 78 tests
cd qa && npx playwright test --config=playwright.local.config.js && cd ..   # 2 tests E2E (requiere server + seed local corriendo)
# Debe ejecutarse DESDE qa/ — la instalación de Playwright vive en qa/node_modules;
# invocarlo desde la raíz del repo falla con "No tests found" al no resolver el mismo paquete.

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
4. Si se configura un webhook de Resend (`resend.com/webhooks`), completar `RESEND_WEBHOOK_SECRET` en `.env` — sin esa variable el endpoint de webhook queda inactivo (no verifica firma), lo cual es seguro pero no procesa eventos de bounce/queja.

---

## 8. Herramientas de testing automatizado

Herramientas para verificar el sistema end-to-end sin depender de revisión manual (leer WhatsApp/correo a mano, etc.). Todas usan credenciales ya presentes en `.env` / `qa/.env.qa` — ninguna requiere instalar software adicional.

| Herramienta | Qué verifica | Estado | Comando |
|---|---|---|---|
| **Playwright E2E** (`qa/tests/flujo-completo.spec.js`) | Flujo completo solicitud→pago→reporte→reseña en el navegador real (Chromium propio de Playwright, sin depender de Chrome del sistema) | ✅ Funcional | `cd qa && npx playwright test --config=playwright.local.config.js` |
| **Playwright — modo visible** | Igual que arriba, pero con ventana de navegador visible (útil para depurar a mano en esta laptop; no aplica en un servidor sin pantalla) | ✅ Funcional | `cd qa && HEADFUL=1 npx playwright test --config=playwright.local.config.js` |
| **Gmail API (lectura de bandeja)** (`qa/check-gmail-inbox.mjs`) | Confirma que un correo transaccional (verificación, notificación) realmente llegó a la bandeja, buscando por remitente/asunto | ✅ Funcional — `GMAIL_READONLY_REFRESH_TOKEN` autorizado contra `m0v4class@gmail.com` (regenerado 2026-08-02). Si expira: `php artisan mova:gmail-auth-url` → autorizar en el navegador → `php artisan mova:gmail-exchange-code {codigo}` → copiar el token impreso a `.env` (`GMAIL_REFRESH_TOKEN`) y `qa/.env.qa` (`GMAIL_READONLY_REFRESH_TOKEN`) — **mismo token en ambos archivos**, no confundir con dejar uno desactualizado | `node qa/check-gmail-inbox.mjs` |
| **Twilio Messages API** | Lee el `status` real de un WhatsApp enviado (`queued`/`sent`/`delivered`/`failed`) y su `error_code` — más confiable que confiar en que la app no haya lanzado una excepción, porque Twilio puede aceptar el envío y fallar la entrega de forma asíncrona | ✅ Funcional, sin configuración adicional | `curl -s -u "$TWILIO_SID:$TWILIO_AUTH_TOKEN" "https://api.twilio.com/2010-04-01/Accounts/$TWILIO_SID/Messages.json?PageSize=5"` |
| **Enlace de verificación firmado** (sin script, vía `php artisan tinker`) | Permite completar el flujo de verificación de email en pruebas sin acceso a la bandeja real — genera el mismo enlace firmado que el correo contendría | ✅ Funcional | `URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)])` desde tinker |
| `mova:testing-backdate-lesson` | Retrocede `start_time` de una clase para poder probar `confirmPayment()` sin esperar a que termine de verdad | ✅ Funcional | `php artisan mova:testing-backdate-lesson {lesson_id}` |
| `mova:testing-verify-phone` | Marca un teléfono como verificado sin pasar por WhatsApp (mismo criterio que el bypass en pantalla de `PhoneVerificationController`) | ✅ Funcional | `php artisan mova:testing-verify-phone {user_id}` |

**Nota sobre error_code de Twilio:** los mensajes de verificación por WhatsApp en este entorno vienen consistentemente con `status: failed` y `error_code: 63015`. Vale la pena revisar ese código específico en la [documentación de errores de Twilio](https://www.twilio.com/docs/api/errors) antes de asumir que es un problema de sandbox — no se confirmó la causa raíz exacta en esta sesión.

---

## 9. Tarifa automática, materias dinámicas y acompañamiento continuo (2026-08-02)

### Tarifa por hora — ahora 100% automática

El profesor ya no fija su `hourly_rate`; se asigna solo al crear el perfil (S/20) y
se recalcula tras cada reseña, dentro de la misma transacción con
`lockForUpdate()` que ya usaba `TeacherReviewController::store()`:

| Nivel | Umbral | Tarifa |
|---|---|---|
| Base | por defecto | S/20 |
| Experto | ≥5 clases completadas Y calificación promedio ≥4.0 | S/25 |
| Élite | ≥20 clases completadas Y calificación promedio ≥4.5 | S/30 |

`TeacherProfile::maxAllowedRate()` es ahora la única fuente de verdad para este
cálculo — sigue usándose también para limitar `specific_rate` en
`ClassOfferController` (tope de precio por oferta individual, que sí sigue
siendo editable por el profesor). `Teacher/Edit.vue` y `Teacher/Setup.vue` ya
no tienen input de tarifa: muestran la tarifa actual + badge de nivel (Edit) o
un texto informativo fijo (Setup).

Cobertura de test: `MonetizationIntegrityTest::test_hourly_rate_auto_upgrades_through_tiers_as_reviews_come_in`
y `test_hourly_rate_stays_at_base_tier_without_enough_average_rating` ejercitan
el flujo real (`POST /lessons/{lesson}/review`), no solo el método aislado.

### Materias dinámicas con normalización

Los profesores ya podían escribir materias libres al registrarse, pero la
detección de duplicados solo comparaba dentro del mismo envío (`mb_strtolower`
en memoria) — dos profesores escribiendo "Física" y "fisica" en sesiones
distintas creaban dos filas. `App\Services\SubjectNormalizer` (minúsculas, sin
tildes, sin espacios repetidos) más la columna `subjects.normalized_name`
(única, migración `2026_08_02_000001`) cierran ese hueco:
`Subject::firstOrCreateByName()` es ahora el único punto de entrada para crear
materias por texto libre, usado en `RegisteredUserController` (registro) y
`TeacherProfileController::resolveSubjectIds()` (edición de perfil). El modelo
normaliza automáticamente en `saving()` como red de seguridad para cualquier
otro punto de creación futuro.

`SubjectSeeder` se redujo de 25 a 6 materias de ejemplo — el catálogo real lo
construyen los profesores. Marketplace y landing ya consultaban
`Subject::orderBy('name')->get()` en vivo, así que las materias nuevas
aparecen ahí sin cambios adicionales.

### Cupos de acompañamiento continuo — ocultos solo del lado del profesor

⚠️ **Corrección importante respecto al pedido original:** el acompañamiento
continuo **no es una funcionalidad sin implementar** — ya está vivo en
`Marketplace/Index.vue` (botón "Solicitar acompañamiento", visible cuando
`mentorship_slots_total > mentorship_slots_taken`) y en el ciclo de vida de
la clase (`LessonController::store()`/`cancel()` incrementan/decrementan
`mentorship_slots_taken` al aceptar/cancelar una solicitud marcada
`is_mentorship`). Lo único que se ocultó, tal como se pidió, es el **input
para que el profesor configure `mentorship_slots_total`/`taken`** en
`Teacher/Edit.vue` y `Teacher/Setup.vue` — los campos siguen en
`TeacherProfile::$fillable` y en la base de datos, y el backend sigue
leyéndolos y escribiéndolos con normalidad.

**Consecuencia práctica de ocultar el input:** cualquier profesor cuyo
`mentorship_slots_total` sea 0 — es decir, todo profesor que se registre de
aquí en adelante, porque ya no hay forma en la UI de subirlo de 0 — nunca
mostrará el botón "Solicitar acompañamiento" en el marketplace, porque
`hasAvailableMentorshipSlots()` siempre será `false`. Los profesores que ya
tenían un total mayor a 0 antes de este cambio no se ven afectados. Si se
quiere mantener el acompañamiento como una funcionalidad realmente
disponible para profesores nuevos, hace falta reintroducir el campo en la UI
(o fijar el total vía un flujo distinto, ej. aprobación de admin) — no se
hizo porque el pedido explícito de esta sesión fue ocultarlo, pero vale la
pena confirmarlo con el equipo antes de que el marketplace empiece a mostrar
menos ofertas de acompañamiento de las esperadas.

**Deliberadamente no se instaló un MCP de Playwright de terceros** (pedido en una sesión anterior): esta sesión de Claude Code ya tiene control de navegador completo vía sus herramientas nativas (`mcp__Claude_Browser__*` — navegar, clickear, leer consola/red, etc.), así que un MCP adicional sería redundante. Instalar paquetes de terceros no verificados desde GitHub de forma autónoma tampoco es algo que deba hacerse sin revisión — si en el futuro se necesita un MCP de Playwright específico (por ejemplo para usarlo fuera de Claude Code), instalarlo y revisarlo manualmente.

---

## 10. Videollamada (Jitsi) y flujo post-clase (2026-08-02)

### Modal de Jitsi rediseñado

`Lessons/ParentIndex.vue`, `Lessons/TeacherIndex.vue` y `Dashboard/Parent.vue`
ya no envuelven la sala en el `Modal.vue` genérico (pensado para diálogos
centrados, no para videollamada a pantalla completa). Ahora usan
`resources/js/Components/JitsiModal.vue`, un componente nuevo y compartido:
overlay `fixed inset-0 z-[9999]` sobre fondo `slate-950`, barra superior con
el nombre de la materia (o "Sala Virtual" si no hay clase asociada) + botón
"Cerrar sala y volver a MOVA", y el iframe en `h-[85vh]` (móvil) /
`h-[90vh]` (desktop), `w-full`. `Dashboard/Teacher.vue` no lo usa porque
nunca tuvo un punto de entrada a Jitsi — solo se le agregó la acción
"Escribir reporte" (ver más abajo), no la sala virtual.

### "Powered by Jitsi" en el free tier

El servidor público `meet.jit.si` que usa MOVA (`useJitsiMeet.js`, carga
`external_api.js` desde ese dominio) puede mostrar branding de Jitsi
("Powered by Jitsi Meet" u overlays similares) dentro del iframe de la
videollamada — **no hay forma de quitarlo usando el servicio gratuito**, es
parte de los términos de uso de `meet.jit.si`. La única forma de eliminarlo
por completo es dejar de depender del servidor público:

- **Self-hosting** — levantar la propia instancia de Jitsi Meet (Docker
  oficial: `jitsi/docker-jitsi-meet`). Requiere infraestructura propia
  (mínimo un VPS con recursos para STUN/TURN/videobridge) y mantenimiento.
- **JaaS (Jitsi as a Service, jaas.8x8.vc)** — la opción manejada de 8x8
  (la empresa detrás de Jitsi): sin branding, SLA, requiere cuenta de pago y
  cambiar `useJitsiMeet.js` para autenticar con JWT contra `8x8.vc` en vez
  de `meet.jit.si` sin autenticación como ahora.

Si en el futuro se decide quitar el branding, **la ruta recomendada es
migrar a JaaS** (no self-hosting) — evita operar infraestructura de
videollamada propia, que es no trivial de mantener con buena calidad (NAT
traversal, escalado del videobridge, etc.) para el tamaño actual de MOVA.

### Flujo post-clase

Antes, cerrar el modal de Jitsi (`api.dispose()` o el botón de cerrar) no
hacía nada más — la persona se quedaba mirando la lista de clases sin ningún
indicio de qué hacer a continuación. Ahora, `useJitsiMeet.js` centraliza el
cierre: tanto el botón "Cerrar sala y volver a MOVA" como el evento
`readyToClose` de Jitsi (se dispara cuando alguien cuelga desde los propios
controles de la videollamada) pasan por la misma función `closeJitsi()`,
que redirige a `route('dashboard', { post_class: lesson.id })` — **excepto**
si el modal se cerró por un error de acceso (`joinError`), porque ahí nunca
hubo clase real a la que "volver".

`Dashboard/Parent.vue` y `Dashboard/Teacher.vue` leen `?post_class=<id>` en
`onMounted()`, muestran un banner (limpian el query string con
`history.replaceState` para que un refresh no lo repita) y lo quitan del
todo si se cierra manualmente:
- Padre: "La clase ha terminado. Confirma tu pago para continuar." → botón
  a `route('parent.lessons')`.
- Profesor: "La clase ha terminado. Escribe el reporte pedagógico." → botón
  directo a `route('lesson-reports.create', post_class)` (ruta ya protegida:
  redirige a `lesson-reports.show` si el reporte ya existe, aborta 422 si la
  clase ya no está en estado `paid`).

Además, `DashboardController::__invoke()` (rama profesor) ahora incluye
lecciones en estado `paid` en `upcoming` (antes solo `scheduled` con
`start_time >= now()`, así que una clase recién pagada nunca aparecía ahí) y
`Dashboard/Teacher.vue` muestra el botón "📝 Escribir reporte" en línea para
esas — así el botón de acción está visible de inmediato al volver del modal,
sin depender únicamente del banner. El lado del padre no necesitó cambios de
query porque `upcoming` ya incluía `scheduled`/`paid`/`pending_parent_confirmation`
sin filtro de `start_time`, y el botón "✓ Ya pagué" ya se gateaba solo por
`hasClassEnded(l)`.

---

## 11. Migración a JaaS — sin límite de 5 minutos (2026-08-02)

### El problema con meet.jit.si público

El dominio público `meet.jit.si` que se usaba hasta ahora muestra un banner
("Embedding meet.jit.si is only meant for demo purposes...") y **corta el
embed a los 5 minutos** cuando se usa desde un dominio ajeno en producción —
es una limitación deliberada del servicio gratuito, no un bug. Esta sección
implementa la migración a **JaaS (Jitsi as a Service, 8x8.vc)**, la opción
recomendada en la sección 10 de este documento para eliminar ese límite sin
pasar a self-hosting.

### Configuración

- `.env` (nunca `.env.example`): `JAAS_APP_ID` (App ID de JaaS, no es
  secreto) y `JAAS_PRIVATE_KEY` (private key del keypair RSA subido en
  jaas.8x8.vc, **en base64 de una sola línea** — evita el problema de
  parsear un PEM multilínea dentro de un archivo `.env`; se genera con
  `base64 -w0 tu-private-key.pem`). `config/jaas.php` decodifica el base64
  de vuelta a PEM al leerlo.
- **Nota de seguridad de esta sesión:** al empezar esta tarea, `.env.example`
  (archivo versionado en git) ya tenía un App ID y una private key RSA reales
  pegados al final — no estaban committeados todavía (cambio local sin
  confirmar), pero de haberse hecho `git add`/commit habrían quedado
  expuestos permanentemente en el historial. Se movieron a `.env`
  (gitignored) antes de tocar nada más, y `.env.example` quedó con los
  placeholders vacíos que pide este flujo. Si esa private key llegó a
  circular fuera de esta máquina (compartida por chat, capturas, etc.),
  conviene rotarla de todos modos desde jaas.8x8.vc — normalmente para 30
  días no aplica ni "vale la pena" pero acá no hay forma de saber cuánto
  circuló ese archivo.

### `JaasService` y el JWT

`app/Services/JaasService.php::generateToken(roomName, userName, isModerator)`
firma un JWT RS256 con la private key (nunca sale del backend). Payload:
`aud=jitsi`, `iss=chat`, `sub=<App ID>`, `room=<jitsi_room sin prefijo>`,
`exp` a 24h, `context.user.name`/`context.user.moderator`. El `room` claim
lleva el nombre de sala SIN el prefijo de tenant (a diferencia del
`roomName` que sí lo necesita en el External API) para que el token quede
acotado a esa sala específica, no a cualquier sala del tenant.

`sub` usa el App ID configurado, no un valor literal fijo — un JWT firmado
con `sub` distinto al App ID real es rechazado por JaaS, así que hardcodear
un placeholder ahí lo habría dejado no funcional.

### `LessonController`

- `store()`: el formato de `jitsi_room` (`mova-lesson-{id}-{random32}`) no
  cambió — ya era válido como segmento de URL para JaaS, el prefijo de
  tenant se añade al usarlo, no al guardarlo.
- `join()`: además de `jitsi_room`, ahora devuelve `jitsi_token` (el JWT,
  generado en cada llamada — no se persiste) y `jaas_app_id` (público, se
  necesita en el frontend para el `roomName` con prefijo y para cargar
  `external_api.js`). `jitsi_password` dejó de usarse — JaaS controla acceso
  y rol de moderador vía el JWT, no vía el mecanismo de password ad-hoc del
  free tier.
- El moderador es quien tiene `teacherProfile.user_id === auth()->id()` en
  esa lección — el profesor asignado, nunca el padre.

### El header `kid` (Key ID)

Primera versión del JWT firmaba solo con `sub` = App ID y sin `kid` en el
header — JaaS lo rechazaba con **"Missing Key ID (kid)"** al entrar a la
sala, aunque la firma en sí fuera válida. JaaS necesita el `kid` para saber
CUÁL public key (de las que el App ID puede tener varias subidas) usar para
verificar; sin él no sabe con qué comparar la firma, sea o no correcta.

`JAAS_KEY_ID` (jaas.8x8.vc > la misma API key de la que salió el App ID y
la private key — el Key ID es un tercer valor, no el App ID ni parte de la
private key) se agregó a `config('jaas.key_id')` y `JaasService::generateToken()`
ahora firma con:

```php
JWT::encode($payload, $privateKey, 'RS256', null, [
    'kid' => $keyId,
    'typ' => 'JWT',
    'alg' => 'RS256',
]);
```

### Frontend

`useJitsiMeet.js` carga `https://8x8.vc/{appId}/external_api.js` (no el
`meet.jit.si/external_api.js` de antes) e instancia
`JitsiMeetExternalAPI('8x8.vc', { roomName: '{appId}/{jitsi_room}', jwt,
userInfo, ... })`. `JitsiModal.vue` no cambió de forma — sigue siendo el
mismo overlay a pantalla completa (`z-[9999]`, barra superior, iframe
`h-[85vh] sm:h-[90vh]`) de la sección 10; solo cambió qué servidor hay
detrás del iframe.

### Tests

`phpunit.xml` fuerza `JAAS_APP_ID`/`JAAS_PRIVATE_KEY`/`JAAS_KEY_ID` con un
keypair RSA + Key ID de prueba (sin relación con la cuenta real de JaaS) —
así el suite no depende de que el `.env` local tenga credenciales reales.
`MonetizationIntegrityTest::test_lesson_join_allows_owner_parent_and_assigned_teacher`
decodifica el JWT devuelto (con la public key derivada de esa misma test
key) y verifica `room`, `aud`, `iss`, que `context.user.moderator` sea
`true` para el profesor y `false` para el padre, y que el header (no solo
el payload — `JWT::decode()` no lo expone, se decodifica el primer segmento
a mano) traiga el `kid` correcto — no solo que la respuesta sea 200.

---

## 12. Auditoría de consola + reseñas visibles + clases por semana (2026-08-03)

### Auditoría de errores de consola

Navegado landing, login, ambos dashboards y una sala de Jitsi real (JaaS,
con JWT firmado) revisando la consola en cada uno. **Cero errores propios
de MOVA** — todas las peticiones al backend (`/notifications`,
`/broadcasting/auth`, `/lessons/{id}/join`, HMR de Vite) devolvieron 200.
No se pudo inspeccionar la consola *interna* del iframe de 8x8.vc (cross-
origin, fuera del alcance de las herramientas de este entorno) — el pedido
explícitamente excluía esos errores (Amplitude/localStorage/speaker-
selection) por venir del lado de JaaS, no de MOVA.

### Perfil público del profesor y marketplace

`TeacherPublicController::show()` y `Teachers/Show.vue` ya traían y
mostraban `avg_rating`, `review_count`, `classes_completed` y una lista de
reseñas recientes con estrellas + comentario — no faltaba nada estructural.
Único ajuste: se agregó "de 5" / "/5" junto al número (antes solo mostraba
"4.8 ★" sin la escala explícita).

El marketplace (`Marketplace/Index.vue`) ya calculaba el rating real por
oferta desde `teacher_profile.visible_reviews` (eager-loaded en
`MarketplaceController`) — verificado en vivo con un profesor con 2 reseñas
de 5★: la card muestra "★ 5.0 (2 reseñas)" correctamente. No se tocó esta
lógica porque ya usa datos reales, no simulados.

### Clases por semana (Esta semana / Clases pasadas)

Nuevo `resources/js/utils/weekGrouping.js`: `splitByWeek(items, dateField)`
separa una lista en "esta semana o después" (lunes 00:00 en adelante) vs
"pasadas", sin tercer balde para clases más allá de esta semana — caen en
"esta semana" también, porque lo relevante para el usuario es "pendiente"
vs. "historial", no la semana calendario exacta. La función NO reordena —
`Lessons/*Index.vue` reciben `lessons` en `start_time desc` del backend
(pasadas queda tal cual, "esta semana" se invierte para mostrar la más
próxima primero) mientras que `Dashboard/*.vue` reciben `upcoming` en
`start_time asc` (ambos baldes se mantienen tal cual llegan).

`Lessons/ParentIndex.vue` y `Lessons/TeacherIndex.vue` extrajeron su card de
clase a `resources/js/Components/Lessons/{Parent,Teacher}LessonCard.vue` —
con dos secciones (`v-for` sobre `thisWeek` y `past`) habría significado
duplicar ~100 líneas de markup+lógica por página; con el componente, cada
sección es una sola línea. `Dashboard/Parent.vue` y `Dashboard/Teacher.vue`
mantienen su markup de timeline/lista inline (más corto, un solo uso cada
uno) pero con las mismas dos secciones con encabezado y estado vacío
independiente por sección.

---

## 13. Identidad de marca aplicada (2026-08-03)

Hasta esta sesión la UI usaba un placeholder (una "M" blanca dentro de una
caja con gradiente) y la paleta `brand` era **el azul genérico de Tailwind**
(`#2563EB`…), sin relación con MOVA. Se aplicaron los archivos de marca
reales que llegaron al repo.

### Archivos fuente encontrados

| Ruta | Contenido |
|---|---|
| `Logos-*/Logos/Imagotipo/Imagotipo-{1..5}.png` | Logo horizontal (isotipo + "Mova") |
| `Logos-*/Logos/Isotipos/Isotipo-{1..5}.png` | Solo la "M" (dos personas dándose la mano) |
| `Tipografía-*/Tipografía/Letras/*.otf` | Plus Jakarta Sans, 14 archivos (7 pesos × normal/itálica) |
| `MOVA MINI MANUAL DE MARCA.pdf` | Hex de marca + pesos tipográficos |

Las variantes numeradas resultaron ser: **-1** color completo · **-2/-3**
monocromo azul/naranja · **-4** todo blanco (fondos oscuros) · **-5** todo
negro. Se clasificaron analizando la composición de píxeles de cada PNG, no
abriéndolos uno por uno.

### Discrepancia de azul (manual vs. logo) — resuelta

El manual declara `#1f5aa6`; los píxeles reales del logo son `#0D409A`. El
naranja sí coincide exacto (`#F59E0B` en ambos). Al pasar los tres colores
reales a HSL se vio que **no se contradicen**:

| Color | Origen | HSL | Contraste vs. blanco |
|---|---|---|---|
| `#1F5AA6` | manual PDF | H 214 · S 69% · L 39% | 6.84:1 |
| `#0D409A` | píxel del logo | H 218 · S 84% · L 33% | 9.47:1 |
| `#0D346D` | navy del apretón de manos | H 216 · S 79% · L 24% | 12.14:1 |

Mismo tono (H≈215), tres luminosidades consecutivas → son pasos de una
misma rampa. Se asignaron a `brand-600` / `brand-700` / `brand-800`
respectivamente, así que **ambas fuentes oficiales se respetan** en el rol
que les corresponde (600 = botones, 700 = hover y superficies que tocan al
logo, 800 = fondos profundos). Los pasos 50–500 y 900–950 se extrapolaron
manteniendo H≈215. Todos los pasos interactivos pasan WCAG AA o AAA con
texto blanco encima (500 = 5.2:1 AA … 900 = 15.1:1 AAA).

El naranja `#F59E0B` **es exactamente `amber-500` de Tailwind**, así que
`accent` reutiliza la escala amber completa en vez de inventar pasos nuevos.

### Tipografía

Plus Jakarta Sans pasó de `.otf` a `.woff2` (fontTools) — 6 pesos, ~36KB
c/u, en `public/fonts/`. Se **auto-hospeda** en vez de usar el CDN de Google
(que era lo que había para Inter): además de ser el archivo de marca que ya
teníamos, elimina una petición a `fonts.gstatic.com`, o sea una
transferencia de datos a un tercero — relevante para la política de
privacidad de una plataforma con datos de menores.

Detalle: Plus Jakarta Sans **no tiene peso 900** y la UI usa `font-black` en
muchos títulos. El `@font-face` de ExtraBold se declara con
`font-weight: 800 900` para que el navegador use el archivo real en vez de
sintetizar una negrita falsa. Solo se precargan Regular y ExtraBold (los dos
pesos sobre el pliegue); precargar los 6 desperdiciaría ancho de banda móvil.

### Logo en la app

Nuevo `resources/js/Components/MovaLogo.vue` con props `variant`
(imagotipo/isotipo) y `theme` (color/blanco), `<picture>` con WebP + PNG de
respaldo, y `width`/`height` reales para evitar CLS. Reemplaza el
placeholder en los 6 sitios: `AppLayout`, `GuestLayout`, `LandingNavbar`,
`LandingFooter`, `StudentInvitation`, `TeacherInvitation`.

`LandingNavbar` alterna `theme` según `scrolled` — versión blanca sobre el
hero azul, versión color cuando el navbar se vuelve blanco. Verificado en
vivo: el `src` cambia de `mova-imagotipo-blanco.webp` a `mova-imagotipo.webp`
al pasar de scrollY 0 a 400.

Assets generados en `public/images/brand/` (WebP + PNG): imagotipo color /
blanco / negro, isotipo color / blanco, más `favicon.ico` multi-resolución
(16→256), `apple-touch-icon` 180, e iconos 192/512 para el manifest.

### Meta y PWA

`app.blade.php`: fuera el `<link>` a Google Fonts, dentro los favicons,
`<meta name="theme-color" content="#0D409A">` y el preload de fuentes. Se
creó `public/manifest.json` (no existía) con los iconos y colores de marca.

### Limitación conocida: no hay SVG

Los logos llegaron **solo como PNG**. Convertir PNG→SVG de verdad requiere
vectorización (potrace/inkscape), que no está instalado en este entorno; y
aunque lo estuviera, un trazado automático sobre un logo de curvas limpias
da peor resultado que el vector original. Lo que se hizo fue optimizar a
WebP con respaldo PNG, que a estos tamaños pesa 8–22KB y se ve nítido.

**Pendiente para el diseñador:** pedir el archivo vectorial original
(`.ai`, `.eps` o `.svg`) del imagotipo e isotipo. Con el SVG se ganaría
nitidez perfecta a cualquier tamaño y peso ~2–4KB, y se podría colorear por
CSS (útil para estados hover/activo). Mientras tanto el PNG/WebP funciona
correctamente en todos los tamaños que la UI usa hoy (máx. 100×32 px).

---

## 14. Páginas legales redactadas (2026-08-03)

> ⚠️ **Este texto sigue sin ser un documento legal validado por un abogado
> colegiado en Perú.** Se redactó a partir del borrador de trabajo en
> `docs/LEGAL_REQUIREMENTS_DRAFT.md`, adaptado a los datos reales de MOVA,
> pero antes de considerarse definitivo requiere revisión legal — sobre
> todo por tratarse de datos de menores de edad.

**Contexto real usado para redactar el contenido:** Perú, padres↔profesores,
datos de menores (nombre/edad/grado), IA (Google Gemini) para enriquecer
diagnósticos, notificaciones por email y WhatsApp (Twilio), videollamadas
por JaaS (8x8) con acceso vía JWT, pagos externos Yape/Plin (MOVA no los
procesa), fotos de perfil y archivos de reporte subidos por profesores.

**[Privacy.vue](../resources/js/Pages/Legal/Privacy.vue)** — 13 secciones:
responsable del tratamiento, datos recopilados (incluye foto de perfil y
datos del menor), finalidad, datos de menores con consentimiento explícito
(Ley N° 29733), uso de IA (Google Gemini, sin decisiones automatizadas sin
supervisión humana), reseñas, terceros (Gmail, Twilio, JaaS/8x8, Railway,
Gemini — con nota explícita de que **no** se usa Meta Pixel ni Google
Analytics hoy), pagos (MOVA no almacena datos bancarios), seguridad,
derechos ARCO, retención, cookies/almacenamiento local, contacto.

**[Terms.vue](../resources/js/Pages/Legal/Terms.vue)** — 17 secciones:
se agregaron explícitamente los roles (padre/profesor/administrador) con
sus obligaciones, y una sección de créditos (no reembolsables, no
transferibles). El resto del contenido de clases/reseñas/IA/videollamadas
(JaaS) ya existía de una sesión anterior y se ajustó para nombrar JaaS en
vez de "Jitsi Meet" y reflejar la app real.

**Cláusula de arbitraje:** se mantuvo **opcional** para padres/apoderados
(consumidores) — nunca sustituye su derecho a acudir a INDECOPI o al Poder
Judicial — y solo aplicable de mutuo acuerdo para profesores (relación de
prestación de servicios independiente). No se hizo obligatorio para
consumidores, siguiendo la advertencia de riesgo INDECOPI del borrador.

**Retiro de contenido:** se usó "Política de Retiro de Contenido" citando
el **Decreto Legislativo N° 822** (Ley sobre el Derecho de Autor peruana),
en vez de "DMCA" (ley federal de EE.UU. que no aplica a una plataforma
peruana sin presencia legal en EE.UU.).

**Cambio de correo de contacto:** todos los correos de soporte/quejas/avisos
en código (páginas legales, footer, `GuestLayout.vue`, `Suspended.vue`,
`TeacherRejectedNotification.php`) se cambiaron de `abelcastillotrabajo@gmail.com`
a **`m0v4class@gmail.com`**. Quedan referencias históricas al correo anterior
solo en documentación (`HANDOFF_FINAL.md` §§1-13, `PROFESSIONAL_POLISH_AUDIT.md`),
que no afectan la app en producción.

**Register.vue:** el checkbox de aceptación de términos ya enlazaba
correctamente a `route('legal.terms')` y `route('legal.privacy')` — no
requirió cambios.

**Verificación:**
- `php artisan test` → 87/87 (399 assertions).
- `npm run build` → sin errores.
- `/terminos` y `/privacidad` verificados en navegador — contenido completo,
  17 y 13 secciones respectivamente, correo `m0v4class@gmail.com` visible.
- `/register` no se pudo probar con sesión anónima en este entorno (había
  una sesión de prueba activa que redirige a `/dashboard`); se verificó por
  código que el checkbox enlaza correctamente.

---

## 15. Auditoría de diseño de la landing (2026-08-03)

Auditoría de `Welcome.vue` contra la skill `impeccable` (register `product`,
declarado en `PRODUCT.md`) y contra las Web Interface Guidelines de Vercel,
descargadas a `docs/WEB_INTERFACE_GUIDELINES.md`.

### Bugs reales encontrados

**1. Sección en blanco permanente (el más grave).** Los `.reveal-item` usaban
`gsap.from()` + ScrollTrigger sin `immediateRender: false`. GSAP aplica
`opacity: 0` **al montar**, no cuando dispara el trigger — así que si el
trigger no llegaba a dispararse (posiciones recalculadas tarde por las
webfonts, render headless, JS lento en gama baja), la sección quedaba
invisible para siempre. Verificado en vivo: elementos con `visible: true` y
`opacity: 0` simultáneamente. Corregido con `immediateRender: false`,
`once: true` y `ScrollTrigger.refresh()` tras `document.fonts.ready`.
Post-fix verificado: los 8 elementos aún sin disparar están en `opacity: 1`.

**2. Sin guard de `prefers-reduced-motion` en la landing.** `PRODUCT.md:62`
lo declara obligatorio y `Dashboard/Parent.vue` sí lo tenía, pero
`Welcome.vue` — la página más visitada — no. Se resolvió en dos capas:
regla global en `app.css` (cubre utilidades Tailwind como `animate-pulse`,
todas las transiciones y los keyframes propios) más el guard explícito con
`matchMedia` en el `onMounted` de GSAP, porque **GSAP anima por JS y la
media query de CSS no lo alcanza**.

**3. Variable CSS nunca definida.** `@keyframes floatShape` leía
`var(--rot, 20deg)` con `--rot` sin declarar en ningún lado: las cuatro
formas del hero caían al fallback de 20deg y pisaban su `transform`
individual, así que los ángulos 45/15/-30 no se veían nunca. Cada forma
declara ahora su propia `--rot`.

### Violaciones de bans de diseño corregidas

| Ban | Dónde | Corrección |
|---|---|---|
| Gradient text | `Welcome.vue` h1, `StudentInvitation.vue`, `TeacherInvitation.vue` | Ámbar sólido `accent-400` (#FBBF24), que es el naranja del propio logo MOVA — 7.4:1 de contraste sobre el azul del hero |
| Eyebrow uppercase en cada sección | 4 secciones ("Proceso simple", "Catálogo", "Expertos", "Testimonios") | Eliminados. La numeración 1/2/3 de "Cómo funciona" se mantiene porque ahí **sí** es una secuencia real |
| Hero-metric template | 4 tiles idénticos de números gigantes | Reescrito como línea de evidencia en prosa, precedida de la frase de confianza que de verdad le importa al padre |
| Glassmorphism decorativo | `backdrop-blur-sm` en testimonios | Quitado (desenfocaba un degradado estático: puro costo de GPU, cero ganancia visual). Se conservó el `backdrop-blur` de la tarjeta del hero: es una sola instancia, con propósito, y sólo se renderiza en desktop (`hidden lg:flex`) |

### Colores fuera de marca corregidos

Tras el rebrand quedaron restos del azul genérico de Tailwind: las cuatro
formas del hero usaban `#60A5FA`/`#3B82F6`/`#93C5FD`/`#BFDBFE`/`#1D4ED8`/
`#2563EB` hardcodeados, más `cyan-100` en el CTA final y `yellow-400` en
todas las estrellas. Todo migrado a `theme('colors.brand.*')` y
`accent-*`. La forma 3 usa ámbar como eco del logo.

### Documentación corregida

`PRODUCT.md` y `HANDOFF_FINAL.md:58` seguían diciendo "Inter" cuando la app
ya usa Plus Jakarta Sans. Además `HANDOFF_FINAL.md:58` afirmaba que la
landing tenía guard de `prefers-reduced-motion` — era **falso** hasta este
cambio; ahora la afirmación es correcta.

### Decisión sobre herramientas de terceros

Se evaluó una lista de ~150 herramientas/repos/skills propuestos. **No se
instaló ninguna dependencia de terceros.** Lo único incorporado fue el
markdown de las guías de Vercel (`docs/WEB_INTERFACE_GUIDELINES.md`),
descargado directamente en vez de ejecutar su `install.sh` (que escribe en
`~/.config/`, `~/.cursor/` y `~/.gemini/` además de `~/.claude/`).

Descartado explícitamente: todo lo React-only (21st.dev/magic MCP, React
Bits, cult-ui, shadcn/ui, Sonner, Vaul) por ser MOVA Vue; y todo lo de
3D/WebGL/three.js/aurora UI porque el registro de MOVA es confianza para
padres en Android de gama media, no portafolio de agencia. Skills de autor
desconocido descartadas por riesgo de inyección de prompt: una skill
inyecta instrucciones en el contexto del agente en cada sesión.

### Verificación

- `npm run build` → sin errores.
- `php artisan test` → 87/87 (399 assertions).
- Verificado en vivo a 1280px y 375px: cero errores de consola, sin scroll
  horizontal, h1 sin desbordar, `bg-clip-text` restantes = 0, eyebrows
  restantes = 0, `--rot` resolviendo a 20/45/15/-30deg.

---

## 16. Cierre del proyecto (2026-08-03)

### Estado actual

- **Tests:** 87/87 PHPUnit (399 assertions) · **2/2 E2E Playwright**
  (`qa/tests/flujo-completo.spec.js` — flujo completo de 8 pasos: solicitud
  → agendar → pago → reporte → calificación; y UI de recargas de créditos).
- **Build:** `npm run build` limpio, sin warnings.
- **Git:** working tree limpio, 137 commits.

**Features completas:**

- **Roles y auth:** padre, profesor, admin con Spatie Permission. Login por
  email/password y Google OAuth2 (Socialite). Verificación de teléfono.
  Suspensión de cuentas por admin.
- **Marketplace y solicitudes:** catálogo de profesores verificados con
  filtros, solicitud de clase por materia/nivel, aceptación/rechazo por el
  profesor, ofertas de clase publicadas por profesores.
- **Clases en vivo:** integración JaaS (8x8.vc, JWT firmado) para
  videollamada — enlace generado server-side vía `GET /lessons/{id}/join`,
  nunca expuesto en el payload de Inertia (`jitsi_room`/`jitsi_password` en
  `$hidden` del modelo `Lesson`). Reprogramación y cancelación de clases.
  Recordatorios automáticos 10 min antes (`classmate:send-reminders` vía
  scheduler).
- **Créditos y pagos:** sistema de créditos del profesor con ledger
  append-only (`credit_transactions`, `idempotency_key` único,
  `lockForUpdate()` en toda mutación). Recarga manual vía Yape/Plin con
  aprobación de admin. Confirmación de pago por el padre ("Ya pagué").
- **Reportes y reseñas:** reporte de clase por el profesor tras cada
  sesión, calificación y reseña por el padre, moderación de reseñas por
  admin (ocultar/mostrar).
- **Diagnóstico de nivel:** cuestionario que recomienda profesores según
  respuestas del padre.
- **Notificaciones:** in-app (con dropdown click-outside + Escape) y
  tiempo real vía Laravel Echo/Reverb. Toast de notificación en vivo en
  `AppLayout`.
- **Legal y cumplimiento:** Términos y Privacidad redactados para D.L. 822
  (Perú), arbitraje opcional, banner de consentimiento de cookies
  (localStorage, sin bloquear interacción con el resto de la página).
- **Identidad de marca:** paleta azul #1F5AA6/#0D409A + acento ámbar
  #F59E0B, tipografía Plus Jakarta Sans auto-hospedada, logos en WebP,
  landing con animaciones GSAP (con guard de `prefers-reduced-motion`),
  auth con panel dividido premium, menú móvil full-screen inmersivo.
- **Admin:** gestión de usuarios, verificación de profesores pendientes,
  moderación de solicitudes/clases/recargas/reseñas, panel de uso de IA.

### Lo que falta para producción

1. **Hosting real.** Hoy corre en `php artisan serve` + XAMPP local. Elegir
   entre Railway, Fly.io u Oracle Cloud (ver guía rápida abajo) — los tres
   soportan PHP+MySQL+queue worker en el tier gratuito/económico.
2. **WhatsApp Cloud API.** El código actual usa el sandbox de Twilio
   (`join <código>` manual, no apto para usuarios reales sin onboarding
   previo). Migrar a WhatsApp Cloud API (Meta) para envío directo sin ese
   paso, o aceptar el sandbox solo como demo.
3. **Revisión legal.** Términos y Privacidad fueron redactados por el
   asistente con foco en D.L. 822 y datos de menores, pero **no han sido
   revisados por un abogado**. Antes de operar con usuarios reales
   (especialmente menores de edad y pagos), un profesional debe validar
   ambos documentos.
4. **Credenciales de producción.** Zoom/JaaS, Twilio, Gmail App Password,
   Google OAuth2, Cloudinary — todas configuradas hoy con credenciales de
   desarrollo/sandbox en `.env` local (nunca versionado). Se necesitan
   credenciales de producción propias antes de lanzar.
5. **Dominio y HTTPS.** `APP_URL` sigue apuntando a `localhost:8000`.

### Despliegue rápido (Railway / Fly.io / Oracle Cloud)

**Railway** (más simple, buena opción para el primer despliegue):
```bash
railway login
railway init
railway add --database mysql
railway up
railway run php artisan migrate --force
railway run php artisan db:seed --force   # opcional, solo si se quieren datos demo
```
Configurar en el dashboard de Railway: todas las variables de `.env`
(menos `DB_*`, que Railway inyecta automáticamente al conectar el plugin
MySQL), `APP_URL` con el dominio que asigne Railway, `APP_ENV=production`,
`APP_DEBUG=false`. Agregar un segundo servicio para
`php artisan queue:work` y un cron/Railway Scheduled Job para
`php artisan schedule:run` cada minuto.

**Fly.io** (más control, requiere `Dockerfile`):
```bash
fly launch                    # detecta Laravel, genera fly.toml + Dockerfile
fly mysql create               # o conectar una MySQL externa (PlanetScale, etc.)
fly secrets set APP_KEY=... DB_HOST=... ZOOM_... TWILIO_... GMAIL_...
fly deploy
fly ssh console -C "php artisan migrate --force"
```
El worker de colas y el scheduler necesitan procesos separados en
`fly.toml` (sección `[processes]`) o una segunda app.

**Oracle Cloud** (VM gratuita permanente, más trabajo manual):
Aprovisionar una instancia Always Free (Ampere A1), instalar PHP 8.1+,
Composer, Node, MySQL/MariaDB vía el gestor de paquetes de la distro,
clonar el repo, `composer install --no-dev`, `npm ci && npm run build`,
configurar Nginx + PHP-FPM, `supervisord` para `queue:work`, y un entry en
crontab del sistema para `php artisan schedule:run` (ver sección "Scheduler
(recordatorios automáticos)" en `SETUP.md`).

En los tres casos, después del primer deploy:
```bash
php artisan key:generate --force   # si APP_KEY no se generó antes
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Usuarios de prueba

| Usuario | Email | Contraseña | Rol |
|---|---|---|---|
| Padre de prueba | `padre@mova.test` | `password123` | parent |
| Profesor de prueba | `profesor@mova.test` | `password123` | teacher |

(Usados por el propio suite E2E en `qa/tests/flujo-completo.spec.js`. Ver
`SETUP.md` para el resto de usuarios de `db:seed`, con contraseña
`password`.)

---

## 17. C-1 — Créditos que quedaban atrapados (2026-08-15 a 2026-08-21)

### El hallazgo original

Auditoría de seguridad post-lanzamiento (agosto 2026) encontró tres hallazgos
críticos, cerrados en orden:

- **C-3** (`533a799`) — la URL real de la sala de Jitsi se guardaba en el
  payload de las notificaciones (`notifications.data`), exponiendo un token
  de acceso a una videollamada con un menor presente. Corregido; limpieza
  histórica del dato ya guardado vía `mova:purge-jitsi-urls`.
- **C-2** (`6149aa4`) — `reschedule()` permitía cambiar `duration_minutes` sin
  ajustar `credits_reserved` — un profesor podía reprogramar una clase de 30
  min (1 crédito reservado) a 4h sin pagar créditos extra. Bloqueado: el
  cambio de duración al reprogramar ya no se acepta.
- **C-1** (esta sección) — el hallazgo estructural: los créditos quedaban
  **atrapados permanentemente** en `credits_reserved` porque la única forma
  de consumirlos era que el padre escribiera una reseña voluntaria
  (`TeacherReviewController::store()`). Una clase en `paid` o
  `pending_parent_confirmation` cuyo padre nunca reseñara se quedaba así
  para siempre — sin reembolsar al profesor ni cobrarle la clase.

### Arquitectura del ledger

`credit_transactions` es la única fuente de verdad financiera (append-only,
`idempotency_key` único). `classes.credits_settled_at` es una **caché
derivada**, nunca la garantía: lo que realmente impide una doble liquidación
bajo concurrencia es el índice `UNIQUE` sobre `idempotency_key`, no un
`credits_settled_at IS NULL` leído antes del lock. Por eso
`credits_settled_at` está deliberadamente **fuera de `$fillable`** en
`Lesson` — la única capa autorizada a escribirla es
`App\Services\LessonSettlementService`, con asignación directa +
`save()` (nunca `update()` con mass assignment; ver "Bugs reales" abajo).

Estado nuevo en el ENUM de `classes.status`: **`needs_admin_review`** — una
clase `scheduled` cuyo padre nunca confirmó el pago dentro de
`unconfirmed_days` (7 días por defecto) escala aquí automáticamente. El
crédito sigue reservado; no es una liquidación, es una bandera para que un
admin decida. Se excluye por construcción (whitelist explícita, no negación)
de `LessonController::join()` — no se puede entrar a la sala virtual de una
clase en disputa — y de `reschedule()`/`cancel()` normales, que exigen
`status === 'scheduled'` exacto.

`Lesson::scopeEndedBefore()`/`scopeEndedAfter()` centralizan la aritmética de
fechas (`start_time + duration_minutes`, con ramas MySQL/SQLite) — reutilizada
por el scheduler, por `SendClassReminders` y por
`Lesson::scopeAwaitingReportWithinGrace()`, para no repetir la misma
expresión SQL en tres sitios (la misma deuda que dejó `hasScheduleOverlap()`
duplicado en C-2).

### Los 3 caminos para liquidar una clase

Los tres pasan por `LessonSettlementService::consume()`/`refund()` — capa
única, reemplaza lo que antes eran implementaciones divergentes del mismo
concepto repartidas en varios controladores:

1. **Automática (`mova:settle-lessons`)** — barrido horario
   (`Kernel::schedule()`), una transacción por lección (nunca por lote — es
   lo que impide que el comando participe en el deadlock multi-lección de
   C-2). Consume `paid`/`pending_parent_confirmation` vencidas más allá de
   `settlement_grace_days`; escala `scheduled` vencidas más allá de
   `unconfirmed_days` a `needs_admin_review` (sin efecto financiero). Un
   error en una lección no aborta el resto del barrido (`report()` +
   continúa).
   **⚠️ Agendado en `Kernel.php` con `--dry-run` a propósito** — activar la
   liquidación real requiere quitar esa bandera a mano, después de revisar
   el dry-run en producción. No se ha activado todavía.
2. **Manual — rescate de admin** (`POST /admin/lessons/{id}/force-complete`
   y `.../force-refund`) — para lo que el scheduler no puede decidir por sí
   solo (ej. el profesor confirma por otro canal que la clase sí ocurrió).
   Razón obligatoria, `throttle:10,1`, gateadas por `role:admin`.
3. **Vía reseña** (`TeacherReviewController::store()`, el camino original) —
   sigue funcionando igual, pero ahora también acepta reseñar una clase que
   ya está en `completed` (auto-liquidada o cerrada por un admin) — la
   reseña se guarda igual, simplemente no dispara un segundo consumo (lo
   evita el mismo guard de idempotencia).

### Decisiones de producto (Fase 3B, confirmadas explícitamente)

- **`settlement_grace_days = 7`** y **`unconfirmed_days = 7`** — mismo valor
  a propósito, para simplificar el razonamiento sobre cuándo escala qué.
  Configurables por env (`CREDITS_SETTLEMENT_GRACE_DAYS`,
  `CREDITS_UNCONFIRMED_DAYS`).
- **`needs_admin_review` es visible, no oculto** — corrección a una
  caracterización imprecisa que circuló en esta ronda ("excluir de listados
  de usuario"): la decisión real fue la opuesta. El profesor ve un banner
  explícito ("tu crédito sigue retenido mientras se resuelve"); el padre ve
  uno neutro ("está en revisión por el equipo MOVA", sin detalle financiero,
  que es un concepto del profesor). Hay una pestaña "En revisión" dedicada
  en `Admin/Lessons.vue`. Lo único que SÍ está excluido por construcción es
  la posibilidad de unirse a la videollamada o reprogramarla/cancelarla por
  las vías normales (ver arriba).
- **Los contadores públicos de `completed`** (landing, perfil de profesor,
  dashboard) cuentan TODAS las clases completadas, incluidas las
  auto-liquidadas — no hay forma de distinguir "el padre reseñó" de "se
  cerró sola" desde esos contadores, y así se decidió que debía ser.

### Bugs reales encontrados y corregidos durante la implementación

Ninguno estaba en el plan original — surgieron al probar la UI real y en una
pasada explícita de `security-review`/`code-review`:

- **`credits_settled_at` no se guardaba, en silencio.** El servicio escribía
  `$lesson->update(['credits_settled_at' => now(), ...])` — como esa columna
  está deliberadamente fuera de `$fillable`, Eloquent la descartaba sin
  avisar, incluso desde el único caller autorizado a escribirla. Ningún test
  lo detectó (ninguno afirmaba sobre esa columna) — se encontró haciendo
  clic en la UI de admin real. Corregido con asignación directa + `save()`.
- **Fuga de cupos de mentoría.** `refund()` cubre estados
  (`paid`/`pending_parent_confirmation`/`needs_admin_review`) que
  `cancel()`/`AdminController::cancelLesson()` nunca tocaban — un
  force-refund de una clase de mentoría más allá de `scheduled` habría
  dejado `mentorship_slots_taken` tomado para siempre.
- **Guard de saldo ausente.** `consume()`/`refund()` no validaban
  `credits_reserved` suficiente antes de escribir — a diferencia del resto
  de mutaciones financieras del repo. Una desincronización ledger↔saldo
  habría empujado el saldo a negativo en silencio en vez de abortar.
- Un par de endurecimientos menores: `SendClassReminders` podía re-notificar
  una lección que un admin acababa de forzar-cerrar (recheck de `status`
  bajo lock añadido); `LessonController::cancel()`/`cancelLesson()` podían
  dar un 500 crudo ante una lección legacy sin ledger (convertido a 422
  accionable).

### Riesgos residuales (documentados, no bloqueantes)

1. **`cancel()` y `AdminController::cancelLesson()` NO se migraron a
   `LessonSettlementService`** — deuda técnica explícita, deliberada: ya
   funcionan, están probados, y migrarlos no formaba parte del bug de C-1.
   Dos implementaciones de "reembolsar desde scheduled" siguen coexistiendo
   con el servicio nuevo.
2. **Deadlock `reschedule` × `reschedule`**, documentado al cerrar C-2 — es
   preexistente y C-1 no lo agrava (una transacción por lección en el
   scheduler impide que settle participe en él).
3. **Legacy NO_LEDGER** — 0 lecciones así existen hoy (verificado con
   `mova:reconcile-ledger`), y `store()` garantiza 1 reserva por diseño, pero
   si alguna vez apareciera una, `reservedCreditAmount()` lanza en vez de
   fabricar un monto — es una decisión deliberada de "fallar ruidoso", no un
   caso sin manejar.
4. **Una decisión de negocio de la Fase 3B sigue sin cerrar** (documentado
   ahí como pendiente, no decidido unilateralmente):
   - Política de reintento si el scheduler falla repetidamente sobre la
     misma lección — hoy simplemente la vuelve a intentar cada hora
     indefinidamente, sin límite ni alerta.
5. **Incidente de MySQL local (2026-08-21, no relacionado con C-1 en sí):**
   XAMPP/MariaDB se corrompió (`mysql.db`, tabla interna de privilegios, no
   los datos de `mova`) tras un corte abrupto durante esta misma sesión —
   diagnosticado con `mysqld --console` y corregido restaurando solo esa
   tabla desde `C:\xampp\mysql\backup\mysql\`. Mencionado aquí por si vuelve
   a ocurrir: el error característico es
   `Can't open and lock privilege tables: Incorrect file format 'db'`.

### Decisión de negocio #3 resuelta — notificación de cierre automático

Implementada tras el cierre inicial de esta sección: `LessonSettledNotification`
(`database` + `mail`, sin WhatsApp/broadcast — es informativa, no urgente),
disparada exclusivamente por `LessonSettlementService::consume(..., notify:
true)`, usado únicamente por `mova:settle-lessons` — nunca por la reseña ni
por `force-complete` de admin, que ya tienen sus propias notificaciones o no
necesitan una. Texto distinto por rol (`hasRole('teacher')`): al profesor se
le dice que su crédito ya se liquidó, al padre que puede calificar. Nunca
incluye `jitsi_room`/`jitsi_password` ni una URL de `meet.jit.si` (mismo
riesgo que C-3) — verificado con un test dedicado que fuerza esos campos en
la lección y confirma su ausencia del payload.

### Verificación (2026-08-21)

- `php artisan test` → **150/150** (132 previos + 10 escenarios A–J de la
  máquina de estados completa + 2 de regresión del guard de saldo + 6 de la
  notificación de cierre automático — Decisión #3, incluido el guard
  notify+actorId encontrado en su propia pasada de code-review).
- `mova:reconcile-ledger --json` → GREEN, 0 anomalías, 0 descuadres, contra
  MySQL real.
- `mova:settle-lessons --dry-run` → 0 candidatas (nada vencido todavía en la
  BD local).
- `npm run build` → limpio.
- Commit `5d72dcd` — sin push todavía.
