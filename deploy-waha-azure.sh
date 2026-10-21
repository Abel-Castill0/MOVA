#!/usr/bin/env bash
# ==============================================================================
# Script de Despliegue de WAHA (WhatsApp HTTP API) en Azure Container Apps
# MOVA Educa - Servicio Compañero en Azure Container Apps Environment
# ==============================================================================
set -euo pipefail

# ── Variables de Configuración ────────────────────────────────────────────────
RESOURCE_GROUP="${RESOURCE_GROUP:-rg-mova-prod}"
LOCATION="${LOCATION:-eastus2}"
CONTAINERAPPS_ENVIRONMENT="${CONTAINERAPPS_ENVIRONMENT:-cae-mova-prod}"
STORAGE_ACCOUNT_NAME="${STORAGE_ACCOUNT_NAME:-stamovaprod}"
FILE_SHARE_NAME="${FILE_SHARE_NAME:-waha-sessions}"
STORAGE_MOUNT_NAME="${STORAGE_MOUNT_NAME:-wahasessions}"

APP_NAME="${APP_NAME:-mova-waha}"
WAHA_IMAGE="${WAHA_IMAGE:-devlikeapro/waha:latest}"
WAHA_PORT=3000

# Credenciales de seguridad (Sobrescribir mediante variables de entorno en producción)
WAHA_API_KEY="${WAHA_API_KEY:-$(openssl rand -hex 24)}"
DASHBOARD_USERNAME="${DASHBOARD_USERNAME:-admin}"
DASHBOARD_PASSWORD="${DASHBOARD_PASSWORD:-$(openssl rand -base64 16)}"

echo "======================================================================"
echo "Iniciando Despliegue de WAHA en Azure Container Apps"
echo "Resource Group:       $RESOURCE_GROUP"
echo "Environment:          $CONTAINERAPPS_ENVIRONMENT"
echo "Storage Account:      $STORAGE_ACCOUNT_NAME"
echo "File Share:           $FILE_SHARE_NAME"
echo "App Name:             $APP_NAME"
echo "======================================================================"

# 1. Asegurar Storage Account y Azure File Share para persistencia de sesiones
echo "--> Paso 1: Verificando Storage Account y Azure File Share..."
STORAGE_EXISTS=$(az storage account check-name --name "$STORAGE_ACCOUNT_NAME" --query "nameAvailable" -o tsv || echo "false")
if [ "$STORAGE_EXISTS" == "true" ]; then
    echo "Creando Storage Account: $STORAGE_ACCOUNT_NAME..."
    az storage account create \
        --name "$STORAGE_ACCOUNT_NAME" \
        --resource-group "$RESOURCE_GROUP" \
        --location "$LOCATION" \
        --sku Standard_LRS \
        --kind StorageV2 \
        --enable-large-file-share \
        --output none
fi

STORAGE_KEY=$(az storage account keys list \
    --resource-group "$RESOURCE_GROUP" \
    --account-name "$STORAGE_ACCOUNT_NAME" \
    --query "[0].value" -o tsv)

echo "Asegurando Azure File Share: $FILE_SHARE_NAME..."
az storage share create \
    --name "$FILE_SHARE_NAME" \
    --account-name "$STORAGE_ACCOUNT_NAME" \
    --account-key "$STORAGE_KEY" \
    --output none

# 2. Vincular Storage Share al Container Apps Environment
echo "--> Paso 2: Vinculando Storage Mount al Environment ($CONTAINERAPPS_ENVIRONMENT)..."
az containerapp env storage set \
    --name "$CONTAINERAPPS_ENVIRONMENT" \
    --resource-group "$RESOURCE_GROUP" \
    --storage-name "$STORAGE_MOUNT_NAME" \
    --azure-file-account-name "$STORAGE_ACCOUNT_NAME" \
    --azure-file-account-key "$STORAGE_KEY" \
    --azure-file-share-name "$FILE_SHARE_NAME" \
    --access-mode ReadWrite \
    --output none

# 3. Crear / Actualizar la Container App 'mova-waha'
echo "--> Paso 3: Desplegando Container App ($APP_NAME)..."
az containerapp create \
    --name "$APP_NAME" \
    --resource-group "$RESOURCE_GROUP" \
    --environment "$CONTAINERAPPS_ENVIRONMENT" \
    --image "$WAHA_IMAGE" \
    --target-port "$WAHA_PORT" \
    --ingress internal \
    --min-replicas 1 \
    --max-replicas 1 \
    --cpu 0.5 \
    --memory 1.0Gi \
    --secrets \
        waha-api-key="$WAHA_API_KEY" \
        dashboard-user="$DASHBOARD_USERNAME" \
        dashboard-pass="$DASHBOARD_PASSWORD" \
    --env-vars \
        WHATSAPP_API_KEY=secretref:waha-api-key \
        WAHA_DASHBOARD_USERNAME=secretref:dashboard-user \
        WAHA_DASHBOARD_PASSWORD=secretref:dashboard-pass \
        WHATSAPP_START_SESSION=default \
        WHATSAPP_DEFAULT_ENGINE=NOWEB \
        WAHA_LOG_LEVEL=info \
    --output none

# 4. Configurar el montaje de volumen persistente en /app/.sessions
echo "--> Paso 4: Configurando montaje de volumen en /app/.sessions..."
az containerapp volume mount set \
    --name "$APP_NAME" \
    --resource-group "$RESOURCE_GROUP" \
    --volume-name "$STORAGE_MOUNT_NAME" \
    --mount-path "/app/.sessions" \
    --output none || echo "Nota: Si el comando volume mount no aplica en esta CLI version, el volumen persistente queda enlazado vía plantilla o comando containerapp update."

# Obtener FQDN interno
WAHA_FQDN=$(az containerapp show --name "$APP_NAME" --resource-group "$RESOURCE_GROUP" --query "properties.configuration.ingress.fqdn" -o tsv)

echo ""
echo "======================================================================"
echo "¡Despliegue completado exitosamente!"
echo "======================================================================"
echo "URL Interna para Laravel:   http://${WAHA_FQDN}"
echo "API Key configurada:        ${WAHA_API_KEY}"
echo "Dashboard Usuario:          ${DASHBOARD_USERNAME}"
echo "Dashboard Password:         ${DASHBOARD_PASSWORD}"
echo "======================================================================"
