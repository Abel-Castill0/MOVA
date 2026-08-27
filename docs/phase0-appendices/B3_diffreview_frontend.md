# Auditoría frontend Vue — MOVA (working tree sin commitear)

## 0. Snapshot

Snapshot auditado: **working tree actual, HEAD=692b365 — NO un checkout aislado.**
Se usó `git diff -- <archivo>` sobre el directorio de trabajo real (incluye todos los cambios sin commitear), y lectura completa para el archivo nuevo. No se modificó ningún archivo, no se hizo ningún commit.

Dominio auditado (17 Vue modificados + 1 Vue nuevo + su controller):
- `Admin/PendingTeachers.vue`, `Admin/Recharges/Index.vue`, `Admin/Reviews.vue`, `Auth/PhoneVerification.vue`, `ClassOffers/Edit.vue`, `ClassRequests/TeacherIndex.vue`, `Dashboard/Parent.vue`, `Legal/Privacy.vue`, `Lessons/ParentIndex.vue`, `Lessons/TeacherIndex.vue`, `Profile/Edit.vue`, `Students/Create.vue`, `Students/Edit.vue`, `Students/Index.vue`, `Teacher/Credits/Index.vue`, `utils/lessonJoin.js`, `utils/statusColors.js`
- Nuevo: `Profile/Partials/NotificationPreferencesForm.vue` + `app/Http/Controllers/NotificationPreferencesController.php`

Para verificar correcta (B) e integración (F) se leyó también el backend real: `LessonController`, `JaasService`, `config/jaas.php`, `RechargeController`, `RechargeApprovalService` (parcial), `RechargeRequest`, `Teacher/CreditController`, `config/credits.php`, `StudentController`, `Student.php`, `Lesson.php`, `ClassRequest.php`, `StudentDiagnostic.php`, `TeacherReview.php`, `User.php`, `WhatsAppChannel.php`, `PhoneVerificationController.php`, `HandleInertiaRequests.php`, la migración `2026_08_24_000002_remove_in_progress_from_classes_status_enum.php`, y rutas en `routes/web.php`.

---

## 1. Grupos de cambios

### Grupo A — Guards de doble-submit + manejo de errores en acciones destructivas/financieras
**Archivos:** `Admin/PendingTeachers.vue`, `Admin/Reviews.vue`, `ClassRequests/TeacherIndex.vue`, `Lessons/ParentIndex.vue`, `Lessons/TeacherIndex.vue`, `Dashboard/Parent.vue` (confirmPayment)

- **A. Intención:** cerrar "F-12": ninguna de estas acciones (rechazar profesor, ocultar/mostrar reseña, rechazar solicitud, cancelar clase, reprogramar, confirmar pago) tenía guard contra doble clic ni `onError`, así que un doble POST o un 422 dejaba al usuario sin feedback.
- **B. Corrección:** cada botón ahora usa un `ref` booleano (`rejecting`, `moderating`, `cancelling`, `rescheduling`, `payingId`) chequeado al inicio de la función y liberado en `onFinish`. Coincide con endpoints reales ya existentes (`admin.teachers.reject`, `admin.reviews.hide/show`, `teacher.requests.reject`, `lessons.cancel`, `lessons.reschedule`, `lessons.confirm-payment`) — no se inventó ningún route nuevo.
- **C/F. Integración:** patrón consistente entre los 5 archivos (mismo naming `xError`/`xing`), coherente con lo que ya hacía `Admin/Recharges/Index.vue` antes de este cambio (mencionado en comentarios F-12 "segunda ronda").
- **G. Testing:** no hay test Feature/Playwright que verifique específicamente "doble clic no duplica la acción" a nivel UI. `LessonController` sí usa `lockForUpdate()` (mencionado en comentario del propio diff), así que el backend resiste aunque el frontend no lo probara; el riesgo real es solo UX (422 confuso), no integridad de datos.
- **H. Regresión:** ninguna — son adiciones puramente aditivas de estado local, no tocan `statusColors.js` ni lógica compartida.
- **I. Mantenibilidad:** patrón repetido 5 veces con copy-paste casi idéntico; candidato razonable a un composable (`useGuardedAction`) pero no bloqueante.
- **J. Producción:** listo. Comentarios in-code (`F-12`) documentan bien el motivo.

