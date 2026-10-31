# WhatsApp con WAHA (proveedor alternativo, deshabilitado por defecto)

Estado (2026-10-29): **código integrado y probado con mocks; sin infraestructura creada; `WHATSAPP_ENABLED=false`.**
Infraestructura propuesta del piloto: [`infra/azure/waha/`](../infra/azure/waha/README.md).

## Integración

```
Notification::toWhatsApp() → WhatsAppChannel → WhatsAppProviderContract (WahaProvider) → WahaClient → WAHA /api/sendText
WAHA → POST /api/webhooks/waha (HMAC-SHA512) → WahaWebhookController → WhatsAppStatusRecorder → whatsapp_messages
```

- **`WhatsAppChannel` es la única puerta.** Aplica interruptor global, teléfono verificado, consentimiento (opt-in/opt-out),
  suspensión y cuenta cerrada. Se eliminaron `WhatsAppService` y `SendWhatsAppNotificationJob`, que se saltaban esas
  verificaciones; el panel de administración ahora solo envía a usuarios registrados que consintieron, por el canal.
- **`WahaProvider`** implementa `WhatsAppProviderContract`. Nunca lanza (así un reintento de la cola no reenvía correo/BD
  de una notificación multicanal). Orden: render → número → cuenta cerrada → lista del piloto → pausa por restricción de
  WhatsApp → tope diario por destinatario → sesión `WORKING` → deduplicado → cupo global por minuto → fila `unknown` ANTES de
  enviar → pausa de «escritura» → `sendText`.
- **Semántica «a lo sumo una vez»**: un timeout o una respuesta 2xx sin id deja la fila en `unknown`; no se reenvía a ciegas.
  «Aceptado por WAHA» = `sent`; solo `message.ack` DEVICE/READ/PLAYED lo lleva a `delivered`/`read`; ERROR → `failed`.
- **Webhook**: HMAC-SHA512 sobre el cuerpo crudo (`X-Webhook-Hmac`), tolerancia de marca de tiempo, límite 256 KB, throttle,
  deduplicado por evento y sin regresión de estado. `session.status` actualiza el estado en caché; `reachoutTimelock` /
  `messageCapping` pausan los envíos.
- **Privacidad**: los textos de WhatsApp no incluyen nombres de menores ni motivos en texto libre. Plantillas usadas:
  `generic_notification`, `phone_verification_code`. Notificaciones con WhatsApp: reprogramación de clase, nueva solicitud de clase,
  recarga aprobada, créditos insuficientes del profesor (1 aviso / 6 h) y verificación de teléfono.
- **Panel** (`/admin/whatsapp`): estado de solo lectura (ya no hace logout+restart en un GET), QR en PNG (GET, throttle),
  reinicio explícito (POST, throttle), envío de prueba por el canal.

## Panel administrativo: Sidebar → WhatsApp (`/admin/whatsapp`)

Vincular y administrar el número dedicado **sin Claude Code, scripts ni localhost**. Es una página nativa de MOVA (Inertia/Vue dentro de `AppLayout`).

- **Qué muestra:** estado real (Conectado · Esperando QR · Iniciando · Desconectado · Error · Número incorrecto · Servicio no disponible), número esperado y conectado,
  estado de WAHA, disponibilidad del servidor, última conexión, ambiente (piloto), tiempo restante del piloto y el estado de los avisos a usuarios.
- **«Conectado» = WAHA en `WORKING` y número igual a `WAHA_EXPECTED_NUMBER`.** Un HTTP 200, o un `me` con el número (WAHA lo guarda al pedir un código sin
  completar la vinculación), no bastan. Con otro número → «Número incorrecto» y se bloquea la prueba.
- **QR real:** Laravel pide `GET /api/{session}/auth/qr` a WAHA por la red privada y entrega el PNG (`Cache-Control: no-store`). Solo con la sesión en
  `SCAN_QR_CODE` y solo si es un PNG válido; cualquier JSON/HTML de error se convierte en un mensaje, nunca en imagen. La página consulta el estado cada 3 s y
  renueva el QR cada 4 s mientras espera; no reinicia nada para conseguir códigos. El navegador no ve la IP privada ni la API key.
