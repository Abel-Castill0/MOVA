# Perfil, configuración y solicitudes de eliminación

Estado del código: 2026-10-23. Este documento describe la implementación; no sustituye la aprobación de una política de conservación. Las evidencias de validación y de despliegue están en la última sección y en `release/DEPLOY_HISTORY.md`.

## Comportamiento del producto

El menú del avatar ofrece **Mi perfil**, **Configuración de cuenta** y **Cerrar sesión**, tanto en escritorio como en móvil. `/profile` abre Información personal y `/account/settings` abre Seguridad. Ambas rutas usan `Profile/Edit.vue`, con pestañas de información personal, seguridad y privacidad/cuenta.

La identidad personal permite editar nombre, correo, teléfono y avatar mediante los controladores existentes. Cambiar el correo exige la contraseña actual y elimina su verificación. Cambiar realmente el número de teléfono invalida su verificación y el consentimiento asociado; el servidor normaliza el número antes de comparar. La contraseña mantiene la validación existente y `AuthenticateSession` invalida otras sesiones cuando detecta el cambio de credencial. El formulario avisa de modificaciones sin guardar.

Para profesores, la edición profesional permanece en `/teacher/profile`, enlazada desde Información personal: materias, presentación, disponibilidad y proceso de verificación no se sustituyen por el formulario de identidad. Las preferencias de WhatsApp reutilizan su flujo existente; no activan proveedores ni garantizan que el canal esté configurado.

## Alcance de «Eliminar mi cuenta»

La acción **registra una solicitud de eliminación y cierra el acceso habitual**. No borra ni anonimiza automáticamente la identidad, los alumnos, las clases, los archivos o la historia financiera, aunque la cuenta no tenga operaciones. No existe una tarea de purga ni un plazo de borrado automático.

Pueden solicitarla usuarios con rol `parent` o `teacher`. Tener ambos roles cierra toda la identidad: no hay cierre parcial de un rol. Una cuenta con rol `admin` no puede usar este cierre, aunque también tenga rol de profesor o padre. La autorización se aplica en servidor, no depende de ocultar el botón.

El modal muestra consecuencias y datos reales: alumnos vinculados, clases pendientes, solicitudes activas y, si hay perfil profesional, créditos disponibles/reservados y pagos pendientes/en revisión. La consulta contempla alumnos con eliminación lógica y las relaciones de profesor y contraoferta. El snapshot también guarda contadores históricos y flags de perfil/avatar, sin copiar nombres ni expedientes de los menores.

**El snapshot es una fotografía al solicitar, no el estado operativo actual.** Un webhook o una resolución posterior puede cambiar operaciones. El administrador debe consultar sus registros actuales antes de decidir.

## Identidad, cierre y acceso posterior

Rutas principales:

| Ruta | Función |
|---|---|
| `POST /profile/closure-code` | Envío de prueba al correo verificado; límite 3 solicitudes por 10 minutos |
| `DELETE /profile` | Confirmación y cierre; límite 5 solicitudes por minuto |
| `GET /cuenta/solicitud` | Estado privado y respuesta al titular |
| `GET /admin/account-deletions` | Bandeja administrativa paginada |
| `PATCH /admin/account-deletions/{deletion}` | Registrar respuesta y evento de auditoría |

El cierre exige aceptación explícita, `scope=account` y contraseña actual o código de correo. Consultar perfil y solicitar cierre no obliga a aceptar versiones legales nuevas ni a verificar el correo; la alternativa de código sí requiere un correo ya verificado. Permite a usuarios de Google utilizar esa alternativa sin inventar una contraseña.

El OTP tiene seis dígitos y caduca en 10 minutos. La caché guarda su hash y correo, bajo una clave ligada al usuario y a un nonce de sesión. Se consume al intentar validarlo: si falla, se debe solicitar otro. Cambiar de correo o sesión invalida la prueba anterior. La identidad se comprueba nuevamente bajo el bloqueo del usuario antes de cerrar, para rechazar credenciales modificadas entre validación y transacción.

`AccountClosureCode` se envía de forma síncrona: no implementa envío en cola y no persiste el código en un payload de trabajo. Se rechazan el transporte `log`, el transporte `array` fuera de pruebas y los destinatarios bloqueados por la allowlist. Si el proveedor falla, se elimina la prueba y se devuelve un error controlado sin registrar el contenido de la excepción. Un resultado positivo de pruebas con correo simulado no demuestra entrega real del proveedor.

