#!/usr/bin/env bash
# MOVA — Comprueba, con consultas de solo lectura, que el piloto de WAHA desapareció y que lo compartido quedó igual.
# Sale con código 1 si algo falla. No usa Cost Management (sus cargos llegan con 8–24 h de retraso): comprueba el estado real.
set -uo pipefail
cd "$(dirname "$0")"
[ -f pilot-state.env ] && . ./pilot-state.env
PILOT_RG="${PILOT_RG:-mova-waha-pilot-rg}"; HUB_RG="${HUB_RG:-mova-prod-rg}"; HUB_VNET="${HUB_VNET:-mova-vnet}"; KV="${KV:-kv-movap-192f01}"
FAIL=0
check() { if [ "$2" = "ok" ]; then echo "PASS  $1"; else echo "FAIL  $1  -> $3"; FAIL=1; fi; }

[ "$(az group exists -n "$PILOT_RG")" = "false" ] && check "grupo $PILOT_RG eliminado (VM, disco, IP, NIC, VNet, NSG, peering to-mova)" ok || check "grupo $PILOT_RG eliminado" bad "sigue existiendo"

LEFT="$(az resource list --tag purpose=waha-pilot --query "[].id" -o tsv 2>/dev/null)"
[ -z "$LEFT" ] && check "ningún recurso con etiqueta purpose=waha-pilot en la suscripción" ok || check "recursos etiquetados restantes" bad "$LEFT"

az network vnet peering show -g "$HUB_RG" --vnet-name "$HUB_VNET" -n to-waha >/dev/null 2>&1 \
  && check "peering to-waha eliminado de $HUB_VNET" bad "sigue existiendo" || check "peering to-waha eliminado de $HUB_VNET" ok

if [ -n "${PRINCIPAL:-}" ]; then
  R="$(az role assignment list --all --query "[?principalId=='$PRINCIPAL'].id" -o tsv 2>/dev/null)"
  [ -z "$R" ] && check "sin roles de la identidad de la VM" ok || check "roles restantes" bad "$R"
fi

if [ -f pilot-baseline.txt ]; then
  az resource list -g "$HUB_RG" --query "[].id" -o tsv | sort > /tmp/pilot-now.txt
  D="$(diff pilot-baseline.txt /tmp/pilot-now.txt)"
  [ -z "$D" ] && check "recursos de $HUB_RG idénticos a la línea base previa al piloto" ok || check "recursos de $HUB_RG cambiaron" bad "$D"
  az network vnet peering list -g "$HUB_RG" --vnet-name "$HUB_VNET" --query "[].name" -o tsv | sort > /tmp/pilot-peer-now.txt
  D2="$(diff pilot-baseline-peerings.txt /tmp/pilot-peer-now.txt)"
  [ -z "$D2" ] && check "peerings de $HUB_VNET idénticos a la línea base" ok || check "peerings de $HUB_VNET cambiaron" bad "$D2"
else
  echo "INFO  sin línea base local (pilot-baseline.txt): no se compara $HUB_RG"
fi

echo; [ $FAIL -eq 0 ] && echo "TODO LIMPIO." || echo "HAY RESTOS: ejecuta ./pilot-teardown.sh --confirm y vuelve a verificar."
exit $FAIL
