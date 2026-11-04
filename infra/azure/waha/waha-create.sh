#!/usr/bin/env bash
# MOVA — WAHA PERMANENTE (opción D de docs/azure-arquitectura-economica-2026-11.md, aprobada por el titular el 2026-11-03).
#
# Diferencias con pilot-create.sh: grupo propio SIN caducidad ni janitor (mova-waha-rg), la VM NO puede borrar nada (sin rol Contributor
# y sin el temporizador de autodestrucción), disco del SO Premium SSD 64 GiB (P6, cubierto por los beneficios gratuitos de la suscripción;
# comprobar en la factura) y una cuenta de almacenamiento para los respaldos cifrados de la sesión fuera de la VM.
#   VNet 10.30.0.0/24 · NSG (nada entra desde Internet; 3000/TCP solo desde mova-vnet) · IP pública Standard (solo salida) · NIC 10.30.0.4
#   · VM Standard_B2pts_v2 (ARM64, 750 h gratuitas) · peering to-mova / to-waha · cuenta de almacenamiento con contenedor de respaldos.
# No toca Culqi, el ledger, movap-web/scheduler, la base de datos ni secretos (los de WAHA ya existen en Key Vault).
# Retirada: borrar el grupo mova-waha-rg y el peering to-waha de mova-vnet (no afecta a MOVA; WhatsApp queda sin servidor).
set -euo pipefail
cd "$(dirname "$0")"
export PYTHONUTF8=1 LC_ALL=C.UTF-8

[ "${1:-}" = "--confirm" ] || { echo "Uso: $0 --confirm"; exit 2; }

RG="${RG:-mova-waha-rg}"; LOC="${LOC:-northcentralus}"
HUB_RG="${HUB_RG:-mova-prod-rg}"; HUB_VNET="${HUB_VNET:-mova-vnet}"; HUB_CIDR="${HUB_CIDR:-10.20.0.0/16}"
KV="${KV:-kv-movap-192f01}"
VM="mova-waha"; VNET="mova-waha-vnet"; SUBNET="default"; NSG="mova-waha-nsg"; PIP="mova-waha-pip"; NIC="mova-waha-nic"
PRIV_IP="10.30.0.4"
HOOK_URL="${HOOK_URL:-https://movaeduca.me/api/webhooks/waha}"
STORAGE="${STORAGE:-movawahabak$(az account show --query id -o tsv | tr -d '-' | cut -c1-8)}"
CONTAINER="waha-session-backups"
TAGS="purpose=waha owner=mova"

SUB_ID="$(az account show --query id -o tsv)"
HUB_ID="$(az network vnet show -g "$HUB_RG" -n "$HUB_VNET" --query id -o tsv)"
KV_ID="$(az keyvault show -n "$KV" --query id -o tsv)"

if [ -n "$(LC_ALL=C tr -d '[:print:][:space:]' < cloud-init.yaml)" ]; then echo "ABORTA: cloud-init.yaml contiene caracteres no ASCII."; exit 1; fi
if az group exists -n "$RG" | grep -q true; then echo "ABORTA: el grupo ${RG} ya existe (no se pisa una instalación existente: nunca dos WAHA con el mismo número)."; exit 1; fi
for s in waha-api-key waha-hook-hmac-key; do az keyvault secret show --vault-name "$KV" -n "$s" --query name -o tsv >/dev/null; done

echo "[1/8] Grupo, NSG, VNet, IP y NIC…"
az group create -n "$RG" -l "$LOC" --tags $TAGS -o none
az network nsg create -g "$RG" -n "$NSG" -l "$LOC" --tags $TAGS -o none
az network nsg rule create -g "$RG" --nsg-name "$NSG" -n AllowMovaVnet3000 --priority 100 --direction Inbound --access Allow \
  --protocol Tcp --source-address-prefixes "$HUB_CIDR" --destination-port-ranges 3000 -o none
az network nsg rule create -g "$RG" --nsg-name "$NSG" -n DenyInternetInbound --priority 4000 --direction Inbound --access Deny \
  --protocol '*' --source-address-prefixes Internet --destination-port-ranges '*' -o none
az network vnet create -g "$RG" -n "$VNET" -l "$LOC" --address-prefixes 10.30.0.0/24 --subnet-name "$SUBNET" \
  --subnet-prefixes 10.30.0.0/24 --nsg "$NSG" --tags $TAGS -o none
az network public-ip create -g "$RG" -n "$PIP" -l "$LOC" --sku Standard --allocation-method Static --version IPv4 --tags $TAGS -o none
az network nic create -g "$RG" -n "$NIC" -l "$LOC" --vnet-name "$VNET" --subnet "$SUBNET" --private-ip-address "$PRIV_IP" \
  --public-ip-address "$PIP" --tags $TAGS -o none