### Grupo B — Migración de `window.confirm()`/`window.prompt()` a modales propios (recargas y borrado de alumno)
**Archivos:** `Admin/Recharges/Index.vue`, `Students/Index.vue`

- **A. Intención ("F-13"):** eliminar los únicos dos diálogos nativos del frontend — uno sobre una aprobación de dinero real, otro sobre el borrado de la ficha de un menor — porque `prompt()` está bloqueado en contextos sandbox y ninguno de los dos permitía bloquear doble envío.
- **B. Corrección:**
  - `Admin/Recharges/Index.vue`: nuevo modal con `ACTION_CONFIG` (approve/reject), motivo obligatorio (min 5 caracteres) solo para reject, y POST a `admin.recharges.approve`/`admin.recharges.reject` — mismos endpoints que antes. Verificado contra `RechargeController@approve` (ahora delegado a `RechargeApprovalService`, ver Grupo D) y confirma el copy "abonará los créditos... y quedará registrado en el ledger" — cierto, así lo hace el servicio.
  - `Students/Index.vue`: modal que nombra al alumno (`deleteTarget.full_name`) y explica "El historial de clases ya realizadas se conserva por motivos contables" — esto es **exacto** frente al backend real: `StudentController@destroy` ahora anonimiza si hay historial académico y hace soft-delete (ver Grupo E). El copy del modal no miente.
- **C/F. Integración:** ambos usan los componentes ya existentes `Modal.vue`/`SecondaryButton.vue`/`InputLabel.vue`, no se introdujo un patrón nuevo de UI.
- **G. Testing:** no hay Playwright que abra estos modales específicamente. `qa/tests/flujo-completo.spec.js` toca `/teacher/credits` pero no `/admin/recharges` ni el flujo de borrado de alumno.
- **H. Regresión:** el cambio de `<Link method="delete">` a `<button>` + `router.delete()` en `Students/Index.vue` es correcto (Inertia `router.delete` es equivalente), sin efectos colaterales visibles.
- **I. Mantenibilidad:** buena — el patrón `ACTION_CONFIG` en Recharges es más limpio que dos funciones casi idénticas.
- **J. Producción:** listo.

### Grupo C — Consentimiento explícito de WhatsApp (feature nueva, multi-capa)
**Archivos:** `Auth/PhoneVerification.vue` (checkbox), `Profile/Partials/NotificationPreferencesForm.vue` (nuevo), `Profile/Edit.vue` (integra el form), `Legal/Privacy.vue` (texto legal actualizado)
**Backend leído:** `PhoneVerificationController`, `NotificationPreferencesController`, `User.php`, `WhatsAppChannel.php`, `HandleInertiaRequests.php`, `routes/web.php`

Ver sección 2 — análisis completo del flujo. Veredicto adelantado: **feature completamente conectada**, no es dead code ni parcial.

