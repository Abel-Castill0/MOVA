# MOVA — Auditoría Fase 0: Integraciones Externas

**Fecha de análisis:** 2026-08-26
**Alcance:** Solo lectura de código. Sin conexiones reales a ningún proveedor. Sin modificaciones al repo.
**Metodología:** (1) lectura de `MOVA_MASTER_CONTEXT.md` §3, `docs/MOVA_SYSTEM_KNOWLEDGE.md` §9/10/11/13, `docs/MOVA_QA_SECURITY_POLICY.md`, `docs/MOVA_CREDENTIAL_EXPOSURE.md`; (2) verificación línea por línea contra el código real (`app/`, `config/`, `routes/`, `composer.json`/`composer.lock`, `.env.example`).

---

## 1. Tabla de clasificación

| # | Integración | Paquete/mecanismo | Clasificación | Severidad de riesgo residual |
|---|---|---|---|---|
| 1 | WhatsApp (Meta Cloud API) | Cliente `Http` nativo, contrato propio | ACTIVA NO CRÍTICA (apagada por defecto, `WHATSAPP_ENABLED=false`) | P2 — sin cuenta Meta real conectada; diseño sólido |
| 2 | Culqi / pagos automáticos | `app/Payment/*` | CONFIGURADA PERO NO USADA (stub deliberado) | P3 — no hay superficie de ataque porque no hay ruta HTTP |
| 3 | JaaS / Jitsi (videollamadas) | `firebase/php-jwt`, `JaasService` | ACTIVA Y CRÍTICA | P1 — depende 100% de `JAAS_PRIVATE_KEY` bien gestionada; sin ella la app se degrada mal (ver §4) |
| 4 | Correo — Gmail API | `GmailApiMailChannel`, `GmailApiMailService` | ACTIVA Y CRÍTICA (mailer por defecto en `.env.example`) | P2 — sin retry, token nuevo por email |
| 5 | Correo — SMTP (Gmail) | Laravel Mail nativo | ACTIVA NO CRÍTICA (solo dev local / fallback) | P3 |
| 6 | Correo — Resend | `resend/resend-laravel` | CONFIGURADA PERO NO USADA por defecto (alternativa documentada, sin activar) | P4 |
| 7 | Sentry | `sentry/sentry-laravel` | CONFIGURADA PERO NO USADA activamente (DSN vacío por defecto) | P3 |
| 8 | Socialite / Google Login | `laravel/socialite` | **ACTIVA NO CRÍTICA** — contradice el contexto previo, ver §3 | P2 — gateada por flag pero con implicación de rol fija |
| 9 | Pusher / Laravel Echo | `pusher/pusher-php-server`, `laravel-echo`, `pusher-js` | ACTIVA NO CRÍTICA | P3 — degrada con gracia (solo se pierde tiempo real, Inertia sigue funcionando) |
| 10 | Cloudinary | `cloudinary-labs/cloudinary-laravel` | **ACTIVA NO CRÍTICA** — contradice el contexto previo, ver §3 | P2 — sin validación antivirus/contenido, pero con fallback a disco local |
| 11 | Redis | Ninguno instalado (`predis`/`phpredis` ausentes) | NO INTEGRADA (config default de Laravel, nunca activada) | P4 — informativo |
| 12 | AWS S3 | Ninguno usado (`FILESYSTEM_DISK=local`) | NO INTEGRADA (decisión deliberada) | P4 |
| 13 | IA / LLMs (Gemini, OpenAI) | Cliente `Http` nativo | ACTIVA NO CRÍTICA (con fallback determinista garantizado) | P3 — ya documentado extensamente, sin cambios de fondo |

---

## 2. Detalle por proveedor

### 2.1 WhatsApp — Meta Cloud API

