# MOVA — Registros técnicos sin datos identificables

## Hallazgos

| ID | Prioridad orientativa | CWE | Evidencia anterior | Corrección |
| --- | --- | --- | --- | --- |
| MOVA-LOG-01 | Media | CWE-532 | `app/Http/Controllers/Auth/PhoneVerificationController.php`: límite de OTP registraba `phone` completo | Advertencia con `user_id` interno únicamente, manteniendo la tasa por teléfono en `RateLimiter` |
| MOVA-LOG-02 | Alta | CWE-532/CWE-359 | `app/Services/DiagnosticAiEnrichmentService.php`: se registraba `$e->getMessage()` si fallaba una llamada a IA o la escritura del uso | Se registra la clase de excepción y metadatos no textuales; no se emiten prompts ni mensajes arbitrarios de proveedores/SQL |

No se asigna CVSS numérico sin un vector de explotación y exposición de logs verificado en despliegue.

## Pruebas de regresión

- `PhoneVerificationWhatsAppTest::test_rate_limits_otp_sends_per_phone_number_across_different_accounts` confirma que se mantiene el bloqueo entre cuentas sin almacenar el teléfono en el aviso.
- `AiPayloadContractTest::test_exception_logging_never_includes_the_prompt` simula una excepción cuyo mensaje **sí contiene** un dato personal del menor y verifica que el contexto del log no lo incluye.
- `AiPayloadContractTest::test_ai_usage_log_database_exception_never_logs_raw_sql_bindings` simula una consulta fallida con binding privado y valida el evento técnico sin consulta ni argumentos.
- Suites integrales de GitHub Actions (SQLite, MySQL y E2E) antes de fusionar a `master`.

## Alcance

Protección de datos en los caminos auditados conforme a los principios de minimización, OWASP Top 10 (registro seguro), ASVS V7 y controles ISO/IEC 27001:2022 A.8.11/A.8.15. **No constituye certificación ISO ni prueba que todos los logs del sistema estén libres de datos personales.**

Riesgo residual: los logs históricos que ya contienen teléfonos o mensajes de proveedor requieren gestión de retención según el ambiente; `user_id` es seudónimo técnico, no anonimización. Otros mensajes de errores de aplicación deben revisarse por separado.