- **A. Intención:** separar "verificar el teléfono" (autenticación) de "aceptar notificaciones por WhatsApp" (consentimiento de producto), y hacer que el segundo sea revocable sin invalidar el primero.
- **B. Corrección:** el checkbox de `PhoneVerification.vue` (`form.whatsapp_notifications`, sin preseleccionar) viaja en el POST de `verify()`; el controller lo valida como `sometimes|boolean` y solo si es `true` fija `whatsapp_opt_in_at`. `NotificationPreferencesForm.vue` lee `page.props.auth.user.whatsapp_opt_in` / `phone_verified`, ambos efectivamente compartidos por `HandleInertiaRequests.php` (`whatsapp_opt_in => $user->wantsWhatsAppNotifications()`, `phone_verified` ya existía). PATCH a `profile.notifications.update` (registrada en `routes/web.php:58-59`, throttle 20/min) → `NotificationPreferencesController@update` → `User::optInToWhatsApp()`/`optOutOfWhatsApp()`.
- **F. Integración Inertia:** sin huecos — cada prop leído en Vue tiene su contraparte compartida en el middleware, y cada acción del frontend tiene ruta+controlador reales.
- **G. Testing:** existe `tests/Feature/WhatsAppConsentTest.php` (nombre sugiere cobertura del propio consentimiento) y `tests/Feature/WhatsAppChannelTest.php`; no se leyó su contenido línea por línea pero la existencia indica que esto no se dejó sin probar en backend. No hay Playwright que marque la casilla de consentimiento ni interactúe con `NotificationPreferencesForm.vue`.
- **H. Regresión:** el texto legal de `Legal/Privacy.vue` cambió de "Twilio" a "Meta (WhatsApp Business Cloud API)" — coherente con el resto del cambio de proveedor visto en `WhatsAppChannel.php`/`PhoneVerificationController.php` (mismo commit conceptual, migración completa de Twilio a un `WhatsAppProviderContract`). Es una migración de proveedor real, no cosmética.
- **I. Mantenibilidad:** buena, con comentarios extensos explicando la distinción `phone_verified_at` vs `whatsapp_opt_in_at`.
- **J. Producción:** funcionalmente lista. Nota menor: el checkbox en `PhoneVerification.vue` se muestra siempre, incluso aunque el usuario termine sin marcar la casilla nunca (no hay "recuérdame luego" ni forma de revisar el opt-in hasta llegar a `/profile`) — es una decisión de producto válida, no un bug.

### Grupo D — Estado "reversed"/"reversal" en recargas y créditos
**Archivos:** `Admin/Recharges/Index.vue` (statusLabel/statusBadge), `Teacher/Credits/Index.vue` (transactionLabel/transactionDetail/rechargeLabel/rechargeBadge)
**Backend leído:** `RechargeRequest.php`, `RechargeController.php` (delega a `RechargeApprovalService`)

- **A. Intención ("F-08"):** el backend ya soporta `reversed`/`reversal` (fillables `reversed_at`, `reversed_by`, `reversal_reason` en `RechargeRequest`, relación nueva `paymentOrder()`) desde que se preparó la arquitectura de pagos, pero el frontend no tenía label para esos valores y caía al fallback `?? status`/`?? type`, mostrando la palabra cruda en inglés.
- **B. Corrección:** correcta y verificada contra el modelo — los campos existen realmente en `RechargeRequest` (no es un estado inventado en el frontend).
- **C/F. Integración:** ambas vistas quedan consistentes entre sí (mismo color `rose`, misma terminología "revertid[ao]").
- **G. Testing:** no se encontró test que dispare específicamente una reversión y verifique el label en UI (tiene sentido: el flujo de reversión real depende de `PaymentOrderTest.php`/webhook, que sí existe en `tests/Feature/` pero no llega hasta una aserción visual).
- **H. Regresión:** ninguna — solo añade branches al mapa de labels, no quita ninguna existente.
- **I. Mantenibilidad:** bien, con comentario que explica la motivación (F-08).
- **J. Producción:** lista. El `transactionDetail()` añadido en `Teacher/Credits/Index.vue` explica al profesor *por qué* se le revirtieron créditos — buen detalle de UX para un movimiento que "resta créditos sin que el profesor haya hecho nada".

### Grupo E — Soft delete + anonimización de `Student` (dato de menor)
**Archivos Vue:** `Students/Index.vue` (ver también Grupo B)
**Backend leído:** `Student.php`, `StudentController.php`, `Lesson.php`, `ClassRequest.php`, `StudentDiagnostic.php`, `TeacherReview.php`

