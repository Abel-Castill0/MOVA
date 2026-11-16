# MOVA V1 — Auditoría final de lanzamiento

Registro de evidencias. Última actualización: 2026-11-16 (UTC). No contiene llaves, QR pagables ni datos personales.

Significado de los estados:

- **Implementado:** existe código.
- **Probado:** hay pruebas automáticas que pasan (simuladas).
- **Validado en live:** hay evidencia del entorno real.
- **Operativo:** existe una operación real completa y satisfactoria.
- **Abierto al público:** cualquier usuario autorizado puede usarlo.

## 1. Resumen

MOVA está **desplegada y sana en producción**, con Culqi live limitado a una cuenta de prueba. **No existe todavía ninguna compra real aprobada**, así que los pagos están implementados y probados, pero no operativos. La apertura general sigue cerrada.

Bloqueos que solo puede resolver una persona:

1. Hacer una compra real de S/ 10 con la cuenta de prueba (§7).
2. Confirmar con Culqi el cuestionario PCI de Custom Checkout.
3. Acreditar el uso comercial del alojamiento: la etiqueta de la suscripción dice `REFERENCIA-REAL`, que es un texto de relleno y no una referencia de Microsoft.
4. Preguntar a Culqi por el importe del QR (§3).

## 2. Estado inicial de esta ronda (2026-11-16)

- `master` en `3406efe`, igual al remoto, sin cambios nuevos de Abel. El PR `Abel-Castill0/MOVA#16` sigue abierto y no se fusionó.
- Producción al empezar: código `188025e`, web revisión 59 y scheduler revisión 60, sanas. `PAYMENT_PROVIDER=culqi`, `CULQI_ENV=live`, `CULQI_METHODS=card,yape,order`, allowlist con una sola cuenta.
- Recargas 4 y 5 (QR): vencidas en MOVA y en Culqi. Recarga 6 (Yape con código): rechazada por fondos insuficientes.
- Recarga 7 (QR, S/ 10, creada el 15/11): Yape leyó el QR; la orden quedó sin pagar. Culqi la seguía mostrando `pending` minutos después de su vencimiento.
- Ledger: `mova:reconcile-ledger` GREEN. Ningún depósito por compra; no se movió dinero.

## 3. QR de billeteras: importe y nombre

Detalle técnico en `docs/payments-culqi.md` §24.

| Tema | Conclusión | Evidencia |
|---|---|---|
| Causa del importe manual en Yape | El QR lo genera PagoEfectivo con `54=1000`; Yape no toma de ahí el importe y lo pide | QR oficial decodificado (13/11 y 15/11, misma estructura); guías de PagoEfectivo («Verifica o ingresa el monto») y de Yape |
| QR de importe fijo | No hay opción oficial en la API de órdenes, el Custom Checkout ni CulqiPanel | OpenAPI de Culqi, documentación y panel revisado el 15/11 |
| Mostrar «MOVA» en Yape | No con este QR: el nombre es el campo 59 («PagoEfectivo») más el CIP | QR decodificado; en CulqiPanel el nombre del comercio ya es «Mova» |
| Mejor solución oficial | Yape con código de aprobación (importe fijado por MOVA) como camino recomendado; logo y título de MOVA en el checkout; instrucciones de importe exacto y de beneficiario | Checkout probado en sandbox; cambios de esta ronda |
| Pendiente de Culqi | Si `54=1000` es lo que impide mostrar el importe, y si un pago por otro importe se rechaza | Sin respuesta documentada |

## 4. Protección ante importes incorrectos

| Caso | Comportamiento | Estado |
|---|---|---|
| Culqi reporta la orden pagada por un importe menor, mayor o en otra moneda | No acredita; la orden queda en revisión | Probado (5 paquetes) |
| Pago pendiente, rechazado o vencido | Cero créditos | Probado |
| Pago exacto confirmado por la API | Un único depósito con los créditos del paquete | Probado |
| Webhook repetido o fuera de orden | No duplica ni degrada | Probado |
| Pago ajeno a una orden de MOVA (metadata distinta) | No acredita | Probado |
| El navegador envía importe o créditos manipulados | Se ignoran; manda el catálogo del servidor | Probado |
| PagoEfectivo acepta un pago menor y Culqi marca `paid` sin cambiar el `amount` | **MOVA no puede detectarlo**: la API no documenta un campo de importe pagado | Riesgo abierto; confirmar con Culqi y revisar el importe abonado de la primera compra |

