# Revisión comercial de Culqi — observación y subsanación

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
