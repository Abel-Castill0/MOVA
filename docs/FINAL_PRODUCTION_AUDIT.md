# MOVA Final Production Audit

**Fecha:** 2026-06-28  
**Auditor:** Claude Code (Sonnet 4.6)  
**Metodología:** Lectura completa de controladores, modelos, migraciones, notificaciones, vistas Vue, scheduler, config, y canal WhatsApp. Sin modificar código ni datos.

---

## Estado general

| Pregunta | Respuesta |
|---|---|
| **Lista para beta** | ✅ Sí — 71/71 QA pasando, healthz=200, prod estable |
| **Lista para producción final** | ❌ No — 4 bloqueadores HIGH, 7 MEDIUM |
| **Principal brecha final** | Sistema de reviews/ratings inexistente + WhatsApp en sandbox |
| **Recomendación ejecutiva** | Implementar reviews y salir de WhatsApp sandbox antes de operar con usuarios reales |

---

## Bloqueadores para FINAL

### 🔴 HIGH — Deben resolverse antes de declarar FINAL

| Área | Brecha | Severidad | Archivo/Ruta afectada | Acción exacta |
|---|---|---|---|---|
| **Marketplace** | No existe sistema de reviews ni ratings. El perfil del profesor (`/teachers/{id}`) solo muestra `classes_completed`. Un usuario real no puede evaluar a un profesor ni leer opiniones de otros padres. | 🔴 HIGH | Ningún modelo `Review` existe — no hay tabla, modelo, controlador, ruta ni Vue | Crear tabla `reviews`, modelo, rutas `/lessons/{lesson}/review`, vista Vue, y mostrar rating en marketplace y perfil público |
| **WhatsApp** | `WhatsAppChannel.php` está configurado para Twilio Sandbox. El error 63007 aparece cuando un usuario real no está unido al sandbox. Los usuarios reales no pueden recibir WhatsApp sin unirse manualmente al sandbox de Twilio. | 🔴 HIGH | `app/Channels/WhatsAppChannel.php:41`, variable `TWILIO_WHATSAPP_FROM` | Registrar número de WhatsApp Business en Twilio (o usar Twilio Content API aprobado por Meta). Cambiar variable a número productivo. |
| **Admin rechaza profesor = hard delete** | `AdminController.rejectTeacher()` llama `$teacher->user->delete()`. Borra permanentemente el registro de usuario sin email de notificación, sin soft-delete, sin posibilidad de recuperar. Si es un error del admin es irrecuperable. | 🔴 HIGH | `app/Http/Controllers/AdminController.php:37` | Cambiar a soft-delete con `SoftDeletes` o a estado `rejection_reason` + notificación email al profesor rechazado. No hard delete. |
| **Backups MySQL** | No hay estrategia documentada de backup, ni prueba de restauración, ni procedimiento de rollback. Railway ofrece backups automáticos solo en planes de pago. Si hay corrupción o error de migración, no hay playbook de recuperación. | 🔴 HIGH | Ningún archivo de documentación. Depende del plan Railway. | Verificar si el plan Railway incluye backups automáticos, documentar frecuencia y retención, probar restauración una vez, crear `docs/DISASTER_RECOVERY.md` |

---

### 🟡 MEDIUM — Requeridos para operación real sostenida