- **Propósito:** verificación de teléfono (OTP) y notificaciones de negocio (recordatorios, confirmaciones, recargas).
- **Config:** `config/services.php` → `services.whatsapp.*`, `services.meta_whatsapp.*`. Env: `WHATSAPP_ENABLED` (default `false`), `WHATSAPP_PROVIDER` (default `fake`), `WHATSAPP_MODE`, `WHATSAPP_REQUIRE_VERIFIED_PHONE`, `META_WHATSAPP_PHONE_NUMBER_ID/ACCESS_TOKEN/APP_ID/APP_SECRET/WEBHOOK_VERIFY_TOKEN/API_VERSION`, `META_WHATSAPP_TEMPLATE_*`.
- **Request/response:** `MetaCloudApiProvider::sendTemplate()` hace `POST https://graph.facebook.com/{version}/{phone_number_id}/messages` con `Http::withToken()->timeout(10)`.
- **Reintentos:** **deliberadamente ninguno.** El código documenta explícitamente por qué: Meta no expone una idempotency key propia, así que reintentar "a ciegas" tras un timeout/excepción arriesgaría doble envío. Un fallo de red se clasifica como `unknown`, no como `failed` — distinción correcta y poco común de ver bien hecha.
- **Idempotencia:** dos capas independientes en el webhook (`whatsapp_webhook_events` con UNIQUE en BD sobre un hash `sha256(messageId:status:timestamp)`, más una regla de "solo avanza" que nunca retrocede el ciclo `sent→delivered→read`, y trata `failed`/`skipped` como terminales).
- **Webhook:** `WhatsAppWebhookController` — valida firma HMAC-SHA256 (`X-Hub-Signature-256`) con `hash_equals()`, límite de tamaño de payload (1MB), verificación de `hub_verify_token` con `hash_equals()`. **Correctamente fail-closed**: sin `META_WHATSAPP_APP_SECRET`/`WEBHOOK_VERIFY_TOKEN` configurados, rechaza todo.
- **Fallback:** `WhatsAppChannel::send()` nunca lanza excepción (try/catch interno en el proveedor); un fallo se loguea y el resto del flujo de notificación continúa.
- **Criticidad:** ACTIVA NO CRÍTICA — el kill-switch (`WHATSAPP_ENABLED=false`) está en `false` por defecto, y `WHATSAPP_PROVIDER=fake` es el default. `App\Support\ProviderGuard` (F-03) hace **fail-closed**: un `WHATSAPP_PROVIDER` desconocido, o `fake` en producción con la integración habilitada, **detiene el arranque de la aplicación** con `RuntimeException` explícita en vez de degradar en silencio al proveedor falso.
- **Verificado en esta sesión:** el código coincide con lo documentado en `MOVA_SYSTEM_KNOWLEDGE.md` §11 — sin discrepancias.

### 2.2 Culqi (pagos automáticos)

- **Estado real verificado (contradice ninguna expectativa — F-21 sigue igual):** `app/Payment/CulqiPaymentProvider.php` implementa `PaymentProviderContract` con **ambos métodos** (`createOrder()`, `verifyWebhook()`) lanzando `RuntimeException` explícita e informativa. Es un stub deliberado, no un olvido — el docblock lo dice y da los 3 pasos pendientes (credenciales reales, confirmar métodos habilitados en el dashboard de Culqi, revisar docs vigentes de Checkout/Órdenes/Webhooks).
- **No existe ninguna ruta HTTP de webhook de Culqi** — confirmado por búsqueda exhaustiva en `routes/api.php` y `routes/web.php`: la única ruta de webhook registrada en `routes/api.php` es la de WhatsApp. `PaymentWebhookService` (lógica de aplicación del evento normalizado) existe y está testeado, pero nada la invoca fuera de tests.
- **`PaymentWebhookEvent`** (value object) documenta explícitamente que solo debe construirse dentro de `verifyWebhook()`, nunca desde un payload HTTP crudo sin pasar por verificación de firma — buen diseño defensivo por adelantado, aunque hoy no hay tráfico real que lo ejercite.
- **`ProviderGuard`** aplica el mismo patrón fail-closed que en WhatsApp: `PAYMENT_PROVIDER` no reconocido, o `fake` en producción con `payments.enabled=true`, detiene el arranque.
- **Migraciones ya aplicadas:** `2026_08_23_000003_create_payment_orders_table.php`, `2026_08_23_000004_create_payment_webhooks_table.php` — tablas existen, sin tráfico real.
- **Conclusión sobre F-21:** el hallazgo previo de "webhook HTTP puede estar incompleto" describe con precisión el estado actual — no está "incompleto", está **intencionalmente inexistente** hasta que haya cuenta comercial real. No hay regresión ni mejora desde la última auditoría; la arquitectura (contrato + stub + tablas + servicio) está lista para recibir una implementación real sin tocar el núcleo financiero.
- **Criticidad:** CONFIGURADA PERO NO USADA. Cero superficie de ataque activa (sin ruta = sin payload aceptado).

### 2.3 JaaS / Jitsi (videollamadas con menores) — atención especial

