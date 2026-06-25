# Product: MOVA

**Last updated:** 2026-06-25
**Method:** codebase scan + memory context

---

## Product Identity

- **One-liner:** Padres buscan profesores verificados, solicitan clases online para sus hijos, y el sistema confirma la sesión con Zoom automático, recordatorios y seguimiento post-clase — todo sin coordinar por WhatsApp o correo manual.
- **Category:** Marketplace B2C con componente de gestión educativa
- **Product type:** B2C hybrid — padres (compradores), profesores (proveedores), admin (operador). No hay cuentas organizacionales; la unidad es el usuario individual.
- **Collaboration:** multiplayer — padre + profesor + alumno + admin interactúan en el mismo flujo

---

## Business Model

- **Monetización:** Sin pagos implementados actualmente. Modelo futuro: comisión por clase o suscripción al profesor.
- **Pricing tiers:** No definidos todavía.
- **Billing integration:** Ninguno detectado. Regla activa: no tocar pagos en esta fase.

---

## Tech Stack

- **Primary language:** PHP 8.x (Laravel 10) + JavaScript (Vue 3)
- **Framework:** Laravel 10 + Inertia.js + Tailwind CSS
- **Database:** MySQL (Railway, tabla `classes` — no `lessons`)
- **Background jobs:** Laravel Queue (driver: database), worker separado en Railway (`mova-queue`), scheduler separado (`mova-scheduler`, cada 60s)
- **Email:** Gmail API vía HTTPS (no SMTP) — `GmailApiMailService` + `GmailApiMailChannel` + `SafeMailChannel`
- **WhatsApp:** Twilio Sandbox — `WhatsAppChannel`, teléfonos normalizados a E.164 (+51)
- **Video:** Zoom API — `ZoomService`, reuniones creadas automáticamente al aceptar clase
- **Auth:** Laravel Breeze + Spatie Roles (admin, teacher, parent)
- **Deploy:** Railway (3 servicios: web, queue worker, scheduler)

---

## Value Mapping

### Primary Value Action
**Padre solicita y obtiene una clase confirmada con Zoom para su hijo** — Si este flujo cae a cero, el producto ha fallado.

### Core Features (entregan valor directamente)
1. **Marketplace público** — Padres sin cuenta pueden explorar profesores verificados y sus ofertas
2. **Solicitud de clase** — Padre elige oferta, describe la necesidad del alumno, envía solicitud
3. **Aceptación de clase** — Profesor acepta, el sistema crea reunión Zoom automáticamente
4. **Notificaciones multicanal** — Email (Gmail API) + WhatsApp (Twilio) + in-app para todos los eventos clave
5. **Recordatorios automáticos** — 10 min antes de clase vía scheduler

### Supporting Features (habilitan el core)
1. **Registro y verificación** — Email verificado obligatorio, teléfono verificado para WhatsApp
2. **Perfil del profesor** — Bio, materias, tarifa, verificación por admin
3. **Gestión de alumnos** — Padre agrega hijos con nivel escolar y escuela
4. **Admin de profesores** — Verificación / rechazo con notificación al profesor
5. **Panel de notificaciones in-app** — Centro de mensajes para cada rol

---

## Entity Model

### Users
- **ID format:** integer autoincrement
- **Roles (Spatie):** `admin`, `teacher`, `parent`
- **Multi-account:** no — un usuario tiene exactamente un rol
- **Campos clave:** name, email, phone, email_verified_at, phone_verified_at, phone_verification_code_hash

### Students (hijos del padre)
- **ID format:** integer autoincrement
- **Relación:** `parent_user_id → users.id`
- **Campos:** first_name, last_name, birth_date, grade_level (primaria/secundaria/universidad), school
- **Nota crítica:** El alumno no tiene cuenta propia. El padre es quien actúa en su nombre.

### TeacherProfile
- **ID format:** integer autoincrement
- **Relación:** `user_id → users.id` (1:1)
- **Campos actuales:** bio, hourly_rate, zoom_account_id, is_verified
- **Campos faltantes (gap):** experience_years, methodology, availability_schedule real, profile_completeness_score, response_time_avg, classes_completed_count, photo_url

