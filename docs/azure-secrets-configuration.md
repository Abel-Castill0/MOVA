# MOVA — Secretos y variables en Azure (configuración, rotación y verificación)

> Estado: CURRENT · 2026-10-22. **No contiene valores secretos**: solo nombres, ubicaciones lógicas, consumidores y procedimientos.
> El `.env` local es **solo de desarrollo/sandbox**; nunca se copia a Azure, a una imagen Docker ni a GitHub.

## 1. Dónde viven los secretos de producción

| Capa | Recurso | Detalle |
|---|---|---|
| Almacén | Key Vault `kv-movap-192f01` (mova-prod-rg, standard, RBAC, purge protection) | 10 secretos: `app-key`, `db-password`, `mail-username`, `mail-password`, `sentry-laravel-dsn`, `jaas-private-key`, `jaas-webhook-signing-secret`, `mercadopago-access-token`, `mercadopago-webhook-secret`, `cloudinary-url` |
| Acceso de las apps | identidad administrada `mova-apps-identity` con rol **Key Vault Secrets User** | Las apps resuelven los secretos por referencia (`keyVaultUrl`) al iniciar una revisión; no hay valores en la configuración de la app |
| Administración | el titular tiene **Key Vault Secrets Officer** | Quien rote un secreto debe tener ese rol |
| Inyección | cada variable sensible es `env` con `secretRef` hacia el secreto de la app (que apunta al Key Vault) | Las variables no sensibles (banderas, hosts, ids públicos) van como literales |

Reglas: un secreto = un nombre descriptivo en minúsculas; ningún secreto en argumentos de comandos, en imágenes (`.dockerignore` excluye `.env` y `.env.*`), en el repositorio ni en documentación.

## 2. Mapa secreto → variable → consumidores (estado verificado 2026-10-22)

Topología actual: `movap-web` (GHCR, min 1), `movap-scheduler` (**ejecuta cola + scheduler en un contenedor**, min 1) y `movap-worker` (**detenido**, conserva su definición para rollback). Las tres definiciones mantienen los mismos 10 secretos.

| Secreto Key Vault | Variable | Servicio | Modo | Web | Scheduler (+cola) | Worker (detenido) | Notas de uso |
|---|---|---|---|---|---|---|---|
| `app-key` | `APP_KEY` | Laravel | prod propia | sí | sí | sí | **No rotar sin plan**: invalida sesiones y datos cifrados |
| `db-password` | `DB_PASSWORD` | MySQL `mova-prod-mysql-8uxzgq` (usuario `movap_app`) | prod | sí | sí | sí | |
| `mail-username` / `mail-password` | `MAIL_USERNAME` / `MAIL_PASSWORD` | Gmail SMTP (`MAIL_MAILER=smtp`) | prod | sí | sí (notificaciones en cola) | sí | contraseña de aplicación |
| `sentry-laravel-dsn` | `SENTRY_LARAVEL_DSN` | Sentry | prod | sí | sí | sí | `SentryScrubber` redacta el secreto del webhook |
| `jaas-private-key` | `JAAS_PRIVATE_KEY` | JaaS (8x8) | prod | sí (firma JWT de sala) | sí | sí | RSA 2048 válida |
| `jaas-webhook-signing-secret` | `JAAS_WEBHOOK_SIGNING_SECRET` | JaaS webhooks | prod | sí (endpoint) | — | — | `JAAS_WEBHOOK_AUTH_TOKEN` es opcional y no está definido |
| `mercadopago-access-token` | `MERCADOPAGO_ACCESS_TOKEN` | Mercado Pago | **live** (APP_USR) | sí | sí (`mercadopago:reconcile`) | sí | **conservar** (pagos históricos y devoluciones) |
| `mercadopago-webhook-secret` | `MERCADOPAGO_WEBHOOK_SECRET` | Mercado Pago webhooks | prod | sí | — | — | |
| `cloudinary-url` | `CLOUDINARY_URL` | Cloudinary (avatares) | prod | sí | sí | sí | |

