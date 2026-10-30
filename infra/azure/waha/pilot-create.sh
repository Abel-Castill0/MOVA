#!/usr/bin/env bash
# MOVA — Piloto de WAHA en una VM B2pts v2 (ARM64). NO SE EJECUTA SIN LA APROBACIÓN EXPRESA DEL TITULAR.
# Crea EXACTAMENTE estos recursos en `mova-prod-rg` (región northcentralus) y nada más:
#   VNet movap-waha-vnet · NSG movap-waha-nsg · IP pública Standard movap-waha-pip (SOLO salida) · NIC movap-waha-nic ·
#   VM movap-waha (Standard_B2pts_v2, Ubuntu 24.04 ARM64, disco Standard SSD 32 GiB) · 2 peerings con mova-vnet ·
#   1 asignación de rol «Key Vault Secrets User» a la identidad de la VM.
# Requiere Azure CLI autenticado. No imprime secretos y no crea secretos: los secretos `waha-api-key` y
# `waha-hook-hmac-key` ya deben existir en el Key Vault (los genera el operador con `openssl rand` y los carga sin mostrarlos).
set -euo pipefail

if [ "${1:-}" != "--confirm" ]; then
  echo "Uso: $0 --confirm  (crea recursos facturables; ver infra/azure/waha/README.md)"; exit 2
fi

RG="${RG:-mova-prod-rg}"; LOC="${LOC:-northcentralus}"
VM="${VM:-movap-waha}"; VNET="movap-waha-vnet"; SUBNET="default"; NSG="movap-waha-nsg"; PIP="movap-waha-pip"; NIC="movap-waha-nic"
PRIV_IP="10.30.0.4"; HUB_VNET="${HUB_VNET:-mova-vnet}"; HUB_CIDR="${HUB_CIDR:-10.20.0.0/16}"
KV="${KV:-kv-movap-192f01}"
HOOK_URL="${HOOK_URL:?Define HOOK_URL=https://<host-del-staging>/api/webhooks/waha}"
DELETE_BY="$(date -u -d '+3 days' +%F)"
TAGS="purpose=waha-pilot delete-by=${DELETE_BY} owner=mova"

echo "[1/8] Registrando proveedor Microsoft.Compute (gratuito)…"
az provider register -n Microsoft.Compute --wait >/dev/null

echo "[2/8] Comprobando que existen los secretos en el Key Vault (solo nombres)…"
for s in waha-api-key waha-hook-hmac-key; do
  az keyvault secret show --vault-name "$KV" -n "$s" --query name -o tsv >/dev/null
done

echo "[3/8] NSG: nada entra desde Internet; el puerto 3000 solo desde la VNet de MOVA…"
az network nsg create -g "$RG" -n "$NSG" -l "$LOC" --tags $TAGS -o none
az network nsg rule create -g "$RG" --nsg-name "$NSG" -n AllowMovaVnet3000 --priority 100 --direction Inbound --access Allow \
  --protocol Tcp --source-address-prefixes "$HUB_CIDR" --destination-port-ranges 3000 -o none
az network nsg rule create -g "$RG" --nsg-name "$NSG" -n DenyInternetInbound --priority 4000 --direction Inbound --access Deny \
  --protocol '*' --source-address-prefixes Internet --destination-port-ranges '*' -o none

echo "[4/8] VNet 10.30.0.0/24 y IP pública Standard (solo para la SALIDA de WAHA hacia WhatsApp)…"
az network vnet create -g "$RG" -n "$VNET" -l "$LOC" --address-prefixes 10.30.0.0/24 --subnet-name "$SUBNET" \
  --subnet-prefixes 10.30.0.0/24 --nsg "$NSG" --tags $TAGS -o none
az network public-ip create -g "$RG" -n "$PIP" -l "$LOC" --sku Standard --allocation-method Static --version IPv4 --tags $TAGS -o none
az network nic create -g "$RG" -n "$NIC" -l "$LOC" --vnet-name "$VNET" --subnet "$SUBNET" --private-ip-address "$PRIV_IP" \
  --public-ip-address "$PIP" --tags $TAGS -o none

echo "[5/8] VM Standard_B2pts_v2 (ARM64)…"
CI="$(mktemp)"; trap 'rm -f "$CI"' EXIT
sed -e "s#__KV_NAME__#${KV}#" -e "s#__HOOK_URL__#${HOOK_URL}#" "$(dirname "$0")/cloud-init.yaml" > "$CI"
az vm create -g "$RG" -n "$VM" -l "$LOC" --size Standard_B2pts_v2 --image Canonical:ubuntu-24_04-lts:server-arm64:latest \
  --nics "$NIC" --os-disk-size-gb 32 --storage-sku StandardSSD_LRS --admin-username mova --authentication-type ssh --generate-ssh-keys \
  --assign-identity '[system]' --custom-data "$CI" --tags $TAGS -o none

echo "[6/8] Rol «Key Vault Secrets User» para la identidad de la VM (solo lectura de secretos)…"
PRINCIPAL="$(az vm show -g "$RG" -n "$VM" --query identity.principalId -o tsv)"
KV_ID="$(az keyvault show -n "$KV" --query id -o tsv)"
az role assignment create --assignee-object-id "$PRINCIPAL" --assignee-principal-type ServicePrincipal \
  --role "Key Vault Secrets User" --scope "$KV_ID" -o none

echo "[7/8] Peering privado con ${HUB_VNET} (la API de WAHA se alcanza por IP privada ${PRIV_IP})…"
HUB_ID="$(az network vnet show -g "$RG" -n "$HUB_VNET" --query id -o tsv)"
WAHA_ID="$(az network vnet show -g "$RG" -n "$VNET" --query id -o tsv)"
az network vnet peering create -g "$RG" --vnet-name "$VNET" -n to-mova --remote-vnet "$HUB_ID" --allow-vnet-access -o none
az network vnet peering create -g "$RG" --vnet-name "$HUB_VNET" -n to-waha --remote-vnet "$WAHA_ID" --allow-vnet-access -o none

echo "[8/8] Listo. URL privada para MOVA: http://${PRIV_IP}:3000  (WAHA_BASE_URL). Elimina antes de ${DELETE_BY}: ./pilot-teardown.sh --confirm"
echo "Siguiente: vincular el QR desde el panel de administración de MOVA y revisar costos con el medidor de la VM (ver README)."