### Subjects
- **Catálogo global** — no pertenece a ningún usuario
- **Relación many-to-many con TeacherProfile** vía `teacher_subject`

### ClassOffer
- **Relación:** `teacher_profile_id`, `subject_id`
- **Campos:** title, description, availability_schedule (JSON), specific_rate, is_active
- **Rol:** Lo que el profesor "publica" en el marketplace

### ClassRequest
- **Relación:** `student_id`, `subject_id`, `class_offer_id` (nullable)
- **Campos:** help_needed, preferred_times (JSON), status
- **Estados:** `pending_parent_approval`, `open`, `accepted`, `rejected`, `completed`
- **Nota:** El padre también puede aprobar/rechazar (control parental ya modelado en rutas)

### Lesson (tabla: `classes`)
- **Relación:** `teacher_profile_id`, `student_id`, `class_request_id`, `class_offer_id`
- **Campos:** start_time, duration_minutes, zoom_meeting_id, zoom_link, zoom_password, status, reminder_sent
- **Estados:** `scheduled`, `in_progress`, `completed`, `cancelled`

---

## Group Hierarchy

MOVA es B2C puro — no hay organizaciones ni equipos. La jerarquía de valor es:

```
User (parent/teacher/admin)
└── Student (hijo del padre)
    └── Lesson (clase programada)
        └── ClassRequest (solicitud origen)
```

No se necesita group tracking al estilo B2B. Los eventos ocurren a nivel `User` y `Lesson`.

---

## Flujos Principales Completos

### Flujo 1: Padre solicita clase (happy path)
```
1. Padre entra al marketplace (público, sin login)
2. Padre ve oferta de profesor verificado
3. Padre hace login / se registra
4. Padre selecciona alumno + describe necesidad
5. ClassRequest creada (status: open)
6. NewClassRequestNotification → profesor (email + in-app)
7. Profesor ve solicitud en /teacher/requests
8. Profesor va a /teacher/requests/{id}/accept
9. Profesor elige fecha/duración → POST /lessons
10. Zoom creado automáticamente
11. ClassRequest → status: accepted
12. Lesson creada (status: scheduled)
13. ClassConfirmedNotification → padre + profesor (email + WhatsApp + in-app)
14. 10 min antes: ClassReminderNotification (scheduler)
15. Clase ocurre en Zoom
```

### Flujo 2: Admin verifica profesor
```
1. Profesor se registra con rol teacher
2. Profesor completa /teacher/setup (perfil básico)
3. Admin ve en /admin/pending-teachers
4. Admin verifica → TeacherVerifiedNotification → profesor (email)
5. Profesor aparece en marketplace con badge verificado
```

### Flujo 3: Padre con control parental (pendiente)
```
1. Hijo solicita clase (flujo alternativo)
2. ClassRequest → status: pending_parent_approval
3. ParentApprovalRequestNotification → padre
4. Padre aprueba → status cambia a open
5. Flujo continúa normalmente
```
*Nota: Las rutas existen (approve/reject) pero el flujo desde frontend es incompleto.*

---

## Diagnóstico: Gaps Críticos Actuales

### Tablas que FALTAN (alto impacto)

| Tabla | Propósito | Impacto si falta |
|-------|-----------|-----------------|
| `lesson_reports` | Reporte post-clase del profesor | Padre no sabe qué pasó |
| `student_learning_profiles` | Perfil académico vivo del alumno | Cada profesor empieza de cero |
| `homework_assignments` | Tareas y recomendaciones post-clase | Sin continuidad |
| `teacher_availability` | Horarios reales del profesor | Coordinación manual |
| `class_attendance` | Registro de asistencia | Sin control real |
| `class_feedback` | Calificación post-clase | Sin reseñas |
| `notification_preferences` | Canal preferido por usuario | Spam o silencio |
| `ai_suggestions` | Sugerencias IA guardadas en DB | Sin trazabilidad |

### Campos que FALTAN en tablas existentes

| Tabla | Campos faltantes |
|-------|-----------------|
| `teacher_profiles` | experience_years, methodology, photo_url, response_time_avg, profile_completeness_score, classes_completed |
| `users` | preferred_notification_channel, timezone |
| `class_requests` | urgency_level, student_level_description |
| `classes` | attended_by_student, attended_by_teacher, completed_at, cancellation_reason |

