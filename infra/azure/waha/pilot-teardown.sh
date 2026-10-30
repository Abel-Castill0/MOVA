#!/usr/bin/env bash
# MOVA — Elimina TODO lo que creó pilot-create.sh (y solo eso). Requiere --confirm. Los secretos del Key Vault NO se borran
# (se pueden reutilizar); la sesión de WhatsApp vive en el disco de la VM y se pierde con ella (hay que volver a vincular).
set -euo pipefail

[ "${1:-}" = "--confirm" ] || { echo "Uso: $0 --confirm"; exit 2; }

RG="${RG:-mova-prod-rg}"; VM="${VM:-movap-waha}"; HUB_VNET="${HUB_VNET:-mova-vnet}"; KV="${KV:-kv-movap-192f01}"

# Seguridad: solo borra recursos etiquetados purpose=waha-pilot.
guard() { [ "$(az resource show -g "$RG" -n "$1" --resource-type "$2" --query "tags.purpose" -o tsv 2>/dev/null)" = "waha-pilot" ]; }

PRINCIPAL="$(az vm show -g "$RG" -n "$VM" --query identity.principalId -o tsv 2>/dev/null || true)"
[ -n "$PRINCIPAL" ] && az role assignment delete --assignee "$PRINCIPAL" --role "Key Vault Secrets User" --scope "$(az keyvault show -n "$KV" --query id -o tsv)" || true
az network vnet peering delete -g "$RG" --vnet-name "$HUB_VNET" -n to-waha 2>/dev/null || true
guard "$VM" Microsoft.Compute/virtualMachines && az vm delete -g "$RG" -n "$VM" --yes -o none
az disk list -g "$RG" --query "[?tags.purpose=='waha-pilot'].name" -o tsv | while read -r d; do [ -n "$d" ] && az disk delete -g "$RG" -n "$d" --yes -o none; done
guard movap-waha-nic Microsoft.Network/networkInterfaces && az network nic delete -g "$RG" -n movap-waha-nic -o none
guard movap-waha-pip Microsoft.Network/publicIPAddresses && az network public-ip delete -g "$RG" -n movap-waha-pip -o none
guard movap-waha-vnet Microsoft.Network/virtualNetworks && az network vnet delete -g "$RG" -n movap-waha-vnet -o none
guard movap-waha-nsg Microsoft.Network/networkSecurityGroups && az network nsg delete -g "$RG" -n movap-waha-nsg -o none
echo "Piloto de WAHA eliminado. Recuerda poner WHATSAPP_ENABLED=false en el entorno que lo usaba."