- **Acciones (POST, MFA reciente, CSRF, confirmación y tope de 4 acciones/15 min en toda la plataforma):** *Iniciar vinculación* (STOPPED → start),
  *Recuperar sesión* (restart), *Restablecer vinculación* (logout + start; exige escribir `RESTABLECER`; solo si la sesión **no** está conectada), *Vincular mediante
  código* (código de 8 caracteres bajo demanda, nunca en logs) y *Enviar mensaje de prueba*.
- **Por qué existe «Restablecer»:** si se pide un código de emparejamiento y no se usa, WAHA queda con credenciales a medias; cada «restart» intenta *iniciar sesión*
  con ellas y falla («Connection Failure»), dejando la sesión en `FAILED` en bucle. Solo restablecer (descartar esas credenciales, que aún no vinculan nada) la recupera.
  WAHA además detiene la espera tras ~2–3 min sin escaneo: por eso hay un botón explícito para iniciar una nueva vinculación (no se reinicia sola).
- **Mensaje de prueba:** destinatario y texto FIJOS por configuración (`WAHA_ADMIN_TEST_RECIPIENT`; no hay campo de destinatario), solo con la sesión conectada al número
  correcto y el destinatario en `WAHA_ALLOWED_RECIPIENTS`. Pasa por `WahaProvider` (lista autorizada, deduplicado 30 min, ritmo, estado de sesión) y queda en
  `whatsapp_messages` (`client_reference = admin_test#…`); el panel muestra solicitud → aceptado por WAHA (id) → entregado (ACK) → leído, sin confundir «aceptado» con «entregado».
  Es la **única** excepción al guardián «solo `WhatsAppChannel` llama al proveedor» (`WhatsAppConsentTest`).
- **`WHATSAPP_ENABLED` vs `WAHA_ADMIN_CONTROL_ENABLED`:** el primero sigue siendo el interruptor de **avisos a usuarios** (false = no se notifica a nadie). El segundo solo
  habilita este panel. Con `ADMIN_CONTROL=true` y `WHATSAPP_ENABLED=false` un administrador puede vincular y probar, y la plataforma **no** envía nada a usuarios.
- **Acceso:** rol `admin` + `admin.mfa`; QR, acciones, código y prueba exigen además `admin.mfa:sensitive` (step-up). Padres, profesores e invitados no ven ni llaman nada.
- **Webhook:** WAHA debe apuntar a `POST /api/webhooks/waha` del entorno que administra el número (HMAC-SHA512). Para recibir el `message.ack` en producción se cambia
  `WHATSAPP_HOOK_URL` en la VM (`/etc/waha/waha.env`) y se reinicia el contenedor.
- **Comprobación sin iniciar sesión:** `php artisan mova:waha-check --qr` (solo lectura) confirma el alcance, el estado y que WAHA entrega un PNG válido sin imprimirlo.

## Configuración (`config/services.php → waha`)

`WAHA_BASE_URL` (en producción: https o IP privada), `WAHA_API_KEY`, `WAHA_HOOK_HMAC_KEY`, `WAHA_DEFAULT_SESSION`, `WAHA_ADMIN_CONTROL_ENABLED`, `WAHA_ADMIN_TEST_RECIPIENT`, `WAHA_EXPECTED_NUMBER`, `WAHA_ENV_LABEL`, `WAHA_PILOT_EXPIRES_AT`,
`WAHA_ALLOWED_RECIPIENTS`, `WAHA_RATE_PER_MINUTE` (8), `WAHA_MAX_PER_RECIPIENT_PER_DAY` (12) y tiempos de escritura/dedupe.
Con `WHATSAPP_ENABLED=true` y `WHATSAPP_PROVIDER=waha`, falta de URL/clave/HMAC **falla cerrado** al resolver el proveedor.

## Desactivar / revertir

