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

**Costo y crédito (lectura del 2026-10-06):** crédito restante US$ 86, vence 14/09/2027 ⇒ gasto sostenible ≈ US$ 7,6/mes.
Gasto real: septiembre ≈ US$ 12,3 (solo staging); octubre 1–5 ≈ US$ 2,23. Previsión del portal US$ 19,22/mes, que **aún no
incluye** el MySQL de producción ni las réplicas siempre activas de `movap-*`. Estimación con producción completa
≈ US$ 30–38/mes (MySQL de producción ≈ 16; `movap-*` ≈ 8–10; ACR ≈ 5; IP pública del entorno ≈ 3,5; staging ≈ 3–5).
Con eso el crédito alcanza ≈ 2,5–3 meses; al agotarse la suscripción Student se deshabilita. Medir con la API de Cost
Management (`az rest` → `Microsoft.CostManagement/query`, agrupando por `ResourceId`) cuando la facturación de producción
aparezca. Ahorro aplicado: staging `worker`/`scheduler` a 0 réplicas (`az containerapp update --min-replicas 1` para volver).
Presupuesto `mova-monthly-20` (US$ 20/mes) con alertas al 50 %/80 % reales y 100 % previsto: informa, no limita el gasto.

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
| JaaS | `JAAS_APP_ID`, `JAAS_KEY_ID`, `JAAS_PRIVATE_KEY` (secreto, base64 PEM en una línea), `JAAS_WEBHOOKS_ENABLED`, `JAAS_WEBHOOK_SIGNING_SECRET` (secreto) | Consola JaaS: API key propia de producción; Webhooks → `https://movaeduca.me/api/webhooks/jaas` | Reunión de dos participantes + eventos de presencia |
| Cloudinary | `CLOUDINARY_URL` (secreto) | Cloud/API key propios de producción | Subida y borrado de un avatar sintético |
| Sentry | `SENTRY_LARAVEL_DSN` (secreto), `SENTRY_ENVIRONMENT=production` | Proyecto propio de producción (recomendado) | Un evento sintético con entorno `production` |
| Google Login (opcional V1) | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_LOGIN_ENABLED` | Google Cloud Console; redirect `https://movaeduca.me/auth/google/callback` | Login con una cuenta de prueba |
| Datos legales (no secretos) | `LEGAL_BUSINESS_NAME`, `LEGAL_RUC`, `LEGAL_ADDRESS`, `LEGAL_SUPPORT_EMAIL` (vía `MOVA_LEGAL_*` en `apps.bicepparam`) | Titular (razón social, RUC, domicilio) | `mova:health-check` sin `LEGAL_PROVIDER_DATA_MISSING` |
| Cuenta admin | (se crea sin contraseña conocida) | — | Recuperar contraseña por correo y activar MFA |

**Rotar antes de activar integraciones live (estado 2026-10-06):**
1. Clave de firma del webhook de Mercado Pago: es **una sola por aplicación** (modo de prueba y productivo coinciden, o sea
   compartida con staging) y se mostró en la interfaz: regenerar en el panel y actualizar `mercadopago-webhook-secret` en los
   3 roles de producción y el secreto de staging. Hoy está inactiva (`MERCADOPAGO_WEBHOOKS_ENABLED=false`).
2. DSN de Sentry de producción (`sentry-laravel-dsn`): pasó por un archivo temporal local ya eliminado; rotación opcional.
3. Token de acceso y clave pública live de Mercado Pago, contraseña de aplicación de correo, clave API de JaaS y
   `CLOUDINARY_URL` de producción: cargarlos al crearlos, nunca por el chat.
4. Credencial histórica del incidente F-26 (MySQL de Railway en el historial de git): sigue abierta y separada.

Regla de oro de rotación: **cada entorno con credenciales distintas** (staging nunca comparte clave con producción;
excepción conocida y pendiente: la clave de firma del webhook de Mercado Pago, única por aplicación).
Tras cargar cada grupo: nueva revisión de los tres roles, `/readyz`, y el smoke de la tabla.

## 6. Gates de lanzamiento pendientes (no técnicos)

Consentimiento de menores y tratamiento/transmisión internacional de datos (ANPD), reglas de asistencia/disputas,
clases recurrentes, verificación documental de profesores y política de reembolsos/deuda: decisiones del
propietario/legal. No se declara producción «100 % lista» sin ellas.
