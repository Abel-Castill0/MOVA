# MOVA – Plataforma de clases particulares en línea

MOVA conecta familias con profesores particulares para clases en vivo por videollamada (Zoom). Los padres registran a sus hijos, buscan profesores en el marketplace, solicitan clases y reciben el enlace de Zoom automáticamente junto con confirmación por correo electrónico y WhatsApp.

---

## Stack tecnológico

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.1+ · Laravel 10 |
| Frontend | Vue 3 (Composition API) · Inertia.js v1 |
| Estilos | Tailwind CSS v3 · Inter (Google Fonts) |
| Bundler | Vite 5 |
| Base de datos | MySQL 8+ |
| Autenticación | Laravel Breeze (sesiones) |
| Roles | Spatie Laravel Permission v6 |
| Videollamadas | Zoom API (Server-to-Server OAuth) |
| WhatsApp | Twilio SDK v8 (Sandbox) |
| Email | Gmail SMTP (App Password) |
| Cola | Laravel Queue (driver: database) |

---

## Requisitos previos

- PHP 8.1+ con extensiones: `pdo_mysql`, `mbstring`, `openssl`, `xml`, `curl`
- Composer 2.x
- Node.js 18+ y npm
- MySQL 8.0+ o MariaDB 10.6+
- Cuenta de Zoom con app Server-to-Server OAuth (`meeting:write:admin`)
- Cuenta de Twilio con Sandbox de WhatsApp
- Cuenta de Gmail con App Password

---

## Instalación local

```bash
# 1. Clonar el repositorio
git clone <url-del-repositorio> mova
cd mova

# 2. Instalar dependencias PHP y JS
composer install
npm install

# 3. Configurar entorno
cp .env.example .env
php artisan key:generate
# Edita .env con tus valores (DB, correo, Zoom, Twilio)

# 4. Publicar config de Spatie Permission
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"

# 5. Crear la base de datos en MySQL y ejecutar migraciones
php artisan migrate
php artisan db:seed

# 6. Compilar assets
npm run build
```

---

## Variables de entorno necesarias

Ver `.env.example` para la lista completa con comentarios. Las variables críticas son:

```env
APP_URL=http://localhost:8001
DB_DATABASE=mova
QUEUE_CONNECTION=database

# Gmail SMTP
MAIL_USERNAME=tu@gmail.com
MAIL_PASSWORD=app_password_16_chars

# Zoom (Server-to-Server OAuth)
ZOOM_ACCOUNT_ID=
ZOOM_CLIENT_ID=
ZOOM_CLIENT_SECRET=
ZOOM_EMAIL=tu@gmail.com

# Twilio WhatsApp
TWILIO_SID=
TWILIO_AUTH_TOKEN=
TWILIO_WHATSAPP_FROM=+14155238886
```

---

## Comandos para ejecutar el proyecto

Cada proceso en una terminal separada:

```bash
# Backend
php artisan serve --port=8001

# Worker de colas (emails y WhatsApp en background)
php artisan queue:work --sleep=3 --tries=3 --timeout=60

# Frontend en desarrollo (con HMR)
npm run dev

# Scheduler de recordatorios (en desarrollo)
php artisan schedule:work
```

En producción, el scheduler se configura como cron:
```
* * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
```

---

## Roles del sistema

| Rol | Capacidades |
|---|---|
| `parent` | Registrar alumnos, buscar marketplace, solicitar clases, aprobar solicitudes (control parental), ver clases de sus hijos |
| `teacher` | Crear perfil y ofertas, ver solicitudes de sus materias, aceptar clases (crea reunión Zoom), cancelar clases |
| `admin` | Ver todos los usuarios, verificar o rechazar profesores pendientes |

El primer admin se crea con el seeder (`admin@mova.test` / `password`). Para crear más admins usa tinker:

```bash
php artisan tinker
>>> \App\Models\User::find($id)->assignRole('admin');
```

---

## Funcionalidades principales

- **Landing page pública** con estadísticas reales y profesores destacados
- **Registro con selección de rol** (padre / profesor) y verificación por email
- **Marketplace** de profesores con filtros por materia, nivel y precio
- **Solicitudes de clase** con control parental opcional
- **Integración Zoom real**: cada clase genera automáticamente una reunión con enlace y contraseña únicos
- **Notificaciones** por email (Gmail SMTP), WhatsApp (Twilio) y campana in-app
- **Recordatorios automáticos** 10 minutos antes de cada clase (scheduler)
- **Panel admin** para verificar o rechazar profesores

---

## Estructura de carpetas clave

```
app/
├── Http/Controllers/     # Controladores por módulo
├── Models/               # User, Student, TeacherProfile, ClassRequest, Lesson, Subject, ClassOffer
├── Notifications/        # ClassConfirmedNotification, ClassReminderNotification, etc.
├── Channels/             # WhatsAppChannel (Twilio)
├── Services/             # ZoomService (integración Zoom API)
└── Console/Commands/     # SendClassReminders (cron de recordatorios)

resources/js/
├── Pages/                # Páginas Vue organizadas por módulo
├── Layouts/              # AppLayout (sidebar responsive), GuestLayout
└── Components/           # NotificationBell, StatusBadge, TimeSlotPicker, etc.

routes/
└── web.php               # Todas las rutas agrupadas por rol
```

> **Nota técnica:** El modelo `Lesson` usa `protected $table = 'classes'` porque `class` es palabra reservada en PHP. La tabla en MySQL se llama `classes`.

---

## Estado actual del proyecto

| Módulo | Estado |
|---|---|
| Autenticación (login, registro, reset, verificación email) | Completo |
| Roles y permisos (parent, teacher, admin) | Completo |
| Landing page, About, páginas de invitación | Completo |
| Marketplace con filtros y paginación | Completo |
| Solicitudes de clase + control parental | Completo |
| Integración Zoom (API real, sin fallback) | Funcional |
| Notificaciones email (Gmail SMTP) | Funcional |
| Notificaciones WhatsApp (Twilio Sandbox) | Funcional |
| Notificaciones in-app (campana + DB) | Completo |
| Recordatorios automáticos (scheduler) | Completo |
| Panel admin | Completo |
| Gestión de alumnos | Completo |
| Perfil de profesor + ofertas de clase | Completo |
| Sistema de pagos | No implementado |
| Tests automatizados | No implementado |
| Despliegue en producción | No configurado |

---

## Credenciales de prueba (después de `db:seed`)

| Rol | Email | Contraseña |
|---|---|---|
| Admin | admin@mova.test | password |
| Profesor | carlos@mova.test | password |
| Profesor | laura@mova.test | password |
| Padre | ana@mova.test | password |

---

## Fundadores

- **Abel Enrique Castillo Yarin** — Desarrollador Principal
- **Elias Paz** — Director Administrativo
