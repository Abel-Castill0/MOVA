# Revisión comercial de Culqi — observación y subsanación

## Actualización vigente — 2026-10-22: cinco paquetes autorizados

Esta sección prevalece sobre el registro anterior, que conserva fechas 2026-10-23 y tres paquetes.
La fecha local es 2026-10-22; el reloj UTC ya marca 2026-10-23 (04:40Z verificado). Esta diferencia de zona horaria explica la aparente fecha futura, pero no demuestra aprobación legal. No se cambia la versión 2026-10-23 ni el historial de aceptaciones.

| Paquete / URL `/comprar-creditos/…` | Créditos | Base S/ | Descuento permanente | Final S/ | Centavos | Ahorro S/ | S/ por crédito |
|---|---:|---:|---:|---:|---:|---:|---:|
| inicio | 5 | 10.00 | 0% | 10.00 | 1000 | 0.00 | 2.00 |
| esencial | 10 | 20.00 | 1% | 19.80 | 1980 | 0.20 | 1.98 |
| impulso | 15 | 30.00 | 2% | 29.40 | 2940 | 0.60 | 1.96 |
| avance | 20 | 40.00 | 3% | 38.80 | 3880 | 1.20 | 1.94 |
| pro | 30 | 60.00 | 4% | 57.60 | 5760 | 2.40 | 1.92 |

Fuente única: `config/credits.php`; `CreditPackages::pricing()` presenta los importes usando enteros y cadenas decimales.
Fórmula comprobada por regresión: `final_centavos × 100 = créditos × 200 × (100 − descuento)`.
El cliente envía exclusivamente el código. `RechargeRequest` conserva nombre, créditos e importe aceptados; `PaymentOrder` conserva centavos/moneda.
No hay migración ni recálculo de compras pendientes o históricas. Un Pro antiguo de S/60 mantiene S/60; uno nuevo de S/57.60 mantiene S/57.60 aunque vuelva a cambiar el catálogo.
Las cinco fichas reutilizan ilustraciones propias de Movi diferentes, con alt, descripción y precio; Pro dice «Mayor ahorro».
Movi y la explicación de precios en devoluciones distinguen precio base de precio final; no cambia la política de devolución.

### Alcance comercial y consulta pendiente

