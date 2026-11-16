# Auditoría de lanzamiento de Culqi — 2026-11-15

Estado tras el aviso de Culqi de que resincronizó el comercio (prueba y producción). Este documento separa lo **preparado**, lo **validado**, lo **operativo en producción** y lo **abierto al público**. No contiene llaves, QR pagables ni datos personales.

Documentos relacionados: arquitectura en `docs/payments-culqi.md` y `docs/payments-architecture.md`; operación en `docs/release/ACTIVAR_CULQI_EN_AZURE.md` y `docs/payments-migration-mp-to-culqi.md` §6.

## 1. Línea base verificada (2026-11-15, 22:45 hora de Lima)

| Elemento | Valor comprobado |
|---|---|
| `master` | `2f22a1c`, igual a `origin/master`, árbol limpio |
| Código desplegado | `188025e` (los commits posteriores son solo documentación) |
| Imagen | `ghcr.io/abel-castill0/mova@sha256:14daabed…b76a9` en web y scheduler |
| Revisiones | `movap-web--0000059` y `movap-scheduler--0000060`, ambas Healthy |
| CI e imagen de `188025e` | GitHub Actions en verde |
| Configuración | `PAYMENT_PROVIDER=culqi`, `CULQI_ENV=live`, `CULQI_METHODS=card,yape,order`, `CULQI_ORDER_TTL_MINUTES=60`, `PAYMENTS_ENABLED=true`, Mercado Pago cerrado para compras nuevas |
| Allowlist | una sola cuenta (la de prueba); verificada por huella, sin imprimirla |
| Key Vault | `culqi-secret-key` y `culqi-webhook-secret` habilitados |
| Sitio | `/readyz` 200, portada 200, términos, privacidad y política de devoluciones 200 |
| PR abierto | `Abel-Castill0/MOVA#16` (QA del 05/11), sin fusionar; no toca este lanzamiento |

`culqi:activation-check` en producción: `RESULTADO: LISTO`, `ESTADO: ACTIVO-PRUEBA`, `COMPRA_LIVE_VERIFICADA: no`. La API live de Culqi responde 200 con la llave de Key Vault.

## 2. Operaciones anteriores (conciliadas contra Culqi)

| Recarga | Intento | Estado en MOVA | Estado en Culqi | Créditos |
|---|---|---|---|---|
| 4 | orden QR | `expired` | `expired` (conciliación oficial `culqi:reconcile --order=2`) | 0 |
| 5 | orden QR | `expired` | `expired` (`culqi:reconcile --order=3`) | 0 |
| 6 | Yape directo | `failed` | cargo denegado por el emisor (fondos insuficientes) | 0 |

- No queda ninguna orden pagable, incierta ni en revisión de Culqi.
- `mova:reconcile-ledger`: **GREEN**, el ledger explica todos los saldos.
- No se movió dinero. Los 10 créditos de cortesía siguen como cortesía y no cuentan como compra.

## 3. Integración frente a la documentación oficial (revisada el 2026-11-15)

- Custom Checkout: `settings.amount` en céntimos, `currency`, `settings.order` y `paymentMethods` (`tarjeta`, `yape`, `billetera`, `bancaMovil`, `agente`, `cuotealo`). MOVA usa exactamente esos campos.
- Órdenes: `amount`, `currency_code`, `description`, `order_number`, `client_details`, `expiration_date`, `confirm`. MOVA crea la orden con `confirm:false` y el checkout de Culqi la confirma.
- Billeteras móviles: importe mínimo S/ 6 y máximo S/ 500, solo soles. Los cinco paquetes (S/ 10 a S/ 57.60) están dentro del rango.
- El aviso de pago llega por `order.status.changed`; MOVA además consulta la API antes de acreditar.

No se encontró ningún defecto de MOVA, por lo que no hubo cambios de código ni despliegue en esta ronda.

## 4. Pruebas

Suite completa sobre `188025e`: 1872 pruebas aprobadas, 25 omitidas. Cubre, entre otros: webhook duplicado, retrasado o con secreto incorrecto; evento que dice «pagado» cuando la API dice lo contrario; doble clic; dos pestañas; error de red tratado como incierto; cargo recuperado tras timeout; pago con la página cerrada; importe distinto enviado a revisión; reembolso total y parcial; reembolso con créditos ya usados; separación de Mercado Pago; acceso solo de profesores y solo de la allowlist. Son pruebas **simuladas**; no sustituyen a la compra real.

## 5. Estado por componente

| Componente | Estado | Evidencia |
|---|---|---|
| Culqi Live (API y llaves) | Verificado | GET live 200 con la llave de Key Vault |
| Tarjetas | Preparado, sin compra real | Pruebas simuladas y sandbox |
| Yape directo | Preparado, sin compra real | Un cargo real llegó a Culqi y fue denegado por fondos |
| Yape QR | Pendiente | Falló antes de la resincronización; sin prueba posterior |
| Plin QR | Pendiente | Sin prueba |
| PagoEfectivo/CIP | Pendiente | El CIP se genera; nunca se pagó uno |
| Webhooks | Verificado en recepción | 1 webhook auténtico recibido (cargo fallido) |
| Conciliación | Verificado | Corre cada 5 min; cerró las órdenes 4 y 5 como vencidas |
| Ledger | Verificado | `mova:reconcile-ledger` GREEN |
| Compra real | Pendiente | `COMPRA_LIVE_VERIFICADA: no` |
| PCI | Pendiente | Sin confirmación escrita de Culqi sobre el cuestionario |
| Alojamiento comercial | Pendiente | La etiqueta de la suscripción contiene un texto de relleno, no una referencia de Microsoft |
| Apertura general | Restringida | Solo la cuenta de prueba |

## 6. Compra real de verificación (la hace una persona autorizada)

1. Abrir una ventana de incógnito e ingresar a https://movaeduca.me con la cuenta de prueba.
2. Ir a **Mis créditos → Recargar créditos** y elegir **Inicio (S/ 10.00)**.
3. Elegir «Billeteras digitales» y generar **un solo** QR.
4. Escanearlo con Yape o Plin, comprobar que la app muestra **S/ 10.00** y pagar. Si la app muestra otro importe o un error, **no pagar**: tomar captura y avisar.
5. Esperar la confirmación en pantalla o pulsar «Verificar pago». El saldo debe subir 5 créditos.
6. No repetir el pago hasta que se haya verificado el resultado.

Verificación posterior (solo lectura): `culqi:activation-check` debe decir `COMPRA_LIVE_VERIFICADA: si`; la orden debe estar `paid` en Culqi por 1000 PEN; debe existir un único depósito de 5 créditos; `mova:reconcile-ledger` debe seguir GREEN.

## 7. Apertura general

Solo después de la compra verificada y con confirmación explícita del titular:

```bash
bash culqi-live-activation.sh enable-all
```

Un medio que no haya superado su propia prueba real se retira antes de `CULQI_METHODS`. Para cerrar compras nuevas sin apagar la conciliación: `bash culqi-live-activation.sh disable`.
