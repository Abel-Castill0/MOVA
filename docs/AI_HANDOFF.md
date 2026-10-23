# MOVA — Handoff para agentes de IA (Claude Code, Codex, Antigravity…)

> Perfil y cierre de cuenta 2026-10-23: menú del avatar (Mi perfil / Configuración de cuenta / Cerrar sesión) y «Eliminar mi cuenta» como **solicitud de cierre** (acceso restringido + comprobante + bandeja admin); **no hay borrado definitivo**: falta aprobar la política de conservación. Detalle, evidencias, carreras MySQL y regla vigente: una cuenta cerrada solo puede cancelar clases ya programadas (`lessons.cancel`) en [`ACCOUNT_LIFECYCLE.md`](ACCOUNT_LIFECYCLE.md); la migración `2026_10_23_060000` es hacia adelante (no `migrate:rollback`). Un rollback de imagen a código anterior pierde la restricción de cuentas cerradas. Publicado el 2026-10-23: `cadd779`, web/scheduler `0000032`, migración aplicada.

> Actualización de catálogo 2026-10-22: cinco paquetes autorizados (Inicio5/10.00, Esencial10/19.80,
> Impulso15/29.40, Avance20/38.80, Pro30/57.60, PEN). `CreditPackages::pricing()` comparte presentación exacta
> entre catálogo y panel docente; las compras históricas conservan sus snapshots. Ver [evidencia comercial vigente](culqi-commercial-review.md).
> Las fechas 2026-10-23 de registros previos no se reinterpretan como aprobación legal: el titular aún debe aprobar
> la política existente de siete días. No habilitar cobros live ni mezclar el evaluador de QA con producción.
> Publicación verificada 2026-10-23 UTC: código `ae5a220`, CI completo verde, web/scheduler revisión `0000031`.
> Teléfono de soporte configurado; cinco ofertas públicas con precios exactos. Sandbox real aprobado, rechazo y devolución
> probados, más compra Esencial por navegador con un solo depósito. 3DS interactivo devolvió error del proveedor antes del reto.
> QA local en 8081; aún falta URL HTTPS aislada para Culqi. Ver informe comercial e historial de despliegue para límites y rollback.

> Estado: CURRENT · última verificación 2026-10-23 UTC · rama `master` · commit de referencia: el que contiene este
> archivo (`git log -1 -- docs/AI_HANDOFF.md`). Este documento **orienta**; el código, las migraciones y el
> [Completion Ledger](release/MOVA_V1_COMPLETION_LEDGER.md) deciden. Si algo aquí contradice al código, está desactualizado:
> corrígelo en el mismo cambio. No contiene secretos y no debe contenerlos.

## 1. Primeros pasos para un agente nuevo

1. Lee [`../AGENTS.md`](../AGENTS.md) (autorizaciones y límites) y [`../CLAUDE.md`](../CLAUDE.md) (reglas de trabajo). Son obligatorias.
2. Estado Git antes de nada: `git status --short --branch`, `git log --oneline -8`, `git fetch origin --prune`. No incluyas cambios ajenos (p. ej. `resources/js/Pages/About.vue` si aparece modificado sin que sea tu tarea).
3. Identifica el módulo con la tabla de §4 y lee **solo** su documento. Para dinero/créditos/pagos lee primero [`payments-migration-mp-to-culqi.md`](payments-migration-mp-to-culqi.md) y [`payments-culqi.md`](payments-culqi.md).
4. Distingue lo probado de lo no probado con la matriz de §6. «Funciona con mocks» ≠ validado.
5. Elige las pruebas con la sección 7 (primero tests dirigidos; la suite completa una sola vez al final).
6. No actives Culqi en producción, no cobres ni reembolses de verdad, no imprimas secretos y no hagas deploy: ver §8 (límites).

## 2. Qué es MOVA

