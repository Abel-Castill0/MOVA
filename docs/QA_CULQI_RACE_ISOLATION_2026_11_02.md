# MOVA — aislamiento del arnés Culqi multiproceso (2026-11-02)

## Alcance

`scripts/qa-payment-race.sh`, `scripts/qa/culqi-stub.php` y
`mova:qa-payment-race` son herramientas de pruebas **sintéticas**, no el flujo
de cobro de producción. Estas comprobaciones usan MySQL 8.4 efímero y un doble
HTTP local; no contactan a Culqi real.

## Hallazgos y remediación

1. `--cleanup` borraba por prefijo todos los usuarios `qa-pay-race-*@mova.test`,
   incluidas otras carreras simultáneas. Ahora exige `--scenario=<id>` y valida
   la identidad sintética y cardinalidad de *ese* usuario, perfil y recarga.
2. El script forzaba `APP_ENV=testing`, incluso si se invocaba en otro entorno.
   Ahora falla cerrado salvo que el operador ya haya configurado
   `APP_ENV=testing`, `DB_CONNECTION=mysql_qa` y `DB_HOST=127.0.0.1` o
   `localhost`; en el Docker Compose QA oficial admite también el servicio
   `mysql_qa`, exclusivamente cuando está presente `MOVA_QA_COMPOSE=1` y
   `/.dockerenv`. Laravel valida además el host efectivo (incluidos `.env`
   y `config:cache`), el esquema fijo `mova_qa` y la ausencia de sockets.
3. El doble HTTP usaba el puerto fijo 18765 y rutas globales `/tmp/` para los
   cargos y los logs. Ahora cada invocación crea un directorio temporal
   privado, obtiene un puerto libre y exige que `/ping` devuelva la identidad
   exacta de esa instancia, para no confundirse con otro servidor.
4. La limpieza por `trap` elimina únicamente archivos de la ejecución y su
   recarga; un fallo de cleanup también produce salida no exitosa. Los errores
   de workers se reflejan en sus códigos de salida y no incluyen mensajes SQL
   ni prefijos de tokens en logs del doble HTTP.
5. La preparación de la recarga, orden y saldo inicial está dentro de una sola
   transacción. Si falla antes de publicar su `scenario=<id>`, se revierten
   todas las filas sintéticas y no queda un escenario huérfano.

## Ejecución y pruebas

```bash
APP_ENV=testing DB_CONNECTION=mysql_qa DB_HOST=127.0.0.1 bash scripts/qa-payment-race.sh reconcile-paid 8
APP_ENV=testing DB_CONNECTION=mysql_qa DB_HOST=127.0.0.1 bash scripts/qa-payment-race.sh reconcile-refund 8
APP_ENV=testing DB_CONNECTION=mysql_qa DB_HOST=127.0.0.1 bash scripts/qa-payment-race.sh charge-attempt 8
```

El job `mysql` del CI ejecuta estas tres carreras. La suite
`QaPaymentRaceIsolationTest` cubre rechazo de conexión insegura, host remoto,
operaciones desconocidas, limpieza sin ID, coexistencia de dos recargas,
rechazo de URL Culqi externa y rollback ante fallo parcial de setup.
El GREEN se refiere exclusivamente a esos escenarios QA; no implica pagos
reales validados ni autorización de despliegue.