`WHATSAPP_ENABLED=false` (y/o `WHATSAPP_PROVIDER=fake`): el canal omite todo. No afecta a Culqi, ledger, scheduler ni colas.
Para retirar la VM: `infra/azure/waha/pilot-teardown.sh --confirm` y comprobar con `pilot-verify.sh` (la limpieza principal es EXTERNA: el workflow `waha-pilot-janitor` borra `mova-waha-pilot-rg` a las 47 h aunque la VM falle; la VM lo hace como respaldo a las 47 h 30 min). Alcance desde MOVA: `php artisan mova:waha-check` (solo lectura).

## Pruebas

`WahaProviderTest`, `WahaWebhookTest`, `WahaChannelIntegrationTest`, `WahaNotificationsTest` + ajustes en
`MonetizationIntegrityTest` y `AccountLifecycleTest` (todo con `Http::fake`; **no hay prueba contra un WAHA real** hasta el piloto).

## Incidente 2026-10-30: WhatsApp revocó la vinculación (causa y política resultante)

- **Qué pasó (evidencia: registros de WAHA):** 07:15 se restableció la vinculación desde el panel; 07:15:58 vinculada; 07:16:44 se envió el primer mensaje (prueba) a un
  número que nunca había escrito al número de MOVA; 07:16:46.9 WhatsApp aplicó una restricción de contacto nuevo (`reachout timelock`, 6 h) y cerró la sesión
  (`Stream Errored (conflict)`). Desde entonces cada inicio de sesión falla (`Connection Failure`): las credenciales ya no valen y hay que **volver a vincular**.
  El mensaje sí llegó al teléfono; el ACK de entrega quedó «pendiente» porque la conexión murió 2 s después del envío (los webhooks sí llegaban y se aceptaron con 200).
- **Lección:** un cliente no oficial que **inicia** chats con desconocidos (y más recién vinculado) se considera sospechoso de spam. Es una causa **evitable**.
- **Política implementada (anti-restricción):**
  1. `WAHA_REQUIRE_USER_INITIATED=true` (por defecto): MOVA solo escribe a quien **escribió primero** al número (evento `message` entrante: se guarda únicamente el número, nunca el texto).
     El alta de avisos es «escribe ACTIVAR al +51 905 922 835»; el consentimiento y el opt-out de MOVA siguen aplicando encima.
  2. Calentamiento: tras una vinculación **nueva** (no tras un reinicio) `WAHA_WARMUP_MINUTES=1440` min sin iniciar chats (los que escribieron primero sí reciben respuesta).
  3. Con una restricción activa (`reachoutTimelock`/`messageCapping`) solo se responde a quien ya escribió.
  4. El mensaje de prueba del panel solo va a un destinatario que ya escribió al número: primero envía un «Hola» desde +51 916 666 934 al +51 905 922 835.
- **Si WhatsApp vuelve a revocar:** alerta crítica a los administradores (`mova:waha-watchdog`) y banner en `/admin/whatsapp`; nada se borra solo; se restablece desde el panel.

## Despliegue de esta funcionalidad (orden obligatorio)

1. Imagen nueva (tag `img-*` → workflow *Image (GHCR)*), digest fijo en `movap-web` y `movap-scheduler`.
2. **`php artisan migrate --force` inmediatamente** (crea `waha_state` y `waha_contacts`; aditiva): `docker/entrypoint.sh` nunca migra y `/readyz` falla con «Migraciones pendientes» hasta que corra.
   Si las tablas faltan, el código no lanza: avisa en el log (máx. 1 cada 5 min), bloquea los primeros mensajes (fail-closed) y descarta la restricción/estado recibidos hasta que existan.
3. Variables del vigilante en el scheduler: `WAHA_ADMIN_CONTROL_ENABLED=true`, `WAHA_BASE_URL`, secreto `WAHA_API_KEY`. `WHATSAPP_ENABLED` sigue en `false` (no envía nada).
4. Rollback: volver al digest anterior; las tablas nuevas pueden quedarse.

## Persistencia de la sesión

- NOWEB guarda la autenticación en `/app/.sessions/noweb/` (`waha.sqlite3` + WAL y archivos por sesión). En la VM es un **bind mount** `/var/lib/waha/sessions` (disco de la VM, no el
  sistema de archivos efímero del contenedor, ni la imagen, ni Git). Nombre de sesión estable: `default`. Directorio `0700`, ≈ 3.7 MB, 22 % del disco usado.