Plataforma peruana de clases particulares en línea: **padres** registran alumnos (menores) y crean solicitudes; **profesores** verificados aceptan o proponen horario gastando **créditos** (1 crédito = 60 min, S/ 2.00); **admin** modera. Videollamada con JaaS (8x8/Jitsi). Producción: `https://movaeduca.me` en Azure Container Apps + Azure MySQL (estado real: Ledger y [`release/MOVA_PRODUCTION_RUNBOOK.md`](release/MOVA_PRODUCTION_RUNBOOK.md)).

**Stack** (fuente: `composer.json`, `package.json`): PHP 8.3+ · Laravel 13 · Vue 3 · Inertia 2 · Tailwind 3 · Vite 5 · MySQL 8.4 (tests: SQLite en memoria) · Spatie Permission · Laravel Breeze · cola `database` por defecto (`QUEUE_CONNECTION` en `.env.example`).

## 3. Arquitectura (rutas verificadas)

| Área | Dónde |
|---|---|
| Rutas | `routes/web.php` (UI + checkout), `routes/api.php` (webhooks: `webhooks/mercadopago`, `webhooks/culqi/{token}`, `webhooks/whatsapp`, `webhooks/jaas`), `routes/console.php` |
| Controladores | `app/Http/Controllers/` (por rol: `Teacher/`, `Parent/`, `Admin/`…) |
| Autorización | Roles `parent`, `teacher`, `admin` (Spatie; se crean en una migración/arranque, ver `database/migrations`) · Policies en `app/Policies/` · middleware `app/Http/Middleware/` (`EnsureAdminMfa`, `EnsureNotSuspended`, `EnsureCurrentLegalAcceptance`, `SecurityHeaders`…) |
| Servicios de negocio | `app/Services/` (`RechargeApprovalService`, `LessonSettlementService`, `LessonSchedulingService`, `JaasService`, `PaymentWebhookService`, `OperationalAlertService`, conciliaciones de pago…) |
| Pagos | `app/Payment/` (`Contracts/`, `MercadoPago/`, `Culqi/`, `FakePaymentProvider`, `Money`) · selección y guard en `app/Providers/AppServiceProvider.php` + `app/Support/ProviderGuard.php`, `CheckoutProvider.php`, `CheckoutAllowlist.php`, `CreditPackages.php` · config `config/payments.php`, `config/credits.php` |
| Jobs / cola | `app/Jobs/` (`ProcessCulqiWebhook`, `ProcessMercadoPagoWebhook`, `SendWhatsAppNotificationJob`, `WorkerHeartbeatJob`) |
| Scheduler | `app/Console/Kernel.php` (liquidación de lecciones, `mercadopago:reconcile` o `culqi:reconcile` **según el proveedor activo**, health-check…) |
| Comandos | `app/Console/Commands/` (`culqi:diagnose|webhook-url|reconcile|sandbox-smoke`, `mercadopago:*`, `mova:settle-lessons`, `mova:qa-mysql-fresh-migrate`, `ledger` y concurrencia…) |
| Modelos / BD | `app/Models/` (`RechargeRequest`, `PaymentOrder`, `PaymentWebhook`, `CreditTransaction`, `TeacherProfile`, `Lesson`…) · `database/migrations/` (~104) · ER y estados en [`architecture/`](architecture/README.md) |
| Frontend | `resources/js/Pages/**` (Inertia), `resources/js/Components`, `resources/js/lib/` (`culqi.js`, `mercadoPago*.js`, `checkoutPolling.js`) |
| Infra | `infra/azure/*.bicep` (plantillas; no es el estado desplegado), `Dockerfile`, `docker/`, `docker-compose.qa.yml` |
| CI | `.github/workflows/ci.yml` (frontend, composer, sqlite, mysql, e2e). **No hay workflow de deploy**; el despliegue es manual y las migraciones **no** se ejecutan al arrancar (comentario en `Dockerfile`; Job de migración revisada, ver Runbook) |
| IA para agentes | `.claude/agents/` (solo lectura), `.claude/skills/`, `.claude/hooks/` (+ `selftest.mjs`), `.claude/rules/`, `.mcp.json` → `tools/culqi-mcp/` · guía [`CLAUDE_CODE_AGENTS.md`](CLAUDE_CODE_AGENTS.md) |