| Área | Brecha | Severidad | Archivo/Ruta afectada | Acción exacta |
|---|---|---|---|---|
| **Operación clases** | El profesor NO puede rechazar una solicitud de clase. `ClassRequestController` tiene `accept()` pero no tiene acción `reject()` para el profesor. El profesor solo puede ignorar o aceptar. | 🟡 MEDIUM | `app/Http/Controllers/ClassRequestController.php`, `resources/js/Pages/ClassRequests/TeacherIndex.vue` | Agregar `POST /teacher/requests/{classRequest}/reject` con validación de `rejection_reason` y notificación al padre. |
| **Operación clases** | No existe `cancel_reason` en la tabla `classes`. `LessonController.cancel()` cancela sin motivo. La notificación al padre dice "contacta al equipo MOVA" sin ningún detalle. | 🟡 MEDIUM | `database/migrations/2024_01_01_000008_create_classes_table.php`, `app/Http/Controllers/LessonController.php:116` | Agregar migración `add_cancel_reason_to_classes_table`, requerir razón en `cancel()`, incluirla en `ClassCancelledNotification`. |
| **Operación clases** | No existe función de reprogramar clase. Cancelar = perder la clase permanentemente. La notificación de cancelación menciona "reagendar" pero no hay ruta ni UI para hacerlo. | 🟡 MEDIUM | No existe ningún archivo de reschedule | Agregar `POST /lessons/{lesson}/reschedule` (teacher only), validar `new_start_time`, cancelar Zoom meeting viejo, crear nuevo, notificar padre. |
| **Operación clases** | El padre NO puede cancelar una clase desde su vista. `LessonController.cancel()` requiere `teacher_profile_id`. `ParentIndex.vue` no tiene botón de cancelar. | 🟡 MEDIUM | `app/Http/Controllers/LessonController.php:116`, `resources/js/Pages/Lessons/ParentIndex.vue` | Agregar `POST /my-classes/{lesson}/cancel` para padres, con validación de ownership, motivo opcional, notificación al profesor. |
| **Admin** | No hay vistas admin de clases, solicitudes, reportes ni diagnósticos globales. Admin solo puede ver usuarios y profesores pendientes. No puede intervenir en operación o reportar irregularidades. | 🟡 MEDIUM | `app/Http/Controllers/AdminController.php`, `routes/web.php:96-103` | Agregar rutas admin: `GET /admin/lessons`, `/admin/requests`, `/admin/reports`, `/admin/diagnostics` con vistas de solo lectura + capacidad de cancelar/resolver. |
| **IA** | `DiagnosticAiEnrichmentService` no tiene límites diarios ni mensuales de tokens. Si `DIAGNOSTIC_AI_ENABLED=true` y hay muchos diagnósticos, el costo de API puede desbordarse. No hay auto-apagado por cuota. | 🟡 MEDIUM | `app/Services/DiagnosticAiEnrichmentService.php`, `config/diagnostic.php` | Agregar `DIAGNOSTIC_AI_DAILY_LIMIT` (ej. 100 llamadas/día). Guardar contador en cache o tabla. Si se supera, log + fallback automático. |
| **Legal** | Los archivos `/terminos` y `/privacidad` contienen texto marcado explícitamente como "Beta". Para producción final necesitan versión definitiva con retención de datos, derechos de eliminación, base legal del tratamiento de datos de menores. | 🟡 MEDIUM | `resources/js/Pages/Legal/Terms.vue`, `resources/js/Pages/Legal/Privacy.vue` | Revisar y actualizar texto legal para versión final. Remover referencias a "beta". Agregar artículo de derechos ARCO (Ley 29733 Perú). |

---

### 🟢 LOW — Mejoras post-lanzamiento pero recomendadas pronto

