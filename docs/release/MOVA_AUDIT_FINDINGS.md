# MOVA — Registro de hallazgos de la auditoría (2026-11-17)

> Snapshot: `master` = `origin/master` = `2473c0b` (árbol limpio al empezar). Trabajo local **sin desplegar** a producción
> (ver «Estado de despliegue» en el informe). Entorno de prueba: contenedor aislado (PHP 8.3, SQLite desechable, correo a log,
> cuentas sintéticas `*@mova.test`, Chromium headless con Playwright + axe-core). Producción: solo lecturas HTTP públicas.
> Convención: «reproducido» = observado en navegador/prueba antes de corregir; «leído» = confirmado leyendo el código; «reportado
> por revisión» = hallazgo del revisor `mova-security-finance`, verificado contra el código citado.

## Corregidos en esta sesión

| ID | Sev. | Módulo | Hallazgo | Evidencia | Causa raíz | Corrección | Prueba | Estado |
|---|---|---|---|---|---|---|---|---|
| F-01 | P2 | Admin · Usuarios | No existía la eliminación de cuentas pedida; la rama `feat/admin-user-safe-erasure` **no tiene commits propios** (su punta `e590c9f` ya está en `master`; no había trabajo parcial que recuperar) | `git rev-list origin/master..origin/feat/admin-user-safe-erasure` = 0; `grep` sin rastro en `app/` | Trabajo nunca confirmado | `AccountLifecycleService::eraseByAdmin` reutiliza el cierre automático; endpoints de vista previa y borrado; UI con modal | `AdminUserErasureTest` (19) + e2e en navegador móvil/escritorio | Corregido |
| F-02 | P2 | Admin · Usuarios | Tabla inutilizable en móvil (columnas cortadas, acciones fuera de alcance), modal propio sin `role=dialog`/Escape/foco, sin búsqueda, emojis como iconos | Captura `before_admin_users_375.png` (reproducido) | Tabla única `overflow-x-auto` + `div` fijo ad hoc | Tarjetas en `<md`, tabla en `md+`, `Modal` accesible, búsqueda, botones ≥44 px | e2e: Escape cierra y devuelve el foco; 0 desborde a 375/1440 | Corregido |
| F-03 | P2 | Admin · Suspensión | Suspender/reactivar sin rastro de auditoría; se podía suspender/reactivar una cuenta ya cerrada | Leído (`AdminController::suspendUser`) | Faltaba log y guarda | `Log::info ADMIN_USER_SUSPENDED/UNSUSPENDED` (solo ids) y rechazo de cuentas cerradas | `test_suspension_and_reactivation_are_audited…` | Corregido |
| F-04 | P2 | Cuentas · Lista de bloqueo | La lista de re-registro de cuentas suspendidas/con deuda se esquivaba con `ana+2@gmail.com` o `a.na@gmail.com` (también en el cierre por el propio titular) | Reportado por revisión; leído `ClosureBlocklist::normalizeEmail` (solo minúsculas) | Sin canonización de buzón | Se guardan y consultan variantes (sin `+etiqueta`; en Gmail sin puntos), compatible con filas antiguas | `test_gmail_aliases_cannot_dodge_the_blocklist` | Corregido |
| F-05 | P3 | Cierre de cuenta | `payment_reviews` contaba revisiones ya resueltas (bloqueo eterno para pagos antiguos) y no existía el conteo de recargas manuales pendientes | Reportado por revisión + leído | `whereNotNull(review_reason)` sin `review_resolved_at` | `whereNull('review_resolved_at')`, nuevo `pending_recharges` | `test_a_pending_manual_recharge_blocks…` | Corregido |
| F-06 | P2 | Landing y páginas legales | Desborde horizontal de 14 px a 320 px (fila de enlaces legales del footer sin salto de línea, 347 px) | Playwright 320 px: 5 páginas (reproducido) | `flex` sin `flex-wrap` | `flex-wrap`, centrado en móvil, objetivo táctil | Barrido después: 0 desbordes en páginas reales | Corregido |
| F-07 | P2 | Solicitar clase (padre) | En móvil los nombres de materia se cortaban («Matemá…», «Program…», «Ciencias …») en el paso central del flujo | Captura `before_class_request_subjects_375.png` (reproducido) | `truncate` en tarjetas de 2 columnas a 375 px | 1 columna bajo 420 px, texto que envuelve, nota «Sin profesores» legible | Captura `after_class_request_subjects_390.png` | Corregido |
| F-08 | P2 | Accesibilidad global | Contraste WCAG AA: 113 páginas-resolución con fallos (`#94a3b8` sobre blanco 2,56:1 en 26 sitios, insignias `-600` sobre `-50`, `slate-500`/`gray-500` sobre el canvas 4,2:1) | axe-core en 184 comprobaciones | Token `ink-subtle` documentado como «decorativo» pero usado como texto; clases `text-slate-400` | Tokens `ink-muted/ink-subtle`, `slate-500`/`gray-500`, textos `emerald/red/orange/green-700` en insignias, azul de marca `#1D4ED8` sobre tinte | axe antes/después (ver informe §E) | Corregido (queda el logotipo «plin», exento) |
| F-09 | P2 | Accesibilidad | `h1` ausente en el catálogo público y duplicado en 3 páginas (el layout ya aporta el `h1`) | axe/DOM: `/marketplace` 0, `/diagnostics/create`, `/teacher/profile`, `/admin/operations` 2 | Páginas con `h1` propio dentro de `AppLayout` | `h2` en las páginas con layout; `h1` solo para visitante | 0 páginas con `h1≠1` | Corregido |
| F-10 | P3 | Accesibilidad | 6 regiones con scroll horizontal sin foco por teclado; puntos del carrusel de 6 px y enlaces de la barra pública de 20 px en tablet (WCAG 2.2 2.5.8) | axe `scrollable-region-focusable`, `target-size` | Sin `tabindex`/área táctil | `tabindex=0 role=region aria-label`; área táctil de 24 px; `min-h-[32px]` | axe después: 0 de ambos | Corregido |
| F-11 | P3 | Zona horaria | 34 de 41 formateos de fecha del frontend usaban la zona del navegador, no Lima (correo/WhatsApp/campana ya usaban `LimaClock`); un usuario con el reloj en otra zona veía horas distintas | Leído (`grep toLocale*`) | Helper `limaTime.js` solo adoptado en 9 archivos | `timeZone: 'America/Lima'` en los formateos de eventos | Build + checks verdes; QA con `timezoneId: America/Lima` | Corregido salvo `WeeklyCalendar` (ver O-06) |
| F-12 | P3 | Cierre de cuenta (auditoría) | En una eliminación por admin los textos y `cancelled_by` habrían atribuido la acción a «la familia/el profesor» y el asiento a «aceptado por el titular» | Reportado por revisión | Textos fijos del autoservicio | `actingAdmin` en el servicio: textos y autor correctos | `test_future_classes_are_cancelled_in_the_name_of_the_administration…` | Corregido |
| F-13 | P2 | Pagos Culqi · cuenta cerrada | Se enviaba un cobro a Culqi para un profesor con la cuenta cerrada (el pago se acreditaría a un perfil suprimido). **Reproducido** con prueba: `Http` recibía la petición | `ClosedAccountPaymentGuardTest` fallaba 5/6 antes del cambio | `resolveAttemptRow` no comprobaba el cierre | Rechazo antes de crear el intento y de cualquier HTTP (lectura sin bloquear `User`: sin ciclo de locks con el cierre) | 9 pruebas SQLite y MySQL | Corregido |
| F-14 | P2 | Recargas · cuenta cerrada | Un admin podía aprobar a mano una recarga de una cuenta cerrada; un pago ya confirmado por el proveedor se asentaba en silencio en un perfil suprimido. La revisión de seguridad halló además un **P1** en mi primera versión: la lectura dentro de la transacción puede ver una instantánea antigua (REPEATABLE READ) y perder la alerta | Reproducido con prueba; P1 por revisión | `credit()` no conocía el estado de la cuenta | Aprobación manual → 422; pago del proveedor → se asienta (el ledger refleja el dinero real) y una incidencia **crítica** `closed-account-payment` se decide tras el commit más externo, sin propagar fallos de la alerta | Pruebas incl. cierre confirmado antes del commit exterior, alerta caída e idempotencia | Corregido |

