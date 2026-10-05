#!/usr/bin/env bash
# MOVA — sondas de SANDBOX real (no producción) dentro del contenedor QA php_qa.
#
#   bash scripts/sandbox-smoke.sh payments [--scenario=approved --scenario=rejected --scenario=pending]
#   bash scripts/sandbox-smoke.sh mail        # 3 correos esenciales a MAIL_SMOKE_RECIPIENT
#   bash scripts/sandbox-smoke.sh files       # Cloudinary: sube, lee, borra un avatar sintético
#   bash scripts/sandbox-smoke.sh sentry      # un evento de prueba al DSN de PRUEBA
#   bash scripts/sandbox-smoke.sh jaas        # reunión real: docente (escritorio) + padre (móvil), JaaS real
#
# Credenciales: se leen de `qa/.env.sandbox` (ignorado por Git; plantilla con los
# nombres exactos en `qa/.env.sandbox.example`). Este script NUNCA imprime los
# valores y los pasa al contenedor por NOMBRE (no quedan en la línea de comandos).
# Cada sonda usa una BD SQLite temporal dentro del contenedor; no toca ninguna
# base real y los comandos se niegan a correr fuera de APP_ENV local/testing.
# Cada sonda ve SOLO las credenciales de su integración: todo lo demás se vacía,
# para que no pueda usar por accidente lo que traiga el .env del checkout.
set -uo pipefail
# Git Bash en Windows convierte rutas como /tmp/x en rutas de Windows al pasarlas a docker.
export MSYS_NO_PATHCONV=1
cd "$(dirname "$0")/.."

ENV_FILE="${SANDBOX_ENV_FILE:-qa/.env.sandbox}"
TARGET="${1:-}"
shift || true

if [ -z "$TARGET" ]; then
  echo "Uso: bash scripts/sandbox-smoke.sh payments|mail|files|sentry|jaas [opciones]"; exit 2
fi
if [ ! -f "$ENV_FILE" ]; then
  echo "Falta $ENV_FILE."
  echo "Copia qa/.env.sandbox.example a $ENV_FILE y completa SOLO credenciales de prueba, en tu máquina."
  echo "No pegues valores en el chat ni en archivos versionados."
  exit 2
fi

# Cargar sin imprimir (el archivo es tuyo y local).
set -a
# shellcheck disable=SC1090
. "$ENV_FILE"
set +a

# ── JaaS: reunión real con dos navegadores (corre en el contenedor E2E) ─────
if [ "$TARGET" = "jaas" ]; then
  for n in JAAS_APP_ID JAAS_KEY_ID JAAS_PRIVATE_KEY; do
    if [ -z "${!n:-}" ]; then
      echo "Falta $n en $ENV_FILE (credenciales REALES de JaaS: ver qa/.env.sandbox.example)."; exit 2
    fi
  done
  echo "Reunión real en JaaS con cámara y micrófono sintéticos (docente en escritorio, padre en móvil)…"
  exec docker compose -f docker-compose.qa.yml run --rm -T \
    -e JAAS_APP_ID -e JAAS_KEY_ID -e JAAS_PRIVATE_KEY -e JAAS_REAL=1 \
    e2e_qa tests/jaas-real-meeting.spec.js "$@"
fi

# ── Mapa de entorno del contenedor ─────────────────────────────────────────
declare -A E       # variables con valor fijo (o vacío = "pisar con nada")
FWD=()             # nombres cuyo VALOR se reenvía desde el shell (sin mostrarlo)

blank()   { for n in "$@"; do E[$n]=""; done; }
forward() {
  for n in "$@"; do
    if [ -n "${!n:-}" ]; then FWD+=("$n"); unset 'E[$n]'; else E[$n]=""; fi
  done
}

