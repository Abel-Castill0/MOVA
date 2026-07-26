# MOVA — Borrador de Requisitos Legales

> ⚠️ **ESTO ES UN BORRADOR DE TRABAJO, NO UN DOCUMENTO LEGAL VÁLIDO.** Ningún texto de esta página debe publicarse en producción sin revisión de un abogado colegiado en Perú. MOVA opera en Perú, procesa datos de **menores de edad** (alumnos) y coordina pagos entre particulares — son exactamente las tres áreas donde una cláusula mal redactada genera responsabilidad real. Ver notas de riesgo en cada sección.

---

## 1. Cláusula de arbitraje (borrador para Términos de Servicio)

> **Resolución de Controversias.** Cualquier controversia, reclamo o disputa que surja de o esté relacionada con el uso de la plataforma MOVA, incluyendo la existencia, validez, interpretación, alcance o incumplimiento de estos Términos de Servicio, será resuelta mediante arbitraje de derecho, conforme al Reglamento del Centro de Arbitraje de [Cámara de Comercio a definir], con sede en Lima, Perú, y en idioma español. El laudo arbitral será definitivo y vinculante para las partes.

**⚠️ Riesgo específico — NO usar tal cual:**
- En Perú, las cláusulas de arbitraje **obligatorio** frente a consumidores (padres/tutores contratando un servicio, bajo el Código de Protección y Defensa del Consumidor) están sujetas a límites de INDECOPI. Una cláusula que obligue a un consumidor a renunciar a la vía judicial puede ser considerada abusiva si no cumple ciertos requisitos de forma (consentimiento expreso y diferenciado, no solo "aceptar y continuar").
- Recomendación: arbitraje **opcional** o solo aplicable a disputas entre MOVA y profesores (relación más cercana a B2B) — no imponerlo unilateralmente a padres como única vía.

---

## 2. Política de Privacidad — declaración de terceros (borrador)

> **Terceros que procesan datos en MOVA.** Para operar la plataforma, MOVA comparte datos limitados con los siguientes proveedores:
>
> - **Meta Pixel** (Meta Platforms, Inc.) — analítica de campañas publicitarias en páginas públicas. *(Nota: revisar si esto realmente está integrado — no se encontró en el código auditado en `MOVA_MASTER_CONTEXT.md`; no declarar un procesamiento que no ocurre.)*
> - **Google Analytics** (Google LLC) — analítica de uso del sitio. *(Misma nota: no confirmado en el código actual.)*
> - **Google Gemini / OpenAI** — enriquecimiento del diagnóstico pedagógico. El texto enviado se **anonimiza** (se remueven nombres propios) antes de salir de MOVA; nunca se usa para decidir qué profesor se recomienda (eso es un algoritmo determinista, no IA).
> - **Twilio** — envío de códigos de verificación y notificaciones por WhatsApp.
>
> Los padres/tutores pueden solicitar la eliminación de los datos de sus hijos en cualquier momento escribiendo a [correo de contacto].

**⚠️ Riesgo específico:**
- Incluí Meta Pixel y Google Analytics porque los pediste, pero **no encontré ninguna integración de esos dos en el código real de MOVA** (grep sobre el proyecto no muestra ni `fbq(`, ni `gtag(`, ni scripts de esos proveedores). Declarar en una Política de Privacidad el uso de un servicio que no se usa es tan problemático legalmente como no declarar uno que sí se usa. **Antes de publicar esto, confirma si van a integrarse** — si no, hay que quitar esas dos líneas.
- Perú tiene ley propia de protección de datos (Ley N° 29733 y su reglamento), con la Autoridad Nacional de Protección de Datos Personales (ANPD) como ente regulador — no es GDPR ni CCPA. Los datos de **menores de edad** requieren consentimiento del padre/tutor de forma explícita, que ya existe implícitamente en el modelo de MOVA (el alumno nunca tiene cuenta propia), pero eso debe quedar declarado explícitamente en la política.

---

## 3. Cláusula DMCA / Safe Harbor (borrador)

> **Contenido subido por usuarios.** El contenido subido por los usuarios de MOVA (fotografías de perfil, archivos adjuntos en reportes de clase) es responsabilidad exclusiva de quien lo sube. MOVA no asume responsabilidad por infracciones de derechos de autor cometidas por sus usuarios. MOVA retirará cualquier contenido infractor previa notificación fehaciente del titular del derecho.

**⚠️ Riesgo específico — el más importante de los tres:**
- **DMCA (Digital Millennium Copyright Act) es una ley federal de Estados Unidos.** MOVA es una plataforma peruana, para usuarios peruanos, sin indicación de que opere o tenga presencia legal en EE.UU. Copiar una cláusula "DMCA Safe Harbor" tal cual **no aplica automáticamente** en Perú — el marco relevante es el **Decreto Legislativo 822 (Ley sobre el Derecho de Autor)**, que no tiene un mecanismo de "puerto seguro" idéntico al de EE.UU.
- Lo que sí es razonable y me pediste en sustancia (deslindar responsabilidad + mecanismo de retiro ante notificación) lo mantuve en el texto de arriba, pero **renombrado como "Política de Retiro de Contenido"** en vez de "DMCA" — si en algún momento MOVA sirve contenido a usuarios en EE.UU. o aloja infraestructura ahí, ahí sí conviene evaluar agregar una cláusula DMCA formal además de la peruana, no en su lugar.

