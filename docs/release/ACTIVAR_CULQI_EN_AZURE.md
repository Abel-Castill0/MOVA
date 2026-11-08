# Activar Culqi en Azure (una página)

Todo lo técnico ya está hecho: llaves live en Key Vault, 5 webhooks en CulqiPanel, checkout, conciliación y ledger.
Para abrir o cerrar las compras no hay que tocar código ni desplegar: solo se ejecuta esta herramienta.
La herramienta nunca muestra llaves ni secretos.

## A. Dónde entrar

1. Abre <https://portal.azure.com> con tu cuenta.
2. Pulsa el icono **Cloud Shell** (`>_`, arriba a la derecha) y elige **Bash**.
3. Descarga la herramienta (una sola vez por sesión):

```bash
curl -fsSLO https://raw.githubusercontent.com/Abel-Castill0/MOVA/master/infra/azure/culqi-live-activation.sh
```

## B. Comprobar que está listo (solo lectura, no cobra nada)

```bash
bash culqi-live-activation.sh check
```

Debe terminar en **`LISTO PARA ACTIVAR`**. Si dice `NO LISTO`, cada línea `FALLA` indica qué falta. Las líneas `NO-VERIFICADO` son normales antes de la primera compra real.

## C. Requisitos externos (los cumples tú, una vez)

- [ ] **Suscripción apta para uso comercial.** Hay dos caminos:
  - Pago por uso.
  - Azure for Students con **autorización expresa de Microsoft**. En ese caso registra la referencia del caso o documento (no el documento) en una etiqueta de la suscripción; la herramienta solo acepta esa suscripción y muestra la referencia abreviada:

```bash
az tag update --resource-id /subscriptions/$(az account show --query id -o tsv) --operation Merge --tags mova-microsoft-commercial-authorization=REFERENCIA-DEL-CASO
```

  Si la autorización vence, añade también `mova-microsoft-commercial-authorization-expires=AAAA-MM-DD`. En el portal es lo mismo: *Suscripciones → Azure for Students → Etiquetas*. Guarda el documento de Microsoft fuera del repositorio.
- [ ] **5 webhooks en CulqiPanel** (Producción → Desarrollo → Webhooks) con la URL vigente.
- [ ] **Tarjeta habilitada** en CulqiPanel (Mi empresa → Datos de mi comercio: Visa, Mastercard y AMEX activas).
- [ ] **Cuestionario PCI** confirmado con Culqi.

## D. Activar

**Paso 1 — solo tu cuenta, con una compra real de verificación.** Pide escribir `ACTIVAR`:

```bash
bash culqi-live-activation.sh enable-test TU-CORREO-DE-PROFESOR
```

Entra en MOVA con esa cuenta → **Mis créditos → Recargar créditos**. Compra el paquete **Inicio (S/ 10,00)** con tu tarjeta y vuelve a ejecutar `check`: debe decir **`COMPRA_LIVE_VERIFICADA: si`**.

**Paso 2 — todos los profesores.** Solo funciona si existe esa compra verificada:

```bash
bash culqi-live-activation.sh enable-all
```

## E. Comprobar que quedó activo

```bash
bash culqi-live-activation.sh check
```

Debe decir `ESTADO: ACTIVO-GENERAL` (o `ACTIVO-PRUEBA` tras el paso 1).
En MOVA, «Mis créditos» muestra los paquetes con el botón de pago; el aviso «Pago en línea en activación» desaparece solo.
Los padres no pueden comprar créditos.

## F. Detener compras nuevas (seguro, en cualquier momento)

```bash
bash culqi-live-activation.sh disable
```

Pide escribir `DETENER`. Cierra **solo las compras nuevas**: los webhooks, la conciliación, las órdenes pendientes y los reembolsos siguen funcionando, y ningún crédito se pierde.
Para volver a abrir, repite el paso D.2.

---

**Otras operaciones:**
- `bash culqi-live-activation.sh rotate-webhook-secret` cambia el secreto del webhook; después pega la URL de `~/culqi-webhook-url.txt` en las 5 entradas de CulqiPanel.
- `bash culqi-live-activation.sh update-keys` carga llaves nuevas de Culqi; las pide ocultas.
- `--dry-run` muestra qué haría sin cambiar nada.

Detalle técnico: `docs/payments-culqi.md` §18–§19.