- **Generación de sala:** `LessonController::store()` genera `jitsi_room = "mova-lesson-{id}-" . Str::random(32)` (nota: el código actual usa 32 caracteres aleatorios, no 8 como decía una versión más antigua del master context citada en el prompt — **discrepancia menor, ver §3**).
- **JWT (`app/Services/JaasService.php`):**
  - Firma RS256 con `JAAS_PRIVATE_KEY` (nunca sale del backend), usando `firebase/php-jwt`.
  - Header incluye `kid` (Key ID, distinto del App ID) — requerido por JaaS o rechaza con "Missing Key ID (kid)".
  - Payload: `aud=jitsi`, `iss=chat`, `sub=$appId`, `room`, `exp`, `nbf`, `context.user.{name,moderator}`, y **deshabilita explícitamente** `livestreaming`/`recording`/`transcription` — bien pensado para un contexto con menores.
  - `abort_unless()` si `JAAS_APP_ID`/`JAAS_PRIVATE_KEY`/`JAAS_KEY_ID` no están configurados → **HTTP 500 explícito**, no una sala sin protección servida en silencio.
  - Ventana de expiración acotada por el llamador (`LessonController::join()`), con fallback a 24h solo si nadie pasa el valor — documentado como corrección de F-06 (antes SIEMPRE 24h, ventana enorme para una clase de 60 min).
  - Expiración mínima operativa de 5 minutos si el reloj está desfasado — evita emitir tokens ya vencidos.
- **Quién lo obtiene:** solo `LessonController::join()`, tras `LessonPolicy::view()` + chequeo de estado de la clase. Respuesta con `Cache-Control: no-store, private`. `jitsi_room` está oculto por `$hidden` en el modelo `Lesson` — nunca viaja en el listado de clases.
- **Nunca en canales de notificación:** verificado que `ClassConfirmedNotification`/`ClassReminderNotification`/`LessonSettledNotification` no incluyen `jitsi_room`/token en su texto (protegido por `NotificationSecurityTest.php`, que busca la cadena real de la sala, no un string genérico).
- **Sin credenciales configuradas (`.env.example` las deja vacías: `JAAS_APP_ID`, `JAAS_PRIVATE_KEY`, `JAAS_KEY_ID`):** el sistema falla con `abort_unless()` → 500 al intentar generar un token. **No degrada silenciosamente a una sala pública sin protección** — esto es la postura correcta para un producto con videollamadas de menores: mejor romper visiblemente que servir una sala sin control de acceso.
- **Nota de discrepancia importante (ver §3):** el `MOVA_MASTER_CONTEXT.md` (fechado 2026-07-18) todavía describe en su §5 "C3" una arquitectura de Jitsi **sin JWT** (`meet.jit.si` público, *security through obscurity* con `Str::random(8)`). El código real de `JaasService.php` y la sección 13 de `MOVA_SYSTEM_KNOWLEDGE.md` reflejan una migración posterior a JaaS con JWT RS256 real — es decir, **la recomendación de remediación C3 del master context ya fue implementada**, pero el propio documento maestro no se actualizó para reflejarlo. Ver detalle en §3.
- **Criticidad:** ACTIVA Y CRÍTICA. Es la dependencia externa con mayor impacto en seguridad de menores del sistema. El diseño actual es sólido (JWT firmado, ventana de acceso acotada, sin persistencia de URL/token en payloads de notificación), pero depende enteramente de que `JAAS_PRIVATE_KEY` esté bien protegida en producción — no se pudo verificar el estado real de esa credencial en Railway (ver §5).

### 2.4 Correo — Gmail API / SMTP / SafeMailChannel / Resend

- **`SafeMailChannel`** (bind() reemplaza `MailChannel` en `AppServiceProvider::register()`): orquesta el fallback según `config('mail.default')`.
  - Si `gmail_api`: intenta `GmailApiMailChannel`; si falla (o falta `GMAIL_REFRESH_TOKEN`) y hay credenciales SMTP completas (`host`+`username`+`password`), reintenta vía SMTP cambiando `mail.default` temporalmente y restaurándolo en `finally`. Si no hay SMTP configurado, se resigna silenciosamente (logueado como warning).
  - Si `array`/`log`: no-op (evita ruido en tests/dev).
  - Si `resend` sin `RESEND_API_KEY`: skip logueado, no intenta enviar.
  - Si `smtp` sin credenciales: skip logueado.
  - Cualquier excepción de `parent::send()` se captura y loguea — **nunca deja que un fallo de correo tumbe el job de notificación** (coherente con `ShouldQueue` en casi todas las notificaciones).