- **A. Intención ("F-18"):** antes `$student->delete()` era un borrado físico que en MySQL fallaba (FK RESTRICT) o en SQLite/tests destruía historial académico silenciosamente (CASCADE). Se introduce `SoftDeletes` + anonimización de campos personales (`first_name`, `last_name`, `birth_date`, `school`) cuando hay historial.
- **B. Corrección:** el copy del modal en `Students/Index.vue` ("Se eliminará su ficha y dejará de aparecer en tus solicitudes... El historial de clases ya realizadas se conserva") es exacto frente a lo que hace `StudentController@destroy` + `Student::anonymize()`.
- **C/H. Regresión (la más relevante de toda la auditoría):** añadir `SoftDeletes` a `Student` rompe por defecto **cualquier** `belongsTo(Student::class)` existente en cuanto el alumno se soft-elimina (Eloquent aplica el scope global también a relaciones `belongsTo`). Se verificó que esto **sí fue detectado y corregido** en la misma tanda de cambios: `Lesson::student()`, `ClassRequest::student()` y `StudentDiagnostic::student()` fueron actualizados a `belongsTo(Student::class)->withTrashed()`, con el mismo comentario explicando que sin esto `?->parent?->notify()` dejaba de encontrar destinatario (es decir, cancelaciones/devoluciones dejaban de notificar al padre **sin ningún error visible**). Es una auditoría cruzada correcta: el cambio en `Student.php` no se hizo de forma aislada.
- **F. Integración:** `StudentController@index` sigue usando `auth()->user()->students()->get()` sin `withTrashed()`, así que el alumno anonimizado/soft-eliminado desaparece correctamente del listado del padre (coincide con el copy del modal).
- **G. Testing:** existe `tests/Feature/FinancialHistoryDurabilityTest.php`, cuyo nombre sugiere cobertura de exactamente este tipo de garantía (historial que sobrevive a la baja). No se auditó su contenido en detalle (fuera de mi dominio estricto), pero la señal es positiva. No hay Playwright que ejercite el modal de borrado de alumno end-to-end.
- **J. Producción:** el cambio en `Student.php`/`StudentController.php` toca el modelo de datos de un menor — **CLAUDE.md exige revisión de seguridad explícita antes de mergear cualquier cambio a `Student`**. Esta auditoría NO constituye esa revisión (mi alcance es frontend); señalo el requisito para que se cumpla antes de dar el grupo por cerrado, aunque el frontend en sí esté correcto.

### Grupo F — Regla de acceso a la sala Jitsi (`canJoinJitsi` / `lessonJoin.js`)
**Archivos:** `Dashboard/Parent.vue`, `utils/lessonJoin.js`, `utils/statusColors.js` (retiro de `in_progress`)
**Backend leído:** `LessonController@join`, `JaasService`, `config/jaas.php`, migración `remove_in_progress_from_classes_status_enum`

- **A. Intención ("F-06"):** unificar una cuarta copia divergente de la regla "¿puedo unirme a la sala?" en `Dashboard/Parent.vue` hacia el `utils/lessonJoin.js` compartido, y corregir dos huecos: (1) sin límite inferior, un botón "Unirse" seguía apareciendo días después de que la clase terminó; (2) `pending_parent_confirmation` estaba bloqueado en frontend pese a estar permitido en backend.
- **B. Corrección — ESTA ES LA VERIFICACIÓN MÁS IMPORTANTE DEL GRUPO:** se leyó `LessonController::join()` completo. El backend:
  - Whitelist de estados: `['scheduled', 'paid', 'pending_parent_confirmation']` — coincide exactamente con lo que ahora permite `lessonJoin.js`.
  - Ventana temporal: exceptúa `'paid'`, y para el resto usa `start_time - join_window_before_minutes` .. `end_time + join_grace_after_minutes`, leyendo `config('jaas.join_window_before_minutes', 15)` / `config('jaas.join_grace_after_minutes', 120)` — **mismos valores por defecto** (`15` y `120`) que las constantes `JOIN_WINDOW_BEFORE_MINUTES`/`JOIN_GRACE_AFTER_MINUTES` hardcodeadas en `lessonJoin.js`. El comentario del propio archivo advierte correctamente: "si se cambian estos valores hay que cambiarlos también en config/jaas.php" — es decir, ya reconoce que es una constante duplicada manualmente, no leída de una fuente común (limitación conocida, no un descuido).
  - El JWT de JaaS ahora expira acotado a esa misma ventana (antes 24h fijas) — endurecimiento de seguridad real, ejecutado con hardening explícito en `JaasService::generateToken()`.
  - Esto es, en efecto, la corrección de una **divergencia de autorización real** (no solo de UX): antes un POST directo a `/lessons/{id}/join` devolvía un token válido días antes de la clase, pese a que la UI decía "disponible 15 min antes". Ahora ambos lados están alineados y el backend es quien manda (frontend solo evita pintar un botón que fallaría).
