#!/usr/bin/env bash
# MOVA — Limpieza EXTERNA del piloto de WAHA. Se ejecuta fuera de la VM (workflow waha-pilot-janitor de GitHub Actions con OIDC,
# o a mano) y no depende de que la VM esté encendida, tenga red o funcione su temporizador.
#
# Qué hace (y nada más):
#   1. Lee la etiqueta `expires-at` de mova-waha-pilot-rg. Si el grupo no existe o aún no caducó, no borra nada (salvo --force).
#   2. Si caducó: elimina el peering `to-waha` de mova-vnet, retira los roles temporales de la identidad de la VM y borra el grupo.
#   3. SIEMPRE verifica el resultado: ni grupo, ni recursos etiquetados purpose=waha-pilot, ni recursos `movap-waha*` en la suscripción,
#      ni peering, ni roles de la VM. Sale con código 1 si queda algo facturable o un resto.
# No toca mova-prod-rg salvo el peering `to-waha` de mova-vnet. Nunca borra un grupo que no lleve purpose=waha-pilot.
set -uo pipefail

FORCE=0; [ "${1:-}" = "--force" ] && FORCE=1
PILOT_RG="${PILOT_RG:-mova-waha-pilot-rg}"; HUB_RG="${HUB_RG:-mova-prod-rg}"; HUB_VNET="${HUB_VNET:-mova-vnet}"; VM="${VM:-movap-waha}"
FAIL=0

say() { echo "[$(date -u +%FT%TZ)] $*"; [ -n "${GITHUB_STEP_SUMMARY:-}" ] && echo "- $*" >> "$GITHUB_STEP_SUMMARY"; return 0; }
check() { if [ "$2" = "ok" ]; then say "PASS  $1"; else say "FAIL  $1 -> $3"; FAIL=1; fi; }

[ -n "${GITHUB_STEP_SUMMARY:-}" ] && echo "### Limpieza del piloto WAHA" >> "$GITHUB_STEP_SUMMARY"

EXISTS="$(az group exists -n "$PILOT_RG" 2>/dev/null || echo error)"
[ "$EXISTS" = "error" ] && { say "FAIL  no se pudo consultar Azure (permisos/sesión)"; exit 1; }
PRINCIPAL=""

if [ "$EXISTS" = "true" ]; then
  PURPOSE="$(az group show -n "$PILOT_RG" --query 'tags.purpose' -o tsv)"
  [ "$PURPOSE" = "waha-pilot" ] && [ "$PILOT_RG" != "$HUB_RG" ] || { say "ABORTA  $PILOT_RG no está etiquetado purpose=waha-pilot: no se borra"; exit 1; }
  EXPIRES="$(az group show -n "$PILOT_RG" --query 'tags."expires-at"' -o tsv)"
  EXP_EPOCH="$(date -u -d "$EXPIRES" +%s 2>/dev/null || echo 0)"
  NOW="$(date -u +%s)"
  say "Grupo $PILOT_RG existe; caduca $EXPIRES (ahora $(date -u +%FT%TZ))"

  if [ "$FORCE" -eq 1 ] || [ "$EXP_EPOCH" -le "$NOW" ]; then
    [ "$EXP_EPOCH" -eq 0 ] && say "AVISO  etiqueta expires-at ausente o ilegible: se trata como caducado (fail-safe)"
    PRINCIPAL="$(az vm show -g "$PILOT_RG" -n "$VM" --query identity.principalId -o tsv 2>/dev/null || true)"
    [ "$FORCE" -eq 1 ] && say "--force: limpiando antes de la caducidad" || say "Caducado: limpiando"
    if az network vnet peering show -g "$HUB_RG" --vnet-name "$HUB_VNET" -n to-waha >/dev/null 2>&1; then
      az network vnet peering delete -g "$HUB_RG" --vnet-name "$HUB_VNET" -n to-waha -o none 2>/dev/null && say "peering to-waha eliminado" || say "no pude eliminar el peering to-waha (se verifica abajo)"
    else
      say "peering to-waha: no existía"
    fi
    if [ -n "$PRINCIPAL" ]; then
      for id in $(az role assignment list --all --query "[?principalId=='$PRINCIPAL'].id" -o tsv 2>/dev/null); do
        az role assignment delete --ids "$id" -o none 2>/dev/null && say "rol temporal retirado" || say "no pude retirar un rol temporal (se verifica abajo)"
      done
    fi
    az group delete -n "$PILOT_RG" --yes -o none && say "grupo $PILOT_RG eliminado" || say "FAIL al eliminar el grupo"
  else
    say "Aún no caduca: no se borra nada."
    exit 0
  fi
else
  say "El grupo $PILOT_RG no existe (nada que limpiar); se comprueban restos."
  if az network vnet peering show -g "$HUB_RG" --vnet-name "$HUB_VNET" -n to-waha >/dev/null 2>&1; then
    az network vnet peering delete -g "$HUB_RG" --vnet-name "$HUB_VNET" -n to-waha -o none 2>/dev/null && say "peering huérfano to-waha eliminado"
  fi
fi

# ── Verificación: nada facturable puede quedar ──────────────────────────────────────────────────────────────
[ "$(az group exists -n "$PILOT_RG")" = "false" ] && check "grupo $PILOT_RG ausente" ok || check "grupo $PILOT_RG ausente" bad "sigue existiendo (¿borrado en curso?)"

TAGGED="$(az resource list --tag purpose=waha-pilot --query "[].name" -o tsv 2>/dev/null)"
[ -z "$TAGGED" ] && check "0 recursos con etiqueta purpose=waha-pilot en la suscripción" ok || check "recursos etiquetados restantes" bad "$(echo "$TAGGED" | tr '\n' ' ')"

NAMED="$(az resource list --query "[?starts_with(name,'movap-waha')].{n:name,t:type}" -o tsv 2>/dev/null)"
[ -z "$NAMED" ] && check "0 recursos movap-waha* (VM, disco, IP, NIC, VNet, NSG) en la suscripción" ok || check "recursos movap-waha* restantes" bad "$(echo "$NAMED" | tr '\t\n' '/ ')"

az network vnet peering show -g "$HUB_RG" --vnet-name "$HUB_VNET" -n to-waha >/dev/null 2>&1 \
  && check "peering to-waha ausente en $HUB_VNET" bad "sigue existiendo" || check "peering to-waha ausente en $HUB_VNET" ok

if [ -n "$PRINCIPAL" ]; then
  LEFT="$(az role assignment list --all --query "[?principalId=='$PRINCIPAL'].id" -o tsv 2>/dev/null)"
  [ -z "$LEFT" ] && check "sin roles de la identidad de la VM" ok || check "roles de la VM restantes" bad "$(echo "$LEFT" | wc -l) asignación(es)"
fi

[ "$FAIL" -eq 0 ] && say "RESULTADO: LIMPIO — no quedan recursos facturables del piloto." || say "RESULTADO: HAY RESTOS — ejecutar infra/azure/waha/pilot-teardown.sh --confirm"
exit "$FAIL"
