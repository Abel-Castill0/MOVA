#!/bin/sh
# Ejecuta PHPUnit sobre una COPIA del working tree dentro del container
# php_qa (docker-compose.qa.yml). Mismo motivo que qa-e2e-serve.sh: correr
# directamente sobre el bind mount Windows/OneDrive multiplica el I/O, y una
# copia fija además el snapshot (editar archivos durante la corrida no
# contamina el resultado).
#
#   docker compose -f docker-compose.qa.yml exec -T php_qa sh scripts/qa-snapshot-phpunit.sh [phpunit args]
#   docker compose -f docker-compose.qa.yml exec -T php_qa sh scripts/qa-snapshot-phpunit.sh -c phpunit.mysql.xml
set -eu

SNAP=/tmp/mova-snapshot
rm -rf "$SNAP"
mkdir -p "$SNAP"
cd /app
tar --exclude=./node_modules --exclude=./.git --exclude=./qa/node_modules \
    --exclude=./storage/logs --exclude=./test-results -cf - . | tar -C "$SNAP" -xf -
cd "$SNAP"
exec vendor/bin/phpunit --no-progress "$@"
