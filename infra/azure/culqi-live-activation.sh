#!/usr/bin/env bash
# MOVA — activar / detener Culqi live desde Azure Cloud Shell (Bash), sin tocar código, imágenes ni migraciones.
#
#   bash infra/azure/culqi-live-activation.sh check                    # solo lectura: ¿está listo?
#   bash infra/azure/culqi-live-activation.sh enable-test <correo>     # abre compras SOLO para esa cuenta de profesor
#   bash infra/azure/culqi-live-activation.sh enable-all               # abre compras a todos los profesores (exige compra live verificada)
#   bash infra/azure/culqi-live-activation.sh disable                  # cierra compras nuevas (webhooks, conciliación y reembolsos siguen)
#   bash infra/azure/culqi-live-activation.sh rotate-webhook-secret    # nuevo secreto del webhook (luego actualizar las 5 URL en CulqiPanel)
#   bash infra/azure/culqi-live-activation.sh update-keys              # cargar llaves nuevas pk_live/sk_live (las pide sin mostrarlas)
#
# Control operativo único: CULQI_CHECKOUT_ALLOWLIST con PAYMENT_PROVIDER=culqi.
#   vacía = todos los profesores · un correo = solo esa cuenta · *.invalid = compras cerradas (Culqi sigue conciliando).
# Siempre se aplica a movap-scheduler y movap-web juntos y se confirma el estado final desde la aplicación. Nunca imprime llaves
# ni secretos. Opciones: --dry-run (no cambia nada), --yes (sin pregunta; solo para disable).
set -uo pipefail

RG="${MOVA_RG:-mova-prod-rg}"
WEB="${MOVA_WEB_APP:-movap-web}"
SCHED="${MOVA_SCHEDULER_APP:-movap-scheduler}"
KV="${MOVA_KEYVAULT:-kv-movap-192f01}"
DOMAIN="${MOVA_DOMAIN:-movaeduca.me}"
URL_FILE="${MOVA_WEBHOOK_URL_FILE:-$HOME/culqi-webhook-url.txt}"
APPS=("$SCHED" "$WEB")
VARS=(PAYMENT_PROVIDER CULQI_CHECKOUT_ALLOWLIST MERCADOPAGO_NEW_PAYMENTS_ENABLED)
DRY=0; YES=0; ARGS=()
for a in "$@"; do case "$a" in --dry-run) DRY=1 ;; --yes) YES=1 ;; *) ARGS+=("$a") ;; esac; done
CMD="${ARGS[0]:-}"
FAILS=0; REPORT=""
TMPFILES=()
trap 'rm -f "${TMPFILES[@]}" 2>/dev/null' EXIT

