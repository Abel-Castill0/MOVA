#!/usr/bin/env bash
# MOVA — Piloto de WAHA en una VM B2pts v2 (ARM64). NO SE EJECUTA SIN LA APROBACIÓN EXPRESA DEL TITULAR.
#
# Todo lo exclusivo del piloto vive en UN grupo de recursos propio (mova-waha-pilot-rg, northcentralus):
#   VNet · NSG · IP pública Standard (solo salida) · NIC · VM Standard_B2pts_v2 · disco SO · peering to-mova.
# Fuera de ese grupo solo se crean 3 cosas, todas exclusivas del piloto y retiradas por el teardown:
#   · peering `to-waha` en mova-vnet · rol «Network Contributor» de la VM sobre ESE peering · rol «Key Vault Secrets User»
#   de la VM (solo hasta su primer arranque; este script lo retira) · y la VM tiene «Contributor» sobre su propio grupo.
# No toca Culqi, el ledger, movap-web/scheduler, la base de datos ni ningún otro recurso compartido.
#
# Los secretos `waha-api-key` y `waha-hook-hmac-key` deben existir ya en el Key Vault (se generan con `openssl rand` y se cargan
# sin mostrarlos). Este script no los crea ni los imprime.
set -euo pipefail
cd "$(dirname "$0")"

[ "${1:-}" = "--confirm" ] || { echo "Uso: HOOK_URL=https://<host-staging>/api/webhooks/waha $0 --confirm"; exit 2; }

PILOT_RG="${PILOT_RG:-mova-waha-pilot-rg}"; LOC="${LOC:-northcentralus}"
HUB_RG="${HUB_RG:-mova-prod-rg}"; HUB_VNET="${HUB_VNET:-mova-vnet}"; HUB_CIDR="${HUB_CIDR:-10.20.0.0/16}"
KV="${KV:-kv-movap-192f01}"
VM="movap-waha"; VNET="movap-waha-vnet"; SUBNET="default"; NSG="movap-waha-nsg"; PIP="movap-waha-pip"; NIC="movap-waha-nic"
PRIV_IP="10.30.0.4"
HOOK_URL="${HOOK_URL:?Define HOOK_URL=https://<host-del-staging>/api/webhooks/waha}"
EXPIRES_AT="$(( $(date +%s) + ${PILOT_SECONDS:-171900} ))"   # caducidad dura: 47 h 45 min (<48 h) desde ahora
EXPIRES_ISO="$(date -u -d "@${EXPIRES_AT}" +%FT%TZ)"
TAGS="purpose=waha-pilot expires-at=${EXPIRES_ISO} owner=mova"
STATE="./pilot-state.env"                           # local, ignorado por Git: ids para verificar/retirar roles

SUB_ID="$(az account show --query id -o tsv)"
HUB_ID="$(az network vnet show -g "$HUB_RG" -n "$HUB_VNET" --query id -o tsv)"
HUB_PEERING_ID="${HUB_ID}/virtualNetworkPeerings/to-waha"
KV_ID="$(az keyvault show -n "$KV" --query id -o tsv)"

echo "[0/9] Línea base de recursos compartidos (para demostrar al final que no cambiaron)…"
az resource list -g "$HUB_RG" --query "[].id" -o tsv | sort > pilot-baseline.txt
az network vnet peering list -g "$HUB_RG" --vnet-name "$HUB_VNET" --query "[].name" -o tsv | sort > pilot-baseline-peerings.txt
wc -l < pilot-baseline.txt | xargs echo "      recursos en ${HUB_RG}:"

echo "[1/9] Registrando Microsoft.Compute (gratuito) y comprobando secretos (solo nombres)…"
az provider register -n Microsoft.Compute --wait >/dev/null
for s in waha-api-key waha-hook-hmac-key; do az keyvault secret show --vault-name "$KV" -n "$s" --query name -o tsv >/dev/null; done

echo "[2/9] Grupo de recursos exclusivo del piloto…"
az group create -n "$PILOT_RG" -l "$LOC" --tags $TAGS -o none

echo "[3/9] NSG: nada entra desde Internet; 3000/TCP solo desde la VNet de MOVA…"
az network nsg create -g "$PILOT_RG" -n "$NSG" -l "$LOC" --tags $TAGS -o none
az network nsg rule create -g "$PILOT_RG" --nsg-name "$NSG" -n AllowMovaVnet3000 --priority 100 --direction Inbound --access Allow \
  --protocol Tcp --source-address-prefixes "$HUB_CIDR" --destination-port-ranges 3000 -o none
az network nsg rule create -g "$PILOT_RG" --nsg-name "$NSG" -n DenyInternetInbound --priority 4000 --direction Inbound --access Deny \
  --protocol '*' --source-address-prefixes Internet --destination-port-ranges '*' -o none