- **F. Integración:** `Dashboard/Parent.vue` ahora importa `canJoinJitsi` desde el util compartido en vez de redefinirlo — elimina la 4ª copia divergente. El texto de fallback para `status === 'paid'` que no cumple la condición se actualizó de "Disponible 15 min antes" a "La sala de esta clase ya no está disponible" con un comentario que admite honestamente que esa rama es **inalcanzable en la práctica** (`canJoinJitsi()` devuelve `true` para todo `'paid'`), conservada "por si la regla de acceso cambia" — código muerto reconocido y documentado, no un bug oculto.
- **H. Regresión — `utils/statusColors.js`:** se retira la entrada `in_progress`. Se verificó que este valor de enum fue eliminado del lado backend en la migración `2026_08_24_000002_remove_in_progress_from_classes_status_enum.php`, cuyo propio comentario documenta una búsqueda global previa confirmando que ningún controlador/servicio/job asigna jamás ese estado. `STATUS_STYLES` es consumido solo por `Components/StatusBadge.vue` y `Components/WeeklyCalendar.vue` (ninguno de los dos está en mi dominio, pero grep confirma que no hay más consumidores) — no hay riesgo de mostrar un badge sin estilo para un estado que ya no puede ocurrir en BD.
- **G. Testing:** `tests/Feature/JitsiAccessWindowTest.php` existe y su nombre indica cobertura backend directa de esta ventana — señal fuerte de que esto no se dejó sin probar. `tests/Feature/RescheduleTest.php` es el mencionado en el propio comentario de la migración como el único lugar que usaba `in_progress` como data provider (para verificar que NO se puede reprogramar desde ese estado) — habría que confirmar que ese test se ajustó, pero es un archivo de test, no Vue, así que queda fuera de mi verificación exhaustiva.
- **J. Producción:** lista, y es probablemente el cambio de mayor peso de seguridad de todo mi dominio — toca acceso a videollamadas con menores. Cae directamente bajo la regla de CLAUDE.md de "revisión de seguridad explícita" para cambios en Jitsi; el razonamiento en los comentarios ya hace ese trabajo de forma notablemente rigurosa, pero formalmente sigue pendiente confirmar que alguien la ejecutó como paso explícito antes de mergear.

### Grupo G — Errores de validación visibles en formularios (`InputError`)
**Archivos:** `ClassOffers/Edit.vue`, `Students/Create.vue`, `Students/Edit.vue`

- **A. Intención ("F-19"):** estos formularios no mostraban ningún error de validación del backend; un 422 dejaba al usuario ante un formulario que "no guarda" sin explicación.
- **B. Corrección:** se verificaron las reglas reales en `ClassOfferController` (`title`, `specific_rate`, `availability_schedule` sí se validan) y coincide con los campos donde se añadió `<InputError>`.
- **F. Integración — hueco real encontrado:** en `ClassOffers/Edit.vue`, `availability_schedule` se valida en el backend con claves anidadas (`availability_schedule.days.*.*.start`, `.end`, con regex de hora). El `InputError` añadido lee `form.errors.availability_schedule` (la clave "raíz"), que Laravel solo puebla si el error ocurre en esa clave exacta — un error en `availability_schedule.days.0.0.start` (ej.: hora mal formada) **no se muestra**, porque vive en una clave anidada distinta. Además el bloque entero de disponibilidad está detrás de un toggle colapsable (`v-if="showAvailability"`), así que aunque el error de la clave raíz sí llegara, quedaría oculto si el usuario no expandió esa sección. Es una mejora real pero incompleta frente al 100% de los casos de validación de ese campo.
- **I. Mantenibilidad:** en `Students/Create.vue`/`Edit.vue` ahora coexisten dos mecanismos de error para el mismo campo en algunos casos (el `<p v-if="form.errors.first_name">` ya existente, más el nuevo `<InputError>` recién añadido justo antes) — ver detalle en Grupo H más abajo.
- **J. Producción:** funcional para el caso común (error de nivel superior), no para los casos anidados de `availability_schedule`.

