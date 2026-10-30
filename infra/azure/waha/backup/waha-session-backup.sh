#!/usr/bin/env bash
# MOVA - Respaldo CIFRADO y CONSISTENTE de la sesion vinculada de WAHA (/var/lib/waha/sessions).
#
# Por que se detiene WAHA un instante: NOWEB guarda la autenticacion en archivos y en SQLite (waha.sqlite3 + WAL). Copiarlos "en
# caliente" puede producir un respaldo incompleto o corrupto. Se hace: parar -> tar -> arrancar (unos 5-10 s; la sesion NO se
# cierra, solo se reconecta). Siempre se vuelve a arrancar, aunque algo falle (trap).
#
# Garantias: archivo cifrado (AES-256-CBC, PBKDF2), huella SHA-256 verificada, nunca imprime contenido ni claves, un solo respaldo
# a la vez (flock), solo respalda si la sesion esta en WORKING (no pisa un respaldo bueno con una sesion perdida), retencion limitada.
# La clave vive en /etc/waha/backup.key (0600, root); copia de seguridad de esa clave: Key Vault (secreto waha-session-backup-key).
#
# Variables (con valores por defecto de la VM): SESSIONS_DIR, OUT_DIR, KEY_FILE, KEEP, NO_DOCKER=1 (pruebas locales), UPLOAD=0 (solo local),
# STORAGE_ACCOUNT, BLOB_CONTAINER (destino fuera de la VM, con la identidad administrada), NOTIFY=0 (no avisar a MOVA).
set -euo pipefail

SESSIONS_DIR="${SESSIONS_DIR:-/var/lib/waha/sessions}"
OUT_DIR="${OUT_DIR:-/var/backups/waha}"
KEY_FILE="${KEY_FILE:-/etc/waha/backup.key}"
KEEP="${KEEP:-7}"
[ "$KEEP" -ge 1 ] 2>/dev/null || KEEP=1   # nunca borrar todos los respaldos
NO_DOCKER="${NO_DOCKER:-0}"
UPLOAD="${UPLOAD:-1}"
NOTIFY="${NOTIFY:-1}"
STORAGE_ACCOUNT="${STORAGE_ACCOUNT:-}"
BLOB_CONTAINER="${BLOB_CONTAINER:-waha-session-backups}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
NAME="session-${STAMP}.tar.gz.enc"

umask 077
mkdir -p "$OUT_DIR"
exec 9>"${OUT_DIR}/.lock"
flock -n 9 || { echo "otro respaldo en curso"; exit 1; }

[ -s "$KEY_FILE" ] || { echo "falta la clave de cifrado ($KEY_FILE)"; exit 1; }
[ -d "$SESSIONS_DIR" ] || { echo "no existe $SESSIONS_DIR"; exit 1; }

if [ "$NO_DOCKER" != "1" ]; then
  KEYAPI=$(grep ^WHATSAPP_API_KEY= /etc/waha/waha.env | cut -d= -f2-)
  STATUS=$(curl -s -m 8 -H "X-Api-Key: ${KEYAPI}" http://localhost:3000/api/sessions/default | sed -n 's/.*"status":"\([A-Z_]*\)".*/\1/p')
  [ "$STATUS" = "WORKING" ] || { echo "la sesion no esta en WORKING ($STATUS): no se respalda"; exit 2; }
  restart_waha() { systemctl start waha || true; rm -f "${TMP:-}"; }
  trap restart_waha EXIT
  systemctl stop waha
  sleep 3
fi

TMP="$(mktemp -p "$OUT_DIR" .tmp.XXXXXX)"
trap 'rm -f "$TMP"' EXIT   # el tar SIN cifrar nunca queda en disco si algo falla
tar -C "$SESSIONS_DIR" -czf "$TMP" .
[ "$NO_DOCKER" = "1" ] || systemctl start waha   # vuelve a conectar ya; el cifrado no necesita a WAHA detenido

openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -in "$TMP" -out "${OUT_DIR}/${NAME}" -pass "file:${KEY_FILE}"
rm -f "$TMP"
SHA="$(sha256sum "${OUT_DIR}/${NAME}" | cut -d' ' -f1)"
BYTES="$(stat -c %s "${OUT_DIR}/${NAME}")"
printf '%s  %s\n' "$SHA" "$NAME" > "${OUT_DIR}/${NAME}.sha256"

# Integridad: descifrar a /dev/null y listar el tar sin extraer.
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in "${OUT_DIR}/${NAME}" -pass "file:${KEY_FILE}" | tar -tz >/dev/null
echo "respaldo verificado: ${NAME} ${BYTES} bytes sha256=${SHA:0:16}..."

# Retencion local (los ultimos $KEEP).
ls -1t "${OUT_DIR}"/session-*.tar.gz.enc 2>/dev/null | tail -n +$((KEEP + 1)) | while read -r old; do rm -f "$old" "${old}.sha256"; done

if [ "$UPLOAD" = "1" ] && [ -n "$STORAGE_ACCOUNT" ]; then
  TOKEN=$(curl -sf -H 'Metadata:true' 'http://169.254.169.254/metadata/identity/oauth2/token?api-version=2018-02-01&resource=https%3A%2F%2Fstorage.azure.com%2F' | jq -r .access_token)
  BASE="https://${STORAGE_ACCOUNT}.blob.core.windows.net/${BLOB_CONTAINER}"
  curl -sf -X PUT -H "Authorization: Bearer ${TOKEN}" -H 'x-ms-version: 2022-11-02' -H 'x-ms-blob-type: BlockBlob' \
    -H "x-ms-meta-sha256: ${SHA}" --data-binary "@${OUT_DIR}/${NAME}" "${BASE}/${NAME}" >/dev/null
  echo "subido a ${STORAGE_ACCOUNT}/${BLOB_CONTAINER}"
  # Retencion remota: conserva los ultimos $KEEP.
  curl -sf -H "Authorization: Bearer ${TOKEN}" -H 'x-ms-version: 2022-11-02' "${BASE}?restype=container&comp=list&prefix=session-" \
    | grep -o 'session-[0-9TZ]*\.tar\.gz\.enc' | sort -r | tail -n +$((KEEP + 1)) | while read -r b; do
        curl -sf -X DELETE -H "Authorization: Bearer ${TOKEN}" -H 'x-ms-version: 2022-11-02' "${BASE}/${b}" >/dev/null || true
      done
fi

# Aviso firmado a MOVA (solo huella y tamano) para que el panel muestre el ultimo respaldo valido.
if [ "$NOTIFY" = "1" ] && [ -r /etc/waha/waha.env ]; then
  HOOK=$(grep ^WHATSAPP_HOOK_URL= /etc/waha/waha.env | cut -d= -f2-)
  HMAC=$(grep ^WHATSAPP_HOOK_HMAC_KEY= /etc/waha/waha.env | cut -d= -f2-)
  BODY="{\"event\":\"mova.backup\",\"session\":\"default\",\"payload\":{\"sha256\":\"${SHA}\",\"bytes\":${BYTES},\"ts\":$(date +%s)}}"
  SIG=$(printf '%s' "$BODY" | openssl dgst -sha512 -hmac "$HMAC" -hex | sed 's/^.*= *//')
  curl -s -m 20 -o /dev/null -X POST -H 'Content-Type: application/json' -H "X-Webhook-Hmac: ${SIG}" -H 'X-Webhook-Hmac-Algorithm: sha512' -H "X-Webhook-Timestamp: $(date +%s)000" -d "$BODY" "$HOOK" || true
fi
