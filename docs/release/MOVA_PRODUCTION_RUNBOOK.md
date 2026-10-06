# MOVA — Runbook de producción (Azure)

> Estado (2026-10-06): producción **sana y sirviendo `https://movaeduca.me` con TLS, pero con correo real verificado, sin integraciones live**. Este documento no
> contiene secretos: solo nombres, destinos y procedimientos. Estados según el ledger
> (`LOCAL_TEST` / `LIVE_STAGING` / `PRODUCTION_LIVE`): lo descrito aquí como «construido» es `PRODUCTION_LIVE`
> **solo para infraestructura y salud**; ninguna integración externa está activa en producción.

## 1. Topología construida (2026-10-05)

| Capa | Staging | Producción |
|---|---|---|
| Apps (Container Apps) | `mova-web` (min 0), `mova-worker`, `mova-scheduler` | `movap-web` (**min 1**), `movap-worker`, `movap-scheduler` (**1 solo**) |
| Base de datos | servidor `mova-mysql-splisbj6ldoqw`, BD `mova`, usuario `mova_app` | **servidor dedicado** `mova-prod-mysql-8uxzgq` (MySQL 8.4, B1ms, privado, TLS), BD `mova`, usuario `movap_app` (solo DML+DDL sobre `mova.*`), PITR **14 días** |
| Subred / DNS privada | `snet-mysql` | `snet-mysql-prod` (10.20.2.16/28), misma zona privada |
| Secretos | secretos de las apps `mova-*` | secretos propios de `movap-*` (APP_KEY nueva, contraseña de BD propia) |
| Entorno ACA / VNet / ACR / identidad / logs | `mova-aca-env`, `mova-vnet`, ACR, `mova-apps-identity`, `mova-logs` en `mova-prod-rg` | **compartidos** (ver restricción) |
| Dominio | `staging.movaeduca.me` | `movaeduca.me` (apex y `www` ya sirven producción) |

**Restricción real (suscripción Azure for Students):** máximo **1 Container Apps Environment en total**
(`MaxNumberOfGlobalEnvironmentsInSubExceeded`; verificado también en `canadacentral`). Por eso producción comparte
entorno ACA, VNet, ACR y workspace de logs con staging. El resource group `mova-prod-rg` es la *plataforma
compartida* pese a su nombre. Consecuencias: (1) la IP de ingreso es la misma que la de staging
(`68.155.88.233`), pero el enrutamiento es por host (`movaeduca.me` → `movap-web`, `staging.movaeduca.me` →
`mova-web`); (2) una caída del entorno afectaría a ambos. **Recomendación:** al pasar a una suscripción de pago,
crear un entorno ACA propio para producción y mover `movap-*` (IaC ya parametrizado: `MOVA_PREFIX`,
`MOVA_APP_NAME_PREFIX`, `MOVA_MYSQL_SERVER_NAME`, `MOVA_LOCATION`).

**Costo y crédito (lectura del 2026-10-06):** US$ 86 de 100, vence 14/09/2027 (sostenible ≈ US$ 7,6/mes). Gasto real hasta
el 5/oct: US$ 2,23 (la facturación de producción llega con 24–48 h de retraso). Modelo con precios de lista y uso medido:
MySQL ≈ 17,7/mes por servidor (B1ms 0,0187 US$/h + 32 GB), Container Apps de producción ≈ 12–18, ACR ≈ 5,1, IP pública ≈ 3,5,
DNS ≈ 0,5 ⇒ **≈ US$ 39–45/mes (≈ 26–31 sin el MySQL de staging)** ⇒ el crédito dura ≈ 2–3 meses. Medir con `az rest` →
`Microsoft.CostManagement/query` (cuerpo en archivo, cabecera `Content-Type: application/json`, agrupar por `ResourceId`) cuando
aparezca producción. Aplicado: staging `worker`/`scheduler` a 0 réplicas (sin colas ni tareas programadas en staging; revertir con
`--min-replicas 1`) y `movap-web` a 0,25 vCPU/0,5 GiB. Presupuesto `mova-monthly-20`: solo informa. MySQL de staging **detenido** el 2026-10-06 (cede la capa gratuita al de producción; Azure lo
reinicia a los 7 días ⇒ detenerlo de nuevo con `az mysql flexible-server stop -g mova-prod-rg -n mova-mysql-splisbj6ldoqw`, o arrancarlo con
`start` para probar staging).

## 1b. Staging sin base de datos (eliminada el 2026-10-06) y cómo recuperarla