- **Reinicios:** `docker compose` con `restart: unless-stopped`, `waha.service` habilitado (systemd, `Restart=always`) y Docker habilitado: VM → Docker → WAHA carga la sesión del disco.
- **Qué puede cerrar la sesión** (y solo estas rutas): `WahaClient::logoutSession()`, llamado únicamente por la acción **Restablecer vinculación** del panel (POST, MFA reciente, confirmación escrita,
  bloqueado si está conectada); `pilot-teardown.sh`/*janitor* (borran la VM); nada más. Un GET, un health check o un error temporal **nunca** reinician ni desvinculan; el vigilante solo hace
  `start`/`restart` (que conservan las credenciales), con backoff.
- **Recuperación por estados (`mova:waha-watchdog`, cada 5 min):** WORKING → no toca; STARTING → espera; STOPPED → `start` oficial con backoff (10 min, 1 h, 6 h, 12 h; máx. 4 por episodio);
  FAILED → alerta crítica si estuvo conectada y un único `restart` por intento con el mismo backoff; SCAN_QR_CODE tras haber estado conectada → alerta de reautenticación; servidor inalcanzable → alerta
  tras 3 fallos seguidos. Nunca logout, nunca borrado.
- **Respaldo cifrado** (`infra/azure/waha/backup/`): `waha-session-backup.sh` (para WAHA ~10 s para un tar consistente, cifra con AES-256-CBC+PBKDF2, verifica el SHA-256 y que el tar descifre,
  conserva 7, **solo si la sesión está en WORKING**, aviso firmado al panel con huella y tamaño) y `waha-session-restore.sh` (se niega a restaurar con WAHA corriendo o sobre una sesión existente salvo
  `--replace`, verifica huella y descifrado antes de tocar nada, deja copia `.previo`). Temporizador semanal (domingo 03:30 UTC) instalado con `install-backup.sh`; clave en `/etc/waha/backup.key` (0600) y
  copia en Key Vault (`waha-session-backup-key`). Por defecto los respaldos **quedan en la VM** (`UPLOAD=0`): subirlos fuera exige un destino durable aprobado (ver `whatsapp-waha-costos-migracion.md`).
- **Probado:** la mecánica (3 respaldos con retención, cifrado sin texto claro, restauración idéntica bit a bit, rechazo de pisar una sesión, de un respaldo alterado y de una clave equivocada) con
  archivos de sesión simulados en un contenedor aislado. **No** se ha probado aún con una sesión real vinculada (la vinculación se perdió) ni la restauración sobre otra VM.

## Requisitos del teléfono principal y límites de WhatsApp

- El celular **no** actúa como servidor y puede estar apagado o sin Internet: el dispositivo vinculado (WAHA) sigue conectado de forma independiente.
- Sin embargo WhatsApp **desvincula** los dispositivos si el teléfono principal no se usa durante un periodo prolongado (14 días según el lanzamiento multidispositivo; algunas fuentes citan 30 días de inactividad del
  dispositivo vinculado). No se elude: abre WhatsApp en el celular +51 905 922 835 al menos una vez por semana.
- No hay garantía absoluta: Meta puede revocar credenciales, suspender el número o cambiar el protocolo (WAHA es no oficial). Para avisos a desconocidos la vía segura es la Cloud API oficial.

## Catálogo de eventos de WhatsApp (todos por `WhatsAppChannel`, sin datos de menores)

| # | Evento | Destinatario | Estado en código |
|---|---|---|---|
| 1 | Nueva solicitud de clase | profesores elegibles con consentimiento | `NewClassRequestNotification` |
| 3 | Propuesta de nuevo horario | padre | `CounterofferProposedNotification` |
| 5 | Familia rechaza el horario | profesor | `CounterofferRejectedNotification` (nuevo) |
| 6 | Clase confirmada | ambas partes | `ClassConfirmedNotification` |
| 7 | Recordatorios | ambas partes | `ClassReminderNotification` |
| 8 | Cancelación | partes afectadas | `ClassCancelledNotification` |
| 9 | Reprogramación | ambas partes | `ClassRescheduledNotification` |
| 11 | Créditos insuficientes (enfriamiento 6 h) | profesor | `InsufficientCreditsNotification` |
| 12 | Recarga aprobada | profesor | `RechargeApprovedNotification` |
| 13 | Recarga rechazada | profesor | `RechargeRejectedNotification` (nuevo, informativo) |
| 2, 4 | Aceptación del profesor / de la familia (la reserva queda confirmada) | ambas partes | cubiertos por `ClassConfirmedNotification` (listener `SendClassConfirmationNotifications`, tras la confirmación) |
| 10 | Profesor no disponible (cierre de cuenta) | padre | `TeacherClosedAccountNotification` (WhatsApp añadido) |

Con `WHATSAPP_ENABLED=false` **ninguno** envía; con él activo siguen aplicando consentimiento (`whatsapp_opt_in_at`/`whatsapp_opt_out_at`), teléfono verificado, suspensión, cuenta cerrada,
la política de «escribió primero», límites, deduplicado y ACK.

### Consentimiento en el perfil y encolado (commit `6089a0e`)

- **Mi perfil → Privacidad y cuenta → Notificaciones:** con `WHATSAPP_PROVIDER=waha` y `WAHA_EXPECTED_NUMBER`, quien activa los avisos ve el número oficial, la instrucción «escribe ACTIVAR» y un botón
  `wa.me/<número>?text=ACTIVAR`. La tarjeta pasa a «Chat con MOVA activado» cuando el evento entrante de ese teléfono verificado ya está registrado (`WahaSessionState::hasContacted`). Prop Inertia `whatsappChat`
  (`ProfileController::whatsappChat`); `null` con otro proveedor o sin número configurado (es el caso de producción hoy, proveedor `fake`).
- **Encolado seguro:** 12 notificaciones con WhatsApp llaman a `afterCommit()` en su constructor para que el worker no las procese antes de que existan los datos que las originan. Las de recargas
  (`RechargeApproved/Rejected`) no se tocaron: ya se despachan con `DB::afterCommit` desde `RechargeApprovalService`.
- **No hay aún:** prioridades/agrupación/ventanas de silencio entre avisos, ni deduplicado durable por evento/modelo/destinatario/versión más allá del existente de `WahaProvider`; categorías por evento en el perfil
  (hoy un único interruptor de WhatsApp). Son mejoras futuras, no bloqueantes.

## Estado y pendientes (cierre 2026-10-30)

- **Producción:** `6089a0e`, `movap-web`/`movap-scheduler` revisión `0000042`; `WHATSAPP_ENABLED=false`, `WHATSAPP_PROVIDER=fake`; panel `/admin/whatsapp` y vigilante activos (`WAHA_ADMIN_CONTROL_ENABLED=true`).
- **Sesión de WhatsApp:** la vinculación del +51 905 922 835 está **perdida** (`FAILED`); el propietario debe revincular desde el panel (Restablecer vinculación + QR).
- **Pruebas reales pendientes tras revincular y pasar el calentamiento:** reinicio de Docker, reinicio de la VM, celular sin Internet (lo hace el propietario), respaldo real y restauración en copia aislada,
  mensaje real con ACK, eventos con usuarios sintéticos.
- **Decisiones del propietario:** WAHA permanente (≈US$6–12/mes) o Cloud API para avisos a desconocidos; destino de respaldo fuera de la VM. La limpieza del piloto borrará la VM el 2026-11-01 04:25 UTC (+30 min de reserva).

## Pendiente de validar en el piloto (no demostrado)

Vinculación por QR, persistencia de la sesión tras reinicio, entrega y `message.ack` reales, RAM/CPU reales de NOWEB en 1 GiB,
arranque automático, ausencia de exposición de la API y **costo facturado real** (incluida la elegibilidad de las 750 h gratuitas,
que no se puede comprobar antes de crear la VM).

## Riesgos conocidos

Protocolo no oficial (riesgo de bloqueo del número y de términos de WhatsApp); Azure for Students es no comercial;
una VM única sin alta disponibilidad. La vía oficial es Meta Cloud API (ya implementada, `WHATSAPP_PROVIDER=meta`).