**Literales no secretos** (nombres): `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `DB_HOST/PORT/DATABASE/USERNAME`, `MYSQL_ATTR_SSL_CA`, `QUEUE_CONNECTION` (`sync` en web, `database` en scheduler/worker), `CACHE_DRIVER=database`, `SESSION_DRIVER=database`, `PAYMENT_PROVIDER=mercadopago`, `PAYMENTS_ENABLED=true`, `MERCADOPAGO_*` públicos (`PUBLIC_KEY`, `APPLICATION_ID`, `EXPECTED_COLLECTOR_ID`, `EXPECTED_LIVE_MODE=true`, `WEBHOOKS_ENABLED=true`, `CHECKOUT_ALLOWLIST`), `JAAS_APP_ID/KEY_ID/WEBHOOKS_ENABLED`, `CLOUDINARY_FOLDER`, `LEGAL_*`, banderas desactivadas (`GOOGLE_LOGIN_ENABLED`, `WHATSAPP_ENABLED=false` + `WHATSAPP_PROVIDER=fake`, `DIAGNOSTIC_AI_ENABLED=false`, `RECHARGES_ENABLED=false`), `CHATBOT_*`, `LESSON_SETTLEMENT_MODE=live`.

## 3. Qué se hizo con el `.env` local (2026-10-22) y por qué no se subió nada

Auditoría de las 96 variables (sin imprimir valores; comparación por hash contra Azure y Key Vault):

| Resultado | Variables | Decisión |
|---|---|---|
| Idénticas a producción (14) | `APP_NAME`, `DB_CONNECTION/PORT/DATABASE`, `FILESYSTEM_DISK`, `MAIL_MAILER/HOST/PORT/ENCRYPTION/USERNAME/FROM_*`, `JAAS_APP_ID`, `MERCADOPAGO_EXPECTED_COLLECTOR_ID` | Nada que hacer |
| **Distintas** de producción (24) | `APP_ENV` (local), `APP_KEY`, `APP_DEBUG=true`, `APP_URL=localhost`, `DB_HOST` local, `DB_USERNAME`, drivers locales (file/pusher/database), `MAIL_PASSWORD`, `SENTRY_LARAVEL_DSN`, `JAAS_PRIVATE_KEY`, `JAAS_KEY_ID`, `JAAS_WEBHOOK_SIGNING_SECRET`, `MERCADOPAGO_*` (**TEST** local frente a **live** en Azure), `CLOUDINARY_URL`, `DIAGNOSTIC_AI_ENABLED=true` | **No se reemplaza ninguna**: son valores de desarrollo/sandbox (reglas: no sustituir producción por desarrollo ni usar credenciales de prueba como de producción) |
| Solo en local (51) | Culqi (**solo llaves `test`**), Gmail API (`GMAIL_*`), `GEMINI_API_KEY`, `OPENAI_API_KEY`, `PUSHER_*`/`VITE_PUSHER_*`, `TWILIO_*`, `AWS_*`, `REDIS_*`, `MEMCACHED_HOST`, `ADMIN_*`, etc. | **No se suben**: integraciones desactivadas en producción por decisión del titular, obsoletas o de desarrollo |
| Vacía en local (1) | `DB_PASSWORD` | Local usa otra BD |

Hallazgos sobre el archivo (no se editó): **6 variables duplicadas** (`MAIL_FROM_ADDRESS/NAME` líneas 48/119 y 49/120; `GMAIL_CLIENT_ID/SECRET/FROM_ADDRESS/FROM_NAME` líneas 78-81/114-117; phpdotenv de Laravel conserva la **primera** definición), **9 variables sin uso en el código** (`DEBUGBAR_ENABLED`, `VITE_PUSHER_*`, `TWILIO_*`), `CULQI_RSA_PUBLIC_KEY` con `\n` literales (es el formato esperado por el proyecto), sin variables comentadas, sin espacios sin comillas. Recomendación: depurar duplicados y restos obsoletos (Twilio, Pusher, AWS).

**No hay credenciales live de Culqi en el `.env`** ni en Azure: ver `payments-culqi.md` §14. Lo único que falta para Culqi y solo puede aportar el titular: `pk_live_`/`sk_live_` (CulqiPanel de producción), el secreto del webhook de producción y los 5 webhooks registrados.

## 4. Pruebas realizadas (solo lectura, sin cargos ni mensajes)

| Comprobación | Resultado |
|---|---|
| Sesión Azure y suscripción | `Azure for Students`, `mova-prod-rg` |
| Estado de apps | web y scheduler `Running`; worker `Stopped` (por diseño tras C-1) |
| `APP_KEY` | formato `base64:` de 32 bytes: OK |
| MySQL | `/readyz`: `database: ok`, `migrations: ok`, `cache: ok`; contraseña presente |
| Mercado Pago | token **live**; `GET /users/me` 200 y el titular coincide con `MERCADOPAGO_EXPECTED_COLLECTOR_ID`; webhook sin firma → 401 |
| Cloudinary | `GET /ping` 200 con la credencial de producción |
| JaaS | la llave privada carga y firma RS256 (2048 bits); `APP_ID`, `KEY_ID` y secreto del webhook presentes (no se llamó a 8x8) |
| Correo | usuario y contraseña con formato correcto (16 caracteres); **no se probó la autenticación SMTP** para no provocar alertas de inicio de sesión ni correos |
| Sentry | formato de DSN correcto (no se envió ningún evento) |
| Culqi (local, llaves TEST) | modo coherente con `CULQI_ENV`; `GET /v2/charges?limit=1` 200; webhook `/api/webhooks/culqi/…` en producción → 404 (inactivo) |
| WhatsApp/WAHA | `WHATSAPP_ENABLED=false`, proveedor `fake`: sin referencias ni mensajes |
| Exposición | **0 coincidencias** de los 24 valores secretos del `.env` en: historial completo de Git (3371 blobs), 952 archivos no ignorados, sistema de archivos completo de la imagen `a32a1f0` (620 MiB, sin archivo `.env`), logs de Azure de 30 días (patrones `sk_live_`, `sk_test_`, `APP_USR-`, `PRIVATE KEY`, `Bearer`, `cloudinary://`, `base64:`: 0) y 0 errores `ERROR/CRITICAL` en 24 h |