- **`GmailApiMailChannel` + `GmailApiMailService`:** construye el MIME multipart/alternative a mano y llama `POST gmail.googleapis.com/gmail/v1/users/me/messages/send` con `Http::timeout(15)`. **Obtiene un access token nuevo en cada envío** (`getAccessToken()` llama a `oauth2.googleapis.com/token` con el refresh_token, timeout 10s) — no cachea el token entre envíos. Esto es una llamada HTTP extra por cada correo (2 requests salientes por email), sin caché ni reutilización dentro de la ventana de validez del access token (~1h). No es un fallo funcional pero sí ineficiencia bajo volumen y un punto de fallo adicional por correo.
- **Sin reintentos** en ninguna de las dos llamadas (`getAccessToken`/`send`) — un solo intento, sin backoff. El pequeño colchón viene de que las notificaciones ya corren en cola (`ShouldQueue`) con sus propios reintentos de Laravel (`--tries=3 --backoff=5` en el comando del worker), así que un fallo transitorio de Gmail sí se reintenta, pero a nivel de "job completo", no de esta llamada específica.
- **`config/mail.php`:** el mailer `'gmail_api'` **no está declarado** en el array `mailers` de este archivo — funciona porque `SafeMailChannel` intercepta la rama `gmail_api` *antes* de que Laravel's `MailManager` intente resolver una config de mailer con ese nombre (que fallaría con "Mailer [gmail_api] is not defined" si se le pidiera). Es un acoplamiento implícito: si alguien más llamara `Mail::mailer('gmail_api')` directamente (fuera de `SafeMailChannel`), fallaría. No se encontró ningún otro punto en el código que lo intente.
- **Mailer activo por defecto:** `.env.example` documenta `MAIL_MAILER=gmail_api` como Opción A (recomendada para Railway Hobby, que bloquea SMTP saliente), con SMTP como Opción C solo para desarrollo local (comentada) y Resend como Opción B alternativa (comentada).
- **Resend:** paquete instalado (`resend/resend-laravel ^1.4`), transport declarado en `config/mail.php`, pero no es el mailer activo por defecto — documentado como alternativa "si tienes dominio propio". `.env.example` también documenta `RESEND_WEBHOOK_SECRET` opcional para bounces/quejas, explícitamente marcado como no crítico si se omite.
- **Criticidad:** ACTIVA Y CRÍTICA — el correo es el canal de verificación de cuenta (`MustVerifyEmail`) y de notificaciones de negocio. El diseño de fallback en cascada (Gmail API → SMTP → log) es real, no aspiracional, pero el fallback SMTP solo funciona si además se configuran credenciales SMTP completas en producción (hoy no confirmado si Railway las tiene configuradas en paralelo a Gmail API — no se pudo verificar desde el código, es una variable de entorno de producción).

### 2.5 Sentry

- **Paquete:** `sentry/sentry-laravel ^4.26`, con `config/sentry.php` completo y bien documentado (DSN, sample rates, `ignore_transactions` para `/up`, integración con Redis command tracing — aunque Redis no está instalado, ver §2.11).
- **Auto-wiring:** el paquete se auto-registra vía Laravel package discovery (no hay entrada manual en `config/app.php['providers']`, ni código en `app/Exceptions/Handler.php` — `register()` tiene un `reportable()` vacío). Esto es el patrón normal de `sentry-laravel` en Laravel 10 (no necesita tocar el Handler).
- **Estado real:** `SENTRY_LARAVEL_DSN` está **vacío en `.env.example`** — sin un DSN real configurado en el entorno, el SDK no envía nada (el propio SDK de Sentry se auto-desactiva sin DSN, es su comportamiento estándar, no un bug de MOVA).
- **Criticidad:** CONFIGURADA PERO NO ACTIVA por defecto. El master context afirma "verificado end-to-end" — eso implica que en algún momento se probó con un DSN real (no versionado, correctamente). No se pudo re-verificar en esta sesión sin credenciales reales, y no se intentó (fuera de alcance: "no te conectes a servicios externos reales").

### 2.6 Socialite / Google Login — activo, no documentado previamente (discrepancia confirmada)

