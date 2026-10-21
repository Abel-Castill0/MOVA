> Estado: CURRENT · 2026-10-21 (Actualizado 18:00 UTC-5)

# Registro Completo de Cambios: UI / Mascota Movi, Integración WAHA (WhatsApp) y Entorno Local Docker (2026-10-21)

**Fecha y última actualización:** 2026-10-21 (01:17 – 18:00 UTC-5)
**Rama actual de trabajo:** `Elias-rama` / integrada en `master`
**Propósito de este documento:** Proveer contexto completo, trazable y verificable para cualquier agente de IA o desarrollador que tome el proyecto en este punto, incluyendo qué está commiteado, qué está pendiente de commit, qué contenedores están corriendo y cómo operarlos.

---

## 1. Estado Actual de Git (`Elias-rama`)

### 1.1. Commit ya realizado en `Elias-rama` (`040e673`)
- **Commit:** `040e673` — `feat(ui): animaciones de Movi en Como funciona, ocultar precio en destacados y fix navbar` (subido a `origin/Elias-rama`).
- Incluye todos los cambios de la **Fase 1 (UI / Mascota Movi / Navbar)** detallados en la sección 3 de este documento.

### 1.2. Cambios actuales en el Working Tree (Pendientes de commit en `Elias-rama`)
Corresponden a la **Fase 2 (Integración de WhatsApp con WAHA)** y **Fase 3 (Bypass de autenticación/verificación en entorno `local`)**:

| Estado Git | Archivo | Descripción |
| :--- | :--- | :--- |
| `A` (`??`) | `deploy-waha-azure.sh` | Script Bash con Azure CLI (`az`) para desplegar `mova-waha` (`devlikeapro/waha:latest`) en Azure Container Apps con persistencia en Azure File Share (`/app/.sessions`), `min-replicas 1`, `max-replicas 1` e ingress en puerto `3000`. |
| `M` | `.env.example` | Variables `WAHA_BASE_URL`, `WAHA_API_KEY` y `WAHA_DEFAULT_SESSION` documentadas. |
| `M` | `config/services.php` | Registro del bloque `'waha'` (`url`, `api_key`, `session`) junto a la configuración legacy existente. |
| `A` (`??`) | `app/Services/WhatsAppService.php` | Servicio HTTP hacia WAHA: normalización de números peruanos (`formatChatId` con prefijo `51` para 9 dígitos y sufijo `@c.us`), `sendText()`, y `checkSessionStatus()` con auto-recuperación de sesiones expiradas (`FAILED`/`STOPPED`) o desvinculadas desde el celular (`logout` + `restart`). |
| `A` (`??`) | `app/Jobs/SendWhatsAppNotificationJob.php` | Job asíncrono (`ShouldQueue`) con `$tries = 3`, `$backoff = [10, 30, 60]` que inyecta `WhatsAppService` en `handle()`. |
| `A` (`??`) | `app/Http/Controllers/Admin/WhatsAppController.php` | Controlador del panel de administración con `index()` (vista Inertia), `status()` (JSON para refresco en vivo) y `sendTest()` (envío por cola o síncrono). |
| `A` (`??`) | `resources/js/Pages/Admin/WhatsAppStatus.vue` | Vista Vue 3 (`<script setup>`) en `/admin/whatsapp` con indicador de estado en tiempo real (`WORKING`, `SCAN_QR_CODE`, `STARTING`, `FAILED`, `STOPPED`, `DISCONNECTED`) y formulario de prueba de envío. |
| `M` | `routes/web.php` | Rutas protegidas bajo el grupo `admin` (`GET /admin/whatsapp`, `GET /admin/whatsapp/status`, `POST /admin/whatsapp/send-test`). |
| `M` | `app/Http/Middleware/EnsureAdminMfa.php` | Bypass automático de 2FA/MFA de administrador únicamente cuando `app()->environment('local')` es `true`. |
| `M` | `app/Http/Controllers/Auth/AuthenticatedSessionController.php` | En entorno `local`, al iniciar sesión auto-verifica `email_verified_at` si es `null` y registra automáticamente la aceptación de términos legales vigentes (`LegalAcceptance`) para entrar directo al dashboard sin pantallas intermedias. |
| `M` | `app/Http/Controllers/Auth/RegisteredUserController.php` | En entorno `local`, asigna `email_verified_at => now()` al registrar un usuario nuevo. |

