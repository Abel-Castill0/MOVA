# MOVA — Azure IaC (Bicep)

**Fuente de verdad CURRENT:** [MOVA V1 Completion Ledger](../../docs/release/MOVA_V1_COMPLETION_LEDGER.md).
Este documento describe IaC y procedimientos; una plantilla no demuestra el
estado desplegado. Consulta de solo lectura de Azure, 2026-09-29:
`mova-web`, `mova-worker` y `mova-scheduler` tienen revisiones Healthy en el
entorno **STAGING** `mova-prod-rg`; web estaba ScaledToZero (min 0), worker
y scheduler tenían una réplica cada uno, y
`LESSON_SETTLEMENT_MODE=dry_run` en los tres roles. El nombre del resource
group y `APP_ENV=production` no demuestran que sea **PRODUCTION_LIVE**.
La asignación del **PUBLIC_APEX** y el estado **LEGACY** requieren una
verificación separada antes del cutover. Gates pendientes en el ledger.

## Archivos

| Archivo | Qué modela |
|---|---|
| `main.bicep` | Scope suscripción: RG `mova-prod-rg` (mexicocentral) + módulos |
| `main.bicepparam` | Parámetros de deploy. **Sin valores reales**: secretos vía env, falla si falta el admin password |
| `modules/network.bicep` | VNet `10.20.0.0/16`, `snet-aca` /23 (delegada a `Microsoft.App/environments`), `snet-mysql` /28 (delegada a `Microsoft.DBforMySQL/flexibleServers`), Private DNS MySQL |
| `modules/mysql.bicep` | MySQL Flexible Server privado, TLS obligatorio, sin HA, sin geo-backup |
| `modules/log-analytics.bicep` | Workspace PerGB2018, 30 días, tope 1 GB/día |
| `modules/acr.bicep` | ACR Basic/Standard, admin user deshabilitado |
| `modules/identity.bicep` | User-assigned MI + rol `AcrPull` (único RBAC) |
| `modules/aca-environment.bicep` | Environment en VNet, perfil Consumption, sin zone redundancy |
| `modules/container-app.bicep` | App genérica (misma imagen; rol = command/args + ingress) |

## Despliegue separado de foundation y apps

`main.bicep` solo modela **foundation**; `apps.bicep` es una plantilla aparte
con alcance de resource group. El deploy de apps exige una imagen real por
digest y no reutiliza la contraseña admin del servidor MySQL:

| Fase | Plantilla/paso | Crea | Exige |
|---|---|---|---|
| 1. Foundation | `main.bicep` + `main.bicepparam` | RG, VNet/subnets, Private DNS, Log Analytics, ACR, identity + AcrPull, MySQL, ACA environment | `MOVA_MYSQL_ADMIN_PASSWORD` |
| 2. Build + push | imagen | `docker build` → `<acr>.azurecr.io/mova@sha256:<digest>` | AcrPush del operador/pipeline |
| 3. Bootstrap DB | Job manual | `CREATE USER mova_app` + `GRANT` solo sobre `mova.*` | admin DB (solo en el Job) |
| 4. Migrations | paso manual revisado | `php artisan migrate --force` solo tras revisar todas las migraciones | `mova_app` o admin, dentro de la VNet |
| 5. Apps | `apps.bicep` + `apps.bicepparam` | `mova-web` y `mova-worker` por defecto; `mova-scheduler` solo con `MOVA_DEPLOY_SCHEDULER=true` | `MOVA_CONTAINER_IMAGE`, `MOVA_MYSQL_APP_PASSWORD`, `MOVA_APP_KEY` (el actual, **no regenerar**; fail-closed) |

Contrato de `containerImage`: **no hay fallback** en `apps.bicepparam`.
Sin `MOVA_CONTAINER_IMAGE`, `MOVA_MYSQL_APP_PASSWORD` (≥ 16) o
`MOVA_APP_KEY`, falla la compilación de parámetros, antes de cualquier
deployment. `appConfig` no puede sobrescribir los invariantes de `sharedEnv`
(van al final del `union`; el último argumento gana). `main.bicepparam`
falla si falta `MOVA_MYSQL_ADMIN_PASSWORD` (sin default).

`APP_URL`: vacío ⇒ `https://mova-web.<defaultDomain del ACA environment>` (primer staging). El dominio final se fija en el cutover vía `MOVA_APP_URL`.

### Scheduler y liquidación

`apps.bicepparam` toma `MOVA_DEPLOY_SCHEDULER` del entorno del operador
(`false` por defecto, solo acepta `true`/`false`). Desplegar scheduler no
activa liquidación: `MOVA_LESSON_SETTLEMENT_MODE` vale `dry_run` por defecto.
El modo `live` exige **ambos** `MOVA_LESSON_SETTLEMENT_MODE=live` y
`MOVA_LIVE_SETTLEMENT_ACK=I_ACKNOWLEDGE_LIVE_SETTLEMENT`. La plantilla
`apps.bicep` limita el modo a `dry_run`/`live` y lo fija fuera de `appConfig`.
El ACK solo protege contra activación accidental; no sustituye el gate
financiero, la revisión del ledger ni la autorización de cutover.

