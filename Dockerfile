# syntax=docker/dockerfile:1.7
#
# MOVA — imagen única de producción (Azure Container Apps).
#
# La MISMA imagen (mismo SHA) sirve para los tres roles; el rol lo decide el
# comando con el que se lanza el contenedor (ver docker/entrypoint.sh):
#
#   web        (default)  frankenphp run --config /etc/caddy/Caddyfile
#   worker     php artisan config:clear && php artisan queue:work --queue=default --sleep=3 --tries=3 --timeout=60 --backoff=5 --no-interaction
#   scheduler  php artisan config:clear && php artisan schedule:work -v
#
# Equivale al runtime que Railway genera con Railpack (FrankenPHP + Caddy,
# document root public/), pero reproducible y versionado en el repo.
#
# NUNCA se ejecuta `migrate` aquí ni en el entrypoint: las migraciones son un
# paso explícito y único por deploy (AZ-3), no algo que corra cada réplica.

# ---------------------------------------------------------------------------
# 1) Composer: vendor/ de producción (sin scripts: artisan aún no está aquí)
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader \
    --ignore-platform-reqs

# ---------------------------------------------------------------------------
# 2) Frontend: Vite build → public/build
# ---------------------------------------------------------------------------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js tailwind.config.js postcss.config.js jsconfig.json ./
COPY resources ./resources
# resources/js/app.js importa ../../vendor/tightenco/ziggy (JS del paquete PHP).
COPY --from=vendor /app/vendor/tightenco/ziggy ./vendor/tightenco/ziggy
# Tailwind escanea vistas/JS; laravel-vite-plugin escribe en public/build y
# necesita que public/ exista.
COPY public ./public
RUN npm run build

# ---------------------------------------------------------------------------
# 3) Runtime: FrankenPHP oficial sobre PHP 8.3
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.3 AS runtime

# Solo lo que composer check-platform-reqs + el runtime exigen y la imagen
# base no trae: pdo_mysql (DB) y pcntl (señales/timeouts del queue worker).
# opcache viene compilado en la imagen; aquí solo se habilita — sin él PHP
# recompila cada request y el rendimiento en producción es inaceptable.
RUN install-php-extensions pdo_mysql pcntl opcache

COPY docker/php.ini "$PHP_INI_DIR/conf.d/99-mova.ini"
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/mova-entrypoint
RUN chmod +x /usr/local/bin/mova-entrypoint

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

# Ahora sí, con el código completo, se regenera el autoload optimizado y se
# ejecuta package:discover (el post-autoload-dump que se omitió en 2).
RUN --mount=from=vendor,source=/usr/bin/composer,target=/usr/bin/composer \
    composer dump-autoload --optimize --no-dev

# El disco del contenedor es efímero: storage/ y bootstrap/cache solo
# guardan caché de framework, vistas compiladas y logs temporales.
RUN mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache public \
    && chown -R www-data:www-data /data /config

USER www-data

ENV APP_ENV=production \
    APP_DEBUG=false \
    PORT=8080

EXPOSE 8080

ENTRYPOINT ["mova-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