---

## 2. Arquitectura del Entorno Local Docker (4 Contenedores Activos)

Todos los contenedores corren sobre la red puente `mova-net` y tienen configurada la política `--restart unless-stopped` para levantarse automáticamente con Docker Desktop:

| Contenedor | Imagen | Puerto Host → Contenedor | Rol y Configuración Clave |
| :--- | :--- | :--- | :--- |
| **`mova-mysql`** | `mysql:8.4` | `3307 -> 3306` | Base de datos MySQL 8.4 (`DB_DATABASE=mova`, `DB_USERNAME=root`, password local de desarrollo). Contiene todas las migraciones y el `DatabaseSeeder` ejecutado (14 usuarios con `email_verified_at` y términos legales aceptados). |
| **`mova-waha`** | `devlikeapro/waha:latest` | `3000 -> 3000` | Servidor WhatsApp HTTP API (WAHA Core, motor `NOWEB`). Volumen persistente `waha_sessions:/app/.sessions`. Inicia automáticamente la sesión `default`. |
| **`mova-web`** | `mova:latest` | `8080 -> 8080` | Servidor web principal de MOVA (FrankenPHP + Caddy + PHP 8.3 + Inertia/Vue compilado). Conectado a `mova-mysql:3306` y `http://mova-waha:3000`. Directorio raíz dentro del contenedor: `/app`. |
| **`mova-worker`** | `mova:latest` | Interno (`mova-net`) | Worker de colas de Laravel ejecutando `php artisan queue:work --tries=3 --timeout=90`. Procesa `SendWhatsAppNotificationJob` contra `http://mova-waha:3000`. |

### 2.1. Accesos y Credenciales de Desarrollo Local
- **Aplicación MOVA:** `http://localhost:8080`
- **Panel Admin de WhatsApp (MOVA):** `http://localhost:8080/admin/whatsapp`
  - **Admin de prueba:** `admin@mova.pe`
  - **Profesor de prueba:** `carlos.mendoza@mova.pe`
  - **Alumno/Padre de prueba:** `maria.garcia@gmail.com`
  - *(Contraseña local de todos los usuarios sembrados por `DatabaseSeeder`: `password`)*
- **Dashboard de WAHA (Escaneo de QR):** `http://localhost:3000/dashboard`
  - **Usuario Dashboard:** `admin`
  - **Contraseña Dashboard:** `password`
  - **API Key local (`X-Api-Key`):** `mova_local_waha_key`

---

## 3. Detalle Técnico — Fase 1: Cambios UI, Mascota Movi y Navbar (Commit `040e673`)

### 3.1. Ocultación de tarifa por hora en "Profesores destacados" (`Welcome.vue`)
- **Archivo:** `resources/js/Pages/Welcome.vue`
- En las tarjetas del carrusel de la sección **Profesores destacados** (`#profesores`), se eliminó el `<span>` que mostraba el precio por hora (`S/{{ parseFloat(t.hourly_rate).toFixed(0) }}/h`) y se cambió el contenedor inferior de `justify-between` a `justify-end`.

### 3.2. Animaciones de la mascota Movi en "¿Cómo funciona MOVA?" (`MoviMascot.vue`, `Welcome.vue`, `public/images/mascot/*`)
- Las 3 tarjetas de pasos en desktop (`Solicita tu clase`, `Un experto acepta` y `Aprende en vivo`) muestran a la ardilla **Movi** asomándose con una animación WebP personalizada al hacer hover (`@mouseenter` / `@mouseleave`), con estilo visual unificado:
  1. **Paso 1 — "Solicita tu clase" (`pose="solicita"`):** `movi-solicita-200.webp`, `movi-solicita-420.webp`, `movi-solicita-anim.webp` (18 frames).
  2. **Paso 2 — "Un experto acepta" (`pose="experto"`):** `movi-experto-200.webp`, `movi-experto-420.webp`, `movi-experto-anim.webp` (18 frames).
  3. **Paso 3 — "Aprende en vivo" (`pose="celebra"`):** Rediseñado en el mismo estilo (`movi-celebra-200.webp`, `movi-celebra-420.webp`, `movi-celebra-anim.webp`).
