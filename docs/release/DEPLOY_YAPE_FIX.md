# Runbook — Despliegue del fix Yape (`fix/yape-amount-format`)

> **Estado: PLAN — no ejecutar hasta que Mercado Pago responda al ticket WCS-55456** y confirme el formato
> esperado de `transaction_amount` (o el campo que corresponda). No se ha hecho merge a `master` ni deploy.
> Ver el caso completo en [`MERCADOPAGO_SUPPORT_CASE_2072.md`](./MERCADOPAGO_SUPPORT_CASE_2072.md) y la
> respuesta técnica enviada (22:17) en ese mismo archivo. Datos operativos en
> [`MOVA_PRODUCTION_RUNBOOK.md`](./MOVA_PRODUCTION_RUNBOOK.md).

## 0. Contexto y precondiciones

- **Qué cambia la rama:** `amountMinorToApiNumber()` pasa de devolver un número JSON (`1`) a un **string
  decimal con 2 decimales** (`"1.00"`) — preparación para el caso 2072 si MP confirma que el problema es el
  formato del monto. Incluye un test unitario y ajustes de 4 aserciones + 1 mock de sandbox.
- **Rama:** `fix/yape-amount-format` (5 commits, HEAD `bae552962ad6da829837db7d05c8787849fa278e`).
- **NO hacer merge ni deploy hasta:** (1) respuesta de MP confirmando que el formato string es lo esperado
  **o** que el campo culpable es otro, y (2) autorización explícita del titular.
- **Si MP responde que el campo culpable NO es el monto** (p. ej. `payer.phone`, ver §6): **descartar esta rama
  o rebasarla**; la corrección correcta se prepara aparte. No se despliega un cambio que no corresponda al
  diagnóstico confirmado.

## 1. Merge a `master`

```bash
git checkout master
git fetch origin
git merge --no-ff fix/yape-amount-format
```

- **Conflictos esperados:** pocos/nulos. La rama tocó `app/Payment/MercadoPagoPaymentProvider.php`,
  `tests/Feature/CreditCheckoutControllerTest.php`, `tests/Feature/MercadoPagoPaymentProviderTest.php`,
  `tests/Feature/MercadoPagoSandboxSmokeCommandTest.php`, `tests/Unit/MercadoPagoAmountTest.php` (nuevo),
  `phpunit.xml` y `scripts/test-yape-payload.php` (nuevo). Si hay conflicto en `phpunit.xml`, conservar la
  suite `Unit` re-declarada por la rama.
- Verificar el diff antes de confirmar el merge:
  ```bash
  git log --oneline master..fix/yape-amount-format
  git diff master..fix/yape-amount-format --stat
  ```

## 2. Push y CI

```bash
git push origin master
```

- `ci.yml` corre en `push` a `master`: jobs `frontend`, `composer`, `sqlite` (PHPUnit `--no-progress`),
  `mysql` (`phpunit.mysql.xml`) y `e2e` (Playwright). **No continuar hasta CI verde** (todos los jobs).
- Especial atención al job `sqlite`: es donde corre el nuevo `MercadoPagoAmountTest::test_transaction_amount_is_string_with_two_decimals`
  y las 4 aserciones actualizadas a `'30.00'`/`'10.00'`/`'25.00'`.
- CI usa PHP 8.3; **no se puede reproducir localmente** (XAMPP local es 8.1.25). La validación local previa se
  hizo con `scripts/test-yape-payload.php` (autónomo, sin `vendor/`).

## 3. Construir la imagen y subirla al ACR

Construir desde el árbol de `master` ya mergeado y etiquetar con el **digest** (nunca solo `:latest`):

```bash
# Autenticar en el ACR (nombre real: movaacrsplisbj6ldoqw)
az acr login --name movaacrsplisbj6ldoqw

# Build + push (desde la raíz del repo)
az acr build --registry movaacrsplisbj6ldoqw \
  --image mova:yape-fix-$(git rev-parse --short HEAD) \
  --file Dockerfile .

# Obtener el digest para fijar la imagen inmutable
az acr repository show-manifests --name movaacrsplisbj6ldoqw --repository mova --top 1 \
  --query "[0].digest" -o tsv
```

- La imagen se referenciará como `movaacrsplisbj6ldoqw.azurecr.io/mova@sha256:<digest>`.

