# MOVA – Guía de configuración local

## Requisitos previos

- PHP 8.1+ con extensiones: `pdo_mysql`, `mbstring`, `openssl`, `xml`, `curl`
- Composer 2.x
- Node.js 18+ y npm
- MySQL 8.0+ o MariaDB 10.6+
- Cuenta de JaaS (8x8 / Jitsi as a Service) con `JAAS_APP_ID`, `JAAS_PRIVATE_KEY`, `JAAS_KEY_ID` — Zoom ya no se usa (eliminado del código, ver migraciones `2026_07_18_000001_drop_zoom_columns.php` y `2026_07_26_153152_drop_zoom_meeting_id_column.php`)
- (Opcional) Cuenta de Meta Business con WhatsApp Business Cloud API — solo si se quiere WhatsApp real; por defecto `WHATSAPP_PROVIDER=fake` y no se envía nada
- Cuenta de Gmail con **App Password** de 16 caracteres generada

---

## 1. Instalar dependencias

```bash
composer install
npm install
```

## 2. Configurar variables de entorno

```bash
cp .env.example .env
php artisan key:generate
```

Edita `.env` con los valores reales (ver sección de variables de entorno en `.env.example`).

## 3. Publicar config de Spatie Permission

```bash
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
```

## 4. Crear base de datos y ejecutar migraciones

Crea la base de datos manualmente en MySQL:

```sql
CREATE DATABASE mova CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Luego ejecuta:

```bash
php artisan migrate
php artisan db:seed
```

## 5. Compilar assets frontend

```bash
npm run build       # producción
# o
npm run dev         # desarrollo con HMR (Hot Module Replacement)
```

## 6. Iniciar servidor y workers

Cada proceso en una terminal separada:

```bash
# Backend
php artisan serve --port=8001

# Worker de colas (emails, WhatsApp)
php artisan queue:work --sleep=3 --tries=3 --timeout=60

# Frontend en desarrollo (opcional, no necesario si ya compilaste con build)
npm run dev
```

## 7. Scheduler (recordatorios automáticos)

**En desarrollo:**
```bash
php artisan schedule:work
```

**En producción**, agrega esta línea al crontab:
```
* * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
```

El scheduler ejecuta `classmate:send-reminders` cada minuto para recordatorios 10 minutos antes de cada clase.

---

## Credenciales de prueba (después de `db:seed`)

| Usuario | Email | Contraseña | Rol |
|---|---|---|---|
| Admin MOVA | admin@mova.test | password | admin |
| Carlos Fernández | carlos@mova.test | password | teacher |
| Laura García | laura@mova.test | password | teacher |
| Ana López | ana@mova.test | password | parent |

---

## Notas importantes

- **JaaS/Jitsi:** si `JAAS_APP_ID`/`JAAS_PRIVATE_KEY`/`JAAS_KEY_ID` no están configurados, `LessonController::join()` responde con un 500 explícito en vez de servir una sala sin protección — es una decisión de seguridad deliberada (nunca degrada a una sala pública), no un bug. Ver `app/Services/JaasService.php`.
- **WhatsApp (Meta Cloud API):** el número destinatario debe tener el teléfono verificado en MOVA y las plantillas deben estar aprobadas por Meta. Con `WHATSAPP_PROVIDER=fake` (por defecto) no se envía nada real. Ver `docs/WHATSAPP_PRODUCTION_NOTES.md`.
- **Gmail App Password:** Usa la contraseña de aplicación de 16 caracteres, **no** la contraseña de tu cuenta Google.
- **QUEUE_CONNECTION=database:** Las notificaciones se procesan en segundo plano. Sin el worker corriendo, los emails y WhatsApp no se enviarán.
- **Teléfono para WhatsApp:** Formato internacional sin espacios: `+51987654321`