- **Corrección de solapamiento vertical:** En `Welcome.vue`, el encabezado de la sección "¿Cómo funciona MOVA?" aumentó su margen inferior de `mb-16` a `mb-36` (`144px`) para que la animación emergente de la tarjeta central nunca tape el título.

### 3.3. Corrección del destello/línea blanca en `LandingNavbar.vue`
- **Archivo:** `resources/js/Components/LandingNavbar.vue`
- Se fijó `border-b` en la clase base del `<nav>` alternando únicamente entre `border-gray-100` y `border-transparent shadow-none`, y restringiendo la transición a `transition-[background-color,border-color,box-shadow] duration-300`.

---

## 4. Detalle Técnico — Fase 2: Integración WhatsApp con WAHA (`devlikeapro/waha:latest`)

### 4.1. Servicio Backend (`app/Services/WhatsAppService.php`)
- Lee `config('services.waha.url')`, `config('services.waha.api_key')` y `config('services.waha.session')`.
- **`formatChatId(string $phone)`:**
  - Limpia caracteres no numéricos (`\D+`).
  - Remueve prefijo internacional `00` si existe.
  - Si el número limpio tiene **9 dígitos** (estándar móvil de Perú), antepone automáticamente `51`.
  - Devuelve `[numero]@c.us` (ej. `916666934` → `51916666934@c.us`).
- **`sendText(string $phone, string $message)`:**
  - Envía `POST /api/sendText` con cabecera `X-Api-Key`, `timeout(15)` y `retry(2, 500, throw: false)`.
  - Registra errores en `Log::error()` y captura cualquier `Throwable` devolviendo un arreglo estructurado (`['success' => bool, 'data' => mixed, 'error' => ?string]`) sin interrumpir el ciclo de vida de la petición de MOVA.
- **`checkSessionStatus()`:**
  - Consulta `GET /api/sessions/{session}`.
  - **Auto-curación (Self-healing):**
    - En WAHA (`NOWEB`), si el usuario no escanea el código QR tras ~2.5 minutos, la sesión pasa a estado `FAILED` o `STOPPED`.
    - Asimismo, si un teléfono previamente vinculado elimina la sesión desde la app móvil de WhatsApp, WAHA arroja `Connection Failure` y deja la sesión en `FAILED` con datos residuales en `me`.
    - `checkSessionStatus()` detecta automáticamente si el estado es `FAILED` o `STOPPED`: si `me` no está vacío en un estado `FAILED`, ejecuta `POST /api/sessions/{session}/logout` para limpiar las credenciales revocadas, y acto seguido ejecuta `POST /api/sessions/{session}/restart`, espera `1.2s` y vuelve a consultar `GET /api/sessions/{session}`, entregando la sesión directamente en estado `SCAN_QR_CODE` con un QR fresco.

### 4.2. Job Asíncrono (`app/Jobs/SendWhatsAppNotificationJob.php`)
- Implementa `ShouldQueue` con `$tries = 3` y `$backoff = [10, 30, 60]`.
- Si `WhatsAppService::sendText()` retorna `success === false`, lanza un `RuntimeException` para que el worker de Laravel aplique los reintentos con el backoff configurado.

### 4.3. Controlador y Vista Admin (`WhatsAppController.php` & `WhatsAppStatus.vue`)
- Rutas en `routes/web.php` dentro del grupo `admin` (`auth`, `verified`, `admin.mfa`, `terms.accepted`, `role:admin`):
  - `GET /admin/whatsapp` (`admin.whatsapp.index`) → Renderiza `Admin/WhatsAppStatus.vue`.
  - `GET /admin/whatsapp/status` (`admin.whatsapp.status`) → Devuelve JSON con el estado actual de WAHA para el botón **"Actualizar"**.
  - `POST /admin/whatsapp/send-test` (`admin.whatsapp.send-test`) → Permite despachar un mensaje de prueba tanto encolado (`SendWhatsAppNotificationJob::dispatch`) como síncrono (marcando el checkbox de envío directo).