`AccountLifecycleService::requestClosure` usa una transacción y bloqueo de usuario, seguido del perfil profesional cuando existe. La solicitud es única por usuario e idempotente. Registra referencia UUID, método de identidad, snapshot y evento; establece `account_closed_at`, revoca tokens personales, sesiones de base de datos, token de recuerdo y tokens previos de recuperación. Desactiva la verificación del profesor y sus ofertas públicas. No consume créditos ni libera reservas, devuelve pagos o cancela clases.

El controlador cierra la sesión actual, la invalida y conserva únicamente la referencia necesaria para consultar el recibo en la nueva sesión. El recibo no se obtiene con un `user_id` o UUID arbitrario de la URL: procede de la identidad autenticada o de la referencia guardada en sesión. Sus props contienen referencia, estado, fechas y respuesta, no el snapshot completo. El comprobante muestra número de solicitud, tipo, fecha, estado, mensaje y siguiente paso. Un visitante sin sesión solo lo ve durante 30 minutos tras el cierre (`closure_reference_until`), para que un equipo compartido no lo exponga indefinidamente; el titular lo consulta siempre iniciando sesión.

`EnsureAccountIsOpen` redirige cuentas cerradas al estado de su solicitud; peticiones JSON reciben 403. Permite el estado, logout, páginas legales y reclamaciones. Volver a iniciar sesión o recuperar la contraseña no reabre el dashboard. Se impiden nuevas clases/aceptaciones y la exposición pública del profesor cerrado incluso si se restaura su flag de verificación por separado. Los bloqueos de participantes serializan altas/ediciones relevantes con el cierre; las operaciones históricas permanecen consultables por sus vías autorizadas.

Los avisos rutinarios se suprimen mediante `PreventClosedAccountNotifications` y el guard del trabajo de WhatsApp. La recuperación de contraseña se exceptúa expresamente para permitir acceso al recibo; no reactiva la cuenta.

## Menores, archivos y conservación

El modelo actual tiene un único `parent_user_id` por alumno. Ese vínculo técnico no prueba custodia exclusiva ni autorización para destruir todos los registros del menor. No hay transferencia automática a otro tutor, reasignación a un profesor ni eliminación en cascada por cerrar la cuenta del padre. Soporte debe validar representación, obligaciones pendientes y derechos de terceros antes de resolver sobre datos del menor.

Se conservan clases, solicitudes, recargas, órdenes, ledger, reservas y reseñas para revisión. El cierre no modifica importes ni estados financieros. Esta conservación provisional no constituye una autorización de retención indefinida: faltan una matriz aprobada de categorías, finalidades, bases y plazos aplicables, junto con el procedimiento verificable de supresión y anonimización.

El cierre **no invoca borrado de Cloudinary**, ni elimina comprobantes, avatares o adjuntos. Quitar una foto desde el formulario de avatar es otra operación existente y sí usa el servicio de almacenamiento. La resolución final debe identificar también copias y archivos de proveedores; esta entrega no certifica su eliminación.

## Atención administrativa y plazo de respuesta

La bandeja está restringida a administradores y conserva los controles de autenticación, verificación, MFA y suspensión. Permite registrar una respuesta de 20 a 5000 caracteres. `respond` bloquea la solicitud, registra actor/fecha/texto y cambia `pending_review` a `responded`; repetir exactamente la respuesta no añade otro evento. **Respondido no significa eliminado.** No ejecuta purga, reembolso, reapertura o cancelación de obligaciones. La respuesta aparece en el recibo; no se implementa un envío automático de respuesta por correo.

El responsable debe revisar las operaciones actuales, determinar qué datos pueden suprimirse y cuáles deben conservarse con justificación, y responder al titular. La interfaz informa del plazo general de **10 días hábiles desde el día siguiente de la solicitud para responder a la cancelación**. No lo presenta como promesa de borrado total. El software no calcula un vencimiento con feriados ni resuelve excepciones o ampliaciones legales; su seguimiento corresponde al responsable hasta incorporar ese proceso validado.

Fuentes oficiales de referencia:

- [Procedimiento para ejercer derechos ARCO, Gobierno del Perú](https://www.gob.pe/9269-iniciar-procedimiento-para-el-ejercicio-de-derechos-de-acceso-rectificacion-cancelacion-y-oposicion).
- Decreto Supremo N.° 016-2024-JUS, Reglamento de la Ley N.° 29733; revisar especialmente el régimen y plazos de respuesta del artículo 69. [Publicación del MINJUSDH sobre su aprobación](https://www.gob.pe/institucion/minjus/noticias/1067368-ejecutivo-aprueba-nuevo-reglamento-de-la-ley-de-proteccion-de-datos-personales/).

Estos enlaces y el texto de interfaz no sustituyen la validación legal de los criterios concretos de conservación, representación de menores y ejecución de supresión. No se ha aprobado en esta entrega ningún plazo nuevo de retención.

## Esquema, publicación y recuperación

`2026_10_23_060000_create_account_deletion_requests.php` añade `users.account_closed_at` nullable e indexado y crea `account_deletion_requests`. La tabla tiene referencia única, usuario único con FK restrictiva, estado, método de identidad, snapshot/eventos JSON y respuesta/fechas. No borra ni transforma registros existentes.

`down()` es intencionalmente una operación vacía: no se debe destruir evidencia de solicitudes al revertir una versión de aplicación. La estrategia de esquema es **hacia adelante**. No ejecutar `migrate:rollback` como procedimiento de recuperación: puede alterar el historial de migraciones aunque conserve las columnas, y un `up` posterior intentaría recrearlas. Cualquier corrección se implementa como nueva migración revisada, conservando evidencia.

Antes de publicar, revisar el SQL y probarlo en MySQL QA, disponer del respaldo y seguir el Job de migración del runbook. No arrancar una versión que consulte estas columnas antes de aplicar la migración. Un rollback de aplicación a código anterior que ignore `account_closed_at` puede devolver acceso a cuentas cerradas; por tanto no es seguro restaurar ciegamente una imagen previa. Preferir corregir hacia adelante o mantener controles de cierre equivalentes durante la recuperación, sin borrar solicitudes ni reabrir usuarios.

## Verificación y límites pendientes

Evidencia ejecutada sobre el árbol final (2026-10-23; datos sintéticos, contenedores QA, nunca cuentas reales):

- `AccountLifecycleTest` (27 pruebas): identidad por contraseña/código (incorrecto, caducado, reutilizado, de otra sesión, correo no verificado, roles no elegibles, límites de frecuencia 3/10 min y 5/min, el código nunca aparece en logs ni en caché en claro), cierre idempotente, admin excluido, conservación de alumnos/clases/créditos/pagos/ledger, recibo privado y caducidad del recibo de invitado, avisos suprimidos, trabajo de WhatsApp en cola descartado para cuentas cerradas, y sin bono de bienvenida a una cuenta cerrada.
- Carreras MySQL multiproceso (`scripts/qa-account-closure-race.php`, procesos PHP independientes, el líder retiene sus locks y el seguidor debe esperar): doble cierre, cierre vs. agendar clase (ambos órdenes), cierre vs. nueva solicitud de clase, cierre de profesor vs. aprobación admin (ambos órdenes), cierre con recarga pendiente que se acredita después y viceversa (un solo depósito), cierre vs. liquidación de clase del scheduler con notificaciones reales (la cuenta cerrada no recibe avisos) y viceversa. Verifican una sola solicitud/evento, alumnos y clases vinculadas, saldo y ledger coherentes. Sin interbloqueos.
- Perfil de MySQL de PHPUnit (`phpunit.mysql.xml`) sobre los tests afectados.

Decisión pendiente del propietario: una cuenta cerrada **no puede cancelar** clases ya programadas (el middleware bloquea toda ruta salvo estado, logout, legales y reclamaciones). La clase sigue vigente para la otra parte y la liquidación automática se aplica según las reglas existentes. Opciones: exigir que no haya clases pendientes antes de solicitar el cierre, permitir `lessons.cancel` a cuentas cerradas, o que soporte las resuelva caso por caso. Mientras no se decida, soporte debe revisarlas.

Límites que no se acreditan: no hay purga, anonimización ni borrado en proveedores (Cloudinary, correo, WhatsApp); no hay envío automático de la respuesta al titular; no se ha probado entrega real de correo del código (solo transporte simulado en pruebas).

Para regresión dirigida: `AccountLifecycleTest`, `ProfileTest`, `FinancialHistoryDurabilityTest`, `MonetizationIntegrityTest` y las carreras. En navegador comprobar padre/profesor/múltiples roles, 375 px y escritorio, avatar con teclado, pestañas, cambios sin guardar, modal con scroll/foco/Escape, errores de identidad, recibo tras logout y respuesta administrativa. No realizar cierres ni pruebas destructivas con cuentas reales.