Límite del análisis de exposición: no se pudo inspeccionar Sentry (panel externo) ni escanear las capas comprimidas de la imagen de GHCR por valor (se escaneó el árbol de archivos de la misma fuente). La credencial histórica F-26 (MySQL de Railway en el historial) es un incidente aparte y sigue abierta (`MOVA_CREDENTIAL_EXPOSURE.md`).

## 5. Procedimientos

### 5.1 Crear o actualizar un secreto (sin exponerlo)
1. Escribir el valor en Key Vault **sin pasarlo como argumento**: portal de Azure (Key Vault → Secrets → New version) o `az keyvault secret set --vault-name kv-movap-192f01 --name <nombre> --file <archivo>` leyendo de un archivo temporal protegido que se borra de inmediato (nunca `--value` en la línea de comandos).
2. Si es un secreto nuevo, añadirlo a la app: `az containerapp secret set -g mova-prod-rg -n <app> --secrets "<nombre>=keyvaultref:<URL-del-secreto-sin-versión>,identityref:<id-de-mova-apps-identity>"` y enlazarlo: `az containerapp update -g mova-prod-rg -n <app> --set-env-vars "<VARIABLE>=secretref:<nombre>"`.
3. Los secretos por referencia se resuelven **al arrancar una revisión**: tras rotar un valor existente hay que crear una revisión (`az containerapp update … --revision-suffix <sufijo>`) en cada app consumidora, de una en una (scheduler → web) y comprobando `/readyz` y los logs entre cada paso.

