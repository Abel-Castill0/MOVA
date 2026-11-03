# MOVA — Contención de datos sensibles en redirecciones de validación

## Hallazgos verificados en código

| ID | Riesgo | Evidencia previa | Impacto | Estado |
| --- | --- | --- | --- | --- |
| MOVA-PII-01 | **Medio** · CWE-200/CWE-359 | `app/Exceptions/Handler.php`: `$dontFlash` excluía contraseñas, pero no `code`, `recovery_code` ni `token` | Los valores podían acabar en `_old_input` al redirigir después de una validación fallida | Corregido en código; prueba en CI pendiente |
| MOVA-PII-02 | **Medio** · CWE-359 | `app/Http/Controllers/ComplaintController.php`: `Request::validate()` y `back()->withInput()` completo en errores de base de datos | DNI, domicilio, contacto, información de menores y relato podían persistirse temporalmente en la sesión | Corregido en código; pruebas en CI pendientes |
| MOVA-PII-03 | **Alto** · CWE-532 | `app/Http/Controllers/ComplaintController.php`: `report($e)` en una `QueryException` al crear la reclamación | Los mensajes de una excepción SQL pueden incluir consulta/bindings sensibles, difundidos a logs y Sentry | Corregido en código; prueba en CI pendiente |

Los niveles son una prioridad orientativa; no se presenta una puntuación CVSS numérica sin un vector de despliegue comprobado.

## Correcciones

- `Handler::$dontFlash` excluye códigos de MFA y verificación, tokens de recuperación/pago y nombres de campos típicos de secretos de la copia de datos de formularios a la sesión de Laravel.
- Para Culqi, excluye también los parámetros completos de la autenticación 3DS (`parameters3DS`, incluidos criptogramas anidados) y `device_fingerprint_id`.
- `ComplaintController::store()` valida con `Validator::make`; en errores de formulario y fallos de persistencia **solo** conserva valores escalares que pertenezcan a las enumeraciones de `type`, `document_type` y `good_type`. Descarta arrays anidados y valores malformados. Las respuestas JSON conservan el contrato HTTP 422 y su estructura `errors`.
- Los fallos SQL del Libro de Reclamaciones se registran como `complaint.file.database_failure` con **tipo de error y código SQLSTATE únicamente**, sin reportar el objeto de excepción ni sus bindings al monitor de errores.
- Si la persistencia falla en una solicitud JSON, devuelve HTTP 503 con un mensaje genérico y sin datos del consumidor; el formulario Inertia sigue recibiendo un error asociado al campo `detail`.
- En la página Inertia/Vue, `useForm()` ya mantiene los campos editados en el cliente tras una respuesta con errores, por lo que no es necesario replicar DNI ni texto del consumidor en la sesión de servidor.
- No se modificó el almacenamiento autorizado de reclamaciones válidas ni el flujo de numeración, respuesta, notificación y roles.

## Verificación y regresión

- `tests/Feature/SensitiveInputFlashingTest.php::test_validation_redirect_never_flashes_mfa_or_payment_tokens`
- `tests/Feature/LegalComplianceTest.php::test_invalid_complaint_retains_only_nonsensitive_input_in_session`
- `tests/Feature/LegalComplianceTest.php::test_database_failure_while_filing_is_not_a_500`
- `tests/Feature/LegalComplianceTest.php::test_database_failure_responds_with_safe_503_to_json_clients`
- `tests/Feature/LegalComplianceTest.php::test_invalid_complaint_json_api_keeps_422_validation_contract`
- `tests/Feature/LegalComplianceTest.php::test_malformed_selectors_do_not_flash_arbitrary_nested_values`
- Suite HTTP y MySQL de GitHub Actions, y pruebas E2E/Inertia, antes de integrar a `master`.

## Alcance de controles

- OWASP Top 10: protección de datos; ASVS V3 (gestión de sesiones) y V6 (protección de datos), controles técnicos parciales.
- ISO/IEC 27001:2022, anexo A 8.11 (enmascaramiento de datos) y A 8.12 (prevención de fuga de datos), **sin afirmar certificación ni cumplimiento total**.
- Defensa en profundidad: filtrado global de secretos + minimización en un flujo especialmente sensible.

**Riesgo residual:** otros formularios todavía podrían volcar información personal no cubierta por las claves generales de `$dontFlash`. Esta remediación **no** equivale a un análisis completo de todas las sesiones, proveedores, observabilidad ni cargas desplegadas. Si una credencial ya se había almacenado, el cambio impide flashes nuevos pero no la revoca ni elimina sesiones antiguas: la política de rotación/expiración sigue siendo necesaria.

El filtro global de Laravel coincide con **nombres de campos de primer nivel**: no garantiza excluir secretos enviados en estructuras anidadas o bajo otros nombres. La retención en memoria del Libro de Reclamaciones depende de **Inertia `useForm()`**; un cliente HTML tradicional (sin ese estado) solo recuperará los tres selectores no sensibles tras un error.
