# MOVA Production Readiness Audit
**Fecha:** 2026-06-28  
**Auditor:** Claude Sonnet 4.6  
**Base:** Railway URL + Gmail API + Gemini IA desactivada

---

## Estado general

| Criterio | Estado |
|----------|--------|
| Lista para beta con Railway URL y Gmail API | **SÍ, con 5 acciones previas** |
| Lista para producción comercial completa | **NO** — falta legal, backups, bloqueo de usuarios |
| Riesgo principal | **Ausencia de páginas legales mínimas (terms + privacy policy)** con menores involucrados |
| Recomendación | Resolver los 5 bloqueadores de seguridad/legal en 1–2 días, luego abrir beta invitada con 10–15 familias |

---

## Bloqueadores reales antes de usuarios beta

| # | Área | Brecha | Severidad | Archivo/Ruta afectada | Acción recomendada |
|---|------|--------|-----------|----------------------|-------------------|
| 1 | **Legal** | No existen páginas de Términos y Condiciones ni Política de Privacidad. El registro no requiere aceptación de términos. Con menores involucrados esto es obligatorio. | 🔴 ALTA | `routes/web.php`, `resources/js/Pages/Auth/Register.vue` | Crear rutas `/terminos` y `/privacidad` (pueden ser páginas estáticas simples). Agregar checkbox de aceptación en registro. |
| 2 | **Legal** | No hay página ni email de soporte. Los usuarios beta no tienen cómo reportar problemas. | 🔴 ALTA | `resources/js/Pages/Welcome.vue`, layouts | Agregar email de soporte (Gmail temporal válido) en footer y en páginas de error. |
| 3 | **Datos** | Datos de QA contaminan la DB: diagnósticos 12–19, usuarios de prueba con texto real de dificultades académicas. Si se abre beta, usuarios reales verían slots usados por datos de prueba. | 🟠 MEDIA | MySQL `student_diagnostics`, `users`, `diagnostic_recommendations` | Limpiar registros QA con comando artisan puntual. Documentar cuáles son seguros de borrar. |
| 4 | **Seguridad** | `SESSION_SECURE_COOKIE` no está configurado explícitamente en Railway. Railway fuerza HTTPS en el proxy, pero la cookie de sesión no tiene flag `Secure` activo a nivel de Laravel. | 🟠 MEDIA | Railway env var | Agregar `SESSION_SECURE_COOKIE=true` en Railway service MOVA. 1 línea. |
| 5 | **Seguridad** | No hay rate limit en rutas de escritura sensibles: `POST /diagnostics`, `POST /class-requests`, `POST /lessons`. Login sí tiene throttle (5 intentos). El resto no. | 🟠 MEDIA | `routes/web.php`, `app/Http/Kernel.php` | Agregar `->middleware('throttle:10,1')` a los grupos `role:parent` y `role:teacher` que hacen POST. |
| 6 | **Admin** | No existe función de bloqueo/suspensión de usuarios. Un usuario que abuse, envíe contenido inapropiado o sea reportado no puede ser desactivado sin acceso directo a DB. | 🟠 MEDIA | `app/Http/Controllers/AdminController.php`, `routes/web.php` | Agregar campo `is_blocked` en `users`. Ruta `POST /admin/users/{user}/block`. 1 día de trabajo. |
| 7 | **Datos** | Railway MySQL en plan Hobby no tiene backups automáticos configurados. Pérdida de datos de usuarios reales es irrecuperable sin backup. | 🟠 MEDIA | `app/Console/Kernel.php`, Railway | Documentar plan de backup manual (export semanal). Idealmente: upgrade a Railway Pro o configurar dump automático a S3. |
| 8 | **IA** | No hay límite de diagnósticos con IA por usuario/día. Con `DIAGNOSTIC_AI_ENABLED=true`, un padre podría generar cientos de llamadas a Gemini. Actualmente el flag está `false`, mitigando el riesgo. | 🟡 BAJA | `app/Services/DiagnosticAiEnrichmentService.php` | Antes de activar IA en producción, agregar columna `ai_diagnostics_today` o cache por IP. No bloqueador mientras flag=false. |
| 9 | **IA** | No hay logging de uso de IA (cuántas llamadas, qué provider, cuántos tokens consumidos). Imposible controlar costos en producción. | 🟡 BAJA | `app/Services/DiagnosticAiEnrichmentService.php` | Agregar tabla `ai_usage_logs` o contador en Redis antes de activar IA en producción. No bloqueador mientras flag=false. |