### Sistema de pagos y créditos (resumen; detalle en `payments-migration-mp-to-culqi.md`)
- **Culqi es el único proveedor de compras nuevas; Mercado Pago está retirado para ellas** (`MERCADOPAGO_NEW_PAYMENTS_ENABLED=false`, default; desplegado 2026-10-23 en `7f69072`): si Culqi no está listo **no hay compras nuevas** (sin respaldo hacia Mercado Pago ni `fake`); Mercado Pago solo cierra lo histórico (webhook, conciliación, devoluciones). Estado real: producción tiene `PAYMENT_PROVIDER=mercadopago` (= "solo histórico", compras cerradas) hasta que existan llaves live de Culqi y se levanten los bloqueos de `payments-culqi.md` §14.1; detalle y rollback en §15.
- `PAYMENT_PROVIDER` (`fake` por defecto · `mercadopago` · `culqi`) elige **un** proveedor para cobros nuevos; `PAYMENTS_ENABLED=false` por defecto. Cada `RechargeRequest.payment_method` y `PaymentOrder.provider` conserva su proveedor original.
- Un `PaymentOrder` por intento. `RechargeApprovalService::credit()` (`app/Services/RechargeApprovalService.php`) es la **única** vía que acredita: transacción + `lockForUpdate` + `credit_transactions.idempotency_key` único (`recharge:{id}:deposit`). `reverse()` deja el asiento `reversal`.
- Culqi: `CulqiCheckoutController` (UI) → `CulqiPaymentProvider` (cargos/órdenes) → `CulqiPaymentReconciliationService` (verdad vía API) ← `ProcessCulqiWebhook` ← `CulqiWebhookController`.
- `PaymentOrder.status` (enum): `created|pending|paid|failed|expired|cancelled`. «En revisión» = `review_reason` no nulo; «revertido» = `RechargeRequest.status='reversed'` (la orden queda `paid`). `submission_status`: `prepared|submitting|submitted|uncertain|awaiting_3ds`.

## 4. Qué leer según el módulo

| Si tocas… | Lee antes |
|---|---|
| Créditos, recargas, ledger | `payments-migration-mp-to-culqi.md` §4–§6 · `release/MOVA_V1_DECISIONS.md` · `MONETIZATION_INTEGRITY_PHASE1.md` · skill `mova-mercadopago` (invariantes comunes) |
| Culqi | `payments-culqi.md` (todo) |
| Revisión comercial de Culqi (catálogo público, evaluador, legal) | `culqi-commercial-review.md` (todo) |
| Mercado Pago | `payments-architecture.md` (histórico, verificar) · `release/INCIDENT_2026-10-06_yape_live_checkout.md` · `release/MERCADOPAGO_SUPPORT_CASE_2072.md` |
| Despliegue / Azure / DNS | `release/MOVA_PRODUCTION_RUNBOOK.md` · `architecture/01-deployment.md` · `AGENTS.md` (límites de coste) |
| Estados y flujos de negocio | `architecture/03-state-machines.md`, `04-flows.md` · `MOVA_SYSTEM_MAP.md` (parcialmente superado) |
| Perfil, cuentas, cierre/eliminación | `ACCOUNT_LIFECYCLE.md` (todo) · `scripts/qa-account-closure-race.php` |
| UI | `../DESIGN.md`, `../PRODUCT.md`, `WEB_INTERFACE_GUIDELINES.md`, `.claude/rules/frontend-vue.md` |
| WhatsApp | `whatsapp-architecture.md` |
| Estado del lanzamiento | `release/MOVA_V1_COMPLETION_LEDGER.md` |

