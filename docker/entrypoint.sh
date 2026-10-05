#!/bin/sh
# MOVA — entrypoint común a web/worker/scheduler (misma imagen).
#
# Replica lo que Railpack hace en cada arranque de mova-web y que el repo ya
# da por hecho: storage:link + optimize:clear + optimize con las variables de
# entorno REALES del contenedor (nunca en build, donde no hay env válida).
#
# Idempotente: cada réplica puede ejecutarlo sin efectos cruzados.
# NUNCA ejecuta migraciones — eso es un paso explícito de deploy (AZ-3).
set -eu

cd /app

# Enlace public/storage → storage/app/public. Ya no es persistencia de
# producción (Cloudinary), pero el repo lo asume y el comando es idempotente.
php artisan storage:link >/dev/null 2>&1 || true

# En una base de datos NUEVA (cache/sesiones/colas en MySQL y aún sin migrar) optimize:clear
# falla al vaciar la caché; con `set -e` eso dejaba al contenedor en bucle de reinicios y
# bloqueaba justo el primer despliegue. No es fatal: se avisa y se continúa (el web no pasa
# /readyz hasta que las migraciones corran, que es el comportamiento deseado).
php artisan optimize:clear --no-interaction >/dev/null || echo "[entrypoint] optimize:clear no pudo completarse (¿base sin migrar?); se continúa" >&2

# Solo el proceso web cachea config/rutas/vistas. Worker y scheduler
# conservan su contrato Railway (`config:clear && ...`) y leen env en vivo.
case "${1:-}" in
    frankenphp)
        # Laravel 10: optimize = config:cache + route:cache; view:cache aparte.
        php artisan optimize --no-interaction >/dev/null
        php artisan view:cache --no-interaction >/dev/null
        ;;
esac

exec "$@"
