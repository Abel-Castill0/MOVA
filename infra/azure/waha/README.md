# Piloto de WAHA en una VM B2pts v2 (ARM64)

> **Estado: preparado, NO desplegado.** Ningún recurso de este directorio se ha creado. `pilot-create.sh` exige `--confirm`
> y solo debe ejecutarse tras la aprobación expresa del titular de la estimación de costo. Contexto y decisiones:
> [`docs/whatsapp-waha.md`](../../../docs/whatsapp-waha.md).

## Qué es

Una única VM con WAHA (motor NOWEB, sin navegador) y **una sola sesión de WhatsApp** (número dedicado de MOVA). MOVA envía
por `WhatsAppChannel → WahaProvider → POST /api/sendText` y recibe `message.ack` / `session.status` en
`POST /api/webhooks/waha` (firmado HMAC-SHA512). Nada sale de MOVA sin pasar por consentimiento, baja, teléfono verificado,
suspensión y cuenta cerrada.

## Recursos exactos (región `northcentralus`, RG `mova-prod-rg`, etiquetas `purpose=waha-pilot`, `delete-by`)

| Recurso | Nombre | Notas |
|---|---|---|
| VM | `movap-waha` | `Standard_B2pts_v2` (2 vCPU ARM, 1 GiB), Ubuntu 24.04 ARM64, identidad administrada |
| Disco SO | (auto) | Standard SSD 32 GiB (E4) |
| IP pública | `movap-waha-pip` | Standard estática, **solo para la salida** hacia WhatsApp; el NSG bloquea toda entrada |
| NIC | `movap-waha-nic` | IP privada fija `10.30.0.4` |
| VNet | `movap-waha-vnet` | `10.30.0.0/24` |
| NSG | `movap-waha-nsg` | Permite 3000/TCP solo desde `10.20.0.0/16` (VNet de MOVA); deniega Internet |
| Peering ×2 | `to-mova` / `to-waha` | Acceso privado MOVA → WAHA, tráfico entre VNets en la misma región |
| Rol | Key Vault Secrets User | Solo lectura de secretos, para la identidad de la VM |

No se crea ningún otro recurso: ni NAT Gateway, ni Load Balancer, ni Bastion, ni cuenta de almacenamiento. Tampoco se toca
Culqi, el ledger, `movap-scheduler` ni las colas.

## Imagen

`devlikeapro/waha:noweb-arm-2026.10.1@sha256:fce6ae58ba819221521b2673704ef9e6d87f98571d67daec62eb737edd77c2c9`
(versión **y** digest; nunca `latest`). Dashboard y Swagger desactivados.

## Secretos

- `waha-api-key` y `waha-hook-hmac-key` viven en el Key Vault `kv-movap-192f01`; el operador los genera con `openssl rand` y los carga
  sin mostrarlos. La VM los lee al arrancar con su identidad administrada y los guarda en `/etc/waha/waha.env` (0600).
- La sesión vinculada vive en el disco de la VM (`/var/lib/waha/sessions`, 0700). Nada de esto va a GitHub.
- El mismo par de secretos se carga en el staging de MOVA (`WAHA_API_KEY`, `WAHA_HOOK_HMAC_KEY`) como secretos de la Container App.

## Variables de MOVA (solo staging durante el piloto)

```
WHATSAPP_ENABLED=true           # permanece false hasta el inicio del piloto
WHATSAPP_PROVIDER=waha
WAHA_BASE_URL=http://10.30.0.4:3000
WAHA_SESSION=default
WAHA_ALLOWED_RECIPIENTS=<números autorizados, separados por coma>
```

Producción mantiene `WHATSAPP_ENABLED=false` durante todo el piloto.

## Operación

```bash
HOOK_URL=https://<host-staging>/api/webhooks/waha ./pilot-create.sh --confirm   # crear
./pilot-teardown.sh --confirm                                                    # eliminar todo lo etiquetado
```

Desactivar sin borrar: `WHATSAPP_ENABLED=false` en MOVA (los envíos se omiten sin tocar Culqi ni colas).

## Límites conocidos

- WAHA usa el protocolo no oficial de WhatsApp: riesgo de bloqueo del número y de incumplimiento de términos. Número exclusivo de MOVA, nunca mensajes masivos ni sin consentimiento.
- Azure for Students es una oferta no comercial; un uso de producción real requiere otra suscripción.
- Una VM = punto único de fallo; la sesión se pierde con el disco (hay que volver a vincular el QR).