## 5. Cómo trabajar

- **Local:** `composer install && npm install && cp .env.example .env && php artisan key:generate && php artisan migrate` (solo BD local); `php artisan serve --port=8001`, `npm run dev`, `php artisan queue:work`, `php artisan schedule:work`. Nunca seeders ni migraciones en entornos reales.
- **PHP < 8.3 en el host:** corre PHPUnit/artisan en el contenedor `php_qa` de `docker-compose.qa.yml` (PHP 8.3, Node 22). Trampas conocidas: pasar `APP_KEY` por `-e`, no chocar con el puerto 3307 de otro MySQL, y ejecutar `npm ci` y `npm run build` por **separado** comprobando el código de salida (un pipe oculta fallos).
- **Investigar errores:** `storage/logs`, comandos de diagnóstico (`php artisan culqi:diagnose`, `mercadopago:diagnose`), tabla `payment_orders` (`review_reason`, `provider_status*`, `submission_status`), `operational_alerts`. Nunca pegues payloads de proveedor ni secretos en informes.
- **Agentes de Claude Code:** `mova-architect`, `mova-laravel-reviewer`, `mova-vue-reviewer`, `mova-qa-reviewer`, `mova-context-scout` (solo localizar) y **`mova-security-finance` (obligatorio si tocas dinero, auth, menores, JaaS o webhooks)**. Otros agentes: replica la revisión de seguridad financiera manualmente con la misma lista de `payments-migration-mp-to-culqi.md` §4.
- **Antes de modificar código financiero:** lee §4–§6 de la migración, mantén transacción + lock + idempotencia + ledger append-only, no cambies el estado de un pago incierto sin evidencia del proveedor, no inventes políticas (reembolsos, saldo negativo, ausencias, disputas), y añade un test que falle sin tu cambio.
- **Criterio de calidad antes de commit:** `git diff --check`; tests dirigidos; build; sin secretos (`.env`, llaves, URLs de webhook); archivos ajenos fuera del commit; documentación actualizada si cambia comportamiento.

## 6. Estado de trabajo (honesto)

**Implementado y validado** (ejecutado; ver evidencia en `payments-culqi.md` §3/§7 y `payments-migration-mp-to-culqi.md` §8)
- Cobro con tarjeta vía Culqi Custom Checkout en **sandbox real, navegador**: aprobado, rechazado, 3DS completo con reto simulado; créditos una sola vez.
- Orden de efectivo/billetera **creada** y pendiente sin acreditar (sandbox real).
- Backend Culqi (cargos, devoluciones, webhooks, conciliación, `uncertain`, idempotencia) con tests PHPUnit (SQLite y perfil MySQL de pagos), MCP local 19/19.
- Mercado Pago intacto (sus tests siguen en la suite).

**Implementado con validación parcial**
- Estado `paid` de una orden Culqi y `expired/cancelled`: solo con dobles (nunca observado en el sandbox).
- Yape: el token `ype_test_` se crea y el flujo de cargo está probado con dobles; **no hay un pago Yape completo confirmado**.
- Devoluciones, contracargos, parciales: dobles con la forma real del sandbox; sin devolución real de punta a punta en navegador.
- Idempotencia/concurrencia: tests secuenciales/reentrantes y **carreras reales multi-proceso sobre MySQL** (`scripts/qa-payment-race.sh reconcile-paid|reconcile-refund|charge-attempt`, 8–12 procesos × 3: 1 depósito, 1 reversión, 1 POST); no se probó con dos procesos contra el sandbox real de Culqi.

**Pendiente de configurar (propietario)**: registrar 5 webhooks en CulqiPanel con HTTPS público y activar `CULQI_WEBHOOKS_ENABLED`; excluir `/api/webhooks/culqi/` de los logs de acceso de Azure; llaves live solo en el almacén de secretos de producción; confirmar medios habilitados en live; confirmar alcance PCI por escrito con Culqi; prueba live única de S/ 1 con reembolso (AGENTS.md).

