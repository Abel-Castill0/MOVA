#!/usr/bin/env bash
# MOVA — supervisor de UN contenedor que ejecuta la cola Y el scheduler (ahorra una réplica de Container Apps).
#
# Contrato (lo prueban las pruebas de docs/azure-cost-optimization.md §C-1):
#  - Arranca `queue:work` y `schedule:work` con EXACTAMENTE los mismos argumentos que usaban por separado.
#  - Si CUALQUIERA de los dos termina (fallo, OOM-kill de un hijo, excepción fatal), mata al otro y sale con
#    código 1: Azure Container Apps reinicia el contenedor entero (restart policy always). Nunca queda un
#    proceso huérfano trabajando sin su pareja.
#  - SIGTERM/SIGINT (despliegue de una revisión nueva, escalado): se reenvían a los dos hijos; `queue:work`
#    termina el job en curso antes de salir (pcntl) y el contenedor sale con 0.
#  - Se invoca vía `mova-entrypoint` (hace storage:link/optimize:clear y luego `exec "$@"`).
#
# Uso (args del contenedor):  command: ["mova-entrypoint"]   args: ["bash","/usr/local/bin/mova-supervise"]
set -u
cd /app

# Igual que antes: `config:clear && ...` (el contenedor no continúa si no puede leer el entorno en vivo).
php artisan config:clear || exit 1

php artisan queue:work --queue=default --sleep=3 --tries=3 --timeout=60 --backoff=5 --no-interaction &
WORKER=$!
php artisan schedule:work -v &
SCHEDULER=$!

stop_children() {
    kill -TERM "$WORKER" "$SCHEDULER" 2>/dev/null || true
    wait "$WORKER" "$SCHEDULER" 2>/dev/null || true
}

on_signal() {
    echo "[supervise] señal de parada: reenviada a queue:work y schedule:work (el worker termina su job actual)" >&2
    stop_children
    exit 0
}
trap on_signal TERM INT

# Espera al primero que termine (bash >= 4.3). Un `wait -n` interrumpido por una señal ejecuta el trap.
wait -n "$WORKER" "$SCHEDULER"
status=$?

if kill -0 "$WORKER" 2>/dev/null; then ended="schedule:work"; else ended="queue:work"; fi
echo "[supervise] ${ended} terminó (código ${status}): se detiene el otro proceso y se reinicia el contenedor" >&2
stop_children
exit 1