### Grupo H — Duplicación de mensaje de error en `Students/Create.vue`
**Archivo:** `Students/Create.vue`

- **A. Intención:** parte del mismo cambio F-19 de arriba.
- **B/I. Hallazgo:** el diff añade `<InputError class="mt-1" :message="form.errors.first_name" />` **inmediatamente después** del `<input>`, pero la línea siguiente —ya existente, sin tocar— sigue siendo `<p v-if="form.errors.first_name" class="text-xs text-red-500 mt-1">{{ form.errors.first_name }}</p>`. Mismo patrón se repite para `last_name`. Esto significa que, en `Students/Create.vue` (no en `Edit.vue`, que no tenía el `<p>` duplicado previo para esos dos campos), un error de `first_name`/`last_name` **se mostrará dos veces** en pantalla — una vez vía `<InputError>` y otra vía el `<p>` legado. No es un error de datos ni de seguridad, es puramente visual/UX, pero es un defecto real introducido por este diff.
- **J. Clasificación:** `KEEP_WITH_FIX` — quitar el `<p>` legado (o el `<InputError>` nuevo) para `first_name`/`last_name` en `Students/Create.vue`.

### Grupo I — Copy de créditos derivado del servidor
**Archivo:** `Teacher/Credits/Index.vue` (prop `currency`, `price_per_credit`)
**Backend leído:** `Teacher/CreditController.php`, `config/credits.php`

- **A. Intención ("F-16"):** "1 crédito equivale a S/ 2.00" estaba escrito a mano en la plantilla; si se ajustaba `config/credits.php` esa línea mentía al profesor justo en la pantalla donde decide comprar.
- **B. Corrección:** verificado — `CreditController@index` ahora calcula `price_per_credit` por paquete (`amount_pen / credits`, formateado a 2 decimales) y pasa `currency => 'S/'` como prop nueva con default. El Vue consume ambos correctamente, con guard `v-if="pack.price_per_credit"` para el caso `credits === 0` (backend devuelve `null` en ese caso, evitando división por cero — coherente).
- **F. Integración:** prop `currency` declarada con `default: 'S/'` en el componente — funciona incluso si un test o storybook renderizara el componente sin pasar la prop.
- **J. Producción:** lista.

---

## 2. Features parcialmente conectadas

### `NotificationPreferencesForm.vue` — verificación de flujo completo

**Veredicto: INTENTIONAL / completamente conectada, no es dead code.**

Trazado extremo a extremo:

1. **Formulario** (`NotificationPreferencesForm.vue`): checkbox controlado por `page.props.auth.user.whatsapp_opt_in`, deshabilitado si `saving`, dispara `router.patch('profile.notifications.update', { whatsapp: value })`.
2. **Props Inertia:** `whatsapp_opt_in` y `phone_verified` se comparten en `HandleInertiaRequests.php:33` (`'whatsapp_opt_in' => $request->user()->wantsWhatsAppNotifications()`) — el prop que el componente lee **sí existe** del lado del servidor, no es un prop inventado en el frontend.
3. **Ruta:** `routes/web.php:58-59` — `Route::patch('/profile/notifications', [NotificationPreferencesController::class, 'update'])->middleware('throttle:20,1')->name('profile.notifications.update')`. Coincide exactamente con el nombre de ruta usado en el Vue.
4. **Controller:** `NotificationPreferencesController@update` valida `whatsapp: required|boolean` y llama `$user->optInToWhatsApp()` / `optOutOfWhatsApp()`.
5. **Modelo:** `User::optInToWhatsApp()` fija `whatsapp_opt_in_at = now()` y limpia `whatsapp_opt_out_at`; `optOutOfWhatsApp()` fija `whatsapp_opt_out_at = now()`. `wantsWhatsAppNotifications()` implementa la regla "la baja gana siempre" (si hay `whatsapp_opt_out_at`, es `false` sin importar el opt-in histórico).
6. **Efecto real (el punto crítico que pedía la tarea):** `app/Channels/WhatsAppChannel.php::send()` — el canal que efectivamente despacha **las 21 notificaciones existentes** del sistema (recordatorios, confirmaciones, recargas) — ahora comprueba explícitamente `if (method_exists($notifiable, 'wantsWhatsAppNotifications') && !$notifiable->wantsWhatsAppNotifications()) { ...skip... }` antes de enviar. Es decir: **la preferencia que este formulario guarda sí se lee y sí bloquea envíos reales**, no queda huérfana.
7. Además, un `skip` por falta de consentimiento se audita explícitamente en `whatsapp_messages` con `WhatsAppSkipReason::OptOut` — trazabilidad completa de por qué no se envió un mensaje a alguien.