**Bloqueado / sin resolver**: cifrado RSA de Culqi devuelve `crypto_error` (par de llaves a recrear en el panel); el cifrado opcional sigue desactivado (`CULQI_ENCRYPT_PAYLOADS=false`).

**Riesgos conocidos** (no resueltos, heredados o aceptados): (**corregido y desplegado el 2026-10-23 (`7f69072`):** `RechargeApprovalService::credit()/reverse()` enviaban el aviso dentro de la transacción exterior y un fallo SMTP con cola `sync` revertía la acreditación; ahora `notifyAfterCommit`); **bloqueo comercial: la suscripción Azure for Students es solo educativa/no comercial (`payments-culqi.md` §14.1 B-1): no activar cobros live ahí**; no hay llaves live ni comercio verificado; webhooks sin registrar; conectividad Azure→Culqi sin probar; orden de locks Recarga→Orden (`resolveAttemptRow`) vs Orden→Recarga (`applyTruth`) puede diferir; webhook de Culqi sin firma (secreto en la ruta; se re-consulta la API); `paid:false` en ventas exitosas observado solo en test; **Culqi no está activado en producción** (`PAYMENT_PROVIDER` de producción no se ha cambiado y este repositorio no despliega solo).

**Hallazgos bajos de la revisión de seguridad financiera (2026-10-22, sin corregir; ninguno provoca doble crédito):** (a) un segundo intento `paid` sobre una recarga ya `approved` queda `paid` sin alerta (`CulqiPaymentReconciliationService::applyPaid`); (b) `createPaymentAttempt` lanza la búsqueda de un intento incierto sin el intervalo de 15 s, así que clics repetidos agotan el cupo de búsquedas y pasan a revisión antes de tiempo; (c) `culqi:reconcile --order=` sobre un intento `awaiting_3ds` lo trata como incierto, y el barrido automático no cierra un 3DS abandonado; (d) faltan tests de la carrera `completeThreeDs` vs `abandonThreeDs` y del segundo intento pagado. Se corrigieron en la misma revisión: redacción del secreto del webhook en trazas/spans de Sentry y relanzado de `QueryException` en `CulqiCheckoutController::run`.

**Tensión documentada en reglas:** `AGENTS.md` dice «no permitas saldos negativos»; la decisión de negocio vigente (`release/MOVA_V1_DECISIONS.md` §3, tests `AdminRechargeReversalTest`/`PaymentOrderTest`) permite que una devolución **confirmada por el proveedor** deje el saldo negativo (bloquea nuevas reservas). No lo cambies sin decisión del propietario.

## 6b. Infraestructura Azure y costo (resumen; detalle en [`azure-cost-optimization.md`](azure-cost-optimization.md))

Producción = 2 Container Apps activas (`movap-web` min 1/max 2 y `movap-scheduler` min=max 1, que desde 2026-10-22 ejecuta cola + scheduler con `docker/mova-supervise.sh`; `movap-worker` queda **Stopped**, reversible), todas 0,25 vCPU/0,5 GiB en `mova-aca-env` + MySQL B1ms (capa gratuita) + ACR Basic + Key Vault, en `mova-prod-rg` (Azure for Students, **límite de gasto activo: al agotarse el crédito Azure deshabilita los recursos**). Ritmo medido ≈ US$ 1,15/día (≈ 34/mes) frente a ≈ US$ 55 de crédito restante (≈ 7 semanas); piso del diseño actual ≈ US$ 26/mes. Optimización aplicada el 2026-10-22: OPcache de archivos solo para el CLI (`docker/php-cli.ini`, commit `a32a1f0`) para que el scheduler deje de facturar vCPU activa; ahorro **estimado** US$ 4–8/mes, por confirmar con la facturación del 2026-10-23 (cómo en `azure-cost-optimization.md` §7). Pendientes con decisión del titular: C-1 worker+scheduler en un contenedor, C-2 web min=0, C-3 registro alternativo, presupuesto mensual. Registro de despliegues: `release/DEPLOY_HISTORY.md`.

