# MOVA V1 — Registro de decisiones de negocio y legales (2026-10-06)

> **Estado:** decisiones **adoptadas por delegación expresa del propietario** (2026-10-06: «tú toma la mejor decisión, investiga a profundidad»).
> Son las opciones más conservadoras y reversibles que respaldan las fuentes oficiales consultadas. **El propietario decidió operar sin abogado**
> (2026-10-06): estas decisiones son definitivas para V1 y no dependen de una revisión externa; los textos publicados las reflejan. Cada decisión
> indica qué ya está implementado, qué es solo política operativa y qué trámite administrativo queda (siempre del propietario).

## 1. Datos de menores y consentimiento (Ley 29733 y D.S. 016-2024-JUS)

- **Fuente:** el nuevo Reglamento (D.S. 016-2024-JUS, vigente desde el 31/03/2025) considera lícito tratar datos de niños, niñas y adolescentes
  con el consentimiento de quien ejerce la patria potestad o tutela; para servicios digitales dirigidos a menores de 14 años es obligatorio;
  entre 14 y 17 años el adolescente puede consentir según su capacidad si la información se presenta en lenguaje comprensible, y no es libre
  el consentimiento obtenido a cambio de obsequios o beneficios.
- **Decisión:** MOVA trabaja **siempre con consentimiento del padre/madre/apoderado**, sin distinguir la edad del alumno (más protector que el mínimo
  legal). El alumno **no tiene cuenta propia**: lo registra su padre/apoderado, que acepta el texto de `config/legal.php` (`statement`) y la
  Política de Privacidad, con versión y fecha de aceptación (`legal_acceptances`). No se ofrecen obsequios ni beneficios a cambio del consentimiento.
- **Ya implementado:** autorización parental en el alta del alumno, reaceptación al cambiar la versión legal, control parental (aprobación del padre
  de cada solicitud), IA y WhatsApp apagados (la política declara que no se envían datos a IA; `LEGAL_PRIVACY_AI_MISMATCH` lo vigila).
- **Texto:** el consentimiento y la Política de Privacidad vigentes (versión 2026-10-06) son los definitivos de V1; se revisan con datos reales de uso.

## 2. Registro ante la ANPD y flujo transfronterizo

- **Fuente:** el Reglamento exige que el flujo transfronterizo se haga solo hacia países con nivel adecuado de protección o con cláusulas
  contractuales/garantías equivalentes, y que **se ponga en conocimiento de la Dirección General de Transparencia, Acceso a la Información Pública y
  Protección de Datos Personales**; se puede solicitar una opinión previa (respuesta en ≤ 30 días).
- **Decisión:** (a) inscribir el banco de datos «Usuarios/alumnos/profesores de MOVA» y comunicar el flujo transfronterizo; (b) mientras tanto,
  minimizar el dato enviado fuera: Cloudinary (solo avatar), Sentry con `send_default_pii=false`, JaaS (solo nombre mostrado e id de usuario en el
  JWT; sin correo ni teléfono), Gmail SMTP (correos transaccionales), Mercado Pago (cobro de créditos del profesor, no de menores).
- **Encargados/destinos a declarar:** Azure (México Central, base de datos y aplicación), Cloudinary (EE. UU., fotos de perfil), Sentry (errores),
  8x8/JaaS (reuniones), Google (correo), Mercado Pago (pagos).
- **Trámite del titular (gratuito, en línea, no automatizable):** inscribir el banco de datos y comunicar el flujo transfronterizo exige la identificación
  del titular ante la ANPD (gob.pe). Los datos para completarlo están en la tabla de abajo. Es una obligación administrativa del titular; **no impide
  usar la aplicación**, pero conviene hacerlo antes de promocionarla a escala.
- **Plazo de conservación (decidido):** datos de cuenta mientras esté activa; cuenta eliminada ⇒ anonimizada; cuentas inactivas > 2 años pueden anonimizarse;
  registros de pagos/créditos hasta 5 años (obligaciones contables y tributarias); Libro de Reclamaciones al menos 2 años. Publicado en Privacidad §11.