say()  { printf '%s\n' "$*"; }
ok()   { printf 'OK  %s\n' "$*"; }
bad()  { printf 'FALLA  %s\n' "$*"; FAILS=$((FAILS + 1)); }
die()  { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
tmpf() { local f; f=$(mktemp); chmod 600 "$f"; TMPFILES+=("$f"); printf '%s' "$f"; }

az account show >/dev/null 2>&1 || die "No hay sesión de Azure. En Cloud Shell ya la hay; en otra terminal ejecuta: az login"

# Lee TODAS las variables de una app en una sola llamada y falla si Azure no responde (nunca «vacío por error»).
env_json() { az containerapp show -g "$RG" -n "$1" --query "properties.template.containers[0].env" -o json 2>/dev/null; }
env_get() { # json nombre campo(value|secretRef) -> valor; "__AUSENTE__" si la variable no existe
  printf '%s' "$1" | python3 -c 'import json,sys; e={x["name"]:x for x in json.load(sys.stdin)}; v=e.get(sys.argv[1]); print("__AUSENTE__" if v is None else (v.get(sys.argv[2]) or ""))' "$2" "$3"
}
fingerprint() { printf '%s' "$1" | sha256sum | cut -c1-12; }

wait_healthy() {
  local app=$1 rev state i=0
  rev=$(az containerapp show -g "$RG" -n "$app" --query properties.latestRevisionName -o tsv)
  while [ $i -lt 40 ]; do
    state=$(az containerapp revision show -g "$RG" -n "$app" --revision "$rev" --query properties.healthState -o tsv 2>/dev/null)
    [ "$state" = "Healthy" ] && { ok "$app: revisión $rev Healthy"; return 0; }
    [ "$state" = "Unhealthy" ] && break
    sleep 10; i=$((i + 1))
  done
  bad "$app: la revisión $rev no quedó Healthy (estado: ${state:-desconocido})"
  return 1
}

in_container() { # app comando -> salida (az exec exige una terminal: se emula con pty)
  local app=$1 cmd=$2 rev rep
  rev=$(az containerapp show -g "$RG" -n "$app" --query properties.latestRevisionName -o tsv)
  rep=$(az containerapp replica list -g "$RG" -n "$app" --revision "$rev" --query "[0].name" -o tsv)
  [ -n "$rep" ] || return 1
  timeout 240 python3 -c 'import pty,sys; pty.spawn(sys.argv[1:])' \
    az containerapp exec -g "$RG" -n "$app" --revision "$rev" --replica "$rep" --command "$cmd" </dev/null 2>/dev/null | tr -d '\r'
}

activation_report() {
  local i out
  for i in 1 2 3; do
    out=$(in_container "$WEB" "php artisan culqi:activation-check" | grep -E '^(OK|FALLA|AVISO|NO-VERIFICADO|RESULTADO|ESTADO|COMPRA_LIVE_VERIFICADA)')
    printf '%s' "$out" | grep -q '^RESULTADO:' && { printf '%s\n' "$out"; return 0; }
    sleep 20
  done
  return 1
}

# Excepción comercial para Azure for Students: SOLO si ESTA suscripción lleva la etiqueta que el titular pone con la referencia
# del documento/caso de Microsoft que la autoriza (queda en el registro de actividad de Azure: quién y cuándo). Sin etiqueta
# válida, o con fecha de caducidad vencida, la suscripción sigue bloqueada. No afecta a ninguna otra suscripción.
AUTH_TAG="mova-microsoft-commercial-authorization"
AUTH_TAG_EXPIRES="mova-microsoft-commercial-authorization-expires"
student_exception() { # sub nombre quotaId
  local sub=$1 name=$2 quota=$3 tags ref expires
  tags=$(az tag list --resource-id "/subscriptions/$sub" --query properties.tags -o json 2>/dev/null) || tags=""
  [ -n "$tags" ] || tags='{}'
  ref=$(printf '%s' "$tags" | python3 -c 'import json,sys; t=json.load(sys.stdin) or {}; print(t.get(sys.argv[1],""))' "$AUTH_TAG" 2>/dev/null)
  expires=$(printf '%s' "$tags" | python3 -c 'import json,sys; t=json.load(sys.stdin) or {}; print(t.get(sys.argv[1],""))' "$AUTH_TAG_EXPIRES" 2>/dev/null)
  if [[ ! "$ref" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{5,63}$ ]]; then
    bad "Suscripción «$name» ($quota): Azure for Students no admite uso comercial salvo autorización de Microsoft. Si la tienes, registra su referencia en la etiqueta «$AUTH_TAG» de esta suscripción (ver docs/release/ACTIVAR_CULQI_EN_AZURE.md) o pásala a pago por uso."
    return
  fi
  if [ -n "$expires" ]; then
    if [[ ! "$expires" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]]; then
      bad "La etiqueta «$AUTH_TAG_EXPIRES» debe tener formato AAAA-MM-DD"; return
    fi
    if [[ "$(date -u +%Y-%m-%d)" > "$expires" ]]; then
      bad "La autorización comercial de Microsoft registrada venció el $expires"; return
    fi
  fi
  ok "Suscripción «$name» ($quota): EXCEPCIÓN AUTORIZADA por Microsoft para uso comercial (referencia …${ref: -4}${expires:+, vigente hasta $expires}), declarada por el titular en la etiqueta «$AUTH_TAG»"
}

do_check() {
  FAILS=0
  say "== Suscripción (uso comercial)"
  local sub quota name
  sub=$(az account show --query id -o tsv); name=$(az account show --query name -o tsv)
  quota=$(az rest --method get --url "https://management.azure.com/subscriptions/$sub?api-version=2022-12-01" --query subscriptionPolicies.quotaId -o tsv 2>/dev/null)
  if [ -z "$quota" ]; then
    bad "No pude leer el tipo de oferta de la suscripción «$name»: no se puede confirmar el uso comercial"
  elif printf '%s %s' "$quota" "$name" | grep -qi 'student'; then
    student_exception "$sub" "$name" "$quota"
  else
    ok "Suscripción «$name» ($quota) sin restricción de estudiante"
  fi

  say "== Aplicaciones"
  local app j1 j2
  j1=$(env_json "$SCHED") || { bad "No pude leer la configuración de $SCHED"; j1=""; }
  j2=$(env_json "$WEB") || { bad "No pude leer la configuración de $WEB"; j2=""; }
  for app in "${APPS[@]}"; do
    local rev h js
    rev=$(az containerapp show -g "$RG" -n "$app" --query properties.latestRevisionName -o tsv 2>/dev/null)
    h=$(az containerapp revision show -g "$RG" -n "$app" --revision "$rev" --query properties.healthState -o tsv 2>/dev/null)
    [ "$h" = "Healthy" ] && ok "$app: $rev Healthy" || bad "$app: revisión ${rev:-?} en estado ${h:-desconocido}"
    js=$j1; [ "$app" = "$WEB" ] && js=$j2
    [ -n "$js" ] || continue
    for v in CULQI_SECRET_KEY CULQI_WEBHOOK_SECRET; do
      local r; r=$(env_get "$js" "$v" secretRef)
      [ -n "$r" ] && [ "$r" != "__AUSENTE__" ] && ok "$app: $v viene de Key Vault" || bad "$app: $v no está referenciada a Key Vault"
    done
  done
  if [ -n "$j1" ] && [ -n "$j2" ]; then
    for v in PAYMENT_PROVIDER CULQI_ENV CULQI_METHODS CULQI_WEBHOOKS_ENABLED CULQI_PUBLIC_KEY CULQI_CHECKOUT_ALLOWLIST MERCADOPAGO_NEW_PAYMENTS_ENABLED; do
      [ "$(fingerprint "$(env_get "$j1" "$v" value)")" = "$(fingerprint "$(env_get "$j2" "$v" value)")" ] \
        && ok "$v igual en web y scheduler" || bad "$v distinta entre web y scheduler"
    done
  fi

  say "== Key Vault"
  for s in culqi-secret-key culqi-webhook-secret; do
    [ "$(az keyvault secret show --vault-name "$KV" -n "$s" --query attributes.enabled -o tsv 2>/dev/null)" = "true" ] \
      && ok "Secreto $s presente y habilitado" || bad "Secreto $s ausente o deshabilitado en $KV"
  done

  say "== HTTP público"
  local code
  code=$(curl -s -o /dev/null -w '%{http_code}' "https://$DOMAIN/readyz")
  [ "$code" = 200 ] && ok "/readyz 200 (base de datos, migraciones y caché)" || bad "/readyz respondió $code"
  code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d '{}' "https://$DOMAIN/api/webhooks/culqi/$(openssl rand -hex 24)")
  [ "$code" = 401 ] && ok "Receptor del webhook activo (token falso → 401)" || bad "Receptor del webhook respondió $code a un token falso (se esperaba 401)"

  say "== Dentro de la aplicación (culqi:activation-check, solo lectura)"
  if REPORT=$(activation_report); then
    printf '%s\n' "$REPORT"
    printf '%s\n' "$REPORT" | grep -q '^RESULTADO: LISTO' || FAILS=$((FAILS + 1))
  else
    REPORT=""; bad "No se pudo ejecutar culqi:activation-check en $WEB"
  fi
  say "== Requisitos que solo puedes confirmar tú: 5 webhooks en CulqiPanel (producción) con la URL vigente, tarjeta habilitada, PCI con Culqi."
  [ $FAILS -eq 0 ] && say "LISTO PARA ACTIVAR" || say "NO LISTO: $FAILS falla(s)"
  return $FAILS
}