**Segunda pieza del mismo flujo — `Auth/PhoneVerification.vue`:** el checkbox ahí (`form.whatsapp_notifications`, sin preseleccionar) llega a `PhoneVerificationController@verify()`, que lo valida (`sometimes|boolean`) y solo si es `true` (y no hay opt-out previo) fija `whatsapp_opt_in_at`. Antes, verificar el teléfono fijaba el opt-in como efecto colateral automático — el comentario en el propio controller documenta ese antes/después. Este es el punto de entrada más común al opt-in; `NotificationPreferencesForm.vue` es el punto de salida/ajuste posterior desde el perfil. Ambos escriben al mismo par de columnas y son consistentes entre sí.

**Nada suelto encontrado:** no hay ningún prop compartido por Inertia que el frontend ignore, ni ningún campo que el frontend envíe sin que el backend lo valide/consuma, en este grupo de cambios.

### Otras posibles "features a medias" revisadas y descartadas

- **`transactionDetail()` / `reversal` en `Teacher/Credits/Index.vue`:** no es parcial — el campo `type: 'reversal'` y el status `'reversed'` existen realmente en el backend (`RechargeRequest` tiene `reversed_at`/`reversed_by`/`reversal_reason`, `RechargeApprovalService` es quien los produce).
- **Rama `l.status === 'paid'` en `Dashboard/Parent.vue` (Grupo F):** es código con una rama muerta **admitida y documentada** por el propio autor del diff ("F-11: rama inalcanzable... se conserva como red por si la regla de acceso cambia"), no una feature a medias sin declarar — clasificado como DEAD CODE intencional, no como bug.
- **`InputError` para `availability_schedule` (Grupo G):** SÍ es una feature parcialmente conectada en el sentido estricto — el backend valida sub-claves que el frontend no puede mostrar con el binding actual. Clasificación: **INCOMPLETE FEATURE**, evidencia en `ClassOffers/Edit.vue` línea del `<InputError>` de disponibilidad vs. `ClassOfferController.php` líneas 36-41.

---

## 3. Clasificación por grupo

| Grupo | Clasificación | Motivo |
|---|---|---|
| A — Guards doble-submit | KEEP | Correcto, aditivo, sin riesgo. |
| B — Modales reemplazan `confirm()`/`prompt()` | KEEP | Correcto, mejora real de seguridad/accesibilidad (prompt bloqueado en sandbox) y de copy para dato de menor. |
| C — Consentimiento WhatsApp | KEEP | Verificado extremo a extremo, sin cabos sueltos. Requiere que el usuario confirme que la migración de Twilio→Meta Cloud API (fuera de mi dominio estricto) está igual de lista. |
| D — Estados `reversed`/`reversal` | KEEP | Backend ya los produce; frontend solo estaba desactualizado. |
| E — Soft delete/anonimización `Student` | KEEP_WITH_FIX (proceso, no código) | Frontend correcto; **pendiente formal**: la revisión de seguridad explícita que CLAUDE.md exige para cambios a `Student` — no verificar esto sería incumplir la regla del proyecto, no un defecto de este diff. |
| F — Ventana de acceso Jitsi (`lessonJoin.js`) | KEEP_WITH_FIX (proceso, no código) | Backend y frontend coinciden exactamente (mismos defaults 15/120 min); mismo comentario que Grupo E — cambio a Jitsi, requiere la revisión de seguridad explícita del proyecto antes de mergear formalmente. |
| G/H — `InputError` en formularios | KEEP_WITH_FIX | `Students/Create.vue`: quitar el `<p>` duplicado para `first_name`/`last_name`. `ClassOffers/Edit.vue`: el `InputError` de `availability_schedule` no cubre errores anidados de horario — aceptable como mejora parcial, pero documentar la limitación o extenderlo. |
| I — Copy créditos derivado del servidor | KEEP | Correcto y más mantenible que el valor hardcodeado anterior. |