| Campo | Contenido para el formulario de la ANPD |
|---|---|
| Titular | Los datos `LEGAL_*` ya cargados (razón social/RUC/domicilio) |
| Banco de datos | Usuarios de MOVA: padres/apoderados, profesores y alumnos menores registrados por su apoderado |
| Finalidad | Gestión de cuentas, agenda y realización de clases en línea, reportes de clase, créditos y pagos de profesores, atención de reclamos |
| Categorías | Identificación y contacto, foto opcional, datos del alumno (nombre, grado, necesidades que ingresa el apoderado), reportes de clase, registros de aceptación legal, pagos de profesores vía Mercado Pago (MOVA no guarda tarjeta ni código Yape) |
| Menores | Siempre con consentimiento del padre/madre/apoderado |
| Destinatarios/encargados | Microsoft Azure (México Central), Google (correo), 8x8/JaaS (videollamadas), Cloudinary (fotos), Mercado Pago (cobros), Sentry (errores sin identificadores) |
| Transfronterizo | Cloudinary, 8x8/JaaS, Google, Sentry (comunicar a la Dirección General de Transparencia, Acceso a la Información Pública y Protección de Datos Personales) |
| Seguridad | TLS, contraseñas cifradas, MFA de administrador, roles, ledger inmutable, copias de la base (14 días), secretos fuera del código |
| Derechos ARCO | Correo de soporte publicado en la Política de Privacidad (`LEGAL_SUPPORT_EMAIL`) |

## 3. Reembolsos, desistimiento, contracargos y saldo negativo

- **Fuentes:** Código de Protección y Defensa del Consumidor (Ley 29571) y su regulación de contratación a distancia (Indecopi); Mercado Pago permite
  reembolsar un pago hasta **180 días** tras su aprobación, y ante un **contracargo** el importe queda retenido y, si se da por válido, se descuenta al vendedor
  (la resolución puede tardar hasta 6 meses).
- **Decisión de política:**
  1. **Créditos no usados:** reembolsables íntegros a pedido del profesor dentro de **7 días** desde la compra (opción más protectora del consumidor, elegida
     frente a «no reembolsable» porque una exclusión total es la más expuesta ante la Ley 29571 y el costo operativo es bajo) y siempre que no se hayan consumido ni
     reservado; después, no reembolsables salvo error de MOVA. Créditos consumidos (clases dadas): no se reembolsan.
  2. **Quién inicia:** solo un administrador, desde el panel de Mercado Pago (reembolso por el proveedor); el webhook de reembolso **revierte el crédito en el
     ledger** (ya implementado, un único `reversal`).
  3. **Contracargo/saldo negativo:** si un reembolso o contracargo deja el saldo del profesor sin respaldo (créditos ya gastados), el profesor queda **restringido
     para nuevas reservas** hasta regularizar (recargar o resolver), con revisión manual del administrador; **nunca se borra ni edita el ledger** (append-only).
  4. **Lenguaje al usuario:** **publicada en Términos §5 (versión 2026-10-06)**; quien ya aceptó la versión anterior debe reaceptar (flujo existente).
- **Implementado:** reversión por reembolso/contracargo confirmado y reversión manual de admin. **Pendiente de código (post-lanzamiento):** el bloqueo
  automático por saldo negativo (hoy es revisión manual del administrador).

## 4. Asistencia, ausencias y disputas

- **Evidencia disponible:** el webhook de presencia de JaaS (`PARTICIPANT_JOINED/LEFT`) solo guarda evidencia; **no decide** asistencia (decisión previa vigente).
- **Decisión:** (1) si el profesor no se presenta, el padre lo reporta y el administrador cancela la clase: los créditos reservados **se liberan** al profesor
  y el padre no paga nada; (2) si el alumno no se presenta sin avisar con anticipación, la clase se considera dada (el profesor reservó su tiempo) tras el plazo
  de gracia; (3) toda disputa se resuelve **manualmente por el administrador** con la evidencia de presencia y el reporte de clase; (4) los reclamos formales van al
  Libro de Reclamaciones (15 días hábiles, ver ledger). Sin automatismos de sanción en V1.