echo "[2/8] Peering privado MOVA ↔ WAHA…"
WAHA_VNET_ID="$(az network vnet show -g "$RG" -n "$VNET" --query id -o tsv)"
az network vnet peering create -g "$RG" --vnet-name "$VNET" -n to-mova --remote-vnet "$HUB_ID" --allow-vnet-access -o none
az network vnet peering create -g "$HUB_RG" --vnet-name "$HUB_VNET" -n to-waha --remote-vnet "$WAHA_VNET_ID" --allow-vnet-access -o none

echo "[3/8] Cuenta de almacenamiento para respaldos cifrados (sin acceso anónimo, TLS 1.2)…"
az storage account create -g "$RG" -n "$STORAGE" -l "$LOC" --sku Standard_LRS --kind StorageV2 --min-tls-version TLS1_2 \
  --allow-blob-public-access false --https-only true --tags $TAGS -o none
az storage container create --account-name "$STORAGE" -n "$CONTAINER" --auth-mode login -o none 2>/dev/null || \
  az storage container create --account-name "$STORAGE" -n "$CONTAINER" --account-key "$(az storage account keys list -g "$RG" -n "$STORAGE" --query '[0].value' -o tsv)" -o none

echo "[4/8] VM Standard_B2pts_v2 (ARM64), disco P6 64 GiB, SIN autodestrucción…"
CI="$(mktemp)"; trap 'rm -f "$CI"' EXIT
sed -e "s#__KV_NAME__#${KV}#" -e "s#__HOOK_URL__#${HOOK_URL}#" -e "s#__SUB_ID__#${SUB_ID}#" -e "s#__PILOT_RG__#${RG}#" \
    -e "s#__EXPIRES_AT__#4102444800#" -e '/systemctl enable --now waha-expire.timer/d' cloud-init.yaml > "$CI"
grep -q 'waha-expire.timer' "$CI" && grep -q 'enable --now waha-expire' "$CI" && { echo "ABORTA: el temporizador de autodestrucción sigue habilitado"; exit 1; }
az vm create -g "$RG" -n "$VM" -l "$LOC" --size Standard_B2pts_v2 --image Canonical:ubuntu-24_04-lts:server-arm64:latest \
  --nics "$NIC" --os-disk-size-gb 64 --storage-sku Premium_LRS --admin-username mova --authentication-type ssh --generate-ssh-keys \
  --assign-identity '[system]' --custom-data "$CI" --tags $TAGS -o none

echo "[5/8] Permisos mínimos: lectura temporal de los 2 secretos y escritura solo en el contenedor de respaldos…"
PRINCIPAL="$(az vm show -g "$RG" -n "$VM" --query identity.principalId -o tsv)"
ST_ID="$(az storage account show -g "$RG" -n "$STORAGE" --query id -o tsv)"
az role assignment create --assignee-object-id "$PRINCIPAL" --assignee-principal-type ServicePrincipal --role "Storage Blob Data Contributor" \
  --scope "${ST_ID}/blobServices/default/containers/${CONTAINER}" -o none
KV_ROLE="$(az role assignment create --assignee-object-id "$PRINCIPAL" --assignee-principal-type ServicePrincipal --role "Key Vault Secrets User" --scope "$KV_ID" --query id -o tsv)"

echo "[6/8] Esperando el primer arranque (descarga de secretos con la identidad de la VM)…"
OUT=""
for i in $(seq 1 30); do
  OUT="$(az vm run-command invoke -g "$RG" -n "$VM" --command-id RunShellScript --scripts 'test -s /etc/waha/waha.env && echo SECRETS_OK' --query 'value[0].message' -o tsv 2>/dev/null || true)"
  case "$OUT" in *SECRETS_OK*) break;; esac
  sleep 20
done
case "$OUT" in *SECRETS_OK*) ;; *) echo "AVISO: la VM aún no obtuvo los secretos; NO se retira el rol de Key Vault (${KV_ROLE})."; exit 1;; esac

echo "[7/8] Retirando el permiso de Key Vault (mínimo privilegio)…"
az role assignment delete --ids "$KV_ROLE" -o none

echo "[8/8] Estado de WAHA en la VM…"
az vm run-command invoke -g "$RG" -n "$VM" --command-id RunShellScript --scripts \
  'systemctl is-active docker waha 2>/dev/null | tr "\n" " "; echo; . /etc/waha/waha.env; curl -s -m 8 -H "X-Api-Key: ${WAHA_API_KEY}" -o /dev/null -w "api %{http_code}\n" http://localhost:3000/api/sessions/default' \
  --query 'value[0].message' -o tsv

echo
echo "WAHA permanente creado. WAHA_BASE_URL=http://${PRIV_IP}:3000 · respaldos: ${STORAGE}/${CONTAINER} (instalar con backup/install-backup.sh --confirm, RG=${RG} VM=${VM})."