### 4.4. Script de Despliegue en Azure (`deploy-waha-azure.sh`)
- Script automatizado en Bash para Azure CLI (`az`) que crea el Azure File Share, lo registra en el Container Apps Environment, y despliega `mova-waha` con `min-replicas 1`, `max-replicas 1`, puerto `3000` y montaje persistente en `/app/.sessions`.

---

## 5. Detalle Técnico — Fase 3: Acceso Directo en Desarrollo Local (`APP_ENV=local`)

En producción y staging se mantienen intactos todos los controles de seguridad (`verified`, `admin.mfa`, `terms.accepted`). Exclusivamente cuando `app()->environment('local')` es `true`:
1. **`app/Http/Middleware/EnsureAdminMfa.php`:** Permite el paso sin exigir código OTP por correo.
2. **`app/Http/Controllers/Auth/AuthenticatedSessionController.php`:** Al autenticar credenciales válidas en local, marca `email_verified_at = now()` si estaba vacío y crea los registros faltantes en `legal_acceptances` para las versiones vigentes de términos/privacidad.
3. **`app/Http/Controllers/Auth/RegisteredUserController.php`:** Marca `email_verified_at = now()` al crear cuentas nuevas en local.

---

## 6. Comandos Útiles para la Siguiente IA

### 6.1. Verificar que los 4 contenedores estén corriendo
```powershell
docker ps --format "table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}"
```
*(Si estuvieron detenidos tras reiniciar el host: `docker start mova-mysql mova-waha mova-web mova-worker`)*

### 6.2. Sincronizar cambios rápidos de Backend (PHP) sin reconstruir toda la imagen
El código dentro de `mova-web` y `mova-worker` vive en `/app`. Para inyectar un archivo PHP editado en el host:
```powershell
docker cp app/Services/WhatsAppService.php mova-web:/app/app/Services/WhatsAppService.php
docker cp app/Services/WhatsAppService.php mova-worker:/app/app/Services/WhatsAppService.php
docker exec mova-web php artisan optimize:clear
docker restart mova-worker
```

### 6.3. Reconstruir la imagen completa (`mova:latest`) si se modifican archivos Vue/JS/CSS
```powershell
docker build -t mova:latest .
$appKey = (docker inspect mova-web --format '{{range .Config.Env}}{{println .}}{{end}}' | Select-String -Pattern "^APP_KEY=").ToString().Trim()
docker rm -f mova-web mova-worker

docker run -d --name mova-web --restart unless-stopped --network mova-net -p 8080:8080 `
  -e APP_ENV=local -e APP_DEBUG=true -e $appKey -e APP_URL=http://localhost:8080 `
  -e DB_CONNECTION=mysql -e DB_HOST=mova-mysql -e DB_PORT=3306 -e DB_DATABASE=mova `
  -e DB_USERNAME=root -e DB_PASSWORD=mova_local_root `
  -e MAIL_MAILER=log -e CACHE_STORE=database -e SESSION_DRIVER=database -e QUEUE_CONNECTION=database `
  -e WAHA_BASE_URL=http://mova-waha:3000 -e WAHA_API_KEY=mova_local_waha_key -e WAHA_DEFAULT_SESSION=default `
  mova:latest

docker run -d --name mova-worker --restart unless-stopped --network mova-net `
  -e APP_ENV=local -e APP_DEBUG=true -e $appKey -e APP_URL=http://localhost:8080 `
  -e DB_CONNECTION=mysql -e DB_HOST=mova-mysql -e DB_PORT=3306 -e DB_DATABASE=mova `
  -e DB_USERNAME=root -e DB_PASSWORD=mova_local_root `
  -e MAIL_MAILER=log -e CACHE_STORE=database -e SESSION_DRIVER=database -e QUEUE_CONNECTION=database `
  -e WAHA_BASE_URL=http://mova-waha:3000 -e WAHA_API_KEY=mova_local_waha_key -e WAHA_DEFAULT_SESSION=default `
  mova:latest php artisan queue:work --tries=3 --timeout=90
```