### Páginas/Flujos que FALTAN o están incompletos

| Módulo | Estado actual | Gap |
|--------|--------------|-----|
| Perfil público del profesor | No existe `/teacher/{id}` público | Padre no puede ver perfil antes de solicitar |
| Reporte post-clase | No implementado | Mayor diferenciador faltante |
| Dashboard padre | Básico | No responde "¿qué aprendió mi hijo?" |
| Dashboard profesor | Básico | No muestra reportes pendientes |
| Dashboard admin | Básico | Sin alertas de riesgo |
| Marketplace - filtros | Sin filtros | No se puede filtrar por materia/nivel/precio |
| Estados vacíos | Mínimos | Experiencia pobre cuando no hay datos |
| Flujo control parental | Backend listo, frontend incompleto | ParentApproval no activado |
| Cancelación por padre | No existe | Solo el profesor puede cancelar |
| Feedback post-clase | No existe | Sin calificaciones |

---

## Top 10 Problemas Reales que MOVA debe resolver

| # | Problema | Usuario | Impacto | Fase |
|---|---------|---------|---------|------|
| 1 | Padre no sabe qué pasó en clase | Padre | Muy alto — pierde confianza y no repite | 2 |
| 2 | No hay filtros en marketplace | Padre | Alto — explorar es difícil con muchos profesores | 1 |
| 3 | No hay perfil público del profesor | Padre | Alto — no puede decidir antes de solicitar | 1 |
| 4 | Profesor no conoce nivel del alumno antes de clase | Profesor | Alto — clase mal calibrada | 2 |
| 5 | Sin continuidad entre clases | Alumno | Alto — cada clase empieza de cero | 2 |
| 6 | Padre no recibe resumen de avance | Padre | Alto — no ve valor a largo plazo | 2 |
| 7 | Perfil del profesor está incompleto en marketplace | Profesor | Alto — menor conversión | 1 |
| 8 | Dashboard padre no responde preguntas clave | Padre | Medio-alto — experiencia confusa | 1 |
| 9 | Profesor pierde tiempo en admin manual | Profesor | Medio | 3 |
| 10 | Sin alertas de riesgo para admin | Admin | Medio | 3 |

---

## Top 15 Automatizaciones Recomendadas

| # | Nombre | Beneficiado | Trigger | Tabla necesaria | Prioridad |
|---|--------|------------|---------|----------------|-----------|
| 1 | Recordatorio 24h antes | Padre + Profesor | `classes.start_time - 24h` | `classes` (ya existe) | Alta |
| 2 | Recordatorio 2h antes | Padre + Profesor | `classes.start_time - 2h` | `classes` | Alta |
| 3 | Link Zoom 10min antes | Padre + Profesor | scheduler actual | `classes` | Alta (ya parcial) |
| 4 | Alerta si nadie entró 5min después | Admin + Padre | `start_time + 5min, no attendance` | `class_attendance` | Alta |
| 5 | Solicitar reporte al profesor post-clase | Profesor | `classes.status = completed` | `lesson_reports` | Alta |
| 6 | Recordar reporte pendiente 2h después | Profesor | Si no hay reporte 2h post-clase | `lesson_reports` | Alta |
| 7 | Notificar padre cuando reporte esté listo | Padre | `lesson_reports.status = sent` | `lesson_reports` | Alta |
| 8 | Resumen semanal al padre (domingo 18h) | Padre | Cron semanal | `classes`, `lesson_reports` | Media |
| 9 | Alerta alumno sin próxima clase 7 días | Padre | `last_class + 7d, no next class` | `classes` | Media |
| 10 | Alerta solicitud sin respuesta 48h | Admin | `class_requests.created_at + 48h, status=open` | `class_requests` | Media |
| 11 | Score de completitud del perfil docente | Profesor | En cada update de perfil | `teacher_profiles` | Media |
| 12 | Sugerir próxima clase post-reporte | Profesor | `lesson_reports` creado | `classes` | Media |
| 13 | Alerta clase sin reporte 24h | Admin | `classes.end_time + 24h, no report` | `lesson_reports` | Media |
| 14 | Recordar al padre confirmar asistencia | Padre | 2h antes de clase | `classes` | Baja |
| 15 | Email bienvenida con guía para padres | Padre | Registro completado | `users` | Alta |