## Abiertos (reales, no corregidos)

> O-01 y O-02 (cuenta cerrada vs dinero) se corrigieron después como F-13/F-14 (2026-11-17, segundo commit).

| ID | Sev. | Módulo | Hallazgo | Escenario | Corrección propuesta | Bloqueo |
|---|---|---|---|---|---|---|
| O-03 | P3 | Lista de bloqueo | Hashes HMAC con `APP_KEY`: rotar la clave deja la lista inoperante sin aviso | Rotación de clave | Clave dedicada o alerta al rotar | Decisión de operación |
| O-04 | P3 | Lista de bloqueo | Una cuenta limpia eliminada que luego genere deuda (contracargo/`reverse()`) ya tiene el correo libre | Contracargo posterior | Hashes «latentes» en la constancia activados en `reverse()` | **Decisión del propietario** |
| O-05 | P3 | Seguridad | CSP: solo `base-uri`/`object-src`/`frame-ancestors` en vigor; `script-src` está en `Report-Only` (observado en producción) | XSS futuro | Promover `script-src` a enforcing tras revisar informes | Verificación de informes |
| O-06 | P3 | Calendario semanal | `WeeklyCalendar.vue` coloca clases por hora local del navegador (mezcla rejilla y zona) | Usuario con reloj fuera de Lima | Rejilla en hora de Lima | Refactor de componente con pruebas visuales |
| O-07 | P3 | Accesibilidad | Logotipo «plin» (blanco sobre `#00d0b7`, 1,96:1): marca registrada, exenta de WCAG 1.4.3 | — | Ninguna (exento) | — |
| O-08 | P3 | Avisos al titular | Decisión pendiente: avisar por correo a la persona antes de eliminar su cuenta por administración | — | — | **Decisión del propietario** |

## Descartados (falsos positivos del barrido)

- `/notifications` sin título/idioma/`h1`: es el endpoint JSON de la campana, no una página.
- `/auth/google*` → 503 en QA: login Google desactivado a propósito.
- `/api/user` sin sesión → 302 a `/login` (comportamiento estándar de Laravel con `Accept` HTML).

## No verificado en esta sesión (sin evidencia, no se afirma)

Azure (CLI `az` no disponible en el equipo; solo lecturas HTTP públicas de producción), GitHub (CLI `gh` no disponible: PR abiertos no revisados), SignalR en vivo y su degradación, WAHA/WhatsApp, entregabilidad de correo, Culqi live (sin cargos ni reembolsos, por regla), videoclases JaaS (requieren audio/vídeo humano), carrera multi-proceso real cierre-vs-abono en MySQL (solo simulada de forma determinista; el perfil MySQL de las pruebas relevantes sí se ejecutó: 179 OK), pruebas de carga, pruebas de carga, dispositivo móvil físico, Safari/Firefox, MFA de administrador con llave real, flujos de registro con verificación de correo/teléfono reales.
