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
de la VM (lo retira `pilot-create.sh` tras el primer arranque); rol *Contributor* de la VM y del janitor externo sobre **ese mismo grupo**.
Permanentes y sin coste (se crearon para la limpieza externa): `mova-waha-janitor-rg` + identidad `mova-waha-janitor`, el rol propio de peerings y sus 3 asignaciones de mínimo privilegio.
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

## Caducidad y limpieza en tres capas independientes

| Capa | Dónde corre | Cuándo | Qué hace |
|---|---|---|---|
| **1. Janitor externo** (principal) | GitHub Actions `waha-pilot-janitor`, OIDC, **fuera de la VM y de Azure Container Apps** | cron cada 15 min en la ventana 29-oct…4-nov-2026, y a mano (`force`) | A las **47 h** (etiqueta `expires-at`): elimina el peering `to-waha`, retira roles temporales y borra `mova-waha-pilot-rg`; después **verifica** y registra |
| 2. Respaldo en la VM | `waha-expire.timer` | a las **47 h 30 min** | Borra `mova-waha-pilot-rg` con la identidad de la VM (solo tiene Contributor sobre su grupo) |
| 3. Emergencia manual | tu terminal | cuando quieras | `./pilot-teardown.sh --confirm [--delete-secrets]` y `./pilot-verify.sh` |

**Janitor externo — diseño**
- *Independiente de la VM:* no usa nada de la VM; si esta se apaga, falla o pierde red, el janitor igual borra el grupo.
- *Alternativa descartada:* Azure Automation (cuenta propia, límite de 1 h entre horarios y minutos de ejecución) frente a Actions: el repo es público ⇒ minutos gratis, cron de 15 min y menos piezas. El tenant bloquea registrar aplicaciones de Entra, así que la federación OIDC se hace sobre una **identidad administrada asignada por el usuario** (`mova-waha-janitor`, en `mova-waha-janitor-rg`; coste US$0).
- *Permisos mínimos de esa identidad:* `Reader` en la suscripción (para comprobar ausencia de restos); un rol propio (`MOVA WAHA Pilot Peering Cleaner`: solo leer/borrar peerings) en `mova-vnet`; `Role Based Access Control Administrator` en el Key Vault **con condición** (solo puede asignar/retirar *Key Vault Secrets User*); y `Contributor` **solo sobre `mova-waha-pilot-rg`**, concedido por `pilot-create.sh` y que desaparece con el grupo. No puede tocar `mova-prod-rg`, Culqi, el ledger ni la base de datos.
- *Confianza:* la credencial federada solo acepta tokens de `repo:Abel-Castill0/MOVA:ref:refs/heads/master`. Sin secretos en GitHub.
- *Registro:* resumen de la ejecución en Actions + artefacto `janitor-log-<run>` (30 días).
- *Comprobación de lo facturable:* tras limpiar exige 0 grupos `mova-waha-pilot-rg`, 0 recursos con `purpose=waha-pilot`, 0 recursos `movap-waha*` (VM, disco, IP, NIC, VNet, NSG…) en toda la suscripción, 0 peering `to-waha` y 0 roles de la identidad de la VM; sale con error si queda algo.
- *Salvaguarda de creación:* `pilot-create.sh` **se niega a crear nada** si la última ejecución del janitor no fue exitosa, si su identidad no tiene `Reader` o si la caducidad cae fuera de la ventana del cron.
- *Límite conocido:* GitHub puede retrasar tareas programadas varios minutos; por eso el respaldo de la VM llega 30 min después y ambos quedan por debajo de 48 h.
- *Si el repo quedara inactivo 60 días* GitHub desactiva los cron; no aplica a esta ventana de días.

**Verificación manual (solo lectura):** `./pilot-verify.sh` — además compara `mova-prod-rg` y los peerings de `mova-vnet` con una línea base capturada antes de crear nada.

**Control de gasto:** Cost Management llega con 8–24 h de retraso, así que no es el control. El gasto está acotado por construcción
(recursos de precio fijo + caducidad dura < 48 h) y se vigila con el estado real (`az resource list -g mova-waha-pilot-rg`); la factura solo confirma después.
Si la proyección superara US$1 (p. ej. no se aplica el beneficio gratuito y hay desvíos), se apaga y se elimina sin esperar.
**Staging:** al terminar se revierte (`WHATSAPP_ENABLED=false`, sin `WAHA_*`). Producción nunca se toca.

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