---

## Lo que SÍ está bien (no necesita acción antes de beta)

| Área | Estado |
|------|--------|
| `APP_ENV=production` | ✅ Confirmado en Railway |
| `APP_DEBUG=false` | ✅ Confirmado en Railway |
| HTTPS | ✅ Railway fuerza HTTPS en proxy; TrustProxies configurado con `$proxies='*'` |
| TrustProxies | ✅ Activo, todos los headers X-Forwarded-* correctos |
| Cookies cifradas | ✅ `EncryptCookies` en middleware web |
| CSRF | ✅ `VerifyCsrfToken` en middleware web |
| Roles y permisos | ✅ Spatie Permissions; `role:parent`, `role:teacher`, `role:admin` en rutas |
| Rate limit en login | ✅ 5 intentos; `throttle:6,1` en verificación email |
| Email verificado obligatorio | ✅ Middleware `verified` en todas las rutas autenticadas |
| Datos sensibles no expuestos | ✅ Email/teléfono del profesor no visible al padre antes de aceptar |
| `difficulty_text` no expuesto al profesor | ✅ Verificado en QA 16 y 17 |
| `ai_summary` no expuesto al profesor | ✅ Verificado en QA 18 y 19 |
| Queue connection | ✅ `QUEUE_CONNECTION=database` |
| Session driver | ✅ `SESSION_DRIVER=database` |
| Fallback IA automático | ✅ `ai_used_fallback=true` cuando IA falla, nunca lanza excepción |
| `DIAGNOSTIC_AI_ENABLED=false` | ✅ Confirmado en Railway |
| Privacy notice en wizard | ✅ Paso 3 Create.vue muestra aviso de análisis automático |
| Datos no enviados a Gemini | ✅ Solo `subject_name`, `level`, `difficulty_text` anonimizado, `goal`, `urgency` |
| Flujo padre completo | ✅ registro → diagnóstico → solicitud → clase → reporte |
| Flujo profesor completo | ✅ registro → perfil → oferta → aceptar → clase → reporte |
| Flujo admin completo | ✅ verificar/rechazar profesor, gestionar usuarios |
| Cancelar clase | ✅ Profesor puede cancelar; notifica a padre y profesor |
| Recordatorios automáticos | ✅ `SendClassReminders` cada minuto; 24h, 2h, 10m antes |
| Gmail API | ✅ `MAIL_MAILER=gmail_api` configurado |
| WhatsApp Twilio | ✅ Configurado (Sandbox — requiere que cada número se una) |
| In-app notifications | ✅ Tabla `notifications`, `NotificationController` |
| `failed_jobs=0` | ✅ Verificado en DB |
| `pending_jobs=0` | ✅ Verificado en DB |
| `/healthz` | ✅ Retorna 200 |
| Responsive móvil | ✅ Tailwind, max-w-2xl, grids responsive |
| Métricas admin básicas | ✅ Dashboard muestra usuarios, profesores pendientes, clases hoy, jobs fallidos |
| Migraciones no destructivas | ✅ Todas las migraciones usan `nullable`, `addColumn`, sin drops |
| QA 16/17/18/19 | ✅ 56 tests pasando |

---

## No bloqueadores por ahora

Estas brechas son **mejoras post-lanzamiento** y no impiden una beta real:

| Ítem | Por qué no es bloqueador |
|------|--------------------------|
| Dominio propio | La URL de Railway funciona perfectamente para beta. Usuarios técnicos la aceptan. |
| Correo corporativo (hola@mova.pe) | Gmail API desde cuenta personal funciona para beta pequeña. El límite de Gmail API (500 emails/día) es más que suficiente para 10–50 familias. |
| Email transaccional con dominio propio (Resend/Postmark) | Solo necesario cuando se superen los límites de Gmail API o se requiera deliverability profesional. |
| Branding final de email | El contenido importa más que el from en beta. |
| Reprogramar clase (reschedule) | El flujo actual permite cancelar y crear nueva. Suficiente para MVP. |
| Reseñas / ratings | No hay sistema de reviews. Útil post-beta para trust. |
| Admin puede ver todas las clases/solicitudes | Dashboard actual muestra métricas agregadas. Suficiente para beta pequeña. |
| TrustHosts (comentado) | Riesgo bajo con Railway que controla el dominio; no hay CDN externo malicioso. |
| Logs de uso IA | Mientras `DIAGNOSTIC_AI_ENABLED=false`, no hay costo ni uso. |
| Límites diarios IA por usuario | Mismo caso: irrelevante mientras flag está off. |

