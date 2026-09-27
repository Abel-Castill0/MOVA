#!/usr/bin/env bash
# QA — carrera real multi-proceso (ver App\Console\Commands\QaSecurityRace).
# Solo contra mysql_qa/mova_qa (guard en el comando).
#
#   bash scripts/qa-security-race.sh totp 8
#   bash scripts/qa-security-race.sh recovery 8
#   bash scripts/qa-security-race.sh complaints-empty 20
#   bash scripts/qa-security-race.sh complaints-seeded 20
set -euo pipefail

OPERATION="$1"
WORKERS="${2:-8}"
CONN=mysql_qa
export APP_ENV=testing

SETUP="$(php artisan mova:qa-security-race "$OPERATION" --setup --connection="$CONN")"
SCENARIO="$(sed -n 's/.*scenario=\([^ ]*\).*/\1/p' <<<"$SETUP")"
CODE="$(sed -n 's/.*code=\([^ ]*\).*/\1/p' <<<"$SETUP")"
# Barrera: todos los procesos disparan en el mismo instante tras arrancar.
AT="$(php -r 'printf("%.3f", microtime(true) + 4);')"

PIDS=()
for i in $(seq 1 "$WORKERS"); do
  php artisan mova:qa-security-race "$OPERATION" --scenario="$SCENARIO" --code="$CODE" \
      --at="$AT" --worker="$i" --connection="$CONN" > "/tmp/qa_race_${OPERATION}_$i.out" 2>&1 &
  PIDS+=($!)
done
for pid in "${PIDS[@]}"; do wait "$pid"; done

OUT="$(cat /tmp/qa_race_${OPERATION}_*.out)"
rm -f /tmp/qa_race_${OPERATION}_*.out
echo "$OUT" | grep -E "worker=" || true

ACCEPTED="$(grep -c 'result=accepted' <<<"$OUT" || true)"
ERRORS="$(grep -Ec 'result=(error|status=)' <<<"$OUT" || true)"
echo "operation=$OPERATION workers=$WORKERS accepted=$ACCEPTED errors=$ERRORS"

STATUS=0
case "$OPERATION" in
  totp)      [ "$ACCEPTED" -le 1 ] || STATUS=1 ;;
  recovery)  [ "$ACCEPTED" -eq 1 ] || STATUS=1 ;;
  complaints-*)
    UNIQUE="$( (grep -o "code=MOVA-[0-9-]*" <<<"$OUT" || true) | sort -u | wc -l)"
    YEAR="$(date +%Y)"
    ROWS="$(php artisan tinker --execute="config(['database.default'=>'$CONN']); echo App\\Models\\Complaint::where('code','like','MOVA-$YEAR-%')->count();" 2>/dev/null | tail -1)"
    EXPECTED_ROWS=$WORKERS; [ "$OPERATION" = complaints-seeded ] && EXPECTED_ROWS=$((WORKERS + 3))
    # Correlativos coherentes: los N nuevos son exactamente (base+1 .. base+N), sin huecos.
    NUMS="$( (grep -o "code=MOVA-[0-9]*-[0-9]*" <<<"$OUT" || true) | sed 's/.*-0*//' | sort -n | tr '\n' ' ')"
    BASE=$((EXPECTED_ROWS - WORKERS))
    WANT="$(seq $((BASE + 1)) "$EXPECTED_ROWS" | tr '\n' ' ')"
    echo "unique_codes=$UNIQUE rows_this_year=$ROWS expected_rows=$EXPECTED_ROWS contiguous=$([ "$NUMS" = "$WANT" ] && echo yes || echo no)"
    { [ "$ACCEPTED" -eq "$WORKERS" ] && [ "$UNIQUE" -eq "$WORKERS" ] && [ "$ERRORS" -eq 0 ] && [ "$ROWS" -eq "$EXPECTED_ROWS" ] && [ "$NUMS" = "$WANT" ]; } || STATUS=1 ;;
esac

php artisan mova:qa-security-race "$OPERATION" --cleanup --connection="$CONN" > /dev/null 2>&1 || true
[ "$STATUS" -eq 0 ] && echo "GREEN" || echo "RED"
exit "$STATUS"
