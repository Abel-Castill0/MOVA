#!/bin/sh
# MOVA — entrypoint común a web/worker/scheduler (misma imagen).
#
# Replica lo que Railpack hace en cada arranque de mova-web y que el repo ya
# da por hecho: enlace public/storage + caché de config/rutas/vistas con las
# variables de entorno REALES del contenedor (nunca en build, donde no hay env
# válida). Web: solo `optimize`; worker/scheduler: `optimize:clear`.
#
# Idempotente: cada réplica puede ejecutarlo sin efectos cruzados.
# NUNCA ejecuta migraciones — eso es un paso explícito de deploy (AZ-3).
set -eu

cd /app

# Enlace public/storage → storage/app/public. Ya no es persistencia de
# producción (Cloudinary), pero el repo lo asume. Equivale a `artisan storage:link`
# con config/filesystems.php ('links' por defecto) sin pagar un arranque de PHP.
[ -e public/storage ] || ln -s /app/storage/app/public public/storage 2>/dev/null || true

case "${1:-}" in
    frankenphp)
        # Web: este arranque es el que paga el usuario cuando movap-web despierta desde 0 réplicas.
        # `optimize` (Laravel 11+) ya regenera config, eventos, rutas y vistas sobrescribiendo la caché
        # anterior, así que no hace falta limpiar antes ni un view:cache aparte (antes se compilaban las
        # vistas dos veces). Tampoco se vacía la caché de datos: con CACHE_STORE en MySQL, cada despertar
        # hacía un viaje a la base y borraba límites de intentos y bloqueos compartidos con las demás réplicas.
        # Esa limpieza sigue ocurriendo en cada despliegue, cuando arrancan worker y scheduler (abajo).
        php artisan optimize --no-interaction >/dev/null
        ;;
    *)
        # Worker y scheduler (arrancan en cada despliegue): conservan su contrato Railway (`config:clear && ...`),
        # leen env en vivo y limpian todo, caché de datos incluida.
        # En una base de datos NUEVA (cache/sesiones/colas en MySQL y aún sin migrar) optimize:clear
        # falla al vaciar la caché; con `set -e` eso dejaba al contenedor en bucle de reinicios y
        # bloqueaba justo el primer despliegue. No es fatal: se avisa y se continúa.
        php artisan optimize:clear --no-interaction >/dev/null || echo "[entrypoint] optimize:clear no pudo completarse (¿base sin migrar?); se continúa" >&2
        ;;
esac

exec "$@"
