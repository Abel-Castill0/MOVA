# ClassMate – ProyectoMOVA Setup

## 1. Publicar config de Spatie Permission (si no se hizo ya)
```bash
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
```

## 2. Ejecutar migraciones + seeders
```bash
php artisan migrate
php artisan db:seed
```

## 3. Instalar dependencias JS
```bash
npm install
npm run build
```

## 4. Variables de entorno (.env)
Añade al final del archivo .env:

```
QUEUE_CONNECTION=database

ZOOM_ACCOUNT_ID=tu_account_id
ZOOM_CLIENT_ID=tu_client_id
ZOOM_CLIENT_SECRET=tu_client_secret

TWILIO_SID=tu_twilio_sid
TWILIO_AUTH_TOKEN=tu_auth_token
TWILIO_WHATSAPP_FROM=+14155238886

MAIL_MAILER=smtp
MAIL_HOST=sandbox.smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=tu_mailtrap_user
MAIL_PASSWORD=tu_mailtrap_pass
MAIL_FROM_ADDRESS=noreply@classmate.test
MAIL_FROM_NAME="ClassMate"
```

## 5. Iniciar servidor y workers
```bash
php artisan serve
php artisan queue:work
npm run dev
```

## 6. Configurar cron (producción)
```
* * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
```

## Credenciales de prueba
- **Admin:** admin@classmate.test / password

## Notas
- Zoom y Twilio son opcionales: el sistema usa datos ficticios si no están configurados
- El teléfono para WhatsApp debe tener formato internacional: `+34600000000`
- Zoom: crear app "Server-to-Server OAuth" en marketplace.zoom.us con permiso `meeting:write:admin`