[Culqi exige al menos cinco productos, pero permite variar el mínimo de servicios según el modelo](https://ayuda.culqi.com/portal/es/kb/articles/cuales-son-los-requisitos-para-que-la-web-o-app-de-mi-comercio-no-sea-observado-al-momento-de-integrar-la-pasarela).
Cinco paquetes son cinco ofertas del mismo servicio de créditos, no cinco servicios educativos independientes. Su existencia **no garantiza aprobación**.
Consulta propuesta: «MOVA vende créditos prepagados a profesores, en cinco paquetes de 5, 10, 15, 20 y 30 créditos. Las familias pagan las clases directamente al docente. ¿Estas cinco ofertas satisfacen el requisito para nuestro modelo? ¿Aceptan revisar el checkout con llaves de integración y cuenta restringida en un entorno aislado, manteniendo cerrados los cobros de producción?».

### Comisiones: estimación pública, no tarifa contractual de MOVA

[Tarifario oficial Online y Link consultado](https://culqi.com/precios/): tarjetas nacionales/Yape 3.44%, internacionales 3.99%, más US$0.20 por venta Online. Indica comisiones inafectas a IGV y sujetas a evaluación; el contrato efectivo debe confirmarse en CulqiPanel. El PDF contractual antiguo menciona IGV: no se asume que sea el contrato vigente del comercio.
Supuesto de cálculo **referencial**: US$1 = S/3.85 (referencia publicada por Culqi), fijo S/0.77; redondeo final a céntimos. No representa cotización actual ni neto garantizado.

| Paquete | Cobro S/ | Comisión nacional estimada S/ | Neto S/ | Neto por crédito S/ | Comisión internacional estimada S/ |
|---|---:|---:|---:|---:|---:|
| Inicio | 10.00 | 1.11 | 8.89 | 1.7780 | 1.17 |
| Esencial | 19.80 | 1.45 | 18.35 | 1.8350 | 1.56 |
| Impulso | 29.40 | 1.78 | 27.62 | 1.8413 | 1.94 |
| Avance | 38.80 | 2.10 | 36.70 | 1.8350 | 2.32 |
| Pro | 57.60 | 2.75 | 54.85 | 1.8283 | 3.07 |

La nota de mínimo S/3.50 bajo S/87.72 aparece vinculada a Cuotéalo/PagoEfectivo; no se aplica sin evidencia a las tarjetas. Confirmar si ese mínimo incluye el fijo antes de estimar esos medios. No se cambiaron los precios autorizados para compensar comisiones.

### Legal y operaciones: decisiones pendientes, no aprobaciones supuestas

- La regla existente de siete días para créditos sin usar **no está aprobada en esta misión**. Se conserva mientras se prepara la revisión humana. No se inventa un derecho general de arrepentimiento: el [Código y sus lineamientos](https://consumidor.gob.pe/wp-content/uploads/2020/07/Lineamientos_Proteccion_Consumidor_2022.pdf) vinculan la restitución del artículo 59 a supuestos específicos. Revisar también [DL1729 de 2026](https://busquedas.elperuano.pe/dispositivo/NL/2486266-3), atención digital y prácticas coercitivas, con asesoría jurídica.
- Devolución total confirmada por Culqi: reversión una sola vez sobre compra histórica, incluso con créditos gastados según la excepción ya aprobada del ledger. Parcial: `review`, sin reversión proporcional automática. No convertir saldo no usado a S/2: cualquier cálculo debe partir de lo efectivamente pagado y de la política que se apruebe.
- El titular debe resolver: plazo comercial, tratamiento de consumo parcial/reservas, redondeo del importe proporcional, versión y aprobación de Términos/Privacidad/Devoluciones. Los derechos legales imperativos no dependen de una decisión de producto.
- Identidad, RUC y domicilio sí están publicados y configurados (comprobación de esta ronda); el registro anterior que los daba por ausentes está superado. Teléfono autorizado: +51 916666934; no hay redes sociales oficiales.
- Evaluador: buzón autorizado por el titular, cuenta docente restringida mediante `mova:culqi-evaluator` solo en QA. La contraseña no se documenta ni se publica; revocación por el mismo comando. No crear un administrador ni mezclar saldos sintéticos con producción.
- Azure for Students sigue siendo la suscripción efectiva. [Microsoft Learn](https://learn.microsoft.com/azure/education-hub/azure-dev-tools-teaching/azure-students-program) limita el programa a educación/investigación no comercial y desarrollo/pruebas para esos fines. Confirmar alojamiento comercial compatible antes de ventas live; no se modifica suscripción ni se contratan recursos.

### Riesgos heredados identificados en revisión independiente

1. Orden de locks inverso entre creación de intento (Recarga→Orden) y aplicación de verdad (Orden→Recarga): una carrera mixta puede provocar deadlock; las carreras existentes no demuestran ausencia de este caso.
2. `culqi:reconcile --order` filtra primero `pending`, por lo que no reconsulta una orden `paid`; los reembolsos dependen del webhook o del barrido de pagos recientes. No usar ese comando como recuperación universal de devoluciones.
3. El reembolso parcial se envía a revisión y conserva saldo hasta resolución operativa. No se afirma que exista devolución proporcional automática.

### Evidencia ejecutada en esta actualización

- GitHub Actions para **`ae5a22034f9da4586312eaf6f8fb37245026fcb5`**: [CI completo](https://github.com/Abel-Castill0/MOVA/actions/runs/39902196077) con Composer, frontend, SQLite, MySQL y E2E **success**. [Imagen GHCR](https://github.com/Abel-Castill0/MOVA/actions/runs/39902256087): **success**, etiqueta OCI comprobada contra ese SHA, recursos compilados presentes y `.env` ausente.
- SQLite: **1611 pruebas, 6850 aserciones, 7 omitidas**, 35 avisos de deprecación PHPUnit. Primera copia aislada omitió `lang/` y falló la localización del correo; se completó la copia y se repitió con éxito. No se cambió código de correo.
- MySQL 8.4: perfil completo **1611 pruebas, 6849 aserciones, 7 omitidas**, mismas deprecaciones, en contenedor/base QA separados. Incluye integridad, permisos, histórico MP, Culqi, rechazos, pendientes, 3DS simulado, fallos de red, webhook/reconciliación repetidos y reclamaciones.
- Carreras MySQL, ocho procesos cada una: `reconcile-paid`, `reconcile-refund`, `charge-attempt` **GREEN**; un depósito, una reversión cuando corresponde y un solo POST de cargo al stub. Son procesos reales contra MySQL y proveedor simulado, no ocho cargos al sandbox.
- Vite: build correcto usando dependencias aisladas (Vite no estaba instalado en la raíz). Composer validate y diff check correctos. Checks checkout-poll/chatbot-escape/movi-availability correctos; MCP Culqi 19/19.
- Navegador: 375, 768 y 1440 px sin desborde; las cinco imágenes cargan. Selección Esencial → login → retorno a Esencial → recarga de 1980 centavos PEN → iframe real de Culqi con tarjeta pública de prueba → «Pago aprobado» → saldo 10 e historial con un depósito. Reconciliado dos veces más por backend: sigue **1 depósito, saldo 10, estado approved**.
- Sandbox real por comando: aprobado, fondos insuficientes y devolución total correctos. Webhook inyectado localmente dos veces y conciliación doble sin duplicación. Esto **no prueba entrega pública de webhooks desde CulqiPanel**.
- Evidencia local ignorada por Git: `qa/reports/culqi-pricing/{sandbox-approved,catalog-desktop,pro-mobile}.png`. Cuenta creada mediante `mova:culqi-evaluator`, rol teacher no verificado, en SQLite exclusiva de evaluación; no hay datos reales de alumnos. Credencial temporal de QA no publicada. Los registros legales de la fixture QA no son aprobación del titular.
- Pendiente externo: URL HTTPS de evaluación accesible a Culqi. El entorno local `http://localhost:8081` no es accesible al revisor; staging Azure está detenido y sin BD, y no se crean recursos nuevos ni se usan datos de producción para pruebas.
- No verificado en esta ronda: 3DS interactivo, Yape completo, orden de efectivo pagada, tarifa contractual del comercio, hosting comercial aprobado, aceptación del modelo por Culqi. No se efectuaron cobros reales.
- Intento adicional de 3DS con tarjeta Mastercard publicada por Culqi: el proveedor devolvió HTTP400 / `DNGA9999`, sin presentar reto. La recarga quedó fallida y no acreditó; no se atribuye una causa no demostrada. Los tests con dobles de 3DS sí pasan. La documentación de Culqi recomienda un correo específico para estas pruebas; no se sustituyó la identidad del evaluador autorizado.

Archivos de implementación: `config/{credits,catalog}.php`, `app/Support/{CreditPackages,Catalog}.php`,
`app/Http/Controllers/Teacher/CreditController.php`, `app/Services/MoviKnowledgeBase.php`,
`resources/js/Pages/Catalog/{Index,Package}.vue`, `resources/js/Pages/Teacher/Credits/Index.vue`,
`resources/js/Pages/Legal/Refunds.vue`. Pruebas: `tests/Feature/{CreditPackagePricingTest,CulqiCheckoutTest,CulqiPaymentTest,MoviLocalAssistantTest}.php`
y `qa/tests/flujo-completo.spec.js`. Documentación: README principal, índice docs, AI_HANDOFF, ambos documentos de pagos, este informe e historial de despliegue.

### Matriz de entrega comercial

| Requisito | URL/evidencia | Estado |
|---|---|---|
| Cinco ofertas, imágenes, descripciones y precios | `/servicios`, cinco detalles `/comprar-creditos/{codigo}` | Implementado y probado; interpretación comercial pendiente de Culqi |
| Botón y flujo de pago | Detalle → login → checkout; Esencial S/19.80 real sandbox | Verificado en QA; producción conserva compra cerrada |
| Usuario de revisión | `mova:culqi-evaluator`, correo autorizado, teacher sin privilegios | Creado en QA local; acceso público y entrega privada pendientes |
| Identidad/RUC/domicilio | Pie/legales públicos, props del servidor | Verificados presentes |
| Contacto | Correo publicado y teléfono autorizado | +51916666934 publicado y visible en Devoluciones; sin redes oficiales |
| Términos/privacidad/devoluciones | `/terminos`, `/privacidad`, `/politica-de-devoluciones` | Accesibles; aprobación jurídica pendiente |
| Libro integrado | `/libro-de-reclamaciones`, pruebas completas | Implementado; no es formulario externo |
| HTTPS | Dominio público de Azure | Verificado tras desplegar `ae5a220`; catálogo, cinco detalles, legales y salud HTTP200 |
| Cobro live | Ninguno | Cerrado deliberadamente; no declarar comercio listo para cobrar |

### Borrador de respuesta a Culqi — no enviado

**Asunto: MI COMERCIO FUE OBSERVADO**

Hola, equipo Culqi:

MOVA ofrece créditos prepagados de plataforma para profesores. Las familias pagan sus clases directamente al docente; MOVA no intermedia ese pago.
Hemos preparado cinco paquetes con imagen, descripción, precio en PEN, detalle del descuento por volumen y botón de compra. Catálogo público: https://movap-web.whitecliff-88cda913.mexicocentral.azurecontainerapps.io/servicios.
El recorrido de selección, login, checkout y acreditación fue verificado en integración con tarjeta de prueba, sin cobros reales. Producción mantiene los cobros cerrados.
También están disponibles Términos, Privacidad, Devoluciones y Libro de Reclamaciones integrado. La validación final de los textos legales continúa pendiente.

¿Confirman que cinco paquetes de créditos del mismo servicio satisfacen el requisito para este modelo? ¿Aceptan revisar el pago en un entorno HTTPS aislado con llaves de integración? Tras confirmar el entorno, facilitaremos la URL y las credenciales de una cuenta docente restringida por un canal privado. El recorrido será Servicios → paquete → iniciar sesión → Continuar al pago → tarjeta de prueba → saldo e historial.

Quedamos atentos a sus indicaciones para completar la revisión.

MOVA

**Antes de enviar:** completar aprobación legal, URL pública de QA y entrega de contraseña del evaluador por canal privado. El catálogo ya está desplegado y verificado. No adjuntar llaves, tokens, contraseña ni URL secreta del webhook a este documento.

### Publicación verificada — 2026-10-23 UTC

Código `ae5a220` publicado en `origin/master`; imagen inmutable
`ghcr.io/abel-castill0/mova@sha256:3360c62315e5ec030a09740108360fc6d6dbc45443d838976c22f94442139c29`.
`movap-scheduler--0000031` y `movap-web--0000031` están listas y sanas. Worker independiente sigue detenido.
Solo cambió la imagen en ambas aplicaciones y `LEGAL_SUPPORT_PHONE` en web. Sin migraciones, secretos nuevos,
activación de proveedores, cambios de escala ni cuentas sintéticas en producción.

Comprobados por HTTPS: `/readyz`, `/healthz`, `/servicios`, los cinco detalles, `/quienes-somos`,
Términos, Privacidad, Devoluciones y Libro, todos HTTP200. Los datos públicos del catálogo contienen
1000/1980/2940/3880/5760 centavos, descuentos 0/1/2/3/4, teléfono autorizado y `payments.online=false`.
Navegador confirma teléfono visible en Devoluciones. Captura: `qa/reports/culqi-pricing/production-catalog.png` (local, ignorada).

Costos: suscripción Azure for Students confirmada; no se añadieron recursos ni réplicas. La lectura mensual por recurso
devolvió cargos existentes de web (US$3.068035), scheduler (US$5.987013), worker histórico (US$2.911622) y ACR
(US$3.6513458772). Estos importes **no son el total**. El endpoint de saldo devolvió 404 y las consultas agregadas 429:
saldo disponible y previsión exacta no confirmados; no sustituirlos por una estimación como si fuese crédito real.
PITR de MySQL confirmado: retención 14 días; no se ejecutaron migraciones ni restauraciones.

Vista QA local disponible en `http://localhost:8081/servicios` (`mova-pricing-preview`). Contenedores temporales
`mova-pricing-qa` y `mova-pricing-mysql` detenidos tras las pruebas; los contenedores previos del propietario no se alteraron.
Rollback y evidencia CI: [historial de despliegue](release/DEPLOY_HISTORY.md).


> Fecha: 2026-10-23 · Commit desplegado: `546d44c` · Estado: **subsanación técnica lista; faltan datos/decisiones del titular (§9)**.
> Este documento **no contiene** contraseñas, llaves ni URLs de webhook. La contraseña del evaluador se entrega **solo por un canal privado**.

## 1. Observación recibida

Culqi observó el sitio de MOVA y pidió: (1) catálogo de al menos 5 productos/servicios, (2) imagen representativa de cada uno, (3) descripción clara, (4) precio visible, (5) carrito o botón de pago activo, (6) usuario y contraseña de prueba si hay que iniciar sesión para revisar el proceso. Requisitos oficiales adicionales (artículo de ayuda de Culqi sobre requisitos web): información clara del comercio, datos de contacto, Términos y Condiciones, política de cambios y devoluciones, Libro de Reclamaciones integrado, HTTPS y enlaces/redes sociales funcionales.

## 2. Qué vende realmente MOVA (modelo comercial)

- **Lo que Culqi procesa:** MOVA vende **créditos de plataforma a los profesores** (paquetes prepagados, S/ 2.00 por crédito; 1 crédito = 60 min de clase). Es el **único** flujo de cobro por Culqi.
- **Lo que Culqi NO procesa:** la clase en sí la paga la familia directamente al profesor; MOVA no cobra ni intermedia ese pago. Por eso el precio de una clase **no** es un producto de MOVA ni se vende con el botón de compra.
- Fuente única de precios: `config/credits.php` (`packages`, `credit_price_pen`, `class_rates`, `refund_days`). La presentación (imagen, texto, beneficios) vive en `config/catalog.php`. Los componentes Vue no tienen precios escritos a mano; el servidor decide importe, créditos y moneda (el cliente solo envía el código del paquete).

## 3. Catálogo público (sin iniciar sesión)

| URL | Contenido |
|---|---|
| `/servicios` | Catálogo: paquetes de créditos (imagen, descripción, créditos, equivalencia en minutos, precio en PEN) y materias con la cantidad real de profesores verificados y la tarifa máxima por hora |
| `/comprar-creditos/{inicio\|impulso\|pro}` | Detalle de la oferta y **Resumen de compra**; invitado → «Iniciar sesión para comprar» (la selección se conserva); profesor → «Continuar al pago» |

Ofertas reales hoy: **3 paquetes** (Inicio S/ 10.00 · 5 créditos; Impulso S/ 30.00 · 15 créditos; Pro S/ 60.00 · 30 créditos). El catálogo es dinámico: añadir un paquete en `config/credits.php` + su ficha en `config/catalog.php` lo publica solo. Las 6 materias del catálogo se muestran como servicio, pero **no se presentan como disponibles** mientras no haya profesores verificados («Aún sin profesores verificados»).

> **Brecha frente al mínimo de 5 productos:** hoy hay 3 ofertas comprables reales. No se inventaron productos ni precios; ver §9 (decisión del titular).

Imágenes: 7 ilustraciones originales (`public/images/catalog/*.svg`, propiedad de MOVA) + mascota Movi; todas con texto alternativo. Responsive comprobado a 375 px (sin desborde horizontal).

## 4. Flujo de compra navegable

Página pública → `/servicios` → detalle `/comprar-creditos/{paquete}` → (login si hace falta, vuelve al mismo paquete) → «Continuar al pago» → `/teacher/credits/checkout/{id}` (Custom Checkout de Culqi, PEN) → resultado → saldo e historial en `/teacher/credits`. Los créditos se acreditan **una sola vez y solo con el pago confirmado** (API de Culqi como verdad; ledger append-only). Un botón «Comprar»/«Continuar al pago» sustituye al carrito (permitido por Culqi: «carrito o botón de pago activo»).

## 5. Estado honesto del pago

La web dice la verdad según la configuración (`Catalog::paymentsState`):
- Sin Culqi activo: banner «Pago en línea en activación»; el botón queda deshabilitado y el backend responde 503 (nunca crea una recarga).
- Culqi en llaves de prueba: banner «Entorno de pruebas… no se realizan cobros reales» (catálogo, detalle, créditos y checkout).
- Llaves live: sin etiqueta de prueba y solo los medios realmente habilitados.

`CULQI_CHECKOUT_ALLOWLIST` limita quién puede comprar mientras se opera en modo de prueba.

**Producción hoy:** `PAYMENT_PROVIDER=mercadopago` (solo histórico), sin `CULQI_*`: el pago en línea está **cerrado** y así lo indica la web. No se activaron pagos live ni se tocó la configuración financiera.

## 6. Acceso de evaluador

Comando: `php artisan mova:culqi-evaluator {create|revoke|status} <correo>` (`app/Console/Commands/CulqiEvaluator.php`).
- Rol `teacher` únicamente; perfil **sin verificar**, sin saldo ni materias; sin acceso a `/admin` ni a datos de otros usuarios (probado: 403).
- **Sin contraseña conocida**: se crea con un hash aleatorio. El titular fija la contraseña con «Olvidé mi contraseña» desde un buzón que controla y la entrega a Culqi por un canal privado. Nunca pasa por Git, logs ni documentación.
- Revocación: si no compró, se elimina; si ya compró, se **suspende**, se invalida la contraseña y se cierran sesiones (las recargas/ledger no se borran).
- Sin puertas traseras: usa el login normal.

### Estado del evaluador en producción (2026-10-28)

- Cuenta `culqi.revision@movaeduca.me` creada en **producción** con `mova:culqi-evaluator create` (rol `teacher` exclusivo, perfil sin verificar, saldo 0, correo **sin** verificar; `email_verified_at` se dejó en `NULL` a propósito). La contraseña es aleatoria (24 caracteres), se guardó **solo su hash bcrypt** y el valor en claro vive en un archivo protegido fuera del repositorio, en el equipo del titular. Login real probado: contraseña incorrecta rechazada, correcta → dashboard; `/servicios` muestra los cinco paquetes con sus precios; sin sesión se vuelve a `/login`.
- **El checkout sandbox NO es posible en producción**: allí `PAYMENT_PROVIDER=mercadopago` (histórico), no hay llaves `CULQI_*` y el pago en línea está cerrado. Para demostrar el cobro hace falta un entorno aislado con llaves de prueba y base propia (el staging de Azure ya no tiene base de datos). Pendiente de decisión del titular.
- El buzón `culqi.revision@movaeduca.me` es una **propuesta**: el dominio usa reenvío de correo de Namecheap (MX `eforward*.registrar-servers.com`), que exige crear el alias en ese panel; hasta entonces no recibe mensajes ni sirve para recuperar la contraseña.
- Al entrar por primera vez, el evaluador debe aceptar los documentos legales vigentes (no se aceptan por él).
- Revocar: `php artisan mova:culqi-evaluator revoke culqi.revision@movaeduca.me` (Job manual, runbook).

### Entorno sandbox aislado (2026-10-28)

- **URL HTTPS:** `https://movaqa-culqi.whitecliff-88cda913.mexicocentral.azurecontainerapps.io` — Container App `movaqa-culqi` (`mova-prod-rg`, entorno `mova-aca-env`), imagen de producción `3bb67d0` por digest, 1 réplica de 0,25 vCPU / 0,5 GiB.
- **Aislamiento:** base **SQLite propia** (disco local del contenedor con copia consistente en Azure Files; sobrevive a reinicios, ver `culqi-evaluator-handoff.md` §2; no toca la MySQL de producción), `PAYMENT_PROVIDER=culqi` con llaves **de prueba** (`pk_test_/sk_test_`), checkout limitado por `CULQI_CHECKOUT_ALLOWLIST` al correo del evaluador, `noindex`, sin Mercado Pago live, WhatsApp, IA ni Google. Webhooks **habilitados** con un secreto propio de staging y cola/scheduler en el contenedor; falta que el titular los registre en CulqiPanel (modo pruebas). Guía completa, pruebas y pasos: `culqi-evaluator-handoff.md`.
- **Arranque:** el contenedor migra, siembra roles y crea al evaluador con `mova:culqi-evaluator create`, fijando el **hash** de su contraseña (secreto de la app; la contraseña en claro solo existe en el archivo protegido del titular). El evaluador debe aceptar los documentos legales al entrar.
- **Verificado:** `/readyz`, login real (contraseña incorrecta rechazada, correcta → dashboard docente), catálogo con los cinco paquetes, barra con «Servicios» para el profesor autenticado, resumen de `/comprar-creditos/esencial` (10 créditos, S/ 19.80) y apertura de la ventana segura de Culqi con S/ 19.80. **No verificado**: el pago con tarjeta dentro del iframe de Culqi; la automatización del navegador no pudo escribir en él. Falta que el titular lo complete a mano con la tarjeta oficial de prueba y compruebe 10 créditos y un solo depósito.
- **Apagar o revocar:** `az containerapp delete -g mova-prod-rg -n movaqa-culqi` (borra también la base). Al terminar la revisión, eliminar también la cuenta de producción (`mova:culqi-evaluator revoke`).

## 7. Información del comercio y legal (públicos)

| Requisito | URL / lugar | Estado |
|---|---|---|
| Datos del comercio (razón social, RUC, domicilio) | Pie de página (`data-testid="merchant-identity"`), `/terminos`, `/politica-de-devoluciones` | Se muestran **solo si están configurados** (`LEGAL_BUSINESS_NAME`, `LEGAL_RUC`, `LEGAL_ADDRESS`); ver §9 |
| Contacto | Pie de página, `/politica-de-devoluciones` | Correo ✔; teléfono (`LEGAL_SUPPORT_PHONE`) pendiente |
| Redes sociales | Pie de página (solo enlaces `https`) | Pendiente (`SOCIAL_*_URL`) |
| Términos y Condiciones | `/terminos` | Versión 2026-10-23 (Culqi como procesador) |
| Política de privacidad | `/privacidad` | Versión 2026-10-23 (datos enviados a Culqi) |
| Cambios, cancelaciones y devoluciones | `/politica-de-devoluciones` | Reembolso íntegro de créditos no usados en 7 días (`config('credits.refund_days')`) |
| Libro de Reclamaciones | `/libro-de-reclamaciones` (+ enlace en pie y en la compra) | Funcional, código de seguimiento, respuesta ≤15 días hábiles |
| HTTPS | Producción (Azure Container Apps, `https://movap-web.whitecliff-88cda913.mexicocentral.azurecontainerapps.io`) | ✔ servido por HTTPS; dominio propio pendiente |

Los textos legales cambian de versión (`LEGAL_TERMS_VERSION`, `LEGAL_PRIVACY_VERSION` = 2026-10-23): **todos los usuarios deberán aceptar de nuevo** al entrar tras el despliegue. El texto legal requiere aprobación humana del titular (§9).

## 8. Validación (evidencia)

- Pruebas nuevas: `tests/Feature/PublicCatalogTest.php` (12) y `tests/Feature/CulqiEvaluatorTest.php` (5).
- Recorrido en navegador (instancia local, llaves de prueba de Culqi, SQLite): catálogo como invitado ✔; detalle del paquete ✔; login del evaluador y **retorno al paquete elegido** ✔; «Continuar al pago» crea una recarga `pending` con importe/créditos del servidor (S/ 10.00, 5) ✔; el checkout muestra «Entorno de pruebas» y abre el modal de Culqi (modo prueba) con el correo del evaluador ✔; política de devoluciones, Libro de Reclamaciones, pie de página con identidad/contacto, vista móvil ✔.
- **No verificado en navegador:** el cobro con tarjeta de prueba dentro del iframe de Culqi (el control del navegador no puede teclear en un iframe de otro dominio). La acreditación con pago confirmado está cubierta por las pruebas automáticas de Culqi y por la evidencia sandbox de `docs/payments-culqi.md` §3/§7.

## 9. Pendientes que solo el titular puede resolver

1. **≥5 ofertas honestas:** añadir 2 paquetes reales (precio/créditos que el titular decida) en `config/credits.php`, o incorporar profesores verificados reales con sus materias/tarifas. No se inventaron.
2. **Dónde revisa Culqi el checkout de prueba:** producción no tiene llaves de Culqi y Azure for Students es no comercial (`payments-culqi.md` §14.1); opciones: (A) activar llaves de **prueba** en un entorno de revisión con `CULQI_CHECKOUT_ALLOWLIST`, (B) mostrar el flujo desde un entorno separado, (C) aprobar primero el comercio y activar live después.
3. **Datos reales del comercio:** razón social, RUC, domicilio, teléfono y redes (variables de entorno indicadas en §7).
4. **Buzón del evaluador** y crear la cuenta en producción: `mova:culqi-evaluator create <correo>` y fijar la contraseña por «Olvidé mi contraseña».
5. **Aprobar el texto legal** (Términos 2026-10-23, Privacidad 2026-10-23, Devoluciones).

## 10. Limitaciones

Pago en línea cerrado en producción; ninguna transacción live; la web no se afirma «lista para cobrar». Cualquier cambio de precios requiere editar `config/credits.php`.

## 11. Verificación en producción (2026-10-23, `546d44c`)

`/`, `/servicios`, `/comprar-creditos/{inicio,impulso,pro}`, `/politica-de-devoluciones`, `/libro-de-reclamaciones`, `/terminos`, `/privacidad`, `/sitemap.xml`, `/readyz` y `/healthz` responden 200 por HTTPS; un paquete inexistente da 404; `POST /teacher/credits/checkout` sin sesión redirige (302). La web muestra «Pago en línea en activación» (el pago sigue cerrado) y el pie **no** muestra razón social/RUC/domicilio porque no están configurados en producción (§9.3). Configuración financiera sin cambios (`PAYMENT_PROVIDER=mercadopago` histórico, sin `CULQI_*`). La cuenta de evaluador **no** existe todavía en producción (§9.4).

## 12. Recorrido de compra desde «Mis créditos» (2026-10-29)

El botón «Recargar créditos» no llevaba a ninguna compra donde el checkout está cerrado (producción, o un profesor fuera de la allowlist de staging): ahora **Mis créditos muestra siempre los cinco paquetes** y, con el pago activo, cada tarjeta abre el checkout real de Culqi (ventana de integración). **Producción sigue sin cobros** (sin llaves live ni aprobación comercial): allí la pantalla dice «Ver paquetes y precios» y explica que el pago está en activación; no hay botón de compra que no responda. El recorrido evaluable (evaluador → Mis créditos → Recargar créditos → Esencial → Pagar → Culqi → 10 créditos → historial) es el de **staging** (`culqi-evaluator-handoff.md`). Causa y pruebas: `payments-culqi.md` §16.
