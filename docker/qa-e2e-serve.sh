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
set -euo pipefail
cd /app

php_version="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
if [ "$php_version" != "8.3" ]; then
    echo "ABORT: e2e_qa requiere PHP 8.3, detectado $php_version." >&2
    exit 1
fi

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

php -S 0.0.0.0:8012 -t public qa/stabilization-server.php &
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