| Área | Brecha | Severidad | Archivo/Ruta afectada | Nota |
|---|---|---|---|---|
| **Admin** | No hay audit log de acciones admin. No queda registro de qué admin suspendió a qué usuario o cuándo. | 🟢 LOW | `app/Http/Controllers/AdminController.php` | Crear tabla `admin_audit_logs` o usar un observer de User/TeacherProfile. |
| **IA** | No hay panel admin de consumo de IA. No se puede saber cuántas llamadas se hicieron, a qué costo estimado, o si hubo muchos fallbacks. | 🟢 LOW | No existe tabla `ai_usage_logs` | Agregar tabla `diagnostic_ai_usage` con `diagnostic_id`, `provider`, `tokens`, `success`, `created_at`. |
| **Soporte** | La página de soporte es solo un `mailto:`. No hay formulario, ticket system ni tiempo de respuesta prometido. | 🟢 LOW | Footer, `Suspended.vue` | Agregar página `/soporte` con formulario simple o link a Typeform/Tally mientras se implementa algo propio. |
| **Clases** | El estado `in_progress` existe en `classes.status` ENUM pero nunca se usa. `complete()` va directo de `scheduled` a `completed`. | 🟢 LOW | `database/migrations/2024_01_01_000008_create_classes_table.php` | Eliminar `in_progress` del ENUM o implementarlo si se quiere tracking en tiempo real. |
| **Notificaciones** | El profesor rechazado no recibe email. `AdminController.rejectTeacher()` borra sin notificar. | 🟢 LOW (parte del HIGH #3) | `app/Http/Controllers/AdminController.php:38` | Enviar `TeacherRejectedNotification` antes de eliminar (o marcar como rechazado). |
| **Seguridad** | Solo 3 rutas POST tienen `throttle`. Admin routes no tienen rate limit. | 🟢 LOW | `routes/web.php:96-103` | Agregar `throttle:30,1` al grupo admin para evitar scraping. |
| **Marketplace** | Sin filtros por precio, por número de clases completadas, por materia avanzada. Depende solo del orden de registro. | 🟢 LOW | `app/Http/Controllers/MarketplaceController.php` | Agregar filtros de precio, nivel, y orden por `classes_completed` desc. |
| **Consentimiento** | El checkbox de términos en registro es genérico. Para datos de menores (alumnos), Ley 29733 Perú requiere consentimiento más explícito. | 🟢 LOW | `resources/js/Pages/Auth/Register.vue` | Agregar texto adicional: "Confirmo que soy mayor de edad y doy consentimiento para el tratamiento de datos de mis hijos." |

---

## No bloqueadores (excluidos por decisión del usuario)

| Ítem | Motivo |
|---|---|
| **Dominio propio** | Se configurará más adelante |
| **Correo corporativo** | Gmail API es funcional para beta y primera etapa de producción |
| **Branding final de email** | Las plantillas actuales son funcionales |

Adicionalmente, los siguientes ítems están funcionando correctamente y **no son brechas**:

- ✅ Flujo padre completo: diagnóstico → recomendación → solicitud → clase → reporte  
- ✅ Flujo profesor: setup → ofertas → solicitudes → aceptar → clase → reporte  
- ✅ Zoom automático en aceptar clase  
- ✅ Recordatorios 24h + 2h por email y WhatsApp (cuando usuario está en sandbox)  
- ✅ in-app notifications, mark-read, mark-all-read  
- ✅ Suspensión/reactivación de usuarios con middleware  
- ✅ Rate limits en /diagnostics, /class-requests, /lessons  
- ✅ SESSION_SECURE_COOKIE, APP_ENV=production, APP_DEBUG=false  
- ✅ Eliminación de cuenta (ProfileController.destroy con confirmación de contraseña)  
- ✅ Email verificado requerido para notificaciones  
- ✅ Privacidad: `difficulty_text` anonimizado antes de enviarse a IA  
- ✅ Fallback determinista siempre activo (IA desactivada = scoring funciona igual)  
- ✅ DIAGNOSTIC_AI_ENABLED=false en producción  
- ✅ failed_jobs=0, pending_jobs=0  
- ✅ Datos de menores: solo padre puede ver resultados del diagnóstico de sus hijos  
- ✅ Solo profesores verificados + ofertas activas en recomendaciones  
- ✅ Legal mínimo: /terminos, /privacidad, checkbox en registro  
- ✅ Admin dashboard: 6+ métricas operativas  
- ✅ 71/71 QA tests passing (suites 16-20)

---

## Plan final de implementación

| Fase | Objetivo | Archivos probables | QA requerido | Riesgo | Tiempo estimado |
|---|---|---|---|---|---|
| **F1 — Críticos de datos** | Corregir hard-delete de profesor + agregar soft-delete o rejection flow | `AdminController.php`, nueva migración `User` SoftDeletes o `TeacherProfile.rejection_reason`, nueva `TeacherRejectedNotification.php` | QA: admin rechaza profesor → recibe email, datos conservados | Bajo — no afecta flujo existente | 2-3h |
| **F2 — WhatsApp producción** | Registrar número de WhatsApp Business en Twilio y actualizar `TWILIO_WHATSAPP_FROM` | `app/Channels/WhatsAppChannel.php` (sin cambios de código), Railway env vars | QA: usuario real recibe WhatsApp en número propio | Medio — requiere aprobación de Meta/Twilio (puede tomar días) | 1h técnico + tiempo de aprobación |
| **F3 — Gestión de clases** | Cancel con motivo + Reschedule + Profesor rechaza solicitud + Padre cancela | `LessonController.php`, `ClassRequestController.php`, migraciones `cancel_reason` y `reschedule`, 4 nuevas notificaciones | QA 21: flujo completo con cada nuevo estado | Medio — agregar campos a tabla `classes` y `class_requests` | 1 día |
| **F4 — Reviews y ratings** | Sistema completo de reseñas verificadas post-clase | Nueva tabla `reviews`, modelo `Review`, `ReviewController`, rutas `/lessons/{lesson}/review`, `TeacherReviewNotification`, actualizar `Teachers/Show.vue`, `Marketplace/Index.vue` | QA 22: padre revisa, rating visible, no se puede revisar sin clase completada | Alto — feature nueva con modelo de datos nuevo | 2-3 días |
| **F5 — Admin operativo** | Vistas globales de clases/solicitudes/reportes/diagnósticos en admin | `AdminController.php`, nuevas vistas `Admin/Lessons.vue`, `Admin/Requests.vue`, `Admin/Reports.vue` | QA: admin ve listados, puede cancelar clase | Bajo | 1 día |
| **F6 — IA límites** | Daily limit + usage tracking | `DiagnosticAiEnrichmentService.php`, `config/diagnostic.php`, nueva tabla `diagnostic_ai_usage` (opcional), variable `DIAGNOSTIC_AI_DAILY_LIMIT` | QA: cuando se supera límite, fallback activo | Bajo — solo si se activa IA | 2-4h |
| **F7 — Legal final** | Actualizar /terminos y /privacidad para versión definitiva | `resources/js/Pages/Legal/Terms.vue`, `resources/js/Pages/Legal/Privacy.vue` | Visual review manual | Muy bajo | 1-2h |
| **F8 — Backup documentado** | Verificar plan Railway, probar restauración, crear playbook | `docs/DISASTER_RECOVERY.md` | Prueba manual de restauración | Bajo | 2-4h |
| **F9 — Auditoría admin** | Audit log de acciones admin | Nueva tabla `admin_audit_logs`, observer o manual en `AdminController` | QA: cada acción admin genera log | Bajo | 2-4h |

---

## Orden recomendado de implementación

1. **F1 — Corregir hard-delete de profesor** ← Máxima prioridad. Riesgo de pérdida de datos está activo hoy.
2. **F2 — WhatsApp producción** ← Iniciar el proceso de aprobación de Meta lo antes posible (puede tomar días/semanas).
3. **F3 — Gestión completa de clases** ← Sin esto, un usuario real no puede reagendar ni rechazar solicitudes.
4. **F4 — Reviews y ratings** ← El marketplace no genera confianza real sin reseñas. Bloqueador de adopción.
5. **F7 — Legal final** ← Rápido de hacer, necesario antes de onboarding de usuarios reales.
6. **F8 — Backup documentado** ← Verificar que Railway lo cubre y documentar el playbook.
7. **F5 — Admin operativo** ← Necesario para gestión operativa una vez haya volumen de clases.
8. **F6 — IA límites** ← Solo activar cuando se encienda la IA. No urgente mientras `DIAGNOSTIC_AI_ENABLED=false`.
9. **F9 — Auditoría admin** ← Buena práctica pero puede ser post-lanzamiento inicial.

---

## Resumen ejecutivo de brechas

```
ESTADO HOY:  BETA ✅ → FINAL ❌

Ruta crítica hacia FINAL:
  ① Corregir hard-delete profesor          [2-3h]
  ② Iniciar proceso WhatsApp Business      [días externos]
  ③ Gestión de clases (cancel + reschedule + reject) [1 día]
  ④ Reviews y ratings                      [2-3 días]
  ⑤ Legal final + Backup documentado       [1 día]

Estimado total para FINAL: 5-7 días de desarrollo + tiempo de aprobación WhatsApp Business
```

---

*Generado el 2026-06-28. No se modificó código. No se tocaron variables de entorno. Basado en lectura directa de archivos del proyecto.*