### 5.2 Rotación
| Secreto | Cómo | Riesgo |
|---|---|---|
| `mercadopago-*` | Panel de Mercado Pago (credenciales de producción / restablecer firma del webhook); actualizar KV y revisar scheduler → web; el panel exige reverificar identidad: hacerlo seguido | Con webhooks activos, un valor intermedio rechaza eventos: ventana corta; el barrido `mercadopago:reconcile` recupera los perdidos |
| `jaas-private-key` / `jaas-webhook-signing-secret` | Consola JaaS (nueva API key / clave de firma) | Salas en curso usan JWT ya emitidos |
| `mail-password` | Nueva contraseña de aplicación de Google | Correos fallan hasta la revisión nueva |
| `cloudinary-url`, `sentry-laravel-dsn` | Paneles respectivos | Bajo |
| `db-password` | Cambiar el usuario `movap_app` en MySQL y actualizar KV; revisión de todas las apps casi a la vez | **Alto**: caída breve; hacerlo en ventana |
| `app-key` | **Evitar** (invalida sesiones y datos cifrados) | Alto |
| Secreto del webhook de Culqi (futuro) | Valor nuevo (`openssl rand -hex 24`) → KV → revisión → actualizar la URL de los 5 webhooks en CulqiPanel | Medio |

Cualquier secreto mostrado en pantalla, captura o chat se considera expuesto y debe rotarse antes de usarse.

### 5.3 Cuando existan credenciales live de Culqi (no ejecutado)
Crear en KV `culqi-secret-key` y `culqi-webhook-secret` (RSA solo si se decide activar el cifrado); variables: `CULQI_ENV=live`, `CULQI_PUBLIC_KEY` (literal: va al navegador), `CULQI_SECRET_KEY`→`secretref`, `CULQI_WEBHOOK_SECRET`→`secretref`, `CULQI_WEBHOOKS_ENABLED`, `CULQI_METHODS=card`. `CulqiConfig::assertConsistent()` se evalúa en **cada rol que resuelva el proveedor**: web y scheduler (que ejecuta `culqi:reconcile`) deben tener un conjunto coherente; el worker detenido solo si se reactiva. **No cambiar `PAYMENT_PROVIDER`** hasta cumplir `payments-culqi.md` §14 (incluido el bloqueo de términos de Azure for Students).

### 5.4 Rollback
Antes de cada cambio anotar `az containerapp revision list` (revisión y digest actuales). Para volver: `az containerapp revision activate` de la revisión previa o `az containerapp update --image <digest previo>` (ver `release/DEPLOY_HISTORY.md`); un secreto nuevo sin consumidor no afecta a la app y puede retirarse con `az containerapp secret remove`. No se revierten migraciones ni datos.

## 6. Riesgos pendientes

1. **Mínimo privilegio**: web, scheduler y worker reciben los mismos 10 secretos. El scheduler no parece necesitar `jaas-webhook-signing-secret` ni `mercadopago-webhook-secret` (los consumen los endpoints web; inferido del código, no probado); reducirlo exige una revisión cuidadosa y no se hizo para no arriesgar el procesamiento de pagos.
2. `LOG_LEVEL` no está definido en Azure: usa el valor por defecto del código (`debug` en el canal `stderr`). El volumen actual es bajo (≈ 0,7 MB/día), pero conviene decidir `info`/`warning` explícitamente.
3. `.env` local con credenciales de desarrollo en claro (esperable en una máquina de desarrollo): mantenerlo fuera de OneDrive/sincronizadores y de las imágenes; depurar duplicados.
4. El correo no se verificó por autenticación SMTP (ver §4).
5. F-26 (credencial histórica de Railway en Git) sigue abierta: `docs/MOVA_CREDENTIAL_EXPOSURE.md`.
