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
set -uo pipefail

OPERATION="${1:-accept-lesson}"
WORKERS="${2:-5}"

echo "=== GAP-03: ${OPERATION} con ${WORKERS} procesos simultáneos ==="

SCENARIO=$(php artisan mova:concurrency-probe "$OPERATION" --setup | tail -1 | tr -d '[:space:]')
if ! [[ "$SCENARIO" =~ ^[0-9]+$ ]]; then
  echo "FALLO creando el escenario: $SCENARIO"; exit 1
fi
echo "escenario=$SCENARIO"

# Lanzar todos en paralelo y esperar a que terminen todos.
PIDS=()
for i in $(seq 1 "$WORKERS"); do
  php artisan mova:concurrency-probe "$OPERATION" --scenario="$SCENARIO" --worker="$i" > "/tmp/probe_${OPERATION}_$i.out" 2>&1 &
  PIDS+=($!)
done
for pid in "${PIDS[@]}"; do wait "$pid"; done

echo "--- resultados por proceso ---"
cat /tmp/probe_${OPERATION}_*.out | grep -E "worker=" || true
rm -f /tmp/probe_${OPERATION}_*.out

echo "--- estado final ---"
php artisan mova:concurrency-verify "$OPERATION" "$SCENARIO"
VERIFY=$?

php artisan mova:concurrency-probe "$OPERATION" --cleanup > /dev/null 2>&1
exit $VERIFY