- **`app/Http/Controllers/Auth/GoogleAuthController.php`** existe y está **wireado a rutas reales**: `GET /auth/google` (`auth.google`) y `GET /auth/google/callback` (`auth.google.callback`), registradas en `routes/auth.php` dentro del grupo `middleware('guest')`.
- **Flujo:** `redirect()` → `abort_unless(config('services.google.login_enabled'), 503, ...)` → `Socialite::driver('google')->redirect()`. `callback()` → `Socialite::driver('google')->stateless()->user()` (con try/catch que reporta la excepción y redirige a login con error amigable) → vincula **por email** vía `User::firstOrCreate()` (no por `google_id`) → si es cuenta nueva, **asigna rol `parent` automáticamente** y dispara `Registered` event → si ya existía sin email verificado, lo marca verificado (login por Google ya implica verificación) → `Auth::login()` + regeneración de sesión.
- **Gate doble (frontend + backend):** `GOOGLE_LOGIN_ENABLED` (default `false`) se comprueba tanto en el modal del frontend como en el backend — un enlace directo a `/auth/google` no puede saltarse el "stand by" solo ocultando el botón. Buen patrón.
- **Hallazgo de diseño a señalar (no necesariamente un bug):** toda cuenta nueva creada vía Google se asigna automáticamente el rol `parent`, sin posibilidad de elegir `teacher` en ese flujo — un profesor que intente "Continuar con Google" terminaría con una cuenta de padre. No se encontró ningún mecanismo de selección de rol post-callback. Esto es una limitación funcional del flujo social, no una vulnerabilidad de seguridad.
- **Env:** `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` (opcional, default `{APP_URL}/auth/google/callback`), `GOOGLE_LOGIN_ENABLED` — todos vacíos/false en `.env.example`, consistente con "stand by" documentado en comentarios.
- **Corrección de la brief de la Fase 0:** la instrucción de la tarea decía "Socialite... actualmente no aparece en ningún flujo de autenticación ya documentado" — **esto ya no es así**: el flujo existe, está completo, gateado correctamente y documentado con comentarios extensos en el propio código. Es plausible que se haya añadido después de que se escribiera el `MOVA_MASTER_CONTEXT.md` (fechado 2026-07-18) sin que ese documento se actualizara.
- **Criticidad:** ACTIVA NO CRÍTICA (apagada por defecto vía `GOOGLE_LOGIN_ENABLED=false`).

### 2.7 Pusher / Laravel Echo (tiempo real)

- Confirmado sin cambios respecto al master context: `pusher/pusher-php-server ^7.2` (backend), `laravel-echo ^2.4.0` + `pusher-js ^8.5.0` (frontend, verificado en `package.json`).
- `App\Providers\BroadcastServiceProvider` está registrado en `config/app.php` (línea 183) y activo.
- `routes/channels.php` define un único canal privado: `App.Models.User.{id}`, autorizado solo si `(int) $user->id === (int) $id` — correcto y mínimo.
- `config/broadcasting.php` → `'default' => env('BROADCAST_DRIVER', 'null')` — sin `BROADCAST_DRIVER=pusher` configurado, degrada a `null` (sin tiempo real, sin excepción).
- **Criticidad:** ACTIVA NO CRÍTICA — degrada con gracia: sin Pusher configurado, Inertia sigue sirviendo todo por request/response normal; solo se pierde el toast en vivo y el refresco automático de notificaciones (`NotificationBell` sigue funcionando por polling cada 30s independiente del WebSocket).

### 2.8 Cloudinary — activo, no documentado previamente como código existente (discrepancia confirmada)

- **`cloudinary-labs/cloudinary-laravel ^2.3`** SÍ está instalado en `composer.json` (confirmado, no es solo una entrada huérfana).
- **`app/Services/CloudinaryService.php`** existe y se usa en `ProfileController::updateAvatar()` para subir fotos de perfil.
- **Lógica de fallback real, no aspiracional:** `isConfigured()` comprueba `filled(config('cloudinary.cloud_url'))`; si no hay `CLOUDINARY_URL`, `uploadAvatar()` cae a `Storage::disk('public')` con `storeAs('avatars', "user-{$userId}.".$file->extension(), 'public')` — la app funciona en desarrollo sin cuenta de Cloudinary, exactamente el patrón fail-soft que MOVA usa en otras integraciones.
- **Validación de entrada:** en el controlador, `avatar` se valida como `['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096']` (4MB) antes de llegar al servicio — razonable para evitar subir archivos arbitrarios, aunque no hay escaneo antivirus/contenido (fuera de alcance típico para un MVP).
- **Transformaciones aplicadas en la subida real a Cloudinary:** `400x400`, `crop=fill`, `gravity=face`, `quality=auto`, `fetch_format=auto` — recorte centrado en rostro, razonable para avatares.
- **`public_id` determinístico** (`user-{$userId}`) con `overwrite: true` — cada usuario tiene como máximo un asset vivo en Cloudinary bajo ese ID; subir uno nuevo sobrescribe el anterior automáticamente (no acumula basura por reemplazos).
- **Deuda conocida y documentada en el propio código (`ProfileController.php`, comentario TODO):** `removeAvatar()` solo limpia `avatar_url` en la BD — **no borra el asset remoto en Cloudinary**. El archivo queda huérfano en la cuenta de Cloudinary indefinidamente tras cada "quitar foto". No es una vulnerabilidad de seguridad (nadie más puede acceder al asset sin la URL), pero sí acumulación de almacenamiento no reclamado con el tiempo.
- **Env:** `CLOUDINARY_URL` (formato `cloudinary://API_KEY:API_SECRET@CLOUD_NAME`), vacío en `.env.example`.
- **Corrección de la brief de la Fase 0:** la instrucción decía "Cloudinary (almacenamiento de archivos) — no documentado aún, investiga desde cero" apoyándose en el `MOVA_MASTER_CONTEXT.md` que afirma "❌ No integrado — Sin paquete ni código. Credenciales de respaldo probadas → 401 cloud_name mismatch". **Esto es incorrecto respecto al estado actual del código**: el paquete está instalado, hay un servicio dedicado, y está wireado a un endpoint HTTP real (`POST /profile/avatar` o equivalente en `routes/web.php` bajo el controlador de perfil). Es plausible que el master context describa un intento anterior con credenciales que no funcionaron, y que el código haya avanzado después sin que ese documento se actualizara.
- **Criticidad:** ACTIVA NO CRÍTICA — sin `CLOUDINARY_URL`, la funcionalidad de avatar sigue funcionando vía disco local; ninguna otra parte del sistema depende de Cloudinary.

