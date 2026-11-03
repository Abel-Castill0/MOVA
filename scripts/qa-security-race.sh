#!/usr/bin/env bash
# QA — carreras multi-proceso de MFA admin y del Libro de Reclamaciones.
# Escenarios aislados por ID, exclusivamente en mysql_qa/mova_qa local.
#
#   bash scripts/qa-security-race.sh totp 8
#   bash scripts/qa-security-race.sh recovery 8
#   bash scripts/qa-security-race.sh complaints-empty 20
#   bash scripts/qa-security-race.sh complaints-seeded 20
set -euo pipefail

OPERATION="${1:-}"
WORKERS="${2:-8}"
CONN=mysql_qa

# Nunca alterar APP_ENV para saltarse el guard, ni ocultar DB_HOST en .env.
if [[ "${APP_ENV:-}" != testing || "${DB_CONNECTION:-}" != mysql_qa ]]; then
  echo 'Runner seguridad QA: exige APP_ENV=testing y DB_CONNECTION=mysql_qa.' >&2; exit 1
fi
case "${DB_HOST:-}" in
  127.0.0.1|localhost) ;;
  mysql_qa) if [[ "${MOVA_QA_COMPOSE:-}" != 1 || ! -f /.dockerenv ]]; then echo 'Runner seguridad QA: falta identidad Docker Compose QA.' >&2; exit 1; fi ;;
  *) echo 'Runner seguridad QA: DB_HOST no es loopback ni servicio QA Compose.' >&2; exit 1 ;;
esac
case "$OPERATION" in
  totp|recovery|complaints-empty|complaints-seeded) ;;
  *) echo 'Runner seguridad QA: operación inválida.' >&2; exit 1 ;;
esac
if ! [[ "$WORKERS" =~ ^[1-9][0-9]*$ ]] || [ "${#WORKERS}" -gt 2 ] || [ "$WORKERS" -gt 32 ]; then
  echo 'Runner seguridad QA: WORKERS debe ser entero entre 1 y 32.' >&2; exit 1
fi

SCENARIO=""
RUN_DIR="$(mktemp -d "${TMPDIR:-/tmp}/mova-security-race-XXXXXX")"
cleanup_all() {
  local status=$?
  trap - EXIT
  if [[ "$SCENARIO" =~ ^[1-9][0-9]*$ || "$SCENARIO" =~ ^c-203[0-7]-[a-f0-9]{16}$ ]]; then
    if ! php artisan mova:qa-security-race "$OPERATION" --cleanup --scenario="$SCENARIO" --connection="$CONN" > /dev/null 2>&1; then
      echo "FALLO cleanup seguridad QA scenario=${SCENARIO}; revisar mova_qa." >&2
      status=1
    fi
  fi
  rm -f -- "$RUN_DIR"/[0-9]*.out
  rmdir -- "$RUN_DIR"
  exit "$status"
}
trap cleanup_all EXIT

SETUP="$(php artisan mova:qa-security-race "$OPERATION" --setup --connection="$CONN")"
SCENARIO="$(sed -n 's/.*scenario=\([^ ]*\).*/\1/p' <<<"$SETUP")"
CODE="$(sed -n 's/.*code=\([^ ]*\).*/\1/p' <<<"$SETUP")"
case "$OPERATION" in
  totp|recovery)
    if ! [[ "$SCENARIO" =~ ^[1-9][0-9]*$ ]] || [[ -z "$CODE" || "$CODE" == - ]]; then
      echo 'FALLO: setup MFA no devolvió escenario y código válidos.' >&2; exit 1
    fi ;;
  complaints-*)
    if ! [[ "$SCENARIO" =~ ^c-203[0-7]-[a-f0-9]{16}$ ]]; then
      echo 'FALLO: setup reclamaciones no devolvió escenario QA válido.' >&2; exit 1
    fi
    YEAR="${SCENARIO:2:4}" ;;
esac

AT="$(php -r 'printf("%.3f", microtime(true) + 5);')"
PIDS=()
for i in $(seq 1 "$WORKERS"); do
  php artisan mova:qa-security-race "$OPERATION" --scenario="$SCENARIO" --code="$CODE" \
      --at="$AT" --worker="$i" --connection="$CONN" > "$RUN_DIR/$i.out" 2>&1 &
  PIDS+=($!)
done
WORKER_FAILED=0
for pid in "${PIDS[@]}"; do
  if ! wait "$pid"; then WORKER_FAILED=1; fi
done

OUT="$(cat "$RUN_DIR"/[0-9]*.out)"
echo "$OUT" | grep 'worker=' | sort -t= -k2 -n || true
ACCEPTED="$(grep -c 'result=accepted' <<<"$OUT" || true)"
ERRORS="$(grep -Ec 'result=(error|status=)' <<<"$OUT" || true)"
echo "operation=$OPERATION workers=$WORKERS accepted=$ACCEPTED errors=$ERRORS worker_failed=$WORKER_FAILED"

STATUS=0
if [ "$WORKER_FAILED" -ne 0 ] || [ "$ERRORS" -ne 0 ]; then STATUS=1; fi
case "$OPERATION" in
  totp) [ "$ACCEPTED" -le 1 ] || STATUS=1 ;;
  recovery) [ "$ACCEPTED" -eq 1 ] || STATUS=1 ;;
  complaints-*)
    VERIFY="$(php artisan mova:qa-security-race "$OPERATION" --verify --scenario="$SCENARIO" --connection="$CONN" | grep '^verify')"
    echo "$VERIFY"
    field() { sed -n "s/.*$1=\([^ ]*\).*/\1/p" <<<"$VERIFY"; }
    ROWS="$(field rows)"; YEAR_ROWS="$(field total_year)"; LAST="$(field last_number)"; VERIFIED_YEAR="$(field year)"
    UNIQUE="$( (grep -o "code=MOVA-${YEAR}-[0-9]*" <<<"$OUT" || true) | sort -u | wc -l)"
    BASE=0
    [ "$OPERATION" = complaints-seeded ] && BASE=3
    EXPECTED_ROWS=$((WORKERS + BASE))
    NUMS="$( (grep -o "code=MOVA-${YEAR}-[0-9]*" <<<"$OUT" || true) | sed 's/.*-0*//' | sort -n | tr '\n' ' ')"
    WANT="$(seq $((BASE + 1)) "$EXPECTED_ROWS" | tr '\n' ' ')"
    echo "unique_codes=$UNIQUE scenario_rows=$ROWS year_rows=$YEAR_ROWS last_number=$LAST contiguous=$([ "$NUMS" = "$WANT" ] && echo yes || echo no)"
    { [ "$ACCEPTED" -eq "$WORKERS" ] && [ "$UNIQUE" -eq "$WORKERS" ] && [ "$ROWS" -eq "$EXPECTED_ROWS" ] && [ "$YEAR_ROWS" -eq "$ROWS" ] && [ "$LAST" -eq "$EXPECTED_ROWS" ] && [ "$VERIFIED_YEAR" = "$YEAR" ] && [ "$NUMS" = "$WANT" ]; } || STATUS=1 ;;
esac

[ "$STATUS" -eq 0 ] && echo GREEN || echo RED
exit "$STATUS"
