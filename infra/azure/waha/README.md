# Piloto de WAHA en una VM B2pts v2 (ARM64)

> **Estado: preparado, NO desplegado.** Ningún recurso de este directorio existe. `pilot-create.sh` exige `--confirm` y solo
> debe ejecutarse tras la aprobación expresa del titular. Contexto: [`docs/whatsapp-waha.md`](../../../docs/whatsapp-waha.md).

## Qué es

Una VM con WAHA (motor NOWEB, sin navegador) y **una sola sesión** de WhatsApp (número dedicado de MOVA). MOVA envía por
`WhatsAppChannel → WahaProvider → POST /api/sendText` por IP privada, y WAHA devuelve `message.ack` / `session.status` a
`POST /api/webhooks/waha` del staging (firmado HMAC-SHA512, por Internet saliente de la VM).

## Recursos exactos

Todo lo exclusivo vive en **un grupo propio**, `mova-waha-pilot-rg` (northcentralus, etiquetas `purpose=waha-pilot`, `expires-at`):

| Recurso | Nombre | Notas |
|---|---|---|
| Grupo de recursos | `mova-waha-pilot-rg` | Borrarlo elimina todo lo de abajo |
| VM | `movap-waha` | `Standard_B2pts_v2` (2 vCPU ARM, 1 GiB), Ubuntu 24.04 ARM64, identidad administrada |
| Disco SO | (auto) | Standard SSD 32 GiB (E4) |
| IP pública | `movap-waha-pip` | Standard estática, **solo salida**; el NSG bloquea toda entrada |
| NIC | `movap-waha-nic` | IP privada fija `10.30.0.4` |
| VNet | `movap-waha-vnet` | `10.30.0.0/24` |
| NSG | `movap-waha-nsg` | 3000/TCP solo desde `10.20.0.0/16`; Internet denegado |
| Peering | `to-mova` (en el grupo) | lado WAHA |

**Fuera del grupo, lo único que se crea** (todo exclusivo del piloto y retirado por la limpieza):
peering `to-waha` en `mova-vnet`; rol *Key Vault Secrets User*
de la VM (lo retira `pilot-create.sh` tras el primer arranque); rol *Contributor* de la VM sobre **su propio grupo**.
No hay NAT Gateway, Load Balancer, Bastion, almacenamiento ni cambios en Culqi, ledger, `movap-web`, `movap-scheduler`, colas ni base de datos.

## Alcance privado desde Azure Container Apps (verificado)

- `mova-aca-env`: entorno de *workload profiles* (perfil Consumption), **integrado en `mova-vnet/snet-aca`** (10.20.0.0/23), sin NSG ni UDR en esa subred.
  Su tráfico saliente sale por la VNet, así que un peering directo hacia `10.30.0.0/24` es enrutable. Lo no soportado en Consumption-only
  (UDR, NAT, peering por *remote gateway*) no se usa aquí.
- El NSG de WAHA admite 3000/TCP desde `10.20.0.0/16` (incluye `snet-aca`) y deniega Internet: la API no se expone.
- Es una verificación documental y de configuración. **La prueba empírica es la puerta de entrada del piloto**: tras crear la VM,
  `az containerapp exec` en staging ejecuta `php artisan mova:waha-check` (solo lectura). Si no alcanza WAHA, se aborta y se ejecuta la limpieza (coste < US$0.10).

## Estimación de costo (48 h, precios minoristas de Azure del 2026-10-29)

| Concepto | Cálculo | Sin beneficio gratuito | Con las 750 h gratuitas |
|---|---|---|---|
| VM B2pts v2 | US$0.0084/h × 48 | 0.403 | 0 |
| Disco E4 32 GiB | US$2.40/mes × 48/730 | 0.158 | 0.158 |
| IP pública Standard | US$0.005/h × 48 | 0.240 | 0.240 |
| **Peering global** (mexicocentral ↔ northcentralus) | US$0.035/GB salida + US$0.035/GB entrada = US$0.07/GB | ≈0.007 | ≈0.007 |
| Operaciones de disco | US$0.002/10 K; ≤ 500 K | ≤ 0.10 | ≤ 0.10 |
| Salida a Internet / Key Vault / VNet / NSG / NIC | primeros 100 GB gratis; operaciones KV ≈ 0 | 0 | 0 |
| **Total techo** | | **≈ US$0.91** | **≈ US$0.51** |
| **Total esperado** | | ≈ US$0.84 | ≈ US$0.43 |

