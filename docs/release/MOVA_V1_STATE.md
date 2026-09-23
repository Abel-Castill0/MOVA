# MOVA 1.0 — Release state

HEAD: ver `git log -1` (gates corridos sobre c8af121; commits posteriores solo docs)
BRANCH: hardening/az3g0-release-baseline

## DONE
- P0-A baseline: WIP Cloudinary commiteado; composer.lock platform php ^8.3; Inertia v2 + axios 1.20 con lock; build OK; imagen Docker de producción construye (PHP 8.3.33, Laravel 13.32).
- P0-B PII: allowlists en ClassRequest index/teacherIndex y Dashboard. Test: ClassRequestIndexExposureTest.
- P0-C admin: MFA TOTP obligatorio server-side (admin.mfa en todo el grupo auth; admin.mfa:sensitive 15 min en créditos/refunds/suspensiones/profesores/lecciones forzadas), recovery codes hasheados de un uso con lock, rate limit, anti-replay, `mova:admin-mfa-reset`. Sin interruptor de desactivación. Test: AdminMfaTest.
- P0-D: gitleaks historial completo (299 commits): 3 falsos positivos documentados; rango nuevo (16 commits): limpio. AuthenticateSession: rotar contraseña expulsa sesiones previas.
- P0-E finanzas: MySQL completo verde + probes reales multi-proceso (8 procesos): accept-lesson, settle, refund, approve-recharge, reminder-claim → GREEN, ledger sano.
- P0-F Cloudinary: deleteAvatar() con public_id derivado server-side (quitar foto + borrar/anonimizar cuenta). Email: Gmail API (prod) con credenciales presentes en Azure; staging usa MAIL_MAILER=array a propósito. JaaS: JWT RS256, room-scoped, moderator solo profesor, ventana acotada (tests); health-check JAAS_NOT_CONFIGURED crítico en producción.
- P0-G pagos: Mercado Pago/recargas verdes en MySQL (webhook, idempotencia, reconciliación, reversal); flags off en staging.
- P0-I backup: runbook Azure PITR en docs/BACKUP_RESTORE_PLAYBOOK.md §9; dump→restore local MySQL 8.4 con paridad de esquema (396 columnas, hash idéntico).
- P0-J: /healthz y /readyz sin sesión; heartbeats scheduler+worker; Operations Center; readiness probe Bicep -> /readyz.
- P0-K: aceptación legal versionada (append-only); Libro de Reclamaciones público + admin; LEGAL_* por config; health-check LEGAL_PROVIDER_DATA_MISSING.
- Seguridad: cabeceras nosniff, X-Frame-Options, Referrer-Policy, CSP base (base-uri/object-src/frame-ancestors), HSTS en prod https.
- UX/a11y: Libro de Reclamaciones verificado a 390 px (sin scroll horizontal, labels, foco al primer error, mensajes legibles). E2E cubre viewports móvil/tablet/desktop de journeys existentes. Dark mode: sin selector en UI → un solo tema claro (sin cambios).
- CI: .github/workflows/ci.yml.

## BLOCKED_EXTERNAL
- Azure restore: servidor `mova-mysql-restoretest` creado por PITR (Ready, red privada heredada), pero la verificación de datos dentro de la VNet requiere materializar secretos de runtime (denegado por política). Acción humana: autorizar el Job de verificación o verificar manualmente (runbook §9) y luego `az mysql flexible-server delete -g mova-prod-rg -n mova-mysql-restoretest --yes` (contiene copia de datos personales; coste mientras exista).
- Staging Azure: desplegar la nueva imagen exige correr migraciones dentro de la VNet (mismo bloqueo de secretos). Acción humana: `az acr build` + Job de migración con los secretos existentes + `az containerapp update --image <acr>/mova@sha256:<digest>` para web y worker.
- mova-scheduler en Azure: NO desplegado a propósito mientras Railway sea producción (evita recordatorios duplicados). Activar `deployScheduler=true` en el cutover.
- JaaS en Azure: faltan JAAS_* (el health-check lo marcará en producción).
- F-26/F-24A: rotar credencial de producción expuesta en historial y cuentas QA (dueño de las cuentas). Contraseña admin histórica: cambiarla en producción; al primer acceso se exigirá enrolar MFA.
- LEGAL_BUSINESS_NAME / LEGAL_RUC / LEGAL_ADDRESS: datos reales del titular.
- Consentimiento parental específico por menor: requiere texto/diseño legal aprobado.
- Mercado Pago sandbox real: requiere credenciales de prueba en el entorno.
- Dominio definitivo: SEARCH_INDEXING_ENABLED sigue false hasta el cutover.
- Branch protection de master: activar tras CI verde en GitHub.

## ROLLBACK
- Azure: `az containerapp revision list` + `revision activate <anterior>` (web/worker); imagen previa 8e07734.
- DB: migraciones nuevas son aditivas con down(); rollback de código no requiere revertir esquema.
- Railway sigue como producción y rollback hasta el cutover.

## TESTS (sobre c8af121)
- SQLite: 1116 OK. MySQL 8.4: 1116 OK. E2E Playwright: 60/60. build: OK. Concurrencia real: 5/5 GREEN.

## NOTAS OPERATIVAS
- PHP local 8.1: usar docker-compose.qa.yml (php_qa) y `sh scripts/qa-snapshot-phpunit.sh` (no correr npm build durante el snapshot).
- php_qa tiene opcache sin revalidación: reiniciar procesos PHP largos tras editar código.
