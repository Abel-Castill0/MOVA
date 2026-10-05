# MOVA — Runbook de producción (Azure)

> Estado: producción **aprovisionada y sana, aún sin tráfico público ni integraciones live**. Este documento no
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
| Dominio | `staging.movaeduca.me` | `movaeduca.me` (pendiente de cutover) |

**Restricción real (suscripción Azure for Students):** máximo **1 Container Apps Environment en total**
(`MaxNumberOfGlobalEnvironmentsInSubExceeded`; verificado también en `canadacentral`). Por eso producción comparte
entorno ACA, VNet, ACR y workspace de logs con staging. El resource group `mova-prod-rg` es la *plataforma
compartida* pese a su nombre. Consecuencias: (1) la IP de ingreso es la misma que la de staging
(`68.155.88.233`), pero el enrutamiento es por host (`movaeduca.me` → `movap-web`, `staging.movaeduca.me` →
`mova-web`); (2) una caída del entorno afectaría a ambos. **Recomendación:** al pasar a una suscripción de pago,
crear un entorno ACA propio para producción y mover `movap-*` (IaC ya parametrizado: `MOVA_PREFIX`,
`MOVA_APP_NAME_PREFIX`, `MOVA_MYSQL_SERVER_NAME`, `MOVA_LOCATION`).

Costo estimado incremental (precios de lista): MySQL B1ms + 32 GB ≈ USD 15/mes; 3 réplicas pequeñas (web 0,5 vCPU/1 GiB,
worker/scheduler 0,25 vCPU/0,5 GiB, siempre encendidas) ≈ USD 20–30/mes tras la capa gratuita. **Total ≈ USD 35–45/mes.**
Saldo del crédito Student leído en el portal el 2026-10-05: ≈ US$ 86 de US$ 100 → no alcanza con holgura para más de ~2 meses;
confirmar en https://www.microsoftazuresponsorships.com / Cost Management y decidir plan de pago antes de agotarlo.

## 2. Despliegue (procedimiento reproducible)

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

**Estado 2026-10-05:** `www` ya apunta a `movap-web` (cert gestionado, 301 → apex). El apex sigue en GitHub Pages: el paso 3 está pendiente (lo hace o autoriza el titular).

**Cambios previstos (solo web; MX/SPF/TXT/DKIM/DMARC intactos):**
1. TXT `asuid` (apex y `www`) con el *customDomainVerificationId* del entorno (se obtiene con
   `az containerapp env show … --query properties.customDomainConfiguration.customDomainVerificationId`).
2. `www` CNAME → FQDN de `movap-web` (no a `movaeduca.me`) para el certificado gestionado y el redirect.
3. Apex: registro A → IP estática del entorno (`az containerapp env show … --query properties.staticIp`).
4. `az containerapp hostname add/bind` + certificado gestionado para apex y `www` en `movap-web`.
5. `MOVA_APP_URL=https://movaeduca.me`; `www` → apex lo hace `RedirectWwwToApex` (301).
6. Verificar: resolución, TLS, `www`→apex, `/healthz`, `/readyz`, login, correo, OAuth, webhooks.
Ventana esperada sin TLS válido en el apex: minutos (el certificado gestionado exige que el A ya apunte al entorno).
Rollback: restaurar los 4 registros A de GitHub Pages y el CNAME de `www` (TTL del registrador).

## 5. Credenciales de producción (el propietario las rota/carga al final)

Nada de lo siguiente está cargado en producción. **Nunca por el chat**: usar `az containerapp secret set` +
variable de entorno `secretref:` en **cada** rol que la use (web, worker, scheduler), y crear una revisión nueva.

| Integración | Variables / secretos (nombres exactos) | Dónde se obtienen | Prueba posterior |
|---|---|---|---|
| Mercado Pago **live** (webhook productivo ya registrado y `MERCADOPAGO_WEBHOOK_SECRET` cargado en los 3 roles; faltan las credenciales live) | `MERCADOPAGO_ACCESS_TOKEN` (secreto), `MERCADOPAGO_PUBLIC_KEY`, `MERCADOPAGO_APPLICATION_ID`, `MERCADOPAGO_EXPECTED_COLLECTOR_ID`, `MERCADOPAGO_EXPECTED_LIVE_MODE=true`, `MERCADOPAGO_WEBHOOK_SECRET` (secreto), `MERCADOPAGO_WEBHOOKS_ENABLED`, `PAYMENT_PROVIDER=mercadopago`, `PAYMENTS_ENABLED`, `MERCADOPAGO_CHECKOUT_ALLOWLIST` | Panel MP Developers → credenciales de **producción** de la app; Webhooks → modo productivo con `https://movaeduca.me/api/webhooks/mercadopago` | Con allowlist = cuenta del propietario: un cobro real mínimo + reembolso, solo con importe aprobado por el propietario |
| Correo | `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`, `MAIL_USERNAME` (secreto), `MAIL_PASSWORD` (secreto), `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Proveedor SMTP / contraseña de aplicación de Gmail | Registro + verificación + recuperación a un buzón propio |
| JaaS | `JAAS_APP_ID`, `JAAS_KEY_ID`, `JAAS_PRIVATE_KEY` (secreto, base64 PEM en una línea), `JAAS_WEBHOOKS_ENABLED`, `JAAS_WEBHOOK_SIGNING_SECRET` (secreto) | Consola JaaS: API key propia de producción; Webhooks → `https://movaeduca.me/api/webhooks/jaas` | Reunión de dos participantes + eventos de presencia |
| Cloudinary | `CLOUDINARY_URL` (secreto) | Cloud/API key propios de producción | Subida y borrado de un avatar sintético |
| Sentry | `SENTRY_LARAVEL_DSN` (secreto), `SENTRY_ENVIRONMENT=production` | Proyecto propio de producción (recomendado) | Un evento sintético con entorno `production` |
| Google Login (opcional V1) | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_LOGIN_ENABLED` | Google Cloud Console; redirect `https://movaeduca.me/auth/google/callback` | Login con una cuenta de prueba |
| Datos legales (no secretos) | `LEGAL_BUSINESS_NAME`, `LEGAL_RUC`, `LEGAL_ADDRESS`, `LEGAL_SUPPORT_EMAIL` (vía `MOVA_LEGAL_*` en `apps.bicepparam`) | Titular (razón social, RUC, domicilio) | `mova:health-check` sin `LEGAL_PROVIDER_DATA_MISSING` |
| Cuenta admin | (se crea sin contraseña conocida) | — | Recuperar contraseña por correo y activar MFA |

Regla de oro de rotación: **cada entorno con credenciales distintas** (staging nunca comparte clave con producción).
Tras cargar cada grupo: nueva revisión de los tres roles, `/readyz`, y el smoke de la tabla.

## 6. Gates de lanzamiento pendientes (no técnicos)

Consentimiento de menores y tratamiento/transmisión internacional de datos (ANPD), reglas de asistencia/disputas,
clases recurrentes, verificación documental de profesores y política de reembolsos/deuda: decisiones del
propietario/legal. No se declara producción «100 % lista» sin ellas.