El peering se cobra en **ambos** sentidos y en **ambos** extremos. El tráfico está acotado por diseño: el límite de 8 mensajes/min
(≈ 2 KB cada uno) da como máximo ≈ 46 MB en 48 h ⇒ ≈ US$0.003; se presupuestan 100 MB. Quedan por debajo de US$1 incluso sin el beneficio.

## Caducidad y limpieza (no depende de una etiqueta ni de alertas)

1. **Caducidad en la propia VM:** `waha-expire.timer` (cada 5 min) compara con `EXPIRES_AT` (creación + 47 h 45 min). Al llegar, la VM
   llama a la API de Azure con su identidad administrada y borra el peering `to-waha` y el grupo `mova-waha-pilot-rg` completo.
   Su identidad solo puede tocar ese grupo y ese peering.
2. **Respaldo manual, idempotente:** `./pilot-teardown.sh --confirm [--delete-secrets]` — se niega a borrar un grupo que no lleve
   `purpose=waha-pilot` o que sea el compartido; borra el grupo, el peering huérfano y los roles de la identidad.
3. **Verificación (solo lectura, sale 1 si hay restos):** `./pilot-verify.sh` comprueba que el grupo no existe, que no queda ningún
   recurso con `purpose=waha-pilot` en la suscripción, que `to-waha` no existe, que no quedan roles de la identidad y que los
   recursos y peerings de `mova-prod-rg`/`mova-vnet` son **idénticos a la línea base** capturada antes de crear nada.
4. **Control de gasto:** Cost Management llega con 8–24 h de retraso, así que no es el control. El gasto está acotado por construcción
   (recursos de precio fijo + caducidad dura < 48 h) y se vigila con el estado real: `az resource list -g mova-waha-pilot-rg` y el
   estado de la VM en cada comprobación; la factura solo confirma después. Si algo no se aplica (p. ej. el beneficio gratuito) y la
   proyección superara US$1, se apaga y se elimina sin esperar.
5. **Staging:** al terminar se revierte (`WHATSAPP_ENABLED=false`, sin `WAHA_*`). Producción nunca se toca.

## Secretos

`waha-api-key` y `waha-hook-hmac-key` viven en el Key Vault `kv-movap-192f01` (se generan con `openssl rand` y se cargan sin mostrarlos).
La VM los lee **una vez** al primer arranque con su identidad y los guarda en `/etc/waha/waha.env` (0600); después se le retira el rol
del Key Vault. La sesión vinculada vive en el disco (`/var/lib/waha/sessions`, 0700). Nada va a GitHub. Los mismos valores se cargan en
el staging como secretos de la Container App.

## Variables de MOVA (solo staging)

```
WHATSAPP_ENABLED=true           # producción: false durante todo el piloto
WHATSAPP_PROVIDER=waha
WAHA_BASE_URL=http://10.30.0.4:3000
WAHA_SESSION=default
WAHA_ALLOWED_RECIPIENTS=<solo números autorizados, separados por coma>
```

## Plan de pruebas del piloto (staging, solo números autorizados)

Alcance privado (`mova:waha-check`) · imagen ARM64 fija arrancada · vinculación por QR · reinicio de VM y persistencia de la sesión ·
envío a un número autorizado · `message.ack` recibido y reflejado en `whatsapp_messages` · RAM/CPU reales (`az vm run-command` con
`docker stats`, sin abrir SSH) · reinicio automático del contenedor · puerto 3000 inaccesible desde Internet · Culqi/scheduler intactos.

## Operación

```bash
HOOK_URL=https://<host-staging>/api/webhooks/waha ./pilot-create.sh --confirm
./pilot-teardown.sh --confirm       # respaldo manual
./pilot-verify.sh                   # demuestra que quedó limpio
```

## Límites conocidos

- Protocolo no oficial de WhatsApp: riesgo de bloqueo del número y de términos. Número exclusivo de MOVA; nunca envíos masivos ni sin consentimiento.
- Azure for Students es una oferta no comercial.
- Una VM es punto único de fallo; la sesión se pierde con el disco (hay que volver a vincular el QR).
