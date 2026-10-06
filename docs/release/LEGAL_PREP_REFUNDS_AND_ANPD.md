# Preparación legal para revisión profesional (BORRADOR — no es asesoría legal)

> Material de trabajo para el abogado del titular. No se publica nada en el sitio con este documento; los textos vigentes son los de `resources/js/Pages/Legal/*.vue`.
> Parte de `docs/release/MOVA_V1_DECISIONS.md` (decisiones adoptadas por delegación del titular, 2026-10-06).

## 1. Discrepancia detectada: Términos §5 vs. política de reembolsos adoptada

- **Texto publicado hoy (Términos §5, versión legal `2026-09-29`):** «Los créditos no son reembolsables ni transferibles entre cuentas, salvo error atribuible a MOVA».
- **Decisión adoptada (DECISIONS §3):** créditos **no usados** reembolsables íntegros a pedido del profesor dentro de **7 días** desde la compra; consumidos o reservados, no.
- **Efecto:** el texto publicado es más restrictivo que la política adoptada. Antes de abrir el cobro público hay que elegir UNA y alinear el sitio:
  - **Opción A (conservar el texto actual):** menor exposición operativa; riesgo a evaluar por el abogado: si el profesor que recarga se considera consumidor, una cláusula de «no reembolsable» podría ser cuestionada
    (Código de Protección y Defensa del Consumidor, Ley 29571; el derecho de arrepentimiento y la regulación de contratación a distancia deben verificarse en la norma vigente: lo hallado en fuentes de Indecopi era una propuesta normativa, no confirmada como vigente).
  - **Opción B (adoptar la política de 7 días):** más protectora del usuario; requiere nueva versión de Términos (`config/legal.php` → `versions.terms`) y reaceptación (el flujo ya existe).
- **Borrador de cláusula para la Opción B (a revisar):** «Puedes solicitar el reembolso de los créditos que no hayas usado dentro de los 7 días siguientes a la compra, escribiendo a <correo de soporte>. Los créditos ya reservados o consumidos no se reembolsan, salvo error atribuible a MOVA. El reembolso se hace por el mismo medio de pago; el plazo de acreditación depende de Mercado Pago o del banco.»

## 2. Registro ante la ANPD y flujo transfronterizo (datos listos; el trámite lo hace el titular)

Fuente normativa: Ley 29733 y su Reglamento (D.S. 016-2024-JUS, vigente desde 31/03/2025). Sin inventar: lo marcado «TITULAR» lo aporta el titular.

| Campo | Contenido preparado desde el código y la Política de Privacidad vigente |
|---|---|
| Titular del banco de datos | TITULAR (la razón social/RUC/domicilio ya cargados en `LEGAL_*`; confirmar que coinciden con el trámite) |
| Banco de datos | Usuarios de MOVA: padres/apoderados, profesores y alumnos menores registrados por su apoderado |
| Finalidad | Gestión de cuentas, agenda y realización de clases en línea, reportes de clase, créditos y pagos de profesores, atención de reclamos |
| Categorías de datos | Identificación y contacto (nombre, correo, celular opcional), foto opcional, datos del alumno (nombre, grado, necesidades de aprendizaje que ingresa el apoderado), reportes de clase, registros de aceptación legal, datos de pago de profesores gestionados por Mercado Pago (MOVA no guarda tarjeta ni código Yape) |
| Menores | Siempre con consentimiento del padre/madre/apoderado (DECISIONS §1) |
| Encargados / destinatarios | Microsoft Azure (alojamiento, México Central), Google (correo Gmail), 8x8/JaaS (videollamadas), Cloudinary (fotos de perfil), Mercado Pago (cobros), Sentry (errores técnicos sin identificadores) |
| Flujo transfronterizo | Cloudinary, 8x8/JaaS, Google y Sentry procesan fuera del Perú: comunicar a la Dirección General de Transparencia, Acceso a la Información Pública y Protección de Datos Personales y documentar garantías contractuales (cláusulas modelo / nivel de protección) con cada proveedor |
| Medidas de seguridad | TLS, contraseñas cifradas, MFA de administrador, control de acceso por roles, ledger de créditos inmutable, copias de seguridad de la base (14 días), secretos fuera del código; ver `docs/release/MOVA_PRODUCTION_RUNBOOK.md` |
| Plazo de conservación | TITULAR / abogado (no definido en el código) |
| Derechos ARCO | Canal de soporte publicado en la Política de Privacidad; confirmar el correo definitivo (`LEGAL_SUPPORT_EMAIL`, hoy un buzón de Gmail del proyecto) |

## 3. Pendiente de abogado (no inventado)
Redacción final de consentimiento de menores, plazo de conservación, opción A/B de reembolso, antecedentes de profesores como requisito (DECISIONS §6), y cláusulas con proveedores extranjeros.