- **Implementado:** reporte de clase, cancelación por admin, liquidación automática a los 7 días, Libro de Reclamaciones.

## 5. Clases recurrentes

- **Decisión:** **no en V1.** Cada clase se solicita y agenda individualmente; evita compromisos de cobro/consumo automáticos sobre menores. Se revisa con datos reales
  de uso tras el lanzamiento.

## 6. Verificación de profesores

- **Decisión:** el profesor aparece como «verificado» (`is_verified`) **solo tras revisión manual del administrador** de: DNI, evidencia de formación/experiencia y
  declaración de antecedentes (**se exige** certificado de antecedentes penales/policiales vigente por tratarse de trabajo con menores). Sin `is_verified` no recibe solicitudes.
- **Implementado:** bandera `is_verified` y filtro de elegibilidad; **pendiente** (operativo): checklist y custodia de documentos del admin — esos documentos son datos personales
  y quedan bajo el banco de datos del punto 2.

## 7. Modelo de pago entre padres y profesores

- **Estado real:** MOVA **no** cobra a los padres. El **profesor prepaga créditos** (S/ 2 por crédito; 1 crédito = 1 hora) con Mercado Pago/Yape, y el padre,
  al terminar la clase, **confirma el pago/cierre de la clase** en MOVA (`lessons.confirm-payment`, solo clases programadas ya finalizadas); el dinero de la clase no
  pasa por MOVA.
- **Decisión:** mantener ese modelo en V1. Intermediar el dinero entre padres y profesores (cobro, retención, pago a terceros) convertiría a MOVA en
  intermediario de pagos con obligaciones adicionales (conciliación, retenciones, posible regulación de servicios de pago, riesgo de contracargos sobre
  menores); se reevalúa cuando haya volumen y asesoría. **No debe anunciarse** pago padre → profesor dentro de la plataforma.

## 8. Liquidación automática (`LESSON_SETTLEMENT_MODE`)

- **Qué hace:** a los 7 días del fin de una clase no cerrada, consume los créditos reservados del profesor (movimiento **interno del ledger**, idempotente);
  **no mueve dinero real**. La ventana de 7 días ya fue una decisión de negocio confirmada por el propietario.
- **Decisión:** pasar a **`live`** en producción (consentimiento delegado del propietario): sin esto, los créditos de clases que nadie cierra quedarían reservados
  indefinidamente. Riesgo mínimo hoy (sin clases reales); cubierto por PHPUnit/e2e. Revertible con `LESSON_SETTLEMENT_MODE=dry_run`.

## 9. Pagos (Mercado Pago / Yape)

- **Estado:** credenciales live cargadas; Yape es el método principal (checkout Yape). La política de reembolsos ya está publicada (punto 3). El cobro público
  **sigue cerrado** únicamente hasta completar la prueba live mínima del propietario con reembolso; el primer intento (S/ 1, Yape) fue rechazado por Mercado Pago
  con HTTP 400/2072 y la causa solo la puede aclarar su soporte (caso preparado en `MERCADOPAGO_SUPPORT_CASE_2072.md`).
- **Migración a Orders:** la API de Payments sigue operativa (solo correcciones de seguridad/estabilidad, sin fecha de retiro publicada): trabajo técnico posterior.

## Fuentes consultadas
- ANPD / Minjus — nuevo Reglamento de la Ley 29733 (D.S. 016-2024-JUS) y noticias oficiales (gob.pe, El Peruano).
- Indecopi — Libro de Reclamaciones (D.S. 101-2022-PCM, 15 días hábiles); Código de Protección y Defensa del Consumidor (Ley 29571).
- Mercado Pago Developers — reembolsos/cancelaciones y gestión de contracargos (Perú).
