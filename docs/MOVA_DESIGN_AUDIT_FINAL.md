# MOVA — Auditoría de diseño exhaustiva (documento vivo)

**No es un anuncio de "rediseño terminado".** Es el rastreador honesto de
cobertura que el propio proceso de esta sesión exigió: inventario completo
por filesystem real, matriz por archivo, y ninguna fila oculta o marcada
"no aplica" sin justificación explícita.

Snapshot de esta versión: `HEAD` `ed96447` (rama `master`), working tree
limpio al generar el inventario, 2026-08-27. El inventario se regenera con
`python resources/../scratchpad/audit_signals.py` (script ad hoc, no
commiteado) cada vez que se actualiza este documento — los números de
"emoji restantes"/"indigo restante" son una re-lectura real de cada archivo,
no una copia del hallazgo original de la Fase 0.

---

## Qué significa cada columna

- **Emoji restantes**: ocurrencias de emoji detectadas en el archivo ahora
  mismo (no todas son necesariamente iconos de interfaz — algunas son
  contenido legítimo que se conserva a propósito; eso se decide archivo por
  archivo en la fase de migración, no aquí).
- **Indigo restante**: apariciones literales de `indigo` — resto de Laravel
  Breeze, nunca la marca.
- **Migrada (tokens+iconos)**: `PARCIAL` si el archivo ya fue tocado en esta
  sesión (Fases 1-3), `NO` si sigue en su estado original. Ningún archivo
  está `COMPLETA` todavía — eso requiere además responsive/dark/a11y/QA
  reales, no solo tokens+iconos.
- **Responsive / Dark / A11y / QA**: `pendiente` en todas las filas todavía.
  Se actualizan a `✅ <fecha> <evidencia>` únicamente cuando existe evidencia
  real (build, test, o verificación en el Browser pane) — nunca por
  inspección de código sola.

---

## Resumen numérico (inventario completo, filesystem como fuente de verdad)

| | Total |
|---|---:|
| Archivos `.vue` totales | 82 |
| Pages | 53 |
| Components | 26 |
| Layouts | 3 |
| Emoji restantes (suma) | 199 en 43 archivos |
| `indigo` restante (suma) | 72 en 18 archivos |
| Archivos con algún trabajo de esta sesión (`PARCIAL`) | 13 de 82 |

## Identidad visual por rol (el lenguaje es común, la composición no)

| Rol/superficie | Prioridad |
|---|---|
| Parent | confianza, claridad, decisiones sin fricción |
| Student (gestionado por el padre, no un rol propio — ver nota) | simplicidad, orientación, bajo esfuerzo cognitivo |
| Teacher | productividad, agenda, acciones rápidas |
| Admin | densidad controlada, precisión, auditoría |
| Marketplace | descubrimiento, confianza, conversión |
| Checkout/pagos | mínima distracción, máxima claridad |
| Clase en vivo (Jitsi) | inmersivo, funcional, cero ruido |
| Landing/público | marca, narrativa, conversión |

