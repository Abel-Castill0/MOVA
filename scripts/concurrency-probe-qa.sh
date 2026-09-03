#!/usr/bin/env bash
# MOVA MYSQL QA GATE — variante de scripts/concurrency-probe.sh que fuerza
# TODOS los procesos a usar la conexión 'mysql_qa' (mova_qa), nunca la
# conexión por defecto del entorno (que en una máquina de desarrollador
# normal apunta a la base de datos de dev real).
#
# Cada `php artisan mova:concurrency-probe`/`mova:concurrency-verify` de
# aquí abajo pasa `--connection=mysql_qa`, y ese flag está guardado en
# runtime por App\Support\QaDatabaseGuard: si 'mysql_qa' no resolviera
# exactamente a la base de datos 'mova_qa', el comando aborta antes de
# tocar nada. Ver config/database.php para el porqué esa conexión no puede
# apuntar a otro lado por accidente (nombre de base de datos fijo en
# código, no leído de variables de entorno).
#
#   ./scripts/concurrency-probe-qa.sh accept-lesson 5
set -uo pipefail

OPERATION="${1:-accept-lesson}"
WORKERS="${2:-5}"
CONN="mysql_qa"

echo "=== MOVA MYSQL QA GATE: ${OPERATION} con ${WORKERS} procesos simultáneos contra mova_qa ==="

SCENARIO=$(php artisan mova:concurrency-probe "$OPERATION" --setup --connection="$CONN" | tail -1 | tr -d '[:space:]')
if ! [[ "$SCENARIO" =~ ^[0-9]+$ ]]; then
  echo "FALLO creando el escenario: $SCENARIO"; exit 1
fi
echo "escenario=$SCENARIO"

# Lanzar todos en paralelo y esperar a que terminen todos.
PIDS=()
for i in $(seq 1 "$WORKERS"); do
  php artisan mova:concurrency-probe "$OPERATION" --scenario="$SCENARIO" --worker="$i" --connection="$CONN" > "/tmp/probe_qa_${OPERATION}_$i.out" 2>&1 &
  PIDS+=($!)
done
for pid in "${PIDS[@]}"; do wait "$pid"; done

echo "--- resultados por proceso ---"
cat /tmp/probe_qa_${OPERATION}_*.out | grep -E "worker=" || true
rm -f /tmp/probe_qa_${OPERATION}_*.out

echo "--- estado final ---"
php artisan mova:concurrency-verify "$OPERATION" "$SCENARIO" --connection="$CONN"
VERIFY=$?

php artisan mova:concurrency-probe "$OPERATION" --cleanup --connection="$CONN" > /dev/null 2>&1
exit $VERIFY
