#!/usr/bin/env bash
# GAP-03 — Ejecuta N intentos SIMULTÁNEOS de una operación financiera contra la
# base de datos real (MySQL) y verifica que solo prospere uno.
#
# No es un test de PHPUnit a propósito: la suite corre sobre SQLite en memoria,
# una conexión y un proceso, así que no puede producir contención real. Aquí
# cada intento es un proceso PHP independiente con su propia conexión, así que
# los locks y los UNIQUE los resuelve el motor de verdad.
#
#   ./scripts/concurrency-probe.sh accept-lesson 5
# Ejecutar únicamente en un MySQL QA local y descartable, con:
#   APP_ENV=testing DB_CONNECTION=mysql_qa DB_HOST=127.0.0.1
# En php_qa de docker-compose.qa.yml: DB_HOST=mysql_qa y MOVA_QA_COMPOSE=1.
set -euo pipefail

OPERATION="${1:-accept-lesson}"
WORKERS="${2:-5}"
CONN=mysql_qa

# No falsificar APP_ENV para saltarse la protección de los comandos.
# El runner debe apuntar al MySQL efímero de CI/local, nunca a un host remoto.
if [[ "${APP_ENV:-}" != testing || "${DB_CONNECTION:-}" != mysql_qa ]]; then
  echo 'Runner QA abortado: exige APP_ENV=testing y DB_CONNECTION=mysql_qa ya establecidos.' >&2
  exit 1
fi
case "${DB_HOST:-}" in
  127.0.0.1|localhost) ;;
  mysql_qa) if [[ "${MOVA_QA_COMPOSE:-}" != 1 || ! -f /.dockerenv ]]; then echo 'Runner QA: falta identidad de Compose QA.' >&2; exit 1; fi ;;
  *) echo 'Runner QA abortado: DB_HOST no es loopback ni servicio Compose QA.' >&2; exit 1 ;;
esac

case "$OPERATION" in
  accept-lesson|settle|refund|approve-recharge|reminder-claim) ;;
  *) echo "Operación QA desconocida." >&2; exit 1 ;;
esac
if ! [[ "$WORKERS" =~ ^[1-9][0-9]*$ ]] || [ "$WORKERS" -gt 32 ]; then
  echo "WORKERS debe ser un entero entre 1 y 32." >&2; exit 1
fi

echo "=== GAP-03: ${OPERATION} con ${WORKERS} procesos simultáneos ==="

SCENARIO=""
RUN_DIR="$(mktemp -d "${TMPDIR:-/tmp}/mova-concurrency-XXXXXX")"
cleanup() {
  local status=$?
  trap - EXIT
  if [[ "$SCENARIO" =~ ^[0-9]+$ ]]; then
    if ! php artisan mova:concurrency-probe "$OPERATION" --scenario="$SCENARIO" --cleanup --connection="$CONN" > /dev/null 2>&1; then
      echo "FALLO al limpiar el escenario QA ${SCENARIO}; revisar mova_qa antes de repetir." >&2
      status=1
    fi
  fi
  rm -f -- "$RUN_DIR"/*.out
  rmdir -- "$RUN_DIR"
  exit "$status"
}
trap cleanup EXIT

SCENARIO=$(php artisan mova:concurrency-probe "$OPERATION" --setup --connection="$CONN" | tail -1 | tr -d '[:space:]')
if ! [[ "$SCENARIO" =~ ^[0-9]+$ ]]; then
  echo "FALLO creando el escenario: $SCENARIO"; exit 1
fi
echo "escenario=$SCENARIO"

# Lanzar todos en paralelo y esperar a que terminen todos.
PIDS=()
for i in $(seq 1 "$WORKERS"); do
  php artisan mova:concurrency-probe "$OPERATION" --scenario="$SCENARIO" --worker="$i" --connection="$CONN" > "$RUN_DIR/$i.out" 2>&1 &
  PIDS+=($!)
done
WORKER_FAILED=0
for pid in "${PIDS[@]}"; do
  if ! wait "$pid"; then WORKER_FAILED=1; fi
done

echo "--- resultados por proceso ---"
grep -hE 'worker=' "$RUN_DIR"/*.out || true
if [[ "$WORKER_FAILED" == 1 ]] || grep -q 'result=ERROR' "$RUN_DIR"/*.out; then
  echo 'FALLO: al menos un worker produjo una excepción (ERROR).' >&2
  exit 1
fi

# Es una operación de compare-and-set: exactamente un proceso debe ganar.
# El estado final por sí solo no demuestra que haya existido un solo ganador.
if [[ "$OPERATION" == accept-lesson || "$OPERATION" == reminder-claim ]]; then
  WINNERS=$(awk '/result=WON/{n++} END{print n+0}' "$RUN_DIR"/*.out)
  if [[ "$WINNERS" != 1 ]]; then
    echo "FALLO: ganadores=${WINNERS} (esperado exactamente 1)." >&2
    exit 1
  fi
fi

echo "--- estado final ---"
php artisan mova:concurrency-verify "$OPERATION" "$SCENARIO" --connection="$CONN"
