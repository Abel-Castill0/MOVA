#!/usr/bin/env bash
# MOVA - Instala en la VM de WAHA el respaldo cifrado semanal de la sesion (scripts + clave + temporizador). Requiere --confirm.
#
# - Genera la clave de cifrado AQUI, la guarda en Key Vault (secreto waha-session-backup-key) y la entrega a la VM con un rol
#   TEMPORAL de solo lectura sobre ESE secreto (se retira al terminar). Nunca se imprime.
# - Por defecto los respaldos quedan en la VM (/var/backups/waha) y NO se suben a ninguna parte: subirlos fuera de la VM exige un
#   destino durable aprobado (STORAGE_ACCOUNT / BLOB_CONTAINER en /etc/waha/backup.env). Ver docs/whatsapp-waha.md.
# - NO ejecuta ningun respaldo: el script se niega a respaldar una sesion que no este en WORKING.
set -euo pipefail
cd "$(dirname "$0")"
export PYTHONUTF8=1 LC_ALL=C.UTF-8

[ "${1:-}" = "--confirm" ] || { echo "Uso: $0 --confirm"; exit 2; }
RG="${PILOT_RG:-mova-waha-pilot-rg}"; VM="${VM:-movap-waha}"; KV="${KV:-kv-movap-192f01}"; SECRET="waha-session-backup-key"

if ! az keyvault secret show --vault-name "$KV" -n "$SECRET" --query name -o tsv >/dev/null 2>&1; then
  K="$(mktemp)"; trap 'rm -f "$K"' EXIT
  head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' > "$K"
  az keyvault secret set --vault-name "$KV" -n "$SECRET" --file "$K" --encoding utf-8 --query name -o tsv >/dev/null
  rm -f "$K"; trap - EXIT   # la clave no se queda en el disco local
  echo "clave de respaldo creada en Key Vault (valor no mostrado)"
fi

PRINCIPAL="$(az vm show -g "$RG" -n "$VM" --query identity.principalId -o tsv)"
SECRET_ID="$(az keyvault secret show --vault-name "$KV" -n "$SECRET" --query id -o tsv | sed 's#/[0-9a-f]\{32\}$##;s#https://\([^.]*\)\.vault\.azure\.net/secrets/\(.*\)#\1 \2#')"
KV_ID="$(az keyvault show -n "$KV" --query id -o tsv)"
SCOPE="${KV_ID}/secrets/${SECRET}"
ROLE_ID="$(az role assignment create --assignee-object-id "$PRINCIPAL" --assignee-principal-type ServicePrincipal --role "Key Vault Secrets User" --scope "$SCOPE" --query id -o tsv)"
revoke() { az role assignment delete --ids "$ROLE_ID" -o none; [ -z "$(az role assignment list --scope "$SCOPE" --query "[?principalId=='${PRINCIPAL}'].id" -o tsv)" ] || { echo "ERROR: el rol temporal sobre la clave NO se retiro; revocalo a mano"; exit 1; }; }
trap revoke EXIT
sleep 45   # propagacion del rol

PAY="$(mktemp)"
{
  echo 'set -e'
  echo 'mkdir -p /var/backups/waha /etc/waha && chmod 700 /var/backups/waha'
  for f in waha-session-backup.sh waha-session-restore.sh; do
    echo "base64 -d > /usr/local/bin/$f <<'B64EOF'"; base64 -w0 "$f"; echo; echo 'B64EOF'; echo "chmod 750 /usr/local/bin/$f"
  done
  cat <<EOF
TOKEN=\$(curl -sf -H 'Metadata:true' 'http://169.254.169.254/metadata/identity/oauth2/token?api-version=2018-02-01&resource=https%3A%2F%2Fvault.azure.net' | jq -r .access_token)
umask 077
curl -sf -H "Authorization: Bearer \${TOKEN}" "https://${KV}.vault.azure.net/secrets/${SECRET}?api-version=7.4" | jq -er .value > /etc/waha/backup.key
chmod 600 /etc/waha/backup.key
cat > /etc/systemd/system/waha-backup.service <<'UNIT'
[Unit]
Description=Respaldo cifrado de la sesion de WAHA
[Service]
Type=oneshot
EnvironmentFile=-/etc/waha/backup.env
ExecStart=/usr/local/bin/waha-session-backup.sh
UNIT
cat > /etc/systemd/system/waha-backup.timer <<'UNIT'
[Unit]
Description=Respaldo semanal de la sesion de WAHA
[Timer]
OnCalendar=Sun *-*-* 03:30:00
Persistent=true
RandomizedDelaySec=300
[Install]
WantedBy=timers.target
UNIT
[ -f /etc/waha/backup.env ] || printf 'UPLOAD=0\n' > /etc/waha/backup.env
systemctl daemon-reload
systemctl enable --now waha-backup.timer
echo "instalado: \$(systemctl is-enabled waha-backup.timer) key=\$(stat -c %a /etc/waha/backup.key) scripts=\$(ls /usr/local/bin/waha-session-*.sh | wc -l)"
EOF
} > "$PAY"
az vm run-command invoke -g "$RG" -n "$VM" --command-id RunShellScript --scripts "@${PAY}" --query "value[0].message" -o tsv | tail -4
rm -f "$PAY"