# Base aislada y segura para TODAS las sondas.
E[APP_ENV]=local;           E[APP_DEBUG]=false
E[DB_CONNECTION]=sqlite;    E[DB_DATABASE]=/tmp/mova_sandbox.sqlite
E[QUEUE_CONNECTION]=sync;   E[BROADCAST_DRIVER]=null
E[CACHE_DRIVER]=array;      E[SESSION_DRIVER]=array
E[WHATSAPP_ENABLED]=false;  E[DIAGNOSTIC_AI_ENABLED]=false
E[MAIL_MAILER]=array

# Por defecto, ninguna integración tiene credenciales; cada objetivo reenvía las suyas.
blank SENTRY_LARAVEL_DSN SENTRY_DSN CLOUDINARY_URL \
      GMAIL_CLIENT_ID GMAIL_CLIENT_SECRET GMAIL_REFRESH_TOKEN GMAIL_FROM_ADDRESS GMAIL_FROM_NAME \
      MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD MAIL_ENCRYPTION MAIL_FROM_ADDRESS MAIL_FROM_NAME \
      MAIL_SMOKE_RECIPIENT \
      MERCADOPAGO_ACCESS_TOKEN MERCADOPAGO_PUBLIC_KEY MERCADOPAGO_APPLICATION_ID \
      MERCADOPAGO_EXPECTED_COLLECTOR_ID MERCADOPAGO_WEBHOOK_SECRET MERCADOPAGO_SANDBOX_PAYER_EMAIL \
      JAAS_APP_ID JAAS_KEY_ID JAAS_PRIVATE_KEY

case "$TARGET" in
  payments)
    forward MERCADOPAGO_ACCESS_TOKEN MERCADOPAGO_PUBLIC_KEY MERCADOPAGO_APPLICATION_ID \
            MERCADOPAGO_EXPECTED_COLLECTOR_ID MERCADOPAGO_WEBHOOK_SECRET MERCADOPAGO_SANDBOX_PAYER_EMAIL
    E[PAYMENTS_ENABLED]=true;  E[PAYMENT_PROVIDER]=mercadopago
    E[MERCADOPAGO_WEBHOOKS_ENABLED]=true;  E[MERCADOPAGO_EXPECTED_LIVE_MODE]=false
    CMD='php artisan mercadopago:sandbox-smoke "$@"'
    ;;
  mail)
    forward GMAIL_CLIENT_ID GMAIL_CLIENT_SECRET GMAIL_REFRESH_TOKEN GMAIL_FROM_ADDRESS GMAIL_FROM_NAME \
            MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD MAIL_ENCRYPTION MAIL_FROM_ADDRESS MAIL_FROM_NAME \
            MAIL_SMOKE_RECIPIENT
    if [ -n "${MAIL_MAILER:-}" ]; then FWD+=(MAIL_MAILER); unset 'E[MAIL_MAILER]'; fi
    CMD='php artisan mova:mail-smoke "$@"'
    ;;
  files)
    forward CLOUDINARY_URL
    CMD='php artisan mova:cloudinary-smoke "$@"'
    ;;
  sentry)
    forward SENTRY_LARAVEL_DSN
    E[SENTRY_ENVIRONMENT]=qa
    CMD='php artisan sentry:test "$@"'
    ;;
  *)
    echo "Objetivo desconocido: $TARGET (payments|mail|files|sentry|jaas)"; exit 2 ;;
esac

ARGS=()
for k in "${!E[@]}"; do ARGS+=(-e "$k=${E[$k]}"); done
for n in "${FWD[@]}"; do ARGS+=(-e "$n"); done

docker compose -f docker-compose.qa.yml up -d mysql_qa php_qa >/dev/null 2>&1

docker compose -f docker-compose.qa.yml exec -T "${ARGS[@]}" php_qa sh -c '
  rm -f /tmp/mova_sandbox.sqlite && touch /tmp/mova_sandbox.sqlite
  php artisan config:clear >/dev/null 2>&1
  php artisan migrate --force --no-interaction >/dev/null || { echo "migrate falló"; exit 1; }
  '"$CMD"'
' _ "$@"