**STAGING:** si el operador necesita scheduler, selecciona
`MOVA_DEPLOY_SCHEDULER=true` y mantiene `dry_run`. **TARGET_PRODUCTION:**
selecciona `live` solo después de aprobar el gate, comprobar unicidad del
scheduler y detener la instancia **LEGACY** que pudiera duplicar trabajos.
Estas decisiones se toman en cada deploy; el archivo no refleja ni cambia
por sí mismo el estado actual de Azure.

## Integraciones: variables de despliegue (defaults seguros)

`apps.bicepparam` las lee del shell que ejecuta el deploy; ninguna activa nada por
sí sola y ninguna escribe un valor en el repositorio. Si falta algo obligatorio, el
resultado vuelve al estado seguro (nunca queda una integración a medio configurar).
Probado compilando con `bicep build-params` para cada combinación (2026-10-05).

| Variable | Default | Efecto |
|---|---|---|
| `MOVA_MAIL_MAILER` | `array` | `gmail_api` (requiere las 3 `MOVA_GMAIL_*`) o `smtp` (requiere `MOVA_MAIL_HOST`, `MOVA_MAIL_USERNAME`, `MOVA_MAIL_PASSWORD`, `MOVA_MAIL_FROM_ADDRESS`; opcionales `MOVA_MAIL_PORT`=587, `MOVA_MAIL_ENCRYPTION`=tls, `MOVA_MAIL_FROM_NAME`=MOVA). Cualquier otro valor → `array`. |
| `MOVA_PAYMENTS_MODE` | `off` | `sandbox` enciende `PAYMENTS_ENABLED`, `PAYMENT_PROVIDER=mercadopago`, `RECHARGES_ENABLED` y `MERCADOPAGO_WEBHOOKS_ENABLED` **solo si** están las credenciales completas de Mercado Pago (incluidos `MOVA_MERCADOPAGO_APPLICATION_ID`, `…_EXPECTED_COLLECTOR_ID` y `…_WEBHOOK_SECRET`) y `MOVA_MERCADOPAGO_EXPECTED_LIVE_MODE=false`. No existe un modo `live` en esta plantilla: cobrar de verdad exige una decisión y un cambio explícitos del titular. |
| `MOVA_JAAS_WEBHOOKS_ENABLED` + `MOVA_JAAS_WEBHOOK_SIGNING_SECRET` / `MOVA_JAAS_WEBHOOK_AUTH_TOKEN` | `false` | Activa el receptor de presencia `/api/webhooks/jaas` **solo si** JaaS está completo, hay al menos un secreto y vale `true`. Solo guarda evidencia (`lesson_presence_events`); no decide asistencia ni toca créditos. **Dos secretos distintos:** `SIGNING_SECRET` es el que genera JaaS por endpoint (consola → endpoint → «Reveal secret») y verifica `X-Jaas-Signature` (HMAC-SHA256, recomendado); `AUTH_TOKEN` lo inventas tú y se carga en la consola como header `Authorization: Bearer <token>` (opcional; si hay ambos se exigen ambos). Eventos: `PARTICIPANT_JOINED` y `PARTICIPANT_LEFT`. |
| `MOVA_MAIL_ALLOWLIST` | vacío | **Staging**: correos exactos o dominios (`@dominio`) separados por comas; el correo SOLO sale hacia ellos (`App\Support\MailAllowlist`). Obligatoria en un staging con cuentas existentes antes de usar un mailer real. Vacía en producción. |
| `MOVA_SENTRY_ENVIRONMENT` | `staging` | Etiqueta de los eventos de Sentry (solo si hay DSN). En el deploy de producción: `production`. Sin etiqueta, staging contaminaría el proyecto de producción porque `APP_ENV=production` también en staging. |

Un cambio de estas variables es un nuevo deploy de las apps (revisión nueva): no
se aplica a mano sobre `az containerapp update`, para que la plantilla siga siendo
la fuente de verdad y no haya deriva.

## Identidades de base de datos

| Identidad | Uso | Dónde vive |
|---|---|---|
| `mysqlAdminUser` / `mysqlAdminPassword` | crear el servidor; Job de bootstrap/migración | parámetro `@secure()`; **nunca** se inyecta en web/worker/scheduler |
| `mysqlAppUser` = `mova_app` / `mysqlAppPassword` | runtime de las tres apps (`DB_USERNAME` / secret `DB_PASSWORD`) | `runtimeSecrets` → secret de Container Apps |

