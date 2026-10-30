#!/usr/bin/env bash
# MOVA — Elimina TODO lo exclusivo del piloto de WAHA y nada más. Requiere --confirm.
# Es el respaldo manual de la caducidad automática de la VM (waha-expire.timer). Es idempotente: si la VM ya borró el grupo,
# solo limpia lo que quede fuera de él (peering `to-waha` en mova-vnet y roles huérfanos) y verifica.
#   --delete-secrets  además borra del Key Vault los 2 secretos exclusivos del piloto (soft-delete; no purga).
set -euo pipefail
cd "$(dirname "$0")"

[ "${1:-}" = "--confirm" ] || { echo "Uso: $0 --confirm [--delete-secrets]"; exit 2; }
[ -f pilot-state.env ] && . ./pilot-state.env
PILOT_RG="${PILOT_RG:-mova-waha-pilot-rg}"; HUB_RG="${HUB_RG:-mova-prod-rg}"; HUB_VNET="${HUB_VNET:-mova-vnet}"; KV="${KV:-kv-movap-192f01}"

# Guardia: el grupo solo se borra si lleva la etiqueta del piloto (jamás mova-prod-rg ni ningún otro).
if [ "$(az group exists -n "$PILOT_RG")" = "true" ]; then
  [ "$(az group show -n "$PILOT_RG" --query 'tags.purpose' -o tsv)" = "waha-pilot" ] || { echo "ABORTA: $PILOT_RG no está etiquetado purpose=waha-pilot"; exit 1; }
  [ "$PILOT_RG" != "$HUB_RG" ] || { echo "ABORTA: el grupo del piloto no puede ser el compartido"; exit 1; }
  echo "[1/4] Borrando el grupo de recursos del piloto (VM, disco, IP, NIC, VNet, NSG, peering to-mova)…"
  az group delete -n "$PILOT_RG" --yes -o none
else
  echo "[1/4] El grupo $PILOT_RG ya no existe (¿lo borró la propia VM?)."
fi

echo "[2/4] Peering huérfano en $HUB_VNET (único recurso compartido que el piloto modifica)…"
if az network vnet peering show -g "$HUB_RG" --vnet-name "$HUB_VNET" -n to-waha >/dev/null 2>&1; then
  az network vnet peering delete -g "$HUB_RG" --vnet-name "$HUB_VNET" -n to-waha -o none
fi

echo "[3/4] Roles huérfanos de la identidad de la VM…"
KV_ID="${KV_ID:-$(az keyvault show -n "$KV" --query id -o tsv)}"
if [ -n "${PRINCIPAL:-}" ]; then
  for id in $(az role assignment list --all --query "[?principalId=='$PRINCIPAL'].id" -o tsv 2>/dev/null); do az role assignment delete --ids "$id" -o none || true; done
else
  # Sin estado local: asignaciones cuyo principal ya no existe sobre el Key Vault y el grupo compartido.
  for scope in "$KV_ID"; do
    for id in $(az role assignment list --scope "$scope" --query "[?principalName==''].id" -o tsv 2>/dev/null); do az role assignment delete --ids "$id" -o none || true; done
  done
fi

if [ "${2:-}" = "--delete-secrets" ]; then
  echo "[4/4] Borrando los secretos exclusivos del piloto (soft-delete)…"
  for s in waha-api-key waha-hook-hmac-key; do az keyvault secret delete --vault-name "$KV" -n "$s" -o none 2>/dev/null || true; done
else
  echo "[4/4] Secretos del Key Vault conservados (usa --delete-secrets para borrarlos)."
fi

echo "Recuerda revertir staging: WHATSAPP_ENABLED=false y quitar WAHA_* (ver README)."
./pilot-verify.sh