Se eliminó el MySQL de staging (`mova-mysql-splisbj6ldoqw`; datos 100 % sintéticos y recreables) para conservar el crédito de Azure.
Las apps `mova-web|worker|scheduler` siguen definidas (a 0 réplicas) y conservan sus secretos (`db-password` de `mova_app`, firma
de Mercado Pago de la aplicación de staging, etc.). Respaldo: `storage/app/staging-export-2026-10-06.json.gz` (JSON por tabla, local e
ignorado por git). Para recrear staging: (1) crear un servidor MySQL 8.4 B1ms privado en `snet-mysql` con el módulo `modules/mysql.bicep`
(o `main.bicep`, que pide `MOVA_MYSQL_ADMIN_PASSWORD` nuevo) y la zona privada `mova.private.mysql.database.azure.com`; (2) con un Job
manual crear la base `mova` y el usuario `mova_app` con la contraseña del secreto `db-password` de staging; (3) `php artisan migrate --force`
y, **solo en staging**, `db:seed --class=LocalTestDataSeeder` (o importar el JSON del respaldo); (4) `az containerapp update -n mova-worker|mova-scheduler
--min-replicas 1`. No usar nunca esos seeders en producción.

## 2. Despliegue (procedimiento reproducible)

0. Variables de aplicación imprescindibles: `APP_NAME=MOVA` (sin él los correos salen con la marca «Laravel»).
1. Construir la imagen del árbol validado y subirla al ACR; usar siempre el **digest** (`mova@sha256:…`).
2. Variables de shell (no versionadas) y `apps.bicepparam`:
   `MOVA_CONTAINER_IMAGE`, `MOVA_PREFIX=mova`, `MOVA_APP_NAME_PREFIX=movap`, `MOVA_MYSQL_SERVER_NAME`,
   `MOVA_WEB_MIN_REPLICAS=1`, `MOVA_MYSQL_APP_USER=movap_app`, `MOVA_MYSQL_APP_PASSWORD`, `MOVA_APP_KEY`,
   `MOVA_DEPLOY_SCHEDULER=true`, `MOVA_LESSON_SETTLEMENT_MODE=dry_run`, `MOVA_SENTRY_ENVIRONMENT=production`.
3. `bicep build-params` + `az deployment group create -g mova-prod-rg --template-file apps.json`.
4. **Migraciones** (paso explícito, nunca en el arranque): Container Apps *Job* manual con la misma imagen,
   `php artisan migrate --force` + `db:seed --class=RoleSeeder` + `--class=SubjectSeeder` (solo datos de
   referencia). Se eliminó el job al terminar. No se ejecutó ningún seeder de usuarios ni de QA.
5. Verificar `/healthz`, `/readyz` (`{"status":"ready"}`), `/login`, `/register`.

Notas aprendidas: en una base **vacía** el entrypoint fallaba (`optimize:clear` contra caché en MySQL) y dejaba
los contenedores en bucle; ahora es tolerante y `/readyz` sigue en 503 hasta migrar.

## 3. Respaldos y restauración

- PITR automático, retención **14 días**, sin geo-redundancia (no disponible/costoso en esta suscripción).
- **Prueba real de restauración (2026-10-05):** PITR a 20:15:45Z hacia `mova-prod-restore-drill` (iniciado 20:17Z, `Ready` ≈ 21:08Z → **RTO ≈ 50 min** para 32 GB en B1ms). Verificado con un job de solo lectura: 102 migraciones, 42 tablas, 3 roles, 6 materias. Servidor de prueba eliminado.
- Procedimiento: `az mysql flexible-server restore -g mova-prod-rg -n <nuevo> --source-server mova-prod-mysql-8uxzgq
  --restore-time <UTC> --subnet <snet-mysql-prod> --private-dns-zone <zona>`; validar tablas/roles/materias con un
  job; repuntar `DB_HOST` de las apps (o conservar el nombre original) y eliminar el servidor de prueba.

## 4. DNS y cutover de `movaeduca.me` (Namecheap)

**Valores actuales (rollback), capturados 2026-10-05 vía DNS público:**

| Nombre | Tipo | Valor |
|---|---|---|
| `movaeduca.me` | A | 185.199.108.153, 185.199.109.153, 185.199.110.153, 185.199.111.153 (GitHub Pages) |
| `www.movaeduca.me` | CNAME | `movaeduca.me` |
| `movaeduca.me` | MX | 10 eforward1/2/3, 15 eforward4, 20 eforward5 `.registrar-servers.com` (reenvío Namecheap) |
| `movaeduca.me` | TXT | `v=spf1 include:spf.efwd.registrar-servers.com ~all` |
| `staging.movaeduca.me` | CNAME | `mova-web.whitecliff-88cda913.mexicocentral.azurecontainerapps.io` |
| `asuid.staging.movaeduca.me` | TXT | (verificación del entorno ACA) |