## 5. Medios de pago

| Medio | Implementado | Probado | Validado en live | Operativo |
|---|---|---|---|---|
| Tarjeta (token, 3DS) | Sí | Sí (simulado y sandbox) | No | No |
| Yape con código | Sí | Sí | Parcial: un cargo real llegó a Culqi y fue denegado por fondos | No |
| Yape por QR | Sí | Sí | Parcial: Yape lee el QR y pide el importe; sin pago | No |
| Plin por QR | Sí | Sí | No | No |
| PagoEfectivo / CIP | Sí | Sí | Parcial: el CIP se genera y PagoEfectivo lo muestra por S/ 10 | No |

## 6. Webhooks, conciliación y ledger

| Elemento | Estado | Evidencia |
|---|---|---|
| Cinco webhooks de producción | Validado en live (registro y recepción) | 1 evento auténtico recibido (`charge.creation.failed`) |
| Lectura de ese evento | Corregido en esta ronda | Culqi lo envía como objeto `error` sin id de cargo; MOVA lo dejaba en revisión con alerta. Ahora se cierra sin acción; prueba de regresión |
| `order.status.changed` de un pago real | Probado; sin evidencia live | Requiere la compra real |
| Conciliación (`culqi:reconcile`) | Validado en live | Corre cada 5 min; cerró las órdenes 4 y 5; API Logs de Culqi |
| Ledger | Validado en live | `mova:reconcile-ledger` GREEN |
| Mercado Pago | Cerrado para compras nuevas; sigue conciliando lo histórico | Configuración de producción |

**Avisos de `mova:health-check` en producción:**

- `WHATSAPP_PROVIDER_INVALID`: falso positivo. La lista de proveedores válidos del chequeo no incluía `waha`. Corregido en esta ronda.
- `MERCADOPAGO_WEBHOOK_QUEUE_SYNC`: real. La app web usa `QUEUE_CONNECTION=sync`, así que los trabajos de webhook corren dentro de la petición, sin reintentos. Un pago no se pierde, porque la conciliación lo recupera en minutos, pero es menos robusto. Cambiarlo a `database` (el scheduler ya procesa la cola) afecta también al correo y a las notificaciones: queda como decisión del titular.

Alertas abiertas en producción: una de salud (el falso positivo de WhatsApp) y una del webhook de cargo rechazado. Ninguna implica dinero.

## 7. Compra real de verificación (pendiente)

A las 05:22 UTC del 16/11 no queda ninguna orden pagable: la de la recarga 7 venció en Culqi sin pago.

1. Ventana de incógnito → https://movaeduca.me con la cuenta de prueba.
2. **Mis créditos → Recargar créditos → Inicio (S/ 10.00)**.
3. Un solo intento. Con Yape, lo más directo es «Pagar con tarjeta o Yape» → Yape → código de «Aprobar compras». Con QR, si la app pide el importe, escribir exactamente **10.00**.
4. Esperar la confirmación o pulsar «Verificar pago». No repetir el pago.

Verificación posterior, solo lectura: orden o cargo `paid` por 1000 PEN en Culqi, importe abonado en CulqiPanel, webhook recibido, un único depósito de 5 créditos, ledger GREEN y `COMPRA_LIVE_VERIFICADA: si`.

## 8. Módulos de MOVA V1

La columna «Probado» se apoya en la suite PHPUnit completa de esta ronda (§9). «Validado en live» recoge solo lo comprobado en producción.

