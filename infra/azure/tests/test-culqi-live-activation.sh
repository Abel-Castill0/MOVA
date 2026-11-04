#!/usr/bin/env bash
# Pruebas de culqi-live-activation.sh con un az simulado (sin Azure ni cobros): bash infra/azure/tests/test-culqi-live-activation.sh
set -u
HERE=$(cd "$(dirname "$0")" && pwd); TOOL="$HERE/../culqi-live-activation.sh"
BIN=$(mktemp -d); cp "$HERE/fake-az" "$BIN/az"; chmod +x "$BIN/az"; export PATH="$BIN:$PATH"
PASS=0; FAIL=0
reset() {
  export FAKE_DIR; FAKE_DIR=$(mktemp -d); unset FAKE_READ_FAIL FAKE_UPDATE_FAIL FAKE_VERIFIED FAKE_QUOTA FAKE_SUBNAME
  for a in movap-web movap-scheduler; do
    printf '[{"name":"PAYMENT_PROVIDER","value":"%s"},{"name":"CULQI_SECRET_KEY","secretRef":"culqi-secret-key"},{"name":"CULQI_WEBHOOK_SECRET","secretRef":"culqi-webhook-secret"}%s]' "$1" "${2:+,{\"name\":\"CULQI_CHECKOUT_ALLOWLIST\",\"value\":\"$2\"\}}" > "$FAKE_DIR/$a.json"
  done
}
val() { python3 -c 'import json,sys; print({e["name"]:e.get("value") for e in json.load(open(sys.argv[1]))}.get(sys.argv[2]) or "")' "$FAKE_DIR/$1.json" "$2"; }
t() { if eval "$2"; then PASS=$((PASS+1)); echo "  ✓ $1"; else FAIL=$((FAIL+1)); echo "  ✗ $1"; fi; }
run() { echo "$1" | bash "$TOOL" "${@:2}" >"$FAKE_DIR/out" 2>&1; echo $? > "$FAKE_DIR/rc"; }
rc() { cat "$FAKE_DIR/rc"; }
updates() { [ -f "$FAKE_DIR/updates.log" ] && wc -l < "$FAKE_DIR/updates.log" || echo 0; }

reset mercadopago; FAKE_QUOTA=AzureForStudents_2018-01-01 run ACTIVAR enable-test profe@example.pe
t "Azure for Students bloquea enable-test sin tocar nada" '[ "$(rc)" != 0 ] && [ "$(updates)" = 0 ]'

reset mercadopago; FAKE_QUOTA="" run ACTIVAR enable-test profe@example.pe
t "sin quotaId (no se puede confirmar) también bloquea" '[ "$(rc)" != 0 ] && [ "$(updates)" = 0 ]'

reset mercadopago; FAKE_READ_FAIL=movap-web run ACTIVAR enable-test profe@example.pe
t "fallo al leer la configuración: no se cambia nada" '[ "$(rc)" != 0 ] && [ "$(updates)" = 0 ]'

reset mercadopago; run NO enable-test profe@example.pe
t "sin escribir ACTIVAR no se cambia nada" '[ "$(rc)" != 0 ] && [ "$(updates)" = 0 ]'

reset mercadopago; run ACTIVAR enable-test profe@example.pe
t "enable-test abre solo para ese correo en web y scheduler" '[ "$(rc)" = 0 ] && [ "$(val movap-web PAYMENT_PROVIDER)" = culqi ] && [ "$(val movap-scheduler CULQI_CHECKOUT_ALLOWLIST)" = profe@example.pe ] && [ "$(val movap-web CULQI_CHECKOUT_ALLOWLIST)" = profe@example.pe ]'

reset culqi profe@example.pe; run ACTIVAR enable-all
t "enable-all sin compra live verificada se niega" '[ "$(rc)" != 0 ] && [ "$(updates)" = 0 ]'

reset culqi profe@example.pe; FAKE_VERIFIED=si FAKE_UPDATE_FAIL=movap-web run ACTIVAR enable-all
t "si web falla, el scheduler vuelve a la lista de prueba (no queda abierto a todos)" '[ "$(rc)" != 0 ] && [ "$(val movap-scheduler CULQI_CHECKOUT_ALLOWLIST)" = profe@example.pe ]'

reset culqi profe@example.pe; FAKE_VERIFIED=si run ACTIVAR enable-all
t "enable-all con compra verificada abre a todos" '[ "$(rc)" = 0 ] && [ -z "$(val movap-web CULQI_CHECKOUT_ALLOWLIST)" ] && [ -z "$(val movap-scheduler CULQI_CHECKOUT_ALLOWLIST)" ]'

reset culqi; run DETENER disable
t "disable cierra con *.invalid en ambos y mantiene Culqi para conciliar" '[ "$(rc)" = 0 ] && [[ "$(val movap-web CULQI_CHECKOUT_ALLOWLIST)" == *.invalid ]] && [[ "$(val movap-scheduler CULQI_CHECKOUT_ALLOWLIST)" == *.invalid ]] && [ "$(val movap-web PAYMENT_PROVIDER)" = culqi ] && [ "$(val movap-web MERCADOPAGO_NEW_PAYMENTS_ENABLED)" = false ]'

reset culqi; FAKE_UPDATE_FAIL=movap-web run DETENER disable
t "si web no se puede cerrar: falla visible y el scheduler NO se reabre" '[ "$(rc)" != 0 ] && [[ "$(val movap-scheduler CULQI_CHECKOUT_ALLOWLIST)" == *.invalid ]]'

reset culqi; run x disable --dry-run
t "--dry-run no cambia nada" '[ "$(updates)" = 0 ]'

reset mercadopago; run x check
t "check es solo lectura" '[ "$(updates)" = 0 ]'

echo "Resultado: $PASS pasan, $FAIL fallan"; [ $FAIL -eq 0 ]