**Estado 2026-10-06:** apex `A` → `68.155.88.233`, `www` CNAME → `movap-web`; MX (`eforward1–5`), SPF y TXT sin cambios; los
registros de GitHub Pages ya no existen. Apex y `www` enlazados con certificado gestionado en `movap-web` (`az containerapp
hostname add` + `bind --validation-method HTTP`); `www` → apex 301; `/healthz` y `/readyz` 200; `noindex` activo.
**Pendiente:** confirmar que el registrante que figura en Namecheap es la persona autorizada antes de editar más registros.
Rollback (si hiciera falta volver): `A @` → 185.199.108.153, 185.199.109.153, 185.199.110.153, 185.199.111.153 (GitHub Pages)
y `www` CNAME → `movaeduca.me`; quitar el enlace con `az containerapp hostname delete -n movap-web --hostname movaeduca.me`.

## 5. Credenciales de producción (el propietario las rota/carga al final)

Nada de lo siguiente está cargado en producción. **Nunca por el chat**: usar `az containerapp secret set` +
variable de entorno `secretref:` en **cada** rol que la use (web, worker, scheduler), y crear una revisión nueva.

| Integración | Variables / secretos (nombres exactos) | Dónde se obtienen | Prueba posterior |
|---|---|---|---|
| Mercado Pago **live** (webhook productivo ya registrado y `MERCADOPAGO_WEBHOOK_SECRET` cargado en los 3 roles; faltan las credenciales live) | `MERCADOPAGO_ACCESS_TOKEN` (secreto), `MERCADOPAGO_PUBLIC_KEY`, `MERCADOPAGO_APPLICATION_ID`, `MERCADOPAGO_EXPECTED_COLLECTOR_ID`, `MERCADOPAGO_EXPECTED_LIVE_MODE=true`, `MERCADOPAGO_WEBHOOK_SECRET` (secreto), `MERCADOPAGO_WEBHOOKS_ENABLED`, `PAYMENT_PROVIDER=mercadopago`, `PAYMENTS_ENABLED`, `MERCADOPAGO_CHECKOUT_ALLOWLIST` | Panel MP Developers → credenciales de **producción** de la app; Webhooks → modo productivo con `https://movaeduca.me/api/webhooks/mercadopago` | Con allowlist = cuenta del propietario: un cobro real mínimo + reembolso, solo con importe aprobado por el propietario |
| Correo (**cargado y verificado 2026-10-06**; contraseña de aplicación «MOVA produccion») | `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`, `MAIL_USERNAME` (secreto `mail-username`), `MAIL_PASSWORD` (secreto `mail-password`), `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Proveedor SMTP / contraseña de aplicación de Gmail | Registro + verificación + recuperación a un buzón propio |
| JaaS (**clave de producción `…/eb14cb`; webhook de presencia de producción ACTIVO desde 2026-10-06**: endpoint `https://movaeduca.me/api/webhooks/jaas` (JOINED/LEFT) con su propia clave de firma en `jaas-webhook-signing-secret` y `JAAS_WEBHOOKS_ENABLED=true`; verificado con una reunión sintética real: 4 entregas firmadas «Successful»; sin firma ⇒ 401; el endpoint de staging se eliminó de JaaS porque staging no tiene base: volver a añadirlo al recrearlo) | `JAAS_APP_ID`, `JAAS_KEY_ID`, `JAAS_PRIVATE_KEY` (secreto, base64 PEM en una línea), `JAAS_WEBHOOKS_ENABLED`, `JAAS_WEBHOOK_SIGNING_SECRET` (secreto) | Consola JaaS: API key propia de producción; Webhooks → `https://movaeduca.me/api/webhooks/jaas` | Reunión de dos participantes + eventos de presencia |
| Cloudinary (**clave `mova-produccion` con rol Master Admin; subida/lectura/borrado verificados 2026-10-06; `CLOUDINARY_FOLDER=mova-prod/avatars`**) | `CLOUDINARY_URL` (secreto) | Cloud/API key propios de producción | Subida y borrado de un avatar sintético |
| Sentry (**evento sintético de producción confirmado en el panel (issue `MOVA-PRODUCTION-1`, 2026-10-06); además el panel mostró `MOVA-PRODUCTION-2` (`mova:health-check --alert` salió con código 1 mientras había alertas abiertas; ya sin nuevas ocurrencias) y dos errores causados por mis scripts de verificación**) | `SENTRY_LARAVEL_DSN` (secreto), `SENTRY_ENVIRONMENT=production` | Proyecto propio de producción (recomendado) | Un evento sintético con entorno `production` |
| Google Login (opcional V1) | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_LOGIN_ENABLED` | Google Cloud Console; redirect `https://movaeduca.me/auth/google/callback` | Login con una cuenta de prueba |
| Datos legales (no secretos; **cargados y verificados 2026-10-06**) | `LEGAL_BUSINESS_NAME`, `LEGAL_RUC`, `LEGAL_ADDRESS`, `LEGAL_SUPPORT_EMAIL` (vía `MOVA_LEGAL_*` en `apps.bicepparam`) | Titular (razón social, RUC, domicilio) | `mova:health-check` sin `LEGAL_PROVIDER_DATA_MISSING` |
| Cuenta admin | (se crea sin contraseña conocida) | — | Recuperar contraseña por correo y activar MFA |

