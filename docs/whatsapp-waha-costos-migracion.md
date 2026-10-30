# WhatsApp (WAHA): costos, alternativas y migración del piloto

Estado: **análisis y decisión pendiente del propietario** (2026-10-30). No se ha contratado nada nuevo. Precios minoristas de Azure del 2026-10-29
(API de precios de Azure); los de Meta/Hetzner/otros son **referenciales de terceros y deben confirmarse** antes de decidir.

## 1. Qué hay hoy y cuándo desaparece

| Recurso (grupo `mova-waha-pilot-rg`, northcentralus) | Costo mensual | Notas |
|---|---|---|
| VM `Standard_B2pts_v2` ARM64 (2 vCPU, 1 GiB) | US$6.13 (US$0.0084/h) | **US$0 solo si** se aplican las 750 h gratuitas de la suscripción; **no está demostrado** hasta ver la factura |
| Disco SSD estándar 32 GiB (E4) | US$2.40 | + operaciones ≈ US$0.01–0.10 |
| IP pública Standard (solo salida) | US$3.65 | sin ella la VM no tiene salida a Internet (NAT Gateway ≈ US$33) |
| Peering global Mexico Central ↔ North Central US | ≈ US$0.00 | US$0.07/GB; tráfico < 100 MB/mes |
| **Total** | **≈ US$6.05 con el beneficio · ≈ US$12.18 sin él** | |

- **Eliminación programada:** `2026-11-01T04:25:31Z` (etiqueta `expires-at` del grupo). El *janitor* externo (GitHub Actions) borra el grupo completo: **VM, disco, IP y la sesión de WhatsApp que vive en ese disco**. Respaldo en la VM: +30 min.
- **Dónde reside la sesión:** únicamente en el disco de esa VM (`/var/lib/waha/sessions`, 3.7 MB). Si el grupo se borra sin migrar, la sesión desaparece (hay que volver a vincular).
- No se debe «alargar la vida» cambiando una etiqueta: la migración correcta crea el destino permanente, restaura allí una copia consistente de la sesión y solo entonces se retira el piloto.

## 2. Comparación de arquitecturas

| | Costo extra/mes | Persistencia de la sesión | Riesgo | Veredicto |
|---|---|---|---|---|
| **A. VM ARM64 Azure con beneficio gratuito** | ≈ US$6 (disco + IP) | Disco de la VM, respaldo cifrado | Beneficio no demostrado; crédito de estudiante finito | Opción vigente del piloto |
| **B. Misma VM sin beneficio** | ≈ US$12 | igual | Consume el crédito (≈ US$100/12 meses) en ~8 meses | Solo si no hay alternativa |
| **C. WAHA en Azure Container Apps (existente)** | ≈ US$12 (0.5 vCPU/1 GiB siempre encendido) + Azure Files | SQLite sobre SMB: **bloqueos y corrupción** | Alto para la sesión | **Descartada** |
| **D. VM con encendido programado** | ahorra ≤ US$3 (solo cómputo; disco e IP se pagan igual) | cada apagado = desconexión/reconexión | Aumenta el riesgo de revocación | **No recomendada** (ahorro insignificante) |
| **E. Servidor propio 24/7 (mini-PC / Raspberry Pi)** | ≈ US$1–2 de energía | disco local + respaldo | Depende de luz/Internet/ubicación; acceso privado a Azure por túnel | Viable solo si ya existe un equipo siempre encendido |
| **F. VPS ARM externo** (p. ej. Hetzner CAX11) | ≈ €4.5–5 (+IVA), referencial | disco del VPS + respaldo | Otro proveedor/pago; el acceso a MOVA pasa por Internet con TLS | Más barato que B, similar a A sin beneficio |
| **G. WhatsApp Cloud API oficial (Meta)** | por mensaje: utilidad ≈ US$0.02–0.03 fuera de ventana (referencial; Meta factura en PEN en Perú) | **no hay sesión ni QR**: sin riesgo de revocación | Requiere verificación de Meta Business y plantillas aprobadas | **La única vía sin riesgo de ban para avisos a desconocidos** |

## 3. Recomendación (decisión del propietario)

1. **Lo que cambia las reglas:** el 2026-10-30 WhatsApp revocó la vinculación **2 s después del primer mensaje a un contacto nuevo** («reachout timelock» + `conflict`). Un número vinculado a un cliente no oficial **no debe iniciar chats con desconocidos**. Por eso MOVA ahora solo escribe a quien escribió primero (ver `docs/whatsapp-waha.md`). Con esa política WAHA es razonable para un volumen bajo y **solo** para usuarios que se suscriben escribiendo «ACTIVAR».
2. Para avisos a padres/profesores **que no han escrito primero**, la opción segura es **G (Cloud API)**: a volúmenes bajos cuesta céntimos al mes y elimina el riesgo de perder el número.
3. Si se mantiene WAHA: **A** (ya construida) con respaldo cifrado fuera de la VM. Costo recurrente nuevo a aprobar: ≈ **US$6/mes** (US$12 si se pierde el beneficio) + un contenedor de blobs de unos KB (≈ US$0.02/mes).
4. **No** apagar la VM para ahorrar (D) ni mover a Container Apps (C).

**Decisión que necesito:** (a) ¿se mantiene la VM de forma permanente (≈ US$6–12/mes) o se prefiere pasar a la Cloud API oficial para avisos masivos? (b) ¿apruebas un destino de respaldo fuera de la VM (contenedor de blobs en una cuenta de almacenamiento dedicada, ≈ US$0.02/mes)?

## 4. Plan de migración conservador (cuando se apruebe el gasto)

1. Respaldo cifrado consistente de la sesión (`infra/azure/waha/backup/waha-session-backup.sh`) con la sesión en `WORKING`, y subida al destino aprobado.
2. Crear el grupo **permanente** (`mova-waha-rg`) con VNet/NSG/IP/VM nuevos (mismo `pilot-create.sh`, sin etiqueta `purpose=waha-pilot` ni `expires-at`, con su propia limpieza manual).
3. **Parar** la VM del piloto (nunca dos instancias con la misma identidad de WhatsApp) y restaurar el respaldo en la nueva (`waha-session-restore.sh`).
4. Arrancar la nueva, comprobar `WORKING` y el número, enviar un mensaje autorizado a un chat existente.
5. Cambiar `WAHA_BASE_URL` y el webhook; solo entonces dejar que el *janitor* retire el piloto. El *janitor* se conserva para pilotos futuros.
6. Si la restauración falla, la VM del piloto sigue intacta (detenida) y se vuelve a arrancar; en el peor caso hay que escanear un QR nuevo.
