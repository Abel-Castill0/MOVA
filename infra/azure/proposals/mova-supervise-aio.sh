#!/usr/bin/env bash
# PROPUESTA C-4 (NO DESPLEGADA, sin aprobación del titular): web + queue:work + schedule:work en un solo contenedor.
# Probado en local con límites de producción (0,25 vCPU / 512 MiB); ver docs/azure-cost-optimization.md §C-4.
# Requiere maxReplicas=1 en la app web (dos réplicas duplicarían scheduler y worker).
# Uso: command ["mova-entrypoint"], args ["bash","/usr/local/bin/mova-supervise-aio"] (copiar este fichero a la imagen).
set -u
cd /app
php artisan optimize --no-interaction >/dev/null && php artisan view:cache --no-interaction >/dev/null || exit 1
declare -A PID; declare -a CRASHES
start() {
  case "$1" in
    web) frankenphp run --config /etc/caddy/Caddyfile & PID[web]=$! ;;
    queue) php artisan queue:work --queue=default --sleep=3 --tries=3 --timeout=60 --backoff=5 --no-interaction & PID[queue]=$! ;;
    sched) php artisan schedule:work -v & PID[sched]=$! ;;
  esac
}
stop_all() { kill -TERM "${PID[@]}" 2>/dev/null || true; wait "${PID[@]}" 2>/dev/null || true; }
trap 'echo "[aio] SIGTERM: parando todo" >&2; stop_all; exit 0' TERM INT
for p in web queue sched; do start $p; done
while true; do
  wait -n
  for p in web queue sched; do
    if ! kill -0 "${PID[$p]}" 2>/dev/null; then
      echo "[aio] $p terminó" >&2
      if [ "$p" = web ]; then stop_all; exit 1; fi
      now=$(date +%s); CRASHES+=("$now"); CRASHES=($(for t in "${CRASHES[@]}"; do [ $((now-t)) -le 600 ] && echo $t; done))
      if [ "${#CRASHES[@]}" -gt 5 ]; then echo "[aio] demasiadas caídas: reinicio del contenedor" >&2; stop_all; exit 1; fi
      sleep 5; start $p
    fi
  done
done