### 2.9 Redis

- **Confirmado: ningún paquete cliente de Redis (`predis/predis` ni `ext-redis`/phpredis wrapper) aparece como paquete instalado de nivel superior en `composer.lock`** — las únicas menciones de `predis/predis` en ese archivo son como **sugerencia (`suggest`)** de otros paquetes (`illuminate/redis`, `guzzlehttp/guzzle`, `sentry/sentry-laravel`), no como dependencia resuelta e instalada.
- `config/database.php`, `config/cache.php`, `config/queue.php`, `config/broadcasting.php`, `config/session.php` todos mencionan `redis` como una opción soportada (boilerplate estándar de Laravel), pero ninguno de los drivers activos (`CACHE_DRIVER`, `SESSION_DRIVER`, `QUEUE_CONNECTION`, `BROADCAST_DRIVER`) apunta a `redis` en `.env.example` — todos usan `file`/`database`/`pusher`.
- **Conclusión:** Redis es exactamente lo que el brief sospechaba — configuración por defecto de un `.env.example` generado por el scaffolding estándar de Laravel, nunca activada ni con el cliente PHP instalado para poder activarla sin antes correr `composer require predis/predis`. No es una integración "rota" ni "a medio hacer" — es simplemente boilerplate inerte.
- **Criticidad:** NO INTEGRADA (config default nunca activada). Sin riesgo — informativo únicamente.

### 2.10 IA / LLMs (Gemini/OpenAI) — sin cambios de fondo

Verificado consistente con `MOVA_MASTER_CONTEXT.md` §3.3 y `config/diagnostic.php` — no se encontraron discrepancias en esta pasada. Guardas de seguridad (scoring determinista nunca delegado a IA, anonimización antes de enviar texto, rate limiting diario/mensual, auto-desactivación tras errores consecutivos, fallback garantizado) confirmadas en el código, sin repetir el detalle completo aquí para no duplicar lo ya bien documentado.

---

## 3. Discrepancias entre lo documentado previamente y el código real

