#!/usr/bin/env bash
# QA — carrera real multi-proceso del flujo de pagos Culqi (ver App\Console\Commands\QaPaymentRace).
# Solo contra mysql_qa/mova_qa (guard en el comando). No habla con Culqi real.
#
#   bash scripts/qa-payment-race.sh reconcile-paid 8
#   bash scripts/qa-payment-race.sh reconcile-refund 8
#   bash scripts/qa-payment-race.sh charge-attempt 8
set -euo pipefail

OPERATION="${1:-}"
WORKERS="${2:-8}"
CONN=mysql_qa

# Nunca cambiar APP_ENV artificialmente para pasar un guard de producción.
# DB_HOST debe estar EXPORTADO: si no, Laravel podría leer un host remoto
# oculto en .env aunque el shell asumiera que es 127.0.0.1.
if [[ "${APP_ENV:-}" != testing || "${DB_CONNECTION:-}" != mysql_qa ]]; then
  echo 'Runner Culqi QA: exige APP_ENV=testing y DB_CONNECTION=mysql_qa.' >&2; exit 1
fi
case "${DB_HOST:-}" in
  127.0.0.1|localhost) ;;
  mysql_qa) if [[ "${MOVA_QA_COMPOSE:-}" != 1 || ! -f /.dockerenv ]]; then echo 'Runner Culqi QA: falta identidad Docker Compose QA.' >&2; exit 1; fi ;;
  *) echo 'Runner Culqi QA: DB_HOST no es loopback ni el servicio Docker Compose QA.' >&2; exit 1 ;;
esac
case "$OPERATION" in
  reconcile-paid|reconcile-refund|charge-attempt) ;;
  *) echo 'Runner Culqi QA: operación inválida.' >&2; exit 1 ;;
esac
if ! [[ "$WORKERS" =~ ^[1-9][0-9]*$ ]] || [ "${#WORKERS}" -gt 2 ] || [ "$WORKERS" -gt 32 ]; then
  echo 'Runner Culqi QA: WORKERS debe ser entero entre 1 y 32.' >&2; exit 1
fi