Bootstrap (diseño AZ-3, sin implementar): un **Container Apps Job manual**, temporal e idempotente, en el mismo environment (dentro de la VNet) con la misma imagen MOVA. Es el único lugar donde existen las credenciales admin. No sustituye a `mova-scheduler` (que sigue siendo Container App persistente 1..1).

```sql
CREATE USER IF NOT EXISTS 'mova_app'@'%' IDENTIFIED BY '<MOVA_MYSQL_APP_PASSWORD>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES, LOCK TABLES ON `mova`.* TO 'mova_app'@'%';
```
Sin privilegios server-wide.

## TLS cliente → MySQL

`require_secure_transport=ON` en el servidor. Las apps reciben `MYSQL_ATTR_SSL_CA=/etc/ssl/certs/ca-certificates.crt` (bundle de root CAs del sistema en la imagen FrankenPHP; verificado: 150 raíces, `/usr/lib/ssl/cert.pem` apunta a él). Se confía en las raíces, no en intermedias concretas, para tolerar rotaciones de Azure. Verificación de certificado **activada**; nunca `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=false` ni `require_secure_transport=OFF`.

## Invariantes

- **Misma imagen (mismo SHA)** para `mova-web`, `mova-worker`, `mova-scheduler`.
- **`mova-scheduler`: min = max = 1 réplica cuando se despliega.** Dos `schedule:work` duplicarían recordatorios y reconciliaciones. No subir `maxReplicas`.
- **`mova-worker`: min = max = 1** en esta etapa (sin KEDA/autoscaling por cola).
- `QUEUE_CONNECTION=SESSION_DRIVER=CACHE_DRIVER=database` — el disco del contenedor es efímero; los locks de `withoutOverlapping()` necesitan un store compartido.
- **Nunca `migrate --force` en el entrypoint.** Migraciones = paso explícito y único por deploy (AZ-3).
- **STOP de rollback de consentimiento:** después de crear filas reales en `student_data_consents`, `down()` de `2026_09_29_000001_create_student_data_consents_table.php` borraría evidencia real. No revertir esa migración en TARGET_PRODUCTION; detener el procedimiento, preservar/verificar los datos de auditoría y decidir una recuperación supervisada. No inferir consentimientos de alumnos históricos.
- MySQL **sin IP pública**; `require_secure_transport=ON`.
- Pull de imágenes por Managed Identity; **sin usuario/contraseña de registry**.
- La contraseña admin de MySQL **nunca** llega a web/worker/scheduler; el runtime usa `mova_app`.

## Pendientes marcados para AZ-3

- `B1MS_AVAILABILITY_PENDING_AZ3B`: `az mysql flexible-server list-skus -l <region>` sigue devolviendo 500 (cuarta vez consecutiva, AZ-1/AZ-2/AZ-3A/AZ-3A-R; probado también vía `az rest` directo contra varias `api-version` y contra varias regiones como control — falla igual, así que es un problema de esta suscripción/API, no de una región concreta). Tampoco `what-if` sirve como prueba de capacidad real: pasó limpio para `brazilsouth` y el deployment real de MySQL falló igual con `ProvisionNotSupportedForRegion`. La única prueba fiable es el resultado del deployment real.
- **Región reubicada en AZ-3A-R**: `brazilsouth` fue rechazada por esta suscripción Azure for Students con `ProvisionNotSupportedForRegion` al crear el MySQL Flexible Server real (el resto de la foundation — RG, VNet, ACR, Identity, ACA Environment, Log Analytics — sí se creó ahí sin problema; se eliminó por completo tras el fallo). Región vigente: `mexicocentral`, elegida por intersección real entre la Azure Policy "Allowed resource deployment regions" de la suscripción (`westus`, `mexicocentral`, `canadacentral`, `northcentralus`, `brazilsouth`) y las regiones soportadas por los 4 providers de MOVA — las 4 no-Brazil pasan la intersección; `mexicocentral` es la más cercana a Perú.
- `mysqlVersion`: confirmado `8.4` (AZ-2: dump real 9.4→8.4 con paridad de esquema/datos exacta y `migrate --force` 91/91 desde cero sobre el snapshot rescatado de Railway). Ya no es provisional.
- Evidencia AZ-2.1: la imagen de producción (`mova:az2`, **sin** `doctrine/dbal`) ejecutó `migrate --force` desde cero sobre MySQL 8.0.46 efímero: 91 migraciones aplicadas, 0 pendientes; los `->change()` usan ALTER nativo. `doctrine/dbal` NO es dependencia de producción.
- `acrSku`: `Basic` — el beneficio Standard de 12 meses no se pudo confirmar para esta suscripción (mismo problema de API que el punto anterior), así que se mantiene el valor conservador.
- `containerImage`: suministrar `<acr>.azurecr.io/mova@sha256:<digest>` vía `MOVA_CONTAINER_IMAGE` en la fase apps (sin placeholder).
- Providers `Microsoft.App`, `Microsoft.ContainerRegistry`, `Microsoft.OperationalInsights`, `Microsoft.ManagedIdentity`, `Microsoft.Network` registrados en AZ-3A (junto con `Microsoft.DBforMySQL`, ya Registered desde antes).
- GitHub Actions + OIDC (fase posterior; no se inventan permisos aquí).