---

## Top 7 Funciones IA Útiles

| # | Función | Implementación | Requiere revisión humana | Tabla de salida |
|---|---------|---------------|-------------------------|----------------|
| 1 | **Diagnóstico inicial del alumno** — 5 preguntas guiadas para el padre, genera descripción estructurada para la solicitud | Claude API, prompt → texto | No (es asistencia al padre) | `class_requests.help_needed` |
| 2 | **Matching explicable profesor-alumno** — recomienda 3 profesores con razón clara ("tiene experiencia en Álgebra básica para secundaria") | Reglas + Claude API para el texto explicativo | No | Frontend only, no DB |
| 3 | **Reporte post-clase asistido** — El profesor llena 4 campos → IA genera reporte legible para padre → profesor revisa y aprueba | Claude API | **Sí — profesor aprueba antes de enviar** | `lesson_reports` |
| 4 | **Plan de próxima clase** — Basado en el reporte anterior, sugiere tema, objetivos y materiales | Claude API | **Sí — profesor revisa** | `lesson_reports.next_plan` |
| 5 | **Tareas simples post-clase** — 2-3 ejercicios sugeridos según el tema trabajado | Claude API | **Sí — profesor aprueba** | `homework_assignments` |
| 6 | **Resumen semanal para el padre** — Consolida reportes de la semana en lenguaje simple y positivo | Claude API | No (informativo) | Generado on-demand |
| 7 | **Alerta de riesgo** — Detecta patrones: alumno sin asistencia, profesor sin respuesta, clase sin reporte, solicitud sin contestar 48h | Reglas deterministas (no IA generativa) | No | `admin dashboard` |

**Reglas IA inamovibles:**
- Nunca responder tareas directamente al alumno
- Siempre guardar en DB con `generated_by: ai`, `approved_by: teacher_id`
- No entrenar modelos con datos del usuario
- Logs de uso sin contenido sensible

---

## Roadmap por Fases

### Fase 1 — Producto base elegante (ahora)
**Objetivo:** MOVA se ve y funciona como una app profesional en todas las pantallas

- [ ] Perfil público del profesor (`/teacher/{id}`)
- [ ] Filtros en marketplace (materia, nivel, precio, verificado)
- [ ] Tarjeta de profesor mejorada en marketplace
- [ ] Dashboard padre con información útil (próxima clase, estado solicitudes, mis clases)
- [ ] Dashboard profesor con pendientes claros
- [ ] Dashboard admin con métricas básicas
- [ ] Estados vacíos profesionales en todas las páginas
- [ ] Score de completitud del perfil docente
- [ ] Email de bienvenida para padres nuevos
- [ ] Responsive completo (mobile-first)
- [ ] Recordatorio 24h y 2h antes (scheduler)

### Fase 2 — Seguimiento real del aprendizaje
**Objetivo:** El padre siente que MOVA acompaña el progreso de su hijo

- [ ] Reporte post-clase (`lesson_reports`) — formulario simple para profesor
- [ ] Ficha del alumno actualizable por el profesor
- [ ] Tareas y recomendaciones post-clase (`homework_assignments`)
- [ ] Historial académico del alumno visible para el padre
- [ ] Resumen semanal automático al padre
- [ ] Próxima clase sugerida post-reporte

### Fase 3 — Automatización operativa
**Objetivo:** Reducir carga administrativa de profesores y admin

- [ ] Recordatorio post-clase para reporte (2h y 24h después)
- [ ] Alerta alumno sin próxima clase (7 días)
- [ ] Alerta solicitud sin respuesta (48h)
- [ ] Panel admin de riesgos (clases sin reporte, solicitudes sin contestar)
- [ ] Preferencias de notificación por usuario
- [ ] Cancelación por parte del padre
- [ ] Flujo control parental completo (pending_parent_approval)

### Fase 4 — IA útil
**Objetivo:** IA asistente que ahorra tiempo y mejora la calidad

- [ ] Asistente diagnóstico para padres (al solicitar clase)
- [ ] Matching explicable de profesor
- [ ] Reporte post-clase asistido por IA (Claude API)
- [ ] Plan de próxima clase sugerido por IA
- [ ] Tareas simples generadas y aprobadas por profesor
- [ ] Resumen semanal generado por IA

