# MOVA Monetization Integrity - Phase 1

Implementación local en `fix/monetization-integrity`. No activa recargas reales ni modifica producción.

## Reglas comerciales

- 1 crédito equivale a 60 minutos y cuesta S/ 2.00.
- La futura unidad mínima será 0.5 créditos para 30 minutos.
- Durante Fase 1 se conserva el costo existente de 2 créditos por clase. La conversión por duración pertenece a Monetización 2.

## Catálogo de paquetes

El catálogo vive únicamente en `config/credits.php`:

| Código | Nombre | Créditos | Monto PEN |
|---|---|---:|---:|
| `inicio` | Inicio | 5 | 10.00 |
| `impulso` | Impulso | 15 | 30.00 |
| `pro` | Pro | 30 | 60.00 |

El navegador solo envía `package_code`, `payment_method` y `operation_number`. Nombre, créditos y monto se resuelven en servidor y se guardan como snapshots.

## Recargas desactivadas

- `RECHARGES_ENABLED=false` es el valor predeterminado.
- `RECHARGE_PAYMENT_DESTINATION` queda vacío en el repositorio.
- El backend responde con bloqueo controlado si falta cualquiera de esas dos condiciones.
- La vista conserva saldos e historial, pero no muestra compra, teléfono, QR ni destino ficticio.

## Unicidad e idempotencia

El número de operación se conserva como string, se recorta y se eliminan espacios y separadores irrelevantes. La base de datos impone:

```text
UNIQUE(payment_method, operation_number_normalized)
```

Las transacciones financieras usan claves únicas:

```text
teacher:{teacher_profile_id}:welcome
recharge:{recharge_id}:deposit
lesson:{lesson_id}:reservation
lesson:{lesson_id}:consumption
lesson:{lesson_id}:release
```

El ledger incluye referencias opcionales a `lesson_id` y `recharge_request_id`. Los saldos de `teacher_profiles` siguen siendo la fuente operativa; la reconciliación automática con el ledger queda pendiente.

### Preflight de datos históricos

La migración aborta antes de alterar el esquema cuando encuentra operaciones históricas vacías o duplicadas después de normalizar. No modifica, combina ni elimina esos registros. El diagnóstico previo equivalente para MySQL 8 es:

```sql
SELECT COUNT(*) AS empty_operations
FROM recharge_requests
WHERE REGEXP_REPLACE(TRIM(operation_number), '[^[:alnum:]]', '') = '';

SELECT UPPER(REGEXP_REPLACE(TRIM(operation_number), '[^[:alnum:]]', '')) AS normalized_operation,
       COUNT(*) AS occurrences
FROM recharge_requests
GROUP BY normalized_operation
HAVING normalized_operation = '' OR COUNT(*) > 1;
```

La consulta debe ejecutarse únicamente en modo de solo lectura y no deben copiarse números de operación a reportes o logs. Cualquier remediación requiere aprobación del propietario antes de reintentar la migración.

## Estados protegidos

```text
ClassRequest: pending_parent_approval -> approve -> open
ClassRequest: pending_parent_approval -> reject -> rejected
ClassRequest: open -> teacher accept -> accepted

Lesson: scheduled + hora finalizada -> complete -> completed
Lesson: scheduled -> cancel -> cancelled
Lesson: completed -> report -> completed

RechargeRequest: pending -> approve -> approved
RechargeRequest: pending -> reject(reason) -> rejected
```

Cada transición financiera crítica bloquea la fila y revalida el estado dentro de `DB::transaction`. Una segunda aprobación, finalización, cancelación o aceptación no vuelve a mover saldo.

El rechazo individual de solicitudes genéricas no se rediseñó: `teacher_rejected` continúa siendo global y queda expresamente para Monetización 3.

## Conservación financiera

- En MySQL, ledger, recargas, clases y solicitudes dejan de depender de cascadas destructivas para sus relaciones financieras principales.
- Las referencias secundarias de revisión pueden quedar en `NULL`.
- Una cuenta con ledger, recargas o clases se anonimiza y suspende en lugar de eliminarse físicamente.
- Se eliminan datos personales operativos, se desactivan ofertas y se conserva la evidencia financiera.
- Se revocan roles, sesiones persistidas y tokens personales antes de terminar el flujo.
- Una cuenta sin historial protegido mantiene el flujo de borrado físico existente.

La migración de reemplazo de foreign keys es intencionalmente un no-op en SQLite; SQLite se usa para pruebas funcionales, no para validar las reglas `ON DELETE` de MySQL.

El rollback de columnas financieras se niega cuando existen recargas o ledger contextual. La restauración de foreign keys con `CASCADE` también se niega cuando existen clases, solicitudes o registros financieros. Ambos controles evitan destruir o volver vulnerable la evidencia de auditoría.

## Cobertura automatizada

`tests/Feature/MonetizationIntegrityTest.php` ejecuta controladores y rutas reales con SQLite `:memory:`. Cubre:

- catálogo confiable y bloqueo de recargas;
- normalización y duplicados de operación;
- aprobación/rechazo e idempotencia de depósito;
- transiciones de padre y doble aceptación;
- clase futura, cancelada, completada y doble finalización;
- reporte sin efectos financieros laterales;
- idempotencia del bono;
- conservación de ledger, recarga y clase al eliminar cuenta;
- ausencia de saldos negativos en los casos cubiertos.

El conteo exacto de tests y assertions se actualiza en el reporte del release gate después del QA final.

## Validación MySQL pendiente

Antes de habilitar dinero real se requieren pruebas aisladas sobre un MySQL no productivo para:

- dos requests verdaderamente paralelos sobre el mismo comprobante;
- dos administradores aprobando en paralelo;
- dos profesores aceptando la misma solicitud;
- completar y cancelar simultáneamente;
- comportamiento real de `lockForUpdate`, ENUM y foreign keys `RESTRICT`;
- verificación de las migraciones `up` y `down` con nombres de constraints reales.

## Riesgos pendientes

1. No existe reconciliación automática entre saldos y ledger.
2. El costo fijo de 2 créditos no implementa todavía la regla por duración.
3. No existe settlement para clases abandonadas ni reservas antiguas.
4. Reprogramar aún necesita serialización con completar/cancelar.
5. El rechazo de una solicitud genérica sigue afectando globalmente.
6. Zoom puede quedar huérfano si la API responde y luego falla el commit.
7. Experiencia y cupos de acompañamiento siguen siendo contadores denormalizados.

Por estos riesgos, `RECHARGES_ENABLED` debe permanecer desactivado.