## Clasificación de configuración

| Clase | Ejemplos | Dónde vive |
|---|---|---|
| APP CONFIG | `APP_URL`, `APP_ENV`, drivers, `DB_HOST/PORT/DATABASE/USERNAME` | `sharedEnv` / `appConfig` (texto plano) |
| APP SECRET | `APP_KEY` (migrar **exactamente**, no regenerar), `DB_PASSWORD`, `CLOUDINARY_URL`, credenciales Meta/Google/Gmail/Mercado Pago/JaaS/Pusher/Sentry | `appSecrets` → secrets de Container Apps (`secretRef`) |
| INFRA SECRET | `mysqlAdminPassword`, shared key de Log Analytics | Parámetro `@secure()` / `listKeys()` en deploy; nunca en el repo |

## Validación histórica AZ-2 y comprobación C1.1

En AZ-2 se compiló `main.bicep` y se hizo what-if de foundation; ese
resultado histórico no prueba el estado desplegado hoy. En C1.1 se compiló
`apps.bicep` y se evaluó `apps.bicepparam` con valores de prueba, sin deploy:
default ⇒ scheduler `false` y `dry_run`; scheduler `true` + `dry_run` ⇒
`dry_run`; modo `live` sin ACK ⇒ rechazo; modo `live` con ACK explícito ⇒
compilación. `git diff --check` pasó. No se ejecutó what-if ni deployment
contra Azure en C1.1.

## Estado operativo de staging (2026-10-05) y reglas aprendidas

- Deploy actual: imagen `mova@sha256:09d64d9f…` (construida del árbol de trabajo sin commit) en
  web/worker/scheduler. Imagen previa de web para rollback: `sha256:cfcf0105…`.
- **Las migraciones no corren al arrancar.** `/readyz` devuelve 503 mientras haya migraciones
  pendientes, así que una revisión nueva no se activa (la anterior sigue sirviendo). Orden seguro:
  actualizar worker a la imagen nueva → `php artisan migrate:status --pending` y `migrate --force`
  desde ahí tras revisar los archivos → crear revisión nueva de web.
- **Un secreto guardado no basta:** Container Apps exige una revisión nueva para cargarlo
  (`az containerapp update --set-env-vars ...` con cualquier cambio inocuo). Verifica
  `latestReadyRevisionName` tras cada cambio.
- `az containerapp exec` tiene límite de tasa (429, espera de 10 min) y no admite comillas
  fiables: pasar el código PHP en base64 por `$argv` (`php -r eval(gzinflate(base64_decode($argv[1])));`).
- Secreto de firma de JaaS: se obtiene en la consola (endpoint → «Reveal secret», formato
  `whsec_…`) y se carga como secreto `jaas-webhook-signing-secret` →
  `JAAS_WEBHOOK_SIGNING_SECRET`. Nunca por el chat ni por archivos versionados.
- Mercado Pago está en **sandbox** en staging (`PAYMENT_PROVIDER=mercadopago`, `PAYMENTS_ENABLED=true`,
  `MERCADOPAGO_EXPECTED_LIVE_MODE=false`, credenciales `TEST-`, recargas apagadas). **Cada rol** que procese
  pagos (web, worker y scheduler) necesita el secreto Y la variable de entorno que lo referencia
  (`MERCADOPAGO_ACCESS_TOKEN=secretref:mercadopago-access-token`, `MERCADOPAGO_WEBHOOK_SECRET=secretref:…`):
  un secreto sin variable deja al worker con `fetchPayment` fallando en cerrado y los webhooks en `failed`.
  Si pasa, `php artisan mercadopago:reconcile` los reprocesa sin duplicar abonos.
- **Checkout automático acotado:** `MERCADOPAGO_CHECKOUT_ALLOWLIST` (correos separados por comas) limita quién
  puede usar el pago de Mercado Pago; vacía = todos (producción). `RECHARGES_ENABLED` solo gobierna el flujo
  manual, NO este checkout: con pagos sandbox encendidos y sin allowlist, todos los profesores de staging
  pueden generar créditos de prueba. Estado de cierre del 2026-10-05: un correo inexistente (checkout cerrado
  para todos, webhooks y recuperación activos); para probar, poner el correo del profesor QA y crear revisión
  nueva en web, worker y scheduler.
