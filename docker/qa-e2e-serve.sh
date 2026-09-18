#!/usr/bin/env bash
# MOVA E2E DOCKER GATE — entrypoint único del servicio e2e_qa
# (docker-compose.qa.yml). PHP 8.3, Node/Playwright y el código de MOVA
# corren como procesos LOCALES de este mismo container: nada de
# `docker compose exec` anidado por cada `artisan tinker` (ver AZ-3G0-A: ese
# patrón colgó Docker Desktop en el intento anterior). Un solo proceso hace
# todo el ciclo: valida runtime → prepara fixtures → levanta el server →
# corre Playwright → devuelve exactamente su exit code.
#
# Misma base de datos SQLite exclusiva del gate E2E
# (storage/logs/phase2b-e2e.sqlite) y el mismo router
# (qa/stabilization-server.php) de qa/run-stabilization.ps1 (uso sin Docker);
# esa lógica de fail-closed no se toca aquí, solo se le añade un sentinel
# previo para abortar antes de tocar cualquier dato si el workspace QA no es
# el esperado.
#
# AZ-3G0-B.2 — /workspace (host, bind mount de OneDrive/Windows, read-only)
# ya NO es donde corre la app: en este equipo ese bind mount le cuesta a PHP
# ~7-20s por request (assets estáticos incluidos — opcache no lo evita,
# porque el costo real es la lectura de archivo por el bind mount, no
# recompilar bytecode). /app es un volumen Docker-nativo; copiamos el
# snapshot del working tree (con los cambios sin commitear del upgrade a
# Laravel 12 incluidos) UNA vez por corrida y todo — PHP, SQLite, Playwright —
# corre desde ahí. vendor/ y qa/node_modules/ son volúmenes nombrados aparte
# (ver docker-compose.qa.yml) que sobreviven entre corridas `run --rm`
# sucesivas, así que se excluyen de la copia y no se reinstalan si ya están.
set -euo pipefail

php_version="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
if [ "$php_version" != "8.3" ]; then
    echo "ABORT: e2e_qa requiere PHP 8.3, detectado $php_version." >&2
    exit 1
fi

# Limpia /app de la corrida anterior, preservando vendor/ y qa/node_modules/
# (volúmenes nombrados propios, montados dentro de /app — ver compose) para
# no perder esos installs entre corridas.
find /app -mindepth 1 -maxdepth 1 \
    ! -name vendor \
    ! -name qa \
    -exec rm -rf {} +
if [ -d /app/qa ]; then
    find /app/qa -mindepth 1 -maxdepth 1 ! -name node_modules -exec rm -rf {} +
fi

# Copia única del snapshot host -> volumen Docker-nativo. tar en vez de cp
# recursivo: un solo proceso para miles de archivos en vez de un fork por
# archivo. bootstrap/cache se excluye a propósito: un config:cache dejado por
# una corrida anterior de php_qa (bind mount compartido) congela env() y
# contamina este gate con valores de otra conexión/DB (ver AZ-3G0-B.1).
tar -C /workspace \
    --exclude=.git \
    --exclude=node_modules \
    --exclude=qa/node_modules \
    --exclude=vendor \
    --exclude=bootstrap/cache \
    -cf - . | tar -C /app -xf -
mkdir -p /app/bootstrap/cache
cd /app

export APP_ENV=local
export DB_CONNECTION=sqlite
export DB_DATABASE=/app/storage/logs/phase2b-e2e.sqlite
export DATABASE_URL=
export APP_URL=http://127.0.0.1:8012
export BASE_URL="$APP_URL"
export SESSION_DRIVER=file
export SESSION_COOKIE=mova_phase2b_qa
export SESSION_SECURE_COOKIE=false
export BROADCAST_DRIVER=log
export GOOGLE_LOGIN_ENABLED=false
export RECHARGES_ENABLED=true
# `php -S` arranca un proceso PHP nuevo por request, así que un cache driver
# en memoria ('array') parte vacío en cada uno — igual que el arranque de
# aplicación por-test de PHPUnit (mismo CACHE_DRIVER=array que
# phpunit.mysql.xml, misma razón). Sin esto, RateLimiter usa el store por
# defecto (persistente entre requests) y el throttle:5,1 de routes/auth.php
# — invisible antes porque el I/O del bind mount espaciaba los logins más de
# un minuto — corta esta spec real: son ~22 logins secuenciales y ahora cada
# uno tarda <1s. No es debilitar el throttle de producción, solo elegir un
# backend de cache apto para un server QA de un-proceso-por-request.
export CACHE_DRIVER=array
export QA_PHP_BIN="${QA_PHP_BIN:-php}"

expected_db="/app/storage/logs/phase2b-e2e.sqlite"
if [ "$APP_ENV" != "local" ] || [ "$DB_CONNECTION" != "sqlite" ] || [ "$DB_DATABASE" != "$expected_db" ]; then
    echo "ABORT: workspace QA no coincide con lo esperado (APP_ENV=local, DB_CONNECTION=sqlite, DB_DATABASE=$expected_db)." >&2
    exit 1
fi

if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

# qa/node_modules y el cache de browsers de Playwright viven en volúmenes
# nombrados propios (ver docker-compose.qa.yml): .dockerignore excluye qa/
# del build de la imagen (es el mismo .dockerignore de la imagen de
# producción), así que se instalan aquí, en el primer arranque, y persisten
# entre corridas siguientes. `playwright install` es idempotente — se llama
# siempre, pero no vuelve a descargar nada si el cache ya tiene el browser.
if [ ! -x qa/node_modules/.bin/playwright ]; then
    npm ci --prefix qa
fi
npx --prefix qa playwright install --with-deps chromium

rm -f "$DB_DATABASE"
touch "$DB_DATABASE"
php artisan migrate --no-interaction
php artisan db:seed --class=LocalTestDataSeeder --no-interaction
php qa/stabilization-server.php

# bootstrap/cache quedó excluido de la copia, pero config:clear/route:clear/
# view:clear igual antes de servir: por si acaso un composer install con
# scripts (ver composer.json) recreó algo ahí, no queremos servir nada
# cacheado que no sea el de esta corrida.
php artisan config:clear
php artisan route:clear
php artisan view:clear

# opcache.enable_cli=0 (docker/php.ini) es correcto para PHPUnit (evita bytecode
# rancio entre corridas de test), pero mata el rendimiento de este server:
# `php -S` es SAPI cli, así que sin opcache recompila todo Laravel en cada
# request. validate_timestamps=0 es seguro aquí (y no solo rápido): /app es
# el snapshot Docker-nativo de esta corrida, nadie lo edita mientras el
# server está arriba.
php -d opcache.enable_cli=1 -d opcache.validate_timestamps=0 -S 0.0.0.0:8012 -t public qa/stabilization-server.php &
server_pid=$!
cleanup() { kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true; }
trap cleanup EXIT

ready=0
for _ in $(seq 1 30); do
    if curl -sf -o /dev/null "$APP_URL/login"; then
        ready=1
        break
    fi
    sleep 1
done
if [ "$ready" -ne 1 ]; then
    echo "ABORT: qa/stabilization-server.php no respondió en $APP_URL tras 30s." >&2
    exit 1
fi

cd qa
set +e
node node_modules/@playwright/test/cli.js test --config=playwright.local.config.js "$@"
exit_code=$?
set -e

exit "$exit_code"