**Rotar antes de activar integraciones live (estado 2026-10-06):**
1. Clave de firma del webhook de Mercado Pago **de producción** (aplicación `4497812261072016`): ya es distinta de la de staging
   (aplicación `6583217782927097`, renovada el 2026-10-06), pero se mostró un instante al copiarla: regenerar con «Restablecer firma
   secreta» → «Redefinir clave» y actualizar `mercadopago-webhook-secret` en los 3 roles justo antes de habilitar
   `MERCADOPAGO_WEBHOOKS_ENABLED`. El panel pide reverificar la identidad por SMS cada pocos minutos: hacer todos los pasos seguidos.
2. DSN de Sentry de producción (`sentry-laravel-dsn`): pasó por un archivo temporal local ya eliminado; rotación opcional.
3. Token de acceso y clave pública live de Mercado Pago, contraseña de aplicación de correo, clave API de JaaS y
   `CLOUDINARY_URL` de producción: cargarlos al crearlos, nunca por el chat.
4. Credencial histórica del incidente F-26 (MySQL de Railway en el historial de git): sigue abierta y separada.

Regla de oro de rotación: **cada entorno con credenciales distintas** (staging nunca comparte clave con producción; la firma del
webhook de Mercado Pago ya está aislada con una aplicación por entorno).
Tras cargar cada grupo: nueva revisión de los tres roles, `/readyz`, y el smoke de la tabla.

## 5a. Persistencia de la configuración en despliegues futuros

Las variables y secretos de integraciones (correo, JaaS, Mercado Pago, Cloudinary, datos legales…) se cargaron con `az containerapp secret set` /
`update --set-env-vars`. **Un `az deployment group create` completo con `apps.bicep` reemplaza las variables de entorno de las apps con las del
template**, así que solo conserva lo que el template define. Reglas: (1) para publicar una versión nueva usar solo
`az containerapp update --image <acr>/mova@sha256:…` en los tres roles (como en este runbook); (2) si alguna vez se reaplica `apps.bicep`, cargar antes
`set -a; . infra/azure/production.local.env; set +a` (datos legales del titular, archivo local **ignorado por git**; `apps.bicepparam` los toma como
`MOVA_LEGAL_*`) y volver a aplicar las integraciones listadas en §5 (los secretos viven en la app y se mantienen mientras exista el nombre del secreto).

## 5c. Prueba live mínima (S/ 1) y reembolso

Estado: cuenta docente de prueba del propietario con checkout live restringido (allowlist) y paquete «Verificación de cobro» (S/ 1,00).
1. El titular restablece la contraseña de la cuenta docente de prueba («Olvidé mi contraseña»), inicia sesión → **Mis créditos** → paquete
   «Verificación de cobro» → paga con **Yape** (su teléfono y el código de su app; MOVA nunca ve esos datos) y espera «acreditado».
2. Comprobar: orden `paid`, un depósito, 1 crédito, un solo webhook procesado (sin duplicados), importe 1,00 PEN.
3. **Reembolso** desde el panel de Mercado Pago (Actividad → el pago → Devolver dinero) o por API; el webhook de reembolso debe producir **un** `reversal` y
   dejar el saldo de la cuenta de prueba en 0. Registrar comisión retenida.
4. Cerrar: `PAYMENTS_ENABLED=false`, `MERCADOPAGO_WEBHOOKS_ENABLED=false`, `CREDITS_VERIFICATION_PACKAGE_ENABLED=false` (o dejar el checkout público solo cuando la
   política de reembolsos esté publicada y se vacíe `MERCADOPAGO_CHECKOUT_ALLOWLIST`). Nunca más de una prueba.

## 5b. Crear el administrador (cuando el titular elija el correo)

Usar un buzón real del titular, sin alias `+` ni dominios de prueba (el comando rechaza `*.test`, `example.*`). Con la imagen de
producción, desde un Job manual o `az containerapp exec -n movap-web`:

```
php artisan mova:create-admin <correo-del-titular> --name="<Nombre>"
```

Crea la cuenta verificada con contraseña aleatoria desconocida; el titular usa «Olvidé mi contraseña» (correo ya operativo) y el
primer ingreso al panel exige configurar MFA. No ejecutar ningún seeder en producción.

## 6. Gates de lanzamiento pendientes (no técnicos)

Consentimiento de menores y tratamiento/transmisión internacional de datos (ANPD), reglas de asistencia/disputas,
clases recurrentes, verificación documental de profesores y política de reembolsos/deuda: decisiones del
propietario/legal. No se declara producción «100 % lista» sin ellas.