## 4. Desplegar a las Container Apps de producción (solo imagen, sin reaplicar IaC)

> **Regla de oro (§5a del runbook de producción):** publicar una versión nueva se hace **solo** con
> `az containerapp update --image …` en los tres roles. **NO** reaplicar `apps.bicep` completo: reemplazaría
> las variables de entorno de las integraciones (correo, JaaS, MP, Cloudinary) por las del template.

```bash
IMAGE="movaacrsplisbj6ldoqw.azurecr.io/mova@sha256:<digest>"

for APP in movap-web movap-worker movap-scheduler; do
  az containerapp update -g mova-prod-rg -n "$APP" --image "$IMAGE"
done
```

- **Migraciones:** este fix **no** añade migraciones. Si en el futuro un deploy sí las incluyera, el paso es un
  Container Apps *Job* manual con la misma imagen, `php artisan migrate --force` (§2.4 del runbook de
  producción); **nunca** un seeder en producción.
- Verificar la nueva revisión activa y que no haya fallo de arranque:
  ```bash
  az containerapp revision list -g mova-prod-rg -n movap-web \
    --query "[?properties.active].{rev:name,image:properties.template.containers[0].image}" -o table
  ```

## 5. Health check post-deploy

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://movaeduca.me/healthz   # esperado 200
curl -s https://movaeduca.me/readyz                                     # esperado {"status":"ready",...}
```

- Confirmar `database/migrations/cache: ok` en `/readyz`.
- Revisar logs de `movap-worker` y `movap-scheduler` (sin `ERROR`/`Exception` en el arranque).

## 6. Prueba live mínima (S/ 1.00) y reembolso inmediato

> **Requiere autorización explícita del titular.** Una sola transacción, importe mínimo (S/ 1,00), cuenta
> pagadora controlada por el titular, reembolso inmediato. Nunca involucrar alumnos/profesores reales ni
> repetir. `AGENTS.md`: una transacción, mínimo, con reembolso documentado.

1. El titular inicia sesión en la cuenta docente de prueba → **Mis créditos** → paquete «Verificación de cobro»
   (S/ 1,00) → paga con **Yape** (su teléfono + OTP en la app; MOVA nunca ve esos datos).
2. Esperar «acreditado» y comprobar: orden `paid`, **un** depósito, **1** crédito, **un** webhook procesado
   (sin duplicados), importe exacto `1.00 PEN` en el pago de MP.
3. **Reembolso inmediato** desde el panel de MP (Actividad → el pago → Devolver dinero) o por API. Verificar:
   el webhook de reembolso produce **un** `reversal` y el saldo de la cuenta de prueba vuelve a 0. Registrar la
   comisión retenida.
4. Cerrar el checkout: `PAYMENTS_ENABLED=false`, `MERCADOPAGO_WEBHOOKS_ENABLED=false`,
   `CREDITS_VERIFICATION_PACKAGE_ENABLED=false` (o dejar el checkout público solo con la política de
   reembolsos publicada y `MERCADOPAGO_CHECKOUT_ALLOWLIST` vacía). Nunca más de una prueba.

## 7. Rollback

Si el deploy degrada producción:

```bash
# Volver a la imagen previa (revisión anterior) en los tres roles
az containerapp revision list -g mova-prod-rg -n movap-web --query "[].name" -o tsv
az containerapp update -g mova-prod-rg -n movap-web --image <imagen-previa>   # (x3 roles)
```

- El cambio es de una sola función de serialización; el rollback de imagen es inmediato y no toca datos.
- El fix no añade migraciones ⇒ no hay rollback de esquema.

## 8. Checklist resumido

- [ ] MP confirmó el diagnóstico (formato de monto **u** otro campo) y el titular autorizó.
- [ ] `git checkout master && git merge --no-ff fix/yape-amount-format` — sin conflictos.
- [ ] `git push origin master` — **CI verde** (frontend, composer, sqlite, mysql, e2e).
- [ ] `az acr build` + digest de la imagen.
- [ ] `az containerapp update --image` en `movap-web`, `movap-worker`, `movap-scheduler`.
- [ ] `/healthz` 200 y `/readyz` `ready`; logs sin errores.
- [ ] Prueba live S/ 1,00 con Yape + reembolso inmediato + verificación de ledger.
- [ ] Checkout cerrado tras la prueba; comisión y reembolso registrados.