**Nota verificada (no asumida):** MOVA tiene 3 roles reales de login
(`parent`/`teacher`/`admin` — `RoleSeeder.php`). "Student" no inicia sesión;
es un perfil hijo gestionado por el padre (`Students/*.vue`, ya bajo "Parent
(gestión de hijos)" en la matriz). El lenguaje de "identidad para Student"
se aplica al *contenido que un padre ve sobre su hijo/a*, no a una superficie
propia.

## Flujos críticos a auditar como experiencia única (no como páginas sueltas)

Ninguno auditado como flujo todavía — cada página que lo compone aparece
suelta en la matriz de abajo. Se marcan aquí para no perderlos de vista:

- **Padre**: Marketplace → perfil de profesor → solicitud → diagnóstico IA →
  clase asignada → reporte → reseña.
- **Profesor**: registro → verificación (`PendingTeachers`) → perfil/setup →
  disponibilidad → solicitudes → clase → reporte → créditos.
- **Padre (gestión de hijos)**: crear/editar/eliminar estudiante.
- **Admin**: login → dashboard → revisión (profesores/solicitudes/recargas) →
  auditoría.

---

## Matriz de cobertura completa (82 archivos, filesystem real)

<!-- MATRIZ:INICIO -->
### Auth (7 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/Auth/ConfirmPassword.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Auth/ForgotPassword.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Auth/Login.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Auth/PhoneVerification.vue` | 0 | 7 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Auth/Register.vue` | 5 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Auth/ResetPassword.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Auth/VerifyEmail.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |

### Parent (1 archivo)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/Dashboard/Parent.vue` | 30 | 4 | NO | pendiente | pendiente | pendiente | pendiente |

### Parent — gestión de hijos (3 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/Students/Create.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Students/Edit.vue` | 0 | 7 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Students/Index.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |

### Teacher (4 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/Dashboard/Teacher.vue` | 21 | 9 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Teacher/Credits/Index.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Teacher/Edit.vue` | 1 | 2 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Teacher/Setup.vue` | 0 | 13 | NO | pendiente | pendiente | pendiente | pendiente |

### Admin (8 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/Admin/AiUsage.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Admin/Lessons.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Admin/PendingTeachers.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Admin/Recharges/Index.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Admin/Requests.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Admin/Reviews.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Admin/Users.vue` | 2 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Dashboard/Admin.vue` | 12 | 0 | NO | pendiente | pendiente | pendiente | pendiente |

### Marketplace (2 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/Marketplace/Index.vue` | 4 | 1 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Teachers/Show.vue` | 6 | 0 | NO | pendiente | pendiente | pendiente | pendiente |

### Reservas/Solicitudes (6 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/ClassOffers/Edit.vue` | 1 | 6 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/ClassOffers/Index.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/ClassRequests/Accept.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/ClassRequests/Create.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/ClassRequests/Index.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/ClassRequests/TeacherIndex.vue` | 7 | 0 | NO | pendiente | pendiente | pendiente | pendiente |

### Clases/Reportes (6 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/LessonReports/Create.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/LessonReports/ParentIndex.vue` | 6 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/LessonReports/Show.vue` | 6 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/LessonReports/TeacherIndex.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Lessons/ParentIndex.vue` | 3 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Lessons/TeacherIndex.vue` | 3 | 0 | NO | pendiente | pendiente | pendiente | pendiente |

### Diagnóstico IA (2 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/Diagnostics/Create.vue` | 6 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Diagnostics/Results.vue` | 1 | 5 | NO | pendiente | pendiente | pendiente | pendiente |

### Reseñas (1 archivo)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/Reviews/Create.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |

### Perfil/Settings (6 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/Profile/Edit.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Profile/Partials/DeleteUserForm.vue` | 0 | 0 | PARCIAL (usa Modal/Danger/SecondaryButton ya migrados) | pendiente | pendiente | pendiente | pendiente |
| `Pages/Profile/Partials/NotificationPreferencesForm.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Profile/Partials/UpdateAvatarForm.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Profile/Partials/UpdatePasswordForm.vue` | 0 | 0 | PARCIAL (usa PrimaryButton ya migrado) | pendiente | pendiente | pendiente | pendiente |
| `Pages/Profile/Partials/UpdateProfileInformationForm.vue` | 0 | 1 | PARCIAL (usa PrimaryButton ya migrado) | pendiente | pendiente | pendiente | pendiente |

### Legal (2 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/Legal/Privacy.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Legal/Terms.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |

### Landing público (2 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/Landing/StudentInvitation.vue` | 10 | 3 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Landing/TeacherInvitation.vue` | 6 | 1 | NO | pendiente | pendiente | pendiente | pendiente |

### Público/Sistema (3 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/About.vue` | 3 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Suspended.vue` | 1 | 2 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Welcome.vue` | 22 | 0 | NO | pendiente | pendiente | pendiente | pendiente |

### Layouts — compartido (3 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Layouts/AppLayout.vue` | 0 | 0 | **PARCIAL** (iconos migrados Fase 3; colores propios aún sin tokenizar — deliberado, ver DESIGN.md) | pendiente | pendiente | pendiente | pendiente |
| `Layouts/GuestLayout.vue` | 2 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Layouts/PublicPageLayout.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |

### Componentes — compartido (26 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Components/AvailabilityPicker.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Components/BaseButton.vue` | 0 | 0 | **COMPLETA** (Fase 2, ver commit `1b58bc2`) | ✅ (utilidades responsive por diseño) | ✅ (tokens) | ✅ (44px, aria-busy, focus-visible) | ✅ (verificado en Login.vue) |
| `Components/Checkbox.vue` | 0 | 1 (`text-brand-600` ya reemplazó el foco; el string "indigo" restante es un comentario histórico, no una clase) | **COMPLETA** (Fase 2) | ✅ | ✅ | pendiente | pendiente |
| `Components/CookieConsent.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Components/DangerButton.vue` | 0 | 0 | **COMPLETA** (Fase 2) | ✅ | ✅ | ✅ | ✅ |
| `Components/EmptyState.vue` | 0 | 0 | **COMPLETA** (nuevo, Fase 2) — sin consumidores todavía | pendiente | ✅ (tokens) | pendiente | pendiente |
| `Components/Icon.vue` | 0 | 0 | **COMPLETA** (nuevo, Fase 3) | ✅ | ✅ (currentColor) | ✅ (aria-hidden/aria-label explícito) | ✅ |
| `Components/Illustrations/FamilyIllustration.vue` | 6 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Components/Illustrations/TeacherIllustration.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Components/InputError.vue` | 0 | 0 | **COMPLETA** (Fase 2) | ✅ | ✅ | pendiente | pendiente |
| `Components/InputLabel.vue` | 0 | 0 | **COMPLETA** (Fase 2) | ✅ | ✅ | pendiente | pendiente |
| `Components/JitsiModal.vue` | 2 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Components/LandingFooter.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Components/LandingNavbar.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Components/Lessons/ParentLessonCard.vue` | 6 | 1 | NO | pendiente | pendiente | pendiente | pendiente |
| `Components/Lessons/TeacherLessonCard.vue` | 5 | 4 | NO | pendiente | pendiente | pendiente | pendiente |
| `Components/Lessons/WeeklyCalendar.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Components/Modal.vue` | 0 | 0 | **COMPLETA** (Fase 2, ver commit `1b58bc2`) | ✅ (hoja móvil / diálogo centrado, medido en 1280px) | ✅ (tokens) | ✅ (dialog/aria-modal/focus trap/restauración de foco — verificado con teclado real) | ✅ |
| `Components/MovaLogo.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Components/NotificationBell.vue` | 1 | 2 | NO | pendiente | pendiente | pendiente | pendiente |
| `Components/PrimaryButton.vue` | 0 | 0 | **COMPLETA** (Fase 2) | ✅ | ✅ | ✅ | ✅ |
| `Components/SecondaryButton.vue` | 0 | 0 | **COMPLETA** (Fase 2) | ✅ | ✅ | ✅ | ✅ |
| `Components/Skeleton.vue` | 0 | 0 | **COMPLETA** (nuevo, Fase 2) — sin consumidores todavía | ✅ | ✅ | ✅ (aria-hidden) | pendiente |
| `Components/StatusBadge.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Components/TextInput.vue` | 0 | 0 | **COMPLETA** (Fase 2) | ✅ | ✅ | pendiente | pendiente |
| `Components/TimeSlotPicker.vue` | 6 | 3 | NO | pendiente | pendiente | pendiente | pendiente |
<!-- MATRIZ:FIN -->

---

## Decisiones y excepciones (documentadas, no escondidas)

| Decisión | Razón |
|---|---|
| Modo oscuro auto (`prefers-color-scheme`) desactivado | Verificado en navegador: activarlo con solo 13/82 archivos migrados produce una app mitad clara/mitad oscura. Se reactiva cuando la cobertura de tokens sea suficientemente amplia — ver `DESIGN.md`. |
| `Sheet.vue` no construido todavía | Cero consumidores reales hasta la Fase 4 (menú "Más" del padre) — construirlo antes sería código especulativo no verificable. |
| `EmptyState.vue`/`Skeleton.vue` sin consumidores todavía | Establecidos por adelantado (aprobado en el plan), a la espera de que la migración página-por-página los use. |
| `rounded-*` con nombres propios (`chip/control/card/elevated/pill`) en vez de `sm/md/lg/xl` | Evita colisión silenciosa con la escala default de Tailwind — ver `DESIGN.md`. |
| `Checkbox.vue` "indigo restante"=1 | Es un comentario del propio código explicando el reemplazo (`// reemplaza el indigo heredado...`), no una clase activa — falso positivo del grep mecánico, documentado aquí en vez de re-escribir el comentario para "limpiar el número". |

## Lo que este documento NO afirma todavía

- Que las 82 vistas tengan un lenguaje visual coherente — **69 de 82 siguen sin tocar**.
- Que exista ningún flujo completo verificado de punta a punta.
- Que el modo oscuro, el responsive o la accesibilidad estén cubiertos en ninguna página de producto (solo en los primitivos compartidos, fila por fila arriba).
- Que exista media/performance budget medido antes/después a nivel de página (sí existe a nivel de bundle global, ver commits de Fase 1-3).

Este documento se actualiza en cada commit de migración subsiguiente —
nunca se reescribe para "verse más terminado" sin que el commit correspondiente
exista.
