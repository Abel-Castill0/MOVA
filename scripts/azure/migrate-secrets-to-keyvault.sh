#!/usr/bin/env bash
# Migra los secretos de las Container Apps de PRODUCCIÓN a Azure Key Vault (referencias keyvaultref).
#
# Lo ejecuta el TITULAR (o quien él autorice) desde una sesión `az login` con rol Owner/User Access Admin.
# Los VALORES nunca se imprimen ni se pasan como argumentos: pasan por un archivo temporal 0600 que se borra.
# AGENTS.md prohíbe a los agentes consultar valores de secretos de Azure, por eso este script NO lo ejecuta Claude.
#
# Uso:
#   scripts/azure/migrate-secrets-to-keyvault.sh copy      # 1) copia valores de movap-web al vault (no cambia las apps)
#   scripts/azure/migrate-secrets-to-keyvault.sh verify    # 1b) compara SHA-256 origen/vault (solo imprime OK/MISMATCH)
#   scripts/azure/migrate-secrets-to-keyvault.sh switch    # 2) cambia scheduler -> worker -> web a referencias al vault
#   scripts/azure/migrate-secrets-to-keyvault.sh revert    # 3) (emergencia) vuelve a valores en línea desde el vault
#
# ISO/IEC 27001:2022 A.8.24 · A.5.17 · ISO/IEC 27017 CLD.12.1.5. Requisitos previos (ya hechos 2026-10-19):
# vault `kv-movap-192f01` (RBAC), identidad `mova-apps-identity` con "Key Vault Secrets User", rol "Secrets Officer" para quien copia.
set -euo pipefail
export MSYS_NO_PATHCONV=1

RG="${MOVA_RG:-mova-prod-rg}"
KV="${MOVA_KV:-kv-movap-192f01}"
IDENTITY="${MOVA_IDENTITY:-mova-apps-identity}"
SOURCE_APP="${MOVA_SOURCE_APP:-movap-web}"
APPS=(movap-scheduler movap-worker movap-web)   # orden: del menor al mayor impacto
SECRETS=(app-key db-password mercadopago-access-token mercadopago-webhook-secret mail-username mail-password
         cloudinary-url jaas-private-key jaas-webhook-signing-secret sentry-laravel-dsn)

IDENTITY_ID="$(az identity show -g "$RG" -n "$IDENTITY" --query id -o tsv)"
VAULT_URI="https://${KV}.vault.azure.net/secrets"
TMP="$(mktemp)"; chmod 600 "$TMP"
trap 'rm -f "$TMP"' EXIT

# Decodifica un valor entregado como cadena JSON a bytes EXACTOS (conserva saltos de línea: la clave privada de JaaS es PEM multilínea).
decode() { python -c 'import sys,json; sys.stdout.buffer.write(json.load(sys.stdin).encode("utf-8"))'; }
aca_val() { az containerapp secret show -g "$RG" -n "$SOURCE_APP" --secret-name "$1" --query value -o json | decode; }
kv_val()  { az keyvault secret show --vault-name "$KV" -n "$1" --query value -o json | decode; }

ready() { curl -fsS -o /dev/null --max-time 40 "https://movaeduca.me/readyz" && echo "  /readyz OK"; }

case "${1:-}" in
  copy)
    for s in "${SECRETS[@]}"; do
      aca_val "$s" > "$TMP"
      [ -s "$TMP" ] || { echo "ERROR: $s vacío en $SOURCE_APP" >&2; exit 1; }
      az keyvault secret set --vault-name "$KV" -n "$s" --file "$(cygpath -w "$TMP" 2>/dev/null || echo "$TMP")" --encoding utf-8 --query name -o tsv
    done
    echo "Copiados ${#SECRETS[@]} secretos a $KV. Siguiente: $0 verify"
    ;;
  verify)
    bad=0
    for s in "${SECRETS[@]}"; do
      a="$(aca_val "$s" | sha256sum | cut -c1-64)"; k="$(kv_val "$s" | sha256sum | cut -c1-64)"
      if [ "$a" = "$k" ]; then echo "OK        $s"; else echo "MISMATCH  $s"; bad=1; fi
    done
    exit $bad
    ;;
  switch)
    args=()
    for s in "${SECRETS[@]}"; do args+=("${s}=keyvaultref:${VAULT_URI}/${s},identityref:${IDENTITY_ID}"); done
    for a in "${APPS[@]}"; do
      echo "== $a"
      az containerapp secret set -g "$RG" -n "$a" --secrets "${args[@]}" -o none
      # El secreto solo se carga al crear una revisión nueva (mismos nombres de variable: no cambia el entorno).
      az containerapp update -g "$RG" -n "$a" --revision-suffix "kv$(date +%m%d%H%M)" -o none
      sleep 30
      az containerapp revision list -g "$RG" -n "$a" --query "[?properties.active].{rev:name,health:properties.healthState,prov:properties.provisioningState}" -o tsv
      ready
    done
    echo "Migración completada. Mantén los valores antiguos recuperables en el vault hasta cerrar la prueba."
    ;;
  revert)
    for a in "${APPS[@]}"; do
      for s in "${SECRETS[@]}"; do
        kv_val "$s" > "$TMP"
        # AVISO: en emergencia el valor viaja como argumento (visible en la lista de procesos local); usar solo en una máquina de confianza.
        az containerapp secret set -g "$RG" -n "$a" --secrets "${s}=$(cat "$TMP")" -o none
      done
      az containerapp update -g "$RG" -n "$a" --revision-suffix "rv$(date +%m%d%H%M)" -o none
      ready
    done
    ;;
  *) echo "Uso: $0 {copy|verify|switch|revert}" >&2; exit 2 ;;
esac