## 6c. Secretos y variables en Azure (resumen; detalle en [`azure-secrets-configuration.md`](azure-secrets-configuration.md))

Los 10 secretos de producción viven en Key Vault `kv-movap-192f01` y las Container Apps los referencian con `secretref` vía la identidad `mova-apps-identity`. El `.env` local es **solo de desarrollo/sandbox** (APP_DEBUG=true, Mercado Pago y Culqi **TEST**, 24 valores distintos de producción): **no se sube nada de él a producción** y nunca va a imágenes ni a Git (verificado 2026-10-22: 0 coincidencias de sus secretos en el historial de Git, el árbol, la imagen y los logs). No hay credenciales live de Culqi en ningún sitio. Credenciales de producción verificadas en solo lectura: Mercado Pago live (titular coincide), Cloudinary, JaaS (firma RS256), `APP_KEY`, BD (`/readyz`). Pendientes: correo sin prueba de autenticación SMTP, mínimo privilegio por contenedor, `LOG_LEVEL` sin definir.

## 7. Pruebas (comandos oficiales)

| Qué | Cómo |
|---|---|
| PHP completo (SQLite) | `vendor/bin/phpunit` (en `php_qa` si el host es < 8.3); `composer qa:safe` |
| Pagos Culqi | `--filter 'CulqiPaymentTest|CulqiCheckoutTest|CulqiSecretsTest'` |
| Pagos en MySQL | `php artisan mova:qa-mysql-fresh-migrate --env=testing` y `vendor/bin/phpunit -c phpunit.mysql.xml` (servicio `mysql_qa`) |
| Frontend | `npm run build`, `npm run check:title|check:chatbot-escape|check:movi-availability|check:checkout-poll` |
| Carreras de pagos multi-proceso (MySQL QA) | dentro de `php_qa`: `bash scripts/qa-payment-race.sh reconcile-paid\|reconcile-refund\|charge-attempt 8` (`app/Console/Commands/QaPaymentRace.php`, doble HTTP `scripts/qa/culqi-stub.php`; solo `mysql_qa`/`mova_qa`, APP_ENV=testing) |
| MCP Culqi | `npm run check:culqi-mcp` |
| Hooks de Claude | `node .claude/hooks/selftest.mjs` |
| Sandbox Culqi real | `php artisan culqi:sandbox-smoke --confirm-sandbox` (solo local/testing, llaves `*_test_`, importe tope) |

Evidencia de la última ejecución: `payments-culqi.md` §7 (cifras y fecha).

## 8. Límites que no se negocian

Sin cobros/reembolsos reales fuera de la prueba live autorizada; sin imprimir ni versionar secretos (ni URL de webhook con secreto); sin `git reset --hard`/`push --force`; sin tocar Azure/DNS/producción fuera de lo que `AGENTS.md` autoriza; sin migraciones ni seeders en entornos reales fuera del procedimiento del Runbook; sin reescribir migraciones ya aplicadas (usa una nueva).

## 9. Próximos pasos sugeridos

1. Propietario: pasos manuales de `payments-culqi.md` §5 (webhooks, secretos, medios, PCI).
2. Probar una orden **pagada** y Yape completo en sandbox si Culqi publica un simulador; si no, validar en la prueba live controlada.
3. ~~Prueba de concurrencia real de dos procesos sobre la misma recarga en MySQL~~ → hecha (`scripts/qa-payment-race.sh`); queda repetirla contra el sandbox real si Culqi permite cargos de prueba paralelos.
4. Mover `credit()`/`reverse()` a notificaciones `afterCommit` y unificar el orden de locks (cambio financiero: requiere `mova-security-finance`).
5. Plan de activación y rollback: `payments-migration-mp-to-culqi.md` §7.