---

## Plan de implementación final

| # | Fase | Objetivo | Archivos probables | QA requerido | Riesgo | Días |
|---|------|----------|-------------------|--------------|--------|------|
| **1** | **Legal mínimo** | Páginas de términos, privacidad y soporte; checkbox en registro | `routes/web.php`, `Register.vue`, nueva `Terms.vue`, nueva `Privacy.vue` | Verificar que registro requiere checkbox; páginas cargan sin auth | Bajo | 1 |
| **2** | **SESSION_SECURE_COOKIE** | Agregar variable en Railway (1 línea) | Railway env vars | Verificar cookie flag en DevTools | Muy bajo | < 1h |
| **3** | **Limpieza datos QA** | Comando artisan puntual para borrar diagnósticos 12–19 y usuarios QA | `database/` (comando puntual, no seeder) | Verificar DB antes/después | Bajo | < 1h |
| **4** | **Rate limiting en POST routes** | Throttle en rutas de escritura del padre y profesor | `routes/web.php` | QA 16–19 deben seguir pasando | Bajo | < 1h |
| **5** | **Bloqueo de usuarios admin** | Columna `is_blocked`, ruta y botón en admin | `AdminController.php`, `Admin/Users.vue`, migración | Test: usuario bloqueado no puede loguearse | Bajo-medio | 1 |
| **6** | **Backup MySQL** | Script export semanal o documenta manual | `docs/BACKUP_PLAN.md`, Railway schedule | Verificar que el dump funciona y se puede restaurar | Medio | 1 |
| **7** | **Twilio Sandbox → producción** | Salir de sandbox para enviar WhatsApp a cualquier número sin join previo | Railway env vars, `WhatsAppChannel.php` | Envío real a número de prueba | Medio | Depende de aprobación Twilio |
| **8** | **AI usage logging** | Tabla o cache contador antes de activar Gemini en producción | `DiagnosticAiEnrichmentService.php`, migración | Verificar que se registra cada llamada | Bajo | 1 |

---

## Orden recomendado de implementación

1. **`SESSION_SECURE_COOKIE=true`** en Railway — 10 minutos, cero riesgo
2. **Rate limiting POST routes** — 30 minutos, agregar `throttle:10,1` en grupos de rutas, correr QA 16–19
3. **Páginas legales mínimas** — 1 día: Terms.vue, Privacy.vue estáticas, checkbox en registro
4. **Limpieza de datos QA** — comando artisan puntual, 30 minutos, previa revisión de qué IDs son seguros
5. **Bloqueo de usuarios en admin** — 1 día: migración + controller + UI
6. **Documentar plan de backup** — 2 horas: instrucciones claras para export manual MySQL semanal
7. **Contactar Twilio para salir de sandbox** — paralelo; puede tardar días de aprobación
8. **AI usage logging + límites** — antes de activar `DIAGNOSTIC_AI_ENABLED=true` con usuarios reales

---

## Resumen ejecutivo

MOVA tiene la infraestructura técnica lista para beta: flujos completos, notificaciones, diagnóstico con IA validada, QA passing, producción en Railway con HTTPS y cero failed_jobs.

Los únicos bloqueadores reales son:
- **Legal**: sin términos ni privacidad no se puede operar con menores en Perú
- **Datos**: hay que limpiar los datos QA antes de abrir registro real  
- **Seguridad básica**: dos configuraciones de 30 minutos (SESSION_SECURE_COOKIE + throttle)
- **Admin safety**: sin poder bloquear usuarios hay riesgo de abuso sin respuesta posible

Con los ítems 1–5 del orden de implementación resueltos (estimado: 2–3 días), MOVA está lista para abrir una beta invitada con 10–30 familias.
