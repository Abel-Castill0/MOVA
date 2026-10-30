#!/usr/bin/env bash
# MOVA - Restaura un respaldo cifrado de la sesion de WAHA. NUNCA sobre una sesion activa.
#
# Reglas de seguridad (para no abrir dos instancias con la misma identidad de WhatsApp ni pisar la sesion buena):
#   - Exige que WAHA este DETENIDO (no hay contenedor corriendo) en la maquina destino.
#   - Verifica la huella SHA-256 y descifra/lista el tar ANTES de tocar nada.
#   - Extrae primero a un directorio temporal y solo despues mueve; el directorio previo se conserva como .previo-<fecha>.
#   - Se niega a continuar si el destino ya contiene una sesion, salvo --replace explicito.
# Uso: waha-session-restore.sh <respaldo.tar.gz.enc> --origen-detenido [--replace]
#   --origen-detenido  OBLIGATORIO: confirmas que la instancia ORIGINAL (VM del piloto u otra) esta detenida o eliminada. Dos instancias con
#                      la misma identidad de WhatsApp se desconectan entre si y pueden costar la vinculacion.
# Variables: SESSIONS_DIR (destino), KEY_FILE, NO_DOCKER=1 (pruebas locales).
set -euo pipefail

FILE="${1:?Uso: $0 <respaldo.tar.gz.enc> --origen-detenido [--replace]}"
shift
REPLACE=""; CONFIRMED=0
for a in "$@"; do case "$a" in --replace) REPLACE="--replace";; --origen-detenido) CONFIRMED=1;; esac; done
[ "$CONFIRMED" = "1" ] || { echo "Falta --origen-detenido: confirma que la instancia original de WAHA esta detenida (nunca dos instancias con la misma identidad)."; exit 1; }
SESSIONS_DIR="${SESSIONS_DIR:-/var/lib/waha/sessions}"
KEY_FILE="${KEY_FILE:-/etc/waha/backup.key}"
NO_DOCKER="${NO_DOCKER:-0}"

[ -s "$KEY_FILE" ] || { echo "falta la clave de cifrado ($KEY_FILE)"; exit 1; }
[ -f "$FILE" ] || { echo "no existe $FILE"; exit 1; }

if [ "$NO_DOCKER" != "1" ]; then
  if systemctl is-active --quiet waha 2>/dev/null || [ -n "$(docker ps -q 2>/dev/null)" ] || curl -s -m 3 -o /dev/null http://localhost:3000/; then
    echo "WAHA esta en ejecucion en esta maquina: detenlo (systemctl stop waha) antes de restaurar. No se restaura sobre una sesion activa."; exit 1
  fi
  exec 9>/var/backups/waha/.lock 2>/dev/null && flock -n 9 || { echo "hay un respaldo en curso"; exit 1; }
fi

# 1) huella
[ -f "${FILE}.sha256" ] || { echo "falta ${FILE}.sha256: sin huella no se restaura (respaldo posiblemente incompleto)"; exit 1; }
EXPECTED="$(cut -d" " -f1 "${FILE}.sha256")"
ACTUAL="$(sha256sum "$FILE" | cut -d" " -f1)"
[ "$EXPECTED" = "$ACTUAL" ] || { echo "huella SHA-256 no coincide: respaldo incompleto o alterado"; exit 1; }

# 2) descifrar y validar el tar sin extraer
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in "$FILE" -pass "file:${KEY_FILE}" | tar -tz >/dev/null \
  || { echo "no se pudo descifrar o el tar esta danado"; exit 1; }

# 3) destino
if [ -d "$SESSIONS_DIR" ] && [ -n "$(ls -A "$SESSIONS_DIR" 2>/dev/null)" ]; then
  [ "$REPLACE" = "--replace" ] || { echo "$SESSIONS_DIR ya contiene una sesion. Usa --replace si de verdad quieres reemplazarla (se conserva una copia .previo)."; exit 1; }
fi

umask 077
STAGE="$(mktemp -d "${SESSIONS_DIR%/}.restore.XXXXXX")"
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in "$FILE" -pass "file:${KEY_FILE}" | tar -xz -C "$STAGE"
[ -n "$(ls -A "$STAGE")" ] || { rm -rf "$STAGE"; echo "el respaldo estaba vacio"; exit 1; }

if [ -d "$SESSIONS_DIR" ] && [ -n "$(ls -A "$SESSIONS_DIR" 2>/dev/null)" ]; then
  mv "$SESSIONS_DIR" "${SESSIONS_DIR%/}.previo-$(date -u +%Y%m%dT%H%M%SZ)"
elif [ -d "$SESSIONS_DIR" ]; then
  rmdir "$SESSIONS_DIR"
fi
mv "$STAGE" "$SESSIONS_DIR"
chmod 700 "$SESSIONS_DIR"
echo "restaurado en $SESSIONS_DIR ($(find "$SESSIONS_DIR" -type f | wc -l) archivos). Arranca WAHA UNA sola vez: systemctl start waha"