echo "[4/9] VNet 10.30.0.0/24, IP pública Standard (solo SALIDA hacia WhatsApp) y NIC…"
az network vnet create -g "$PILOT_RG" -n "$VNET" -l "$LOC" --address-prefixes 10.30.0.0/24 --subnet-name "$SUBNET" \
  --subnet-prefixes 10.30.0.0/24 --nsg "$NSG" --tags $TAGS -o none
az network public-ip create -g "$PILOT_RG" -n "$PIP" -l "$LOC" --sku Standard --allocation-method Static --version IPv4 --tags $TAGS -o none
az network nic create -g "$PILOT_RG" -n "$NIC" -l "$LOC" --vnet-name "$VNET" --subnet "$SUBNET" --private-ip-address "$PRIV_IP" \
  --public-ip-address "$PIP" --tags $TAGS -o none

echo "[5/9] Peering privado MOVA ↔ WAHA…"
WAHA_VNET_ID="$(az network vnet show -g "$PILOT_RG" -n "$VNET" --query id -o tsv)"
az network vnet peering create -g "$PILOT_RG" --vnet-name "$VNET" -n to-mova --remote-vnet "$HUB_ID" --allow-vnet-access -o none
az network vnet peering create -g "$HUB_RG" --vnet-name "$HUB_VNET" -n to-waha --remote-vnet "$WAHA_VNET_ID" --allow-vnet-access -o none

echo "[6/9] VM Standard_B2pts_v2 (ARM64) con caducidad en ${EXPIRES_ISO}…"
CI="$(mktemp)"; trap 'rm -f "$CI"' EXIT
sed -e "s#__KV_NAME__#${KV}#" -e "s#__HOOK_URL__#${HOOK_URL}#" -e "s#__SUB_ID__#${SUB_ID}#" -e "s#__PILOT_RG__#${PILOT_RG}#" \
    -e "s#__HUB_PEERING_ID__#${HUB_PEERING_ID}#" -e "s#__EXPIRES_AT__#${EXPIRES_AT}#" cloud-init.yaml > "$CI"
az vm create -g "$PILOT_RG" -n "$VM" -l "$LOC" --size Standard_B2pts_v2 --image Canonical:ubuntu-24_04-lts:server-arm64:latest \
  --nics "$NIC" --os-disk-size-gb 32 --storage-sku StandardSSD_LRS --admin-username mova --authentication-type ssh --generate-ssh-keys \
  --assign-identity '[system]' --custom-data "$CI" --tags $TAGS -o none

echo "[7/9] Permisos mínimos de la identidad de la VM…"
PRINCIPAL="$(az vm show -g "$PILOT_RG" -n "$VM" --query identity.principalId -o tsv)"
{ echo "PILOT_RG=${PILOT_RG}"; echo "PRINCIPAL=${PRINCIPAL}"; echo "KV=${KV}"; echo "KV_ID=${KV_ID}"; echo "HUB_RG=${HUB_RG}"; echo "HUB_VNET=${HUB_VNET}"; echo "EXPIRES_AT=${EXPIRES_AT}"; } > "$STATE"
RG_ID="$(az group show -n "$PILOT_RG" --query id -o tsv)"
az role assignment create --assignee-object-id "$PRINCIPAL" --assignee-principal-type ServicePrincipal --role "Contributor" --scope "$RG_ID" -o none
az role assignment create --assignee-object-id "$PRINCIPAL" --assignee-principal-type ServicePrincipal --role "Network Contributor" --scope "$HUB_PEERING_ID" -o none
az role assignment create --assignee-object-id "$PRINCIPAL" --assignee-principal-type ServicePrincipal --role "Key Vault Secrets User" --scope "$KV_ID" -o none

echo "[8/9] Esperando el primer arranque de WAHA (la VM descarga los secretos con su identidad)…"
for i in $(seq 1 30); do
  OUT="$(az vm run-command invoke -g "$PILOT_RG" -n "$VM" --command-id RunShellScript --scripts 'test -s /etc/waha/waha.env && echo SECRETS_OK' --query 'value[0].message' -o tsv 2>/dev/null || true)"
  case "$OUT" in *SECRETS_OK*) break;; esac
  sleep 20
done
case "$OUT" in *SECRETS_OK*) ;; *) echo "AVISO: la VM aún no obtuvo los secretos; revisa con run-command y NO retires el rol de Key Vault todavía."; exit 1;; esac

echo "[9/9] Retirando el permiso de Key Vault (mínimo privilegio: ya no hace falta, los reinicios usan el archivo local)…"
az role assignment delete --assignee "$PRINCIPAL" --role "Key Vault Secrets User" --scope "$KV_ID" -o none

echo
echo "Piloto creado. API privada para MOVA: http://${PRIV_IP}:3000  (WAHA_BASE_URL)."
echo "Caduca solo (la VM borra el grupo ${PILOT_RG}) el ${EXPIRES_ISO}. Respaldo manual: ./pilot-teardown.sh --confirm ; comprobación: ./pilot-verify.sh"