| Módulo | Implementado | Probado | Validado en live | Pendiente |
|---|---|---|---|---|
| Registro, verificación de correo, recuperación, perfil | Sí | Sí | Sí (2026-10-06 y correos de noviembre) | — |
| Consentimientos legales y reaceptación | Sí | Sí | Páginas públicas 200 | Trámites del titular (ANPD) |
| Roles y permisos (padre, profesor, admin) | Sí | Sí | Parcial | — |
| MFA de administrador | Sí | Sí | No comprobado en esta ronda | Comprobación manual |
| Cierre de cuenta | Sí (solicitud, sin borrado definitivo) | Sí | Desplegado el 2026-10-23 | Decisiones del titular |
| Perfil docente, materias, precios, disponibilidad | Sí | Sí | 8 perfiles docentes en producción | Uso real |
| Verificación docente | Sí (manual por admin) | Sí | No comprobado | Política documental (decisión) |
| Búsqueda, catálogo y filtros | Sí | Sí | `/marketplace` 200 | Uso real |
| Registro de hijos y datos de menores | Sí | Sí | 3 alumnos en producción | — |
| Solicitud, reserva, cancelación de clases | Sí | Sí | 1 clase registrada | Flujo completo con usuarios reales |
| Clases virtuales (JaaS) | Sí | Sí (simulado) | Llave cargada; sin reunión real en producción | Primera reunión real |
| Reportes y calificaciones tras la clase | Sí | Sí | No comprobado | Uso real |
| Créditos y ledger | Sí | Sí | Sí (reserva real y cortesía) | Compra real |
| Compra de créditos (Culqi) | Sí | Sí | Parcial (§5) | Compra real |
| Reembolsos y reversos | Sí | Sí | No | Reembolso de la compra de prueba |
| Panel de administración y alertas | Sí | Sí | Alertas reales registradas | — |
| Correo transaccional | Sí | Sí | Sí (Resend desde 2026-11-03) | Confirmar entrega en Gmail |
| WhatsApp (WAHA) | Sí | Sí | Sí (OTP recibido por el titular) | — |
| Libro de reclamaciones | Sí | Sí | Página 200 | Envío real |
| Web pública (landing, legales, sitemap, robots) | Sí | Sí | 13 rutas públicas responden 200 | — |
| Pago padre → profesor | No existe por diseño | — | — | Fuera de V1 |

No se hizo una revisión manual pantalla por pantalla ni pruebas E2E con navegador en esta ronda.

## 9. Pruebas de esta ronda

- Suite PHPUnit completa (SQLite) sobre el árbol de esta ronda: 1902 pruebas, 1901 aprobadas y 25 omitidas; el único fallo era de una prueba nueva y se corrigió. Después se ejecutaron las 158 pruebas de los archivos afectados (todas OK) y la CI corrió la suite completa en verde sobre `b8a7904`.
- `npm run build`, `composer audit` y `npm audit`: sin errores ni avisos tras actualizar `laravel/framework` a 13.42.0.
- No se ejecutaron en esta ronda: perfil MySQL de concurrencia, Playwright/E2E ni revisión visual de la pantalla nueva con una sesión de profesor.
- Desplegado: imagen `sha256:f0b5859e…6e22`, web revisión 60 y scheduler revisión 61, sanas. Tras el despliegue la orden de la recarga 7 figura `expired` en MOVA y en Culqi.

Pruebas nuevas:

- Los cinco paquetes se ordenan por su importe exacto y solo el pago exacto acredita (`CulqiCheckoutTest`).
- Un cargo rechazado que llega como objeto `error` se cierra sin revisión ni crédito (`CulqiPaymentTest`).
- El chequeo de salud acepta `waha` como proveedor de WhatsApp.

Todas son simuladas. No se ejecutó ninguna operación live con dinero.

## 10. Requisitos externos pendientes

| Requisito | Estado | Quién |
|---|---|---|
| Compra real verificada | Pendiente | Titular o Abel |
| PCI/SAQ de Custom Checkout | Sin confirmación escrita de Culqi | Titular |
| Uso comercial de Azure for Students | Sin referencia auténtica de Microsoft | Titular |
| Respuesta de Culqi sobre el importe del QR y los pagos por otro importe | Pendiente | Titular (consulta preparada en `docs/support/`, local) |
| Decisión sobre `QUEUE_CONNECTION` de la web | Pendiente | Titular |

## 11. Apertura al público

Solo con `COMPRA_LIVE_VERIFICADA: si`, los requisitos de §10 resueltos y confirmación explícita del titular:

```bash
bash culqi-live-activation.sh enable-all
```

Un medio sin su propia prueba real se retira antes de `CULQI_METHODS`. Para cerrar compras nuevas sin apagar la conciliación:

```bash
bash culqi-live-activation.sh disable
```

## 12. Después del lanzamiento

- Revisar a diario las alertas del panel de administración y `mova:health-check`.
- Ante un pago no acreditado: `php artisan culqi:reconcile --order=<id>`; nunca acreditar a mano.
- Reembolsos solo desde CulqiPanel; MOVA los refleja al confirmarse.
- Operación detallada: `docs/release/ACTIVAR_CULQI_EN_AZURE.md` y `docs/payments-migration-mp-to-culqi.md` §6.