# Aplica un conjunto de variables (NOMBRE=valor | -NOMBRE para borrar) a una app y espera a que quede Healthy.
apply_vars() {
  local app=$1; shift
  local set=() rm=() kv
  for kv in "$@"; do case "$kv" in -*) rm+=("${kv#-}") ;; *) set+=("$kv") ;; esac; done
  local args=()
  [ ${#set[@]} -gt 0 ] && args+=(--set-env-vars "${set[@]}")
  [ ${#rm[@]} -gt 0 ] && args+=(--remove-env-vars "${rm[@]}")
  az containerapp update -g "$RG" -n "$app" "${args[@]}" -o none 2>/dev/null && wait_healthy "$app"
}

snapshot_vars() { # json -> lista NOMBRE=valor / -NOMBRE para restaurar exactamente
  local js=$1 v val out=()
  for v in "${VARS[@]}"; do
    val=$(env_get "$js" "$v" value)
    [ "$val" = "__AUSENTE__" ] && out+=("-$v") || out+=("$v=$val")
  done
  printf '%s\n' "${out[@]}"
}

expect_state() { # prefijo esperado
  REPORT=$(activation_report) || { bad "No pude confirmar el estado desde la aplicación"; return 1; }
  printf '%s\n' "$REPORT" | grep -E '^(RESULTADO|ESTADO|COMPRA_LIVE_VERIFICADA)'
  printf '%s\n' "$REPORT" | grep -q "^ESTADO: $1" && { ok "Estado confirmado: $1"; return 0; }
  bad "El estado final no es $1"
  return 1
}

open_purchases() { # estado_esperado var=valor...
  local expected=$1; shift
  local j app; declare -A before
  for app in "${APPS[@]}"; do
    j=$(env_json "$app") || die "No pude leer la configuración de $app: no se cambia nada."
    before[$app]=$(snapshot_vars "$j")
  done
  if [ $DRY -eq 1 ]; then say "[--dry-run] Se aplicaría $* en ${APPS[*]}. Nada cambió."; return 0; fi
  for app in "${APPS[@]}"; do
    if ! apply_vars "$app" "$@"; then
      say "Revirtiendo ambos servicios a su configuración anterior…"
      local a reverted=1
      for a in "${APPS[@]}"; do
        mapfile -t prev <<< "${before[$a]}"
        apply_vars "$a" "${prev[@]}" || reverted=0
      done
      [ $reverted -eq 1 ] && die "El cambio no se completó; se restauró el estado anterior." \
        || die "El cambio no se completó y NO se pudo restaurar todo: ejecuta «disable» y revisa el portal."
    fi
  done
  expect_state "$expected" || return 1
}

close_purchases() {
  local sentinel app tries
  sentinel="compras-cerradas-$(openssl rand -hex 6)@mova.invalid"
  if [ $DRY -eq 1 ]; then say "[--dry-run] Se pondría CULQI_CHECKOUT_ALLOWLIST=<cerrado>.invalid y MERCADOPAGO_NEW_PAYMENTS_ENABLED=false. Nada cambió."; return 0; fi
  for app in "${APPS[@]}"; do   # nunca se revierte a «abierto»: se reintenta
    for tries in 1 2 3; do
      apply_vars "$app" "CULQI_CHECKOUT_ALLOWLIST=$sentinel" "MERCADOPAGO_NEW_PAYMENTS_ENABLED=false" && break
      say "Reintentando cierre en $app ($tries/3)…"; sleep 15
    done
  done
  expect_state "PREPARADO"
}

confirm() {
  [ $DRY -eq 1 ] && return 0
  [ $YES -eq 1 ] && [ "$1" = "DETENER" ] && return 0
  local ans; read -r -p "Escribe $1 para continuar: " ans; [ "$ans" = "$1" ] || die "Cancelado."
}

case "$CMD" in
  check)
    do_check; exit $? ;;

  enable-test)
    email="${ARGS[1]:-}"
    [[ "$email" =~ ^[^@[:space:],]+@[^@[:space:],]+\.[^@[:space:],]+$ ]] && [[ "$email" != *.invalid ]] \
      || die "Uso: enable-test <correo de la cuenta de profesor que hará la compra de verificación>"
    do_check || die "Hay fallas: no se activa nada."
    say ""; say "Vas a ABRIR COMPRAS REALES con Culqi solo para ${email%%@*}@… (cobros con dinero real)."
    confirm ACTIVAR
    if ! open_purchases "ACTIVO-PRUEBA" "PAYMENT_PROVIDER=culqi" "CULQI_CHECKOUT_ALLOWLIST=$email" "MERCADOPAGO_NEW_PAYMENTS_ENABLED=false"; then
      say "El estado no es el esperado: se cierran las compras por seguridad."; close_purchases; exit 1
    fi
    say "Siguiente paso: entra con esa cuenta a https://$DOMAIN/teacher/credits, compra el paquete Inicio (S/ 10,00) y luego ejecuta «check»: debe decir COMPRA_LIVE_VERIFICADA: si." ;;

  enable-all)
    do_check || die "Hay fallas: no se activa nada."
    printf '%s\n' "$REPORT" | grep -q '^COMPRA_LIVE_VERIFICADA: si' \
      || die "Aún no hay una compra live verificada (cargo chr_live_ pagado con su depósito en el ledger). Usa primero enable-test."
    say ""; say "Vas a ABRIR COMPRAS REALES con Culqi para TODOS los profesores."
    confirm ACTIVAR
    if ! open_purchases "ACTIVO-GENERAL" "PAYMENT_PROVIDER=culqi" "-CULQI_CHECKOUT_ALLOWLIST" "MERCADOPAGO_NEW_PAYMENTS_ENABLED=false"; then
      say "El estado no es el esperado: se cierran las compras por seguridad."; close_purchases; exit 1
    fi ;;

  disable)
    say "Se cierran las compras NUEVAS. Webhooks, conciliación, órdenes pendientes y reembolsos siguen funcionando."
    confirm DETENER
    close_purchases; exit $? ;;

  rotate-webhook-secret)
    say "Se genera un secreto nuevo para la URL del webhook. Después hay que editar las 5 URL en CulqiPanel."
    confirm ROTAR
    [ $DRY -eq 1 ] && { say "[--dry-run] Nada cambió."; exit 0; }
    tmp=$(tmpf); openssl rand -hex 24 | tr -d '\n' > "$tmp"
    az keyvault secret set --vault-name "$KV" -n culqi-webhook-secret --file "$tmp" -o none || die "No pude guardar el secreto en Key Vault"
    install -m 600 /dev/null "$URL_FILE" && printf 'https://%s/api/webhooks/culqi/%s\n' "$DOMAIN" "$(cat "$tmp")" > "$URL_FILE"
    say "URL nueva guardada en $URL_FILE (solo tú puedes leerla)."
    id=$(az containerapp show -g "$RG" -n "$WEB" --query "properties.configuration.secrets[?name=='culqi-webhook-secret'] | [0].identity" -o tsv)
    for app in "${APPS[@]}"; do
      az containerapp secret set -g "$RG" -n "$app" --secrets "culqi-webhook-secret=keyvaultref:https://$KV.vault.azure.net/secrets/culqi-webhook-secret,identityref:$id" -o none \
        && apply_vars "$app" "CULQI_WEBHOOK_ROTATED_AT=$(date -u +%Y%m%dT%H%M%SZ)" \
        || bad "No pude aplicar el secreto nuevo en $app (la referencia a Key Vault lo cargará sola en unos minutos)"
    done
    for i in 1 2 3 4 5 6; do   # Container Apps puede tardar unos minutos en leer la versión nueva de Key Vault
      code=$(printf 'url = "%s"\n' "$(cat "$URL_FILE")" | curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d '{}' -K -)
      [ "$code" = 400 ] && break; sleep 30
    done
    [ "$code" = 400 ] && ok "MOVA acepta la URL nueva (400 con cuerpo vacío = secreto correcto); la anterior queda revocada" \
      || say "AVISO: la URL nueva aún responde $code; repite «check» en unos minutos."
    say "Ahora edita en CulqiPanel → Desarrollo → Webhooks las 5 entradas y pega la URL de $URL_FILE." ;;

  update-keys)
    say "Pega las llaves de CulqiPanel (producción → Desarrollo → API Keys). No se muestran en pantalla."
    read -r -s -p "Llave pública (pk_live_…): " pk; echo
    read -r -s -p "Llave secreta (sk_live_…): " sk; echo
    [[ "$pk" =~ ^pk_live_[A-Za-z0-9]+$ ]] || die "La llave pública no tiene formato pk_live_…"
    [[ "$sk" =~ ^sk_live_[A-Za-z0-9]+$ ]] || die "La llave secreta no tiene formato sk_live_…"
    hdr=$(tmpf); printf 'Authorization: Bearer %s\n' "$sk" > "$hdr"
    code=$(curl -s -o /dev/null -w '%{http_code}' -H @"$hdr" 'https://api.culqi.com/v2/charges?limit=1')
    [ "$code" = 200 ] || die "Culqi rechazó la llave secreta (HTTP $code)"
    confirm CAMBIAR
    [ $DRY -eq 1 ] && { say "[--dry-run] Nada cambió."; exit 0; }
    skf=$(tmpf); printf '%s' "$sk" > "$skf"; unset sk
    az keyvault secret set --vault-name "$KV" -n culqi-secret-key --file "$skf" -o none || die "No pude guardar la llave secreta en Key Vault"
    id=$(az containerapp show -g "$RG" -n "$WEB" --query "properties.configuration.secrets[?name=='culqi-secret-key'] | [0].identity" -o tsv)
    for app in "${APPS[@]}"; do
      az containerapp secret set -g "$RG" -n "$app" --secrets "culqi-secret-key=keyvaultref:https://$KV.vault.azure.net/secrets/culqi-secret-key,identityref:$id" -o none \
        && apply_vars "$app" "CULQI_PUBLIC_KEY=$pk" || bad "Fallo al aplicar las llaves en $app: ejecuta «disable» y repite update-keys"
    done
    unset pk
    do_check ;;

  *)
    sed -n '2,13p' "$0"; exit 2 ;;
esac