SCENARIO=""
STUB_PID=""
RUN_DIR="$(mktemp -d "${TMPDIR:-/tmp}/mova-culqi-race-XXXXXX")"
STUB_LOG="$RUN_DIR/culqi_posts.log"
STUB_DIR="$RUN_DIR/charges"
RUN_ID="$(basename "$RUN_DIR")"
STUB_ARG=()
cleanup_all() {
  local status=$?
  trap - EXIT
  if [[ -n "$STUB_PID" ]]; then
    kill "$STUB_PID" 2>/dev/null || true
    wait "$STUB_PID" 2>/dev/null || true
  fi
  if [[ "$SCENARIO" =~ ^[1-9][0-9]*$ ]]; then
    if ! php artisan mova:qa-payment-race "$OPERATION" --scenario="$SCENARIO" --cleanup --connection="$CONN" > /dev/null 2>&1; then
      echo "FALLO cleanup Culqi QA scenario=${SCENARIO}; revisar mova_qa." >&2
      status=1
    fi
  fi
  rm -f -- "$RUN_DIR"/*.out "$RUN_DIR"/*.log "$STUB_DIR"/*.json
  if [[ -d "$STUB_DIR" ]]; then rmdir -- "$STUB_DIR"; fi
  rmdir -- "$RUN_DIR"
  exit "$status"
}
trap cleanup_all EXIT

SETUP="$(php artisan mova:qa-payment-race "$OPERATION" --setup --connection="$CONN")"
SCENARIO="$(sed -n 's/.*scenario=\([^ ]*\).*/\1/p' <<<"$SETUP")"
if ! [[ "$SCENARIO" =~ ^[1-9][0-9]*$ ]]; then
  echo 'FALLO: setup no devolvió un escenario numérico.' >&2; exit 1
fi

if [ "$OPERATION" = "charge-attempt" ]; then
  mkdir -p -- "$STUB_DIR"
  PORT="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0");if(!$s)exit(1);$a=stream_socket_get_name($s,false);echo substr(strrchr($a,":"),1);fclose($s);')"
  CULQI_STUB_LOG="$STUB_LOG" CULQI_STUB_DIR="$STUB_DIR" CULQI_STUB_RUN_ID="$RUN_ID" PHP_CLI_SERVER_WORKERS=16 php -S 127.0.0.1:"$PORT" scripts/qa/culqi-stub.php > "$RUN_DIR/stub_server.log" 2>&1 &
  STUB_PID=$!
  STUB_READY=0
  for i in $(seq 1 30); do
    if [[ "$(curl -fsS --max-time 1 "http://127.0.0.1:$PORT/ping" 2>/dev/null || true)" == "$RUN_ID" ]]; then
      STUB_READY=1; break
    fi
    if ! kill -0 "$STUB_PID" 2>/dev/null; then break; fi
    sleep 0.2
  done
  if [[ "$STUB_READY" != 1 ]]; then
    echo 'FALLO: el doble HTTP Culqi no inició o su identidad no coincide.' >&2; exit 1
  fi
  STUB_ARG=(--stub-url="http://127.0.0.1:$PORT")
fi

# Barrera: todos los procesos disparan en el mismo instante tras arrancar.
AT="$(php -r 'printf("%.3f", microtime(true) + 5);')"

PIDS=()
for i in $(seq 1 "$WORKERS"); do
  php artisan mova:qa-payment-race "$OPERATION" --scenario="$SCENARIO" --at="$AT" --worker="$i" \
      --connection="$CONN" "${STUB_ARG[@]}" > "$RUN_DIR/$i.out" 2>&1 &
  PIDS+=($!)
done
WORKER_FAILED=0
for pid in "${PIDS[@]}"; do
  if ! wait "$pid"; then WORKER_FAILED=1; fi
done

OUT="$(cat "$RUN_DIR"/[0-9]*.out)"
echo "$OUT" | grep -E "worker=" | sort -t= -k2 -n | sed 's/^/  /' || true
if [[ "$WORKER_FAILED" == 1 ]]; then
  echo 'FALLO: algún worker Culqi terminó con error inesperado.' >&2
  exit 1
fi
VERIFY="$(php artisan mova:qa-payment-race "$OPERATION" --verify --scenario="$SCENARIO" --connection="$CONN" | grep '^verify')"
echo "  $VERIFY"

field() { sed -n "s/.*$1=\([^ ]*\).*/\1/p" <<<"$VERIFY"; }
ERRORS="$(grep -Ec 'result=error' <<<"$OUT" || true)"
DEPOSITS="$(field deposits)"; REVERSALS="$(field reversals)"; CREDITS="$(field credits_available)"; RECHARGE="$(field recharge)"; ORDERS="$(field orders)"
echo "operation=$OPERATION workers=$WORKERS errors=$ERRORS deposits=$DEPOSITS reversals=$REVERSALS credits=$CREDITS recharge=$RECHARGE"

STATUS=0
case "$OPERATION" in
  reconcile-paid)
    [ "$ERRORS" -eq 0 ] && [ "$DEPOSITS" -eq 1 ] && [ "$REVERSALS" -eq 0 ] && [ "${CREDITS%.*}" -eq 5 ] && [ "$RECHARGE" = approved ] || STATUS=1 ;;
  reconcile-refund)
    [ "$ERRORS" -eq 0 ] && [ "$DEPOSITS" -eq 1 ] && [ "$REVERSALS" -eq 1 ] && [ "${CREDITS%.*}" -eq 0 ] && [ "$RECHARGE" = reversed ] || STATUS=1 ;;
  charge-attempt)
    POSTS="$(grep -c 'POST /v2/charges' "$STUB_LOG" 2>/dev/null || true)"
    ACCEPTED="$(grep -c 'result=accepted' <<<"$OUT" || true)"
    echo "stub_post_charges=$POSTS accepted=$ACCEPTED"
    # También comprobar los PERDEDORES: antes un worker podía lanzar
    # result=error y el gate seguía GREEN si otro sí había cobrado una vez.
    [ "$ERRORS" -eq 0 ] && [ "$POSTS" -eq 1 ] && [ "$ACCEPTED" -eq 1 ] && [ "$ORDERS" -eq 1 ] && [ "$DEPOSITS" -eq 1 ] && [ "$REVERSALS" -eq 0 ] && [ "${CREDITS%.*}" -eq 5 ] && [ "$RECHARGE" = approved ] || STATUS=1 ;;
esac

[ "$STATUS" -eq 0 ] && echo "GREEN" || echo "RED"
exit "$STATUS"