| # | Documento que lo afirma | Afirmación previa | Estado real verificado | Impacto |
|---|---|---|---|---|
| D1 | `MOVA_MASTER_CONTEXT.md` §3.7 | "Cloudinary ❌ No integrado — Sin paquete ni código. Credenciales de respaldo probadas → 401 cloud_name mismatch" | **Integrado y en uso**: paquete instalado, `CloudinaryService.php` completo, usado en `ProfileController::updateAvatar()`, con fallback real a disco local | Medio — un lector del master context concluiría erróneamente que no hay superficie de almacenamiento de archivos de terceros que auditar |
| D2 | Brief de esta Fase 0 (basado en documentación previa) | "Socialite... actualmente no aparece en ningún flujo de autenticación ya documentado" | **Sí aparece**: `GoogleAuthController` completo, rutas registradas, gateado por flag doble (frontend+backend) | Medio — falta cubrir en la superficie de autenticación auditada hasta ahora (ningún documento previo menciona login social) |
| D3 | `MOVA_MASTER_CONTEXT.md` §5 "C3" | Describe Jitsi como *"security through obscurity"*, sala pública en `meet.jit.si` con `Str::random(8)`, sin JWT, y lo lista como riesgo CRÍTICO sin resolver, recomendando migrar a JaaS con JWT | El código real (`JaasService.php`, `docs/MOVA_SYSTEM_KNOWLEDGE.md` §13) ya implementa exactamente esa recomendación: JaaS con JWT RS256, `Str::random(32)` (no 8), ventana de acceso acotada por backend, `livestreaming/recording/transcription` deshabilitados | Alto para la lectura del documento — un lector del master context concluiría que MOVA todavía tiene el riesgo crítico C3 sin resolver, cuando en realidad ya fue remediado en una ronda posterior no reflejada en ese documento |
| D4 | Ninguno explícito, pero implícito en el brief ("Culqi... puede estar incompleto") | Sugiere que el estado del webhook de Culqi podría haber cambiado desde F-21 | **Sin cambios**: sigue exactamente como describe `MOVA_SYSTEM_KNOWLEDGE.md` §10 — stub deliberado, sin ruta HTTP, arquitectura lista mas no conectada | Ninguno — el documento más reciente (`MOVA_SYSTEM_KNOWLEDGE.md`) ya tenía razón; solo el `MOVA_MASTER_CONTEXT.md` más antiguo podría inducir a pensar que faltaba verificar |
| D5 | `MOVA_MASTER_CONTEXT.md`, fechado 2026-07-18 | Documento se presenta como referencia vigente de integraciones | El propio repositorio tiene documentos más recientes y más precisos (`MOVA_SYSTEM_KNOWLEDGE.md`, `MOVA_CREDENTIAL_EXPOSURE.md`, `MOVA_QA_SECURITY_POLICY.md`, todos fechados 2026-08-26) que ya corrigen o profundizan varias de estas áreas | Meta-hallazgo: el `MOVA_MASTER_CONTEXT.md` quedó desactualizado como fuente de verdad para integraciones externas; cualquier auditoría futura debería tratarlo como snapshot histórico, no como estado actual, y priorizar `MOVA_SYSTEM_KNOWLEDGE.md`/docs con fecha más reciente cuando haya conflicto |

**No se encontraron discrepancias** en: WhatsApp/Meta Cloud API, Pusher/Echo, Sentry, Redis, IA/Gemini-OpenAI, Culqi/pagos — todos coinciden con lo ya documentado en las fuentes más recientes.

---

## 4. Riesgos nuevos identificados en esta ronda

| ID | Riesgo | Severidad | Detalle |
|---|---|---|---|
| **N-1** | Documentación de integraciones desactualizada como fuente de verdad | 🟠 P2 | `MOVA_MASTER_CONTEXT.md` (2026-07-18) describe Cloudinary como no integrado y Jitsi sin JWT — ambos ya superados por el código real y por documentos posteriores del propio repo. Riesgo operativo: alguien que audite o tome decisiones basándose solo en el master context tomaría decisiones sobre una arquitectura que ya no existe (p. ej. re-implementar la migración a JaaS que ya está hecha, o descartar Cloudinary como "no aplica" al planear borrado de archivos). **Acción sugerida:** añadir una nota de vigencia al inicio de §3 y §5 del master context señalando que fueron superadas, siguiendo el mismo patrón que ya se usa en `MOVA_SYSTEM_KNOWLEDGE.md` §11 ("Nota de vigencia añadida en una ronda posterior"). |
| **N-2** | GmailApiMailService sin caché de access token ni reintento | 🟢 P3 | Cada envío de correo hace 2 llamadas HTTP externas (obtener token + enviar), sin cachear el `access_token` (válido ~1h) entre envíos ni reintentar ante un fallo transitorio de red. Bajo volumen alto (recordatorios masivos vía `SendClassReminders`, que corre cada minuto), esto multiplica innecesariamente las llamadas a `oauth2.googleapis.com` y aumenta la ventana de fallo por correo. Mitigado parcialmente porque las notificaciones corren en cola con reintentos a nivel de job (`--tries=3`), pero no a nivel de esta llamada específica. **No es una vulnerabilidad**, es una ineficiencia con costo operativo bajo a la escala actual de MOVA. |
| **N-3** | Login social asigna rol `parent` sin posibilidad de elegir `teacher` | 🟢 P3 | Un profesor que intente registrarse vía "Continuar con Google" terminaría con una cuenta de padre sin ruta de corrección visible en el flujo (tendría que contactar soporte o registrarse de nuevo con email/password eligiendo rol). No es una falla de seguridad, es una limitación de producto que vale la pena que el equipo de producto conozca antes de promover el botón de Google para profesores. |
| **N-4** | Cloudinary: `removeAvatar()` no limpia el asset remoto (ya reconocido en el propio código vía TODO) | 🟢 P4 | Acumulación de archivos huérfanos en la cuenta de Cloudinary con el tiempo. Bajo impacto porque no hay credenciales reales conectadas todavía (`CLOUDINARY_URL` vacío) — el problema solo se materializa cuando MOVA conecte una cuenta real de Cloudinary en producción. Vale la pena resolverlo antes de esa conexión, no después. |
| **N-5** | Dependencia crítica de `JAAS_PRIVATE_KEY` sin mecanismo de rotación visible en el código | 🟠 P2 | `JaasService` lee la clave privada directamente de `config('jaas.private_key')` (env var) en cada llamada — no hay versionado de claves (`kid` es fijo por configuración, no hay lógica para aceptar múltiples `kid` durante una rotación). Si la clave se filtra, rotarla requeriría cambiar la env var y siempre generar tokens con la nueva clave inmediatamente, sin ventana de transición para tokens ya emitidos con la clave vieja (los tokens tienen TTL corto por diseño de F-06, lo cual mitiga bastante este riesgo — un token viejo expira solo, no vive "para siempre"). Riesgo bajo en la práctica gracias al TTL corto, pero vale la pena que quede documentado como una limitación conocida de la arquitectura actual, no asumida como respondida. |