Ningún grupo se clasifica DOCS_ONLY, QA_ARTIFACT, TEMPORARY, OBSOLETE, REVERT o BLOCKED — no se encontró código muerto sin declarar, ni un experimento a medio camino, ni un artefacto de QA colado en `resources/js`.

---

## 4. Barrido de secretos

Se ejecutó un grep dirigido (patrones de API keys tipo `sk-`, `AIza`, `xox`, bloques `-----BEGIN`, asignaciones `api_key = "..."`, URLs `*.railway.app`/`mova*`, y `password = "..."`) sobre los 17 archivos Vue/JS modificados + el archivo Vue nuevo + `NotificationPreferencesController.php`.

**Resultado: sin coincidencias.** No se encontró ninguna API key de terceros, token, URL de producción hardcodeada, ni credencial pegada por error en ninguno de los archivos de mi dominio. El único dato "sensible" visible es texto legal en `Legal/Privacy.vue` mencionando proveedores por nombre (Gmail, Meta/WhatsApp, JaaS/8x8, Railway, Google Gemini) — es contenido legal intencional, no un secreto filtrado.

No se muestran valores reales en este reporte porque no se encontró ninguno que mostrar.

---

## 5. Qué no se pudo verificar

- **Verificación visual real en navegador** de los tres modales nuevos (`Admin/Recharges/Index.vue`, `Students/Index.vue`) y del checkbox de `Auth/PhoneVerification.vue` / `NotificationPreferencesForm.vue` — no se levantó la app ni se abrió un navegador; el análisis es 100% estático (diff + lectura de código fuente real de frontend y backend).
- **Contenido interno de `tests/Feature/WhatsAppConsentTest.php`, `JitsiAccessWindowTest.php`, `FinancialHistoryDurabilityTest.php`, `RescheduleTest.php`** — se confirmó su existencia y se infirió su propósito por el nombre y por referencias cruzadas en comentarios del propio código de producción, pero no se leyó su contenido línea por línea (están fuera de mi dominio estricto de archivos Vue).
- **`RechargeApprovalService.php` en detalle** — se confirmó que existe y que `RechargeController@approve` delega en él, pero no se auditó su lógica interna de `lockForUpdate`/idempotencia línea por línea (backend fuera de mi dominio, solo se leyó lo necesario para validar que el copy del modal de `Admin/Recharges/Index.vue` no miente).
- **Migración Twilio → Meta WhatsApp Business Cloud API** en su totalidad (`App\WhatsApp\Contracts\WhatsAppProviderContract`, `WhatsAppMessage`, `WhatsAppSkipReason`, `WhatsAppMessageStatus`) — se leyó lo suficiente de `WhatsAppChannel.php` y `PhoneVerificationController.php` para confirmar que el frontend de `Legal/Privacy.vue` y `PhoneVerification.vue` es coherente con ella, pero no se auditó esa migración como sistema completo (está fuera del alcance "frontend Vue" de esta tarea).
- **Ejecución real de la suite Playwright/PHPUnit** — no se corrieron los tests, solo se localizaron por nombre/ruta y se leyó el diff de `qa/tests/flujo-completo.spec.js` (cambios de infraestructura de URL base, sin relación funcional con mi dominio).
- **`RescheduleTest.php`** — el comentario de la migración de `in_progress` menciona que este test usaba ese estado como data provider; no se confirmó si el test ya fue actualizado (archivo de test, fuera de mi dominio Vue).
