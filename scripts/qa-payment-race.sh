#!/usr/bin/env bash
# QA — carrera real multi-proceso del flujo de pagos Culqi (ver App\Console\Commands\QaPaymentRace).
# Solo contra mysql_qa/mova_qa (guard en el comando). No habla con Culqi real.
#
#   bash scripts/qa-payment-race.sh reconcile-paid 8
#   bash scripts/qa-payment-race.sh reconcile-refund 8
#   bash scripts/qa-payment-race.sh charge-attempt 8
set -euo pipefail

OPERATION="$1"
WORKERS="${2:-8}"
CONN=mysql_qa
export APP_ENV=testing

SETUP="$(php artisan mova:qa-payment-race "$OPERATION" --setup --connection="$CONN")"
SCENARIO="$(sed -n 's/.*scenario=\([^ ]*\).*/\1/p' <<<"$SETUP")"

STUB_PID=""
STUB_ARG=()
STUB_LOG="/tmp/culqi_stub_posts.log"
cleanup_all() {
  [ -n "$STUB_PID" ] && kill "$STUB_PID" 2>/dev/null || true
  php artisan mova:qa-payment-race "$OPERATION" --cleanup --connection="$CONN" > /dev/null 2>&1 || true
  rm -f /tmp/qa_payrace_"${OPERATION}"_*.out
}
trap cleanup_all EXIT

if [ "$OPERATION" = "charge-attempt" ]; then
  rm -f "$STUB_LOG"; rm -rf /tmp/culqi_stub_charges
  PORT=18765
  CULQI_STUB_LOG="$STUB_LOG" PHP_CLI_SERVER_WORKERS=16 php -S 127.0.0.1:$PORT scripts/qa/culqi-stub.php > /tmp/culqi_stub.out 2>&1 &
  STUB_PID=$!
  for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/ping" && break; sleep 0.3; done
  STUB_ARG=(--stub-url="http://127.0.0.1:$PORT")
fi

# Barrera: todos los procesos disparan en el mismo instante tras arrancar.
AT="$(php -r 'printf("%.3f", microtime(true) + 5);')"

PIDS=()
for i in $(seq 1 "$WORKERS"); do
  php artisan mova:qa-payment-race "$OPERATION" --scenario="$SCENARIO" --at="$AT" --worker="$i" \
      --connection="$CONN" "${STUB_ARG[@]}" > "/tmp/qa_payrace_${OPERATION}_$i.out" 2>&1 &
  PIDS+=($!)
done
for pid in "${PIDS[@]}"; do wait "$pid"; done

OUT="$(cat /tmp/qa_payrace_"${OPERATION}"_*.out)"
echo "$OUT" | grep -E "worker=" | sort -t= -k2 -n | sed 's/^/  /' || true
VERIFY="$(php artisan mova:qa-payment-race "$OPERATION" --verify --scenario="$SCENARIO" --connection="$CONN" | grep '^verify')"
echo "  $VERIFY"

field() { sed -n "s/.*$1=\([^ ]*\).*/\1/p" <<<"$VERIFY"; }
ERRORS="$(grep -Ec 'result=error' <<<"$OUT" || true)"
DEPOSITS="$(field deposits)"; REVERSALS="$(field reversals)"; CREDITS="$(field credits_available)"; RECHARGE="$(field recharge)"
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
    [ "$POSTS" -eq 1 ] && [ "$ACCEPTED" -eq 1 ] && [ "$DEPOSITS" -eq 1 ] && [ "${CREDITS%.*}" -eq 5 ] && [ "$RECHARGE" = approved ] || STATUS=1 ;;
esac

[ "$STATUS" -eq 0 ] && echo "GREEN" || echo "RED"
exit "$STATUS"