### Fase 5 — Calidad, confianza y seguridad
**Objetivo:** MOVA es confiable para padres con hijos menores

- [ ] Feedback post-clase (calificación padre al profesor)
- [ ] Score visible del profesor (% completitud, clases realizadas, calificación)
- [ ] Tiempo de respuesta promedio del profesor
- [ ] Historial de clases visible para el padre
- [ ] Botón "Reportar problema" en clase/profesor
- [ ] Panel admin de calidad (profesores por score)
- [ ] Protección de datos del alumno (menores)
- [ ] Auditoría de acciones sensibles

---

## Quick Wins (implementar ya)

| Win | Impacto | Esfuerzo | Archivos afectados |
|-----|---------|----------|-------------------|
| Filtros en marketplace (materia, nivel) | Alto | Bajo | `MarketplaceController.php`, `Marketplace/Index.vue` |
| Estados vacíos profesionales | Medio-alto | Bajo | Todas las páginas Index |
| Recordatorio 24h antes en scheduler | Alto | Bajo | `SendClassReminders.php` (extender) |
| Score de completitud del perfil docente | Medio | Bajo | `TeacherProfile.php` (campo computed) |
| Email de bienvenida al padre | Alto | Bajo | Nueva `WelcomeParentNotification` |
| Dashboard padre: "próxima clase" prominente | Alto | Medio | `Dashboard/Parent.vue`, `DashboardController.php` |
| Perfil público del profesor | Alto | Medio | Nueva ruta + página `Teacher/Public.vue` |

---

## Funciones que NO conviene implementar todavía

| Función | Razón |
|---------|-------|
| Chatbot IA para alumnos | Riesgo pedagógico y de privacidad con menores |
| IA que responda tareas | Contraproducente para el aprendizaje |
| Pagos en línea | Complejidad regulatoria, no es el cuello de botella ahora |
| Sistema de reseñas públicas | Necesita masa crítica de clases primero |
| App móvil nativa | PWA o responsive es suficiente para el MVP |
| Panel de mensajería interna | WhatsApp ya cubre la comunicación urgente |
| IA que evalúe al alumno | Requiere supervisión pedagógica real |
| Multi-idioma | El mercado objetivo es hispanohablante |

---

## Tabla Impacto / Esfuerzo

```
ALTO IMPACTO
│
│  ● Reporte post-clase          ● Perfil público profesor
│  ● Filtros marketplace         ● Dashboard padre mejorado
│  ● Recordatorio 24h/2h
│  ● Email bienvenida padre      ● Ficha académica alumno
│
│  ● Score completitud profesor  ● Resumen semanal padre
│  ● Estados vacíos              ● Dashboard admin riesgos
│
│                                ● IA matching profesor
│                                ● IA reporte asistido
│
BAJO IMPACTO
└────────────────────────────────────────────────────────
  BAJO ESFUERZO                              ALTO ESFUERZO
```

---

## Current State (tracking)
- **Existing tracking:** Ninguno implementado
- **Documentation:** Parcial (SETUP.md, DEPLOY_RAILWAY.md)
- **Known gaps:** Sin analytics, sin logging de eventos de negocio, sin métricas de conversión marketplace

## Integration Targets (futuro)
| Destino | Propósito | Prioridad |
|---------|-----------|-----------|
| PostHog (self-hosted o cloud) | Analytics de producto, funnels, session replay | Media |
| Sentry (ya instalado) | Error tracking | Ya activo |
| Railway Metrics | Infra | Ya activo |

---

## Codebase Observations
- **Feature areas confirmadas:** marketplace, class-offers, class-requests, lessons, teacher-profile, students, admin, notifications, auth
- **Entity model confirmado:** User → [Student | TeacherProfile] → ClassOffer → ClassRequest → Lesson
- **Scheduler corre cada 60s** — ideal para recordatorios granulares
- **Queue procesando limpio** — failed_jobs = 0, base sólida para automatizaciones
- **Tabla `classes` (no `lessons`)** — importante para queries directas
- **ClassRequest.status incluye `pending_parent_approval`** — flujo de control parental ya modelado, falta frontend
