# MOVA — control QA de concurrencia financiera (2026-11-02)

Este documento describe el control **vigente en código**, no certifica la
plataforma de producción ni sustituye las pruebas de los proveedores de pagos.
El historial en `MOVA_PRODUCTION_READINESS.md` corresponde a un snapshot antiguo.

## Fallos reproducibles corregidos

1. `mova:concurrency-verify` consultaba `LedgerReconciliation::run()['healthy']`
   (todos los perfiles). Un ledger antiguo e independiente producía un falso
   **ROJO** aunque la carrera comprobada hubiera sido correcta. Ahora examina
   solo los desajustes del perfil creado por ese escenario y sigue rechazando
   anomalías del propio perfil.
2. La sonda y el verificador aceptaban omitir `--connection`, ejecutándose
   contra la base por defecto. Ambos requieren explícitamente
   `--connection=mysql_qa`, entorno `local`/`testing` y nombre de esquema
   **exactamente** `mova_qa`. El guard revisa también que la configuración
   efectiva de Laravel utilice un host TCP loopback, incluso si procede de
   `.env`. El runner además exige `APP_ENV=testing`, `DB_CONNECTION=mysql_qa`
   y `DB_HOST` local y explícito (`localhost` o `127.0.0.1`).
3. Dos sondas compartían la materia `ConcurrencyProbe` y `--cleanup` borraba
   todos sus registros sin mirar el ID. Ahora cada escenario tiene materia
   sintética única; limpieza requiere `--scenario=ID`, valida la pertenencia
   y borra exclusivamente esa identidad.
4. Una excepción SQL o de servicio podía confundirse con `result=LOST` y
   exit code 0. Ahora cualquier excepción imprevista da `result=ERROR` y
   exit code 1 sin mostrar SQL ni datos personales; el runner recoge los
   exit codes de todos los procesos. También exige un único `WON` para
   `accept-lesson` y `reminder-claim`.
5. `settle` y `refund` podían dar GREEN con un solo asiento financiero aunque
   `classes.status` y `credits_settled_at` no indicaran cierre. El verificador
   exige tanto asiento como estado terminal y marca temporal de liquidación.

## Ejecución aislada

Usar **únicamente MySQL QA efímero y local**, con el esquema `mova_qa`
preparado mediante el mecanismo protegido de migraciones, sin datos reales:

```bash
APP_ENV=testing DB_CONNECTION=mysql_qa DB_HOST=127.0.0.1 bash scripts/concurrency-probe.sh accept-lesson 6
APP_ENV=testing DB_CONNECTION=mysql_qa DB_HOST=127.0.0.1 bash scripts/concurrency-probe.sh settle 6
APP_ENV=testing DB_CONNECTION=mysql_qa DB_HOST=127.0.0.1 bash scripts/concurrency-probe.sh refund 6
APP_ENV=testing DB_CONNECTION=mysql_qa DB_HOST=127.0.0.1 bash scripts/concurrency-probe.sh approve-recharge 6
APP_ENV=testing DB_CONNECTION=mysql_qa DB_HOST=127.0.0.1 bash scripts/concurrency-probe.sh reminder-claim 6
```

La variante antigua `scripts/concurrency-probe-qa.sh` delega en el mismo
runner protegido. El job `mysql` de GitHub Actions ejecuta las cinco carreras
después de PHPUnit, junto con las carreras de Culqi con HTTP simulado.

## Verificación y límites

- Regresiones: `tests/Feature/ConcurrencyVerifyIsolationTest.php` (guard,
  ledger propio/ajeno, limpieza A/B, profesor de A/B, errores y estado
  terminal). Las pruebas que mutan escenarios se ejecutan solo contra MySQL QA.
- Los workers usan conexiones MySQL independientes; el runner valida el estado
  final, los contadores de ganadores y los códigos de salida. No solicita ni
  envía cobros a proveedores reales.
- `LedgerReconciliation::run()` sigue recorriendo todos los perfiles para
  construir el informe; el **veredicto** se filtra por el perfil del escenario.
  En un QA muy grande, convendría incorporar un método de reconciliación
  directamente acotado para reducir tiempo de consulta.
- Un GREEN de estas carreras certifica el escenario y concurrencia ensayados,
  no ausencia de bugs bajo carga arbitraria ni aprobación para producción.