---

## 5. Qué no se pudo verificar, y por qué

- **Si las credenciales reales de Meta/WhatsApp, Culqi, JaaS, Cloudinary, Sentry, Gmail o Google OAuth funcionan de verdad contra el proveedor real** — no se intentó ninguna llamada de red saliente real, por instrucción explícita de la Fase 0 ("no te conectes a ningún servicio externo real"). Todo lo verificado en este documento es análisis estático de código y configuración, no una prueba de humo en vivo.
- **Si `JAAS_PRIVATE_KEY` está protegida adecuadamente en Railway (producción)** — el repositorio no expone esa información; solo se pudo confirmar que el código la lee de una variable de entorno y nunca la persiste en BD ni la expone en respuestas HTTP. El estado de esa credencial en el panel de Railway está fuera del alcance de un análisis del código.
- **Si el fallback SMTP de `SafeMailChannel` tiene credenciales reales configuradas en producción en paralelo a Gmail API** — el código soporta el fallback correctamente, pero si las variables `MAIL_HOST`/`MAIL_USERNAME`/`MAIL_PASSWORD` no están configuradas en el entorno de producción de Railway (algo que no se puede ver desde el repositorio), el fallback nunca se activaría realmente ante un fallo de Gmail API — degradaría a "email no enviado, solo logueado".
- **Si el botón de "Continuar con Google" está actualmente visible en el frontend** — se confirmó el backend (rutas + controlador + gate), pero no se inspeccionó el componente Vue del formulario de login/registro para confirmar si el botón se renderiza condicionalmente según `GOOGLE_LOGIN_ENABLED` compartido al frontend vía Inertia, o si esa bandera ni siquiera llega al cliente. Esto requeriría revisar `HandleInertiaRequests::share()` y las páginas `Login.vue`/`Register.vue`, fuera del alcance estricto de "integraciones externas" pero relevante para entender el estado end-to-end del feature.
- **Si `RESEND_WEBHOOK_SECRET` o cualquier webhook de Resend está de verdad conectado** — el `.env.example` lo documenta como opcional y no crítico; no se encontró ningún controlador de webhook de Resend en `app/Http/Controllers/`, así que se asume no implementado, pero no se hizo una búsqueda exhaustiva dedicada a esa integración específica más allá de confirmar el paquete y el mailer.
- **Privilegios reales de cualquier credencial de terceros ya emitida** (p. ej. si el `META_WHATSAPP_ACCESS_TOKEN` o `JAAS_PRIVATE_KEY` que puedan existir en Railway hoy tienen el alcance mínimo necesario) — no verificable desde el código, requeriría acceso al panel de cada proveedor.

---

## Resumen de metodología (para repetibilidad)

```
# Paquetes realmente instalados (no solo mencionados en docs)
grep -A2 -B2 -E "cloudinary|firebase|guzzle|socialite|pusher|resend|sentry|predis" composer.json
grep -n '"name":' composer.lock | grep -i redis   # confirmar ausencia real de cliente Redis

# Uso real de cada paquete (no solo su presencia en composer.json)
grep -rn "Cloudinary\|Socialite\|Redis" app config routes

# Rutas HTTP reales que exponen cada integración
grep -rn "Webhook\|webhook" routes/*.php

# Verificación de wiring de Sentry (sin tocar app/Exceptions/Handler.php manualmente)
grep -n "Sentry" config/app.php app/Exceptions/Handler.php
```