---

## 4. Integración técnica — Cloudinary para optimización de imágenes de perfil

Estado actual verificado en el código: **Cloudinary no está integrado.** No hay paquete `cloudinary` en `composer.json`, `FILESYSTEM_DISK=local`, y las credenciales de prueba de una sesión anterior fallaron autenticación (`401 cloud_name mismatch`).

Plan de integración:

1. **Paquete:** `composer require cloudinary-labs/cloudinary-laravel` (SDK oficial mantenido para Laravel; expone un disk de Filesystem nativo).
2. **Config:** agregar `CLOUDINARY_URL` a `.env` (formato `cloudinary://API_KEY:API_SECRET@CLOUD_NAME`), publicar config con `php artisan vendor:publish --tag=cloudinary-laravel-config`.
3. **Disk nuevo:** en `config/filesystems.php`, agregar disk `cloudinary` sin reemplazar `local` — los archivos existentes en el `FILESYSTEM_DISK` actual no deben migrarse a ciegas.
4. **Transformación a WebP automática:** las URLs de Cloudinary soportan transformación on-the-fly vía parámetros (`f_auto,q_auto` para formato y calidad automáticos — Cloudinary sirve WebP a navegadores que lo soportan sin necesidad de reconvertir archivos). No requiere procesamiento propio en el backend.
5. **Upload widget:** para el formulario de perfil del profesor (`Teacher/Edit.vue`), usar el Cloudinary Upload Widget vía CDN (`https://upload-widget.cloudinary.com/global/all.js`) con **firma server-side** (nunca exponer `API_SECRET` al frontend) — el backend genera una firma temporal vía un endpoint dedicado (`POST /teacher/profile/avatar-signature`) antes de que el widget suba el archivo directo a Cloudinary.
6. **Alcance:** limitar la primera integración a **foto de perfil del profesor** (`teacher_profiles`, no existe hoy un campo `avatar`/`photo_url` en el esquema — habría que agregarlo vía migración). No extender a "archivos en reportes" en esta fase sin decisión explícita, dado que esos archivos pueden contener información de menores y merecen su propia revisión de privacidad antes de subir a un CDN público de terceros.

---

## 5. Plan de tests E2E con Playwright — flujo crítico completo

`@playwright/test` ya está instalado (`package.json`) pero **sin specs escritas** (confirmado en la auditoría previa). Plan para cubrir el flujo crítico de punta a punta:

```
tests/e2e/
└── critical-flow.spec.js
```

Pasos del flujo, en un solo spec secuencial (cada paso depende del estado dejado por el anterior):

1. **Registro** — crear cuenta padre y cuenta profesor (dos contextos de browser separados, o dos test.step() con logout/login intermedio).
2. **Diagnóstico** — el padre completa el wizard de diagnóstico para un alumno (requiere `Student` ya creado).
3. **Solicitud** — el padre envía una `ClassRequest` desde los resultados del diagnóstico o desde el marketplace.
4. **Agendar** — el profesor acepta la solicitud y agenda (`LessonController::store`), verificar que la clase aparece con `jitsi_room`/`jitsi_password` generados y `price_frozen_pen` calculado.
5. **Pago** — el padre confirma el pago offline (botón "Ya pagué") — requiere mockear o avanzar el reloj del sistema para que `end_time` ya haya pasado (Playwright no puede esperar horas reales; usar `php artisan tinker` o un endpoint de test para ajustar `start_time` de la clase sembrada).
6. **Jitsi** — verificar que el botón "Ingresar a la Sala Virtual" aparece y que el modal monta el iframe/contenedor de `JitsiMeetExternalAPI` (sin necesidad de unirse realmente a una videollamada real en CI — basta verificar que `window.JitsiMeetExternalAPI` se invoca con el `roomName` esperado, interceptando la carga del script).
7. **Reporte** — el profesor sube el reporte pedagógico.
8. **Calificación** — el padre califica al profesor, verificar que el crédito reservado se consume y el estado de la clase pasa a `completed`.

**Datos de prueba:** usar `LocalTestDataSeeder` como base, pero el flujo de agendamiento (paso 4 en adelante) debe partir de una `ClassRequest` **nueva** creada en el propio test — no reutilizar las clases ya sembradas en estado `scheduled`, para no interferir con su propio ciclo de vida.

**Bloqueador conocido:** el paso 5 (pago) depende de que `now() >= lesson.end_time`. En un entorno de test esto requiere either (a) sembrar la clase con `start_time` en el pasado directamente vía base de datos antes de que el test la "descubra" en la UI, o (b) usar `Carbon::setTestNow()` del lado servidor si el test corre contra un servidor Laravel en modo test — la opción (a) es más simple de implementar en Playwright sin tocar el reloj del servidor.

**No implementado en este documento** — esto es un plan, no los specs en sí. Ejecutarlo es trabajo aparte que requiere decidir primero cómo manejar el bloqueador de tiempo del paso 5.
