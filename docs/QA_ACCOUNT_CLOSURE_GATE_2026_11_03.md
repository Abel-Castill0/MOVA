# MOVA — verificación multiproceso del cierre de cuenta (03-11-2026)

## Hallazgos y cambios

El script `scripts/qa-account-closure-race.php` lanza dos procesos PHP y
crea fixtures sintéticos para comprobar que el cierre de una cuenta no
compite incorrectamente con reservas, solicitudes de clase, recargas, liquidaciones,
reembolsos y notificaciones.

| ID | Evidencia anterior | Corrección |
| --- | --- | --- |
| QA-C-01 | Solo exigía `APP_ENV=testing`, `DB_CONNECTION=mysql_qa` y base `mova_qa`; un host remoto con ese nombre de base pasaba el guard | Añadida verificación de host **resuelto por Laravel**, local o Compose QA identificado, antes de conectar y crear datos |
| QA-C-02 | Un nombre de escenario desconocido no entraba en el `foreach` y salía con código 0 | Se valida contra `scenarios()` y se devuelve fallo |
| QA-C-03 | Un proceso `--worker` aceptaba cualquier ruta, operaciones e IDs de cuenta en un `fixture.json` inventado | Ruta y UUID validados, escenario/operaciones/opciones idénticos al catálogo de 15 carreras, materia exclusiva por run, y verificación de usuarios, perfil, alumno, solicitud, recarga/lección opcionales y ledger antes de tocar cualquier cuenta |
| QA-C-04 | Ante un worker fallido se copiaba parte de stderr; el catch mostraba mensajes arbitrarios de excepciones tipo `RuntimeException` | Solo las aserciones propias del arnés pueden mostrar texto; las excepciones externas se registran únicamente por clase |
| QA-C-05 | Los escenarios documentados de cierre no se ejecutaban automáticamente en el CI | Se agrega un gate MySQL al final del job, después de las pruebas y demás carreras |
| QA-C-06 | La aprobación de profesores en la carrera no establecía la identidad del admin QA | `Auth::setUser` fija al administrador sintético y la verificación comprueba `reviewed_by` |

## Ejecución segura

```bash
APP_ENV=testing DB_CONNECTION=mysql_qa DB_HOST=127.0.0.1 \
  php scripts/qa-account-closure-race.php
```

- Exige que la conexión resuelva a **`mova_qa` en MySQL QA local**. Los
  valores de credenciales para esa base se configuran por entorno seguro.
- `phpunit` añade pruebas de host remoto, entorno de producción, nombre de
  escenario incorrecto, ruta de fixture externa y fixture manipulado con
  UUID/ruta válidos pero cuentas ajenas al escenario.
- El CI ejecuta todos los interleavings con datos sintéticos aislados,
  independientemente de las pruebas PHPUnit y de las sondas Culqi/MFA.

## Límite explícito

Este script **retiene intencionadamente sus fixtures** en `mova_qa` para
inspección posterior, tal como documenta el módulo de cierre de cuenta. Se
ejecuta al final del job MySQL CI; su contenedor/BD se descarta al acabar el
job. **No es un comando de limpieza**, y no se ha creado un borrado global:
el historial de créditos y cierres exige conservar integridad referencial.
En estaciones QA persistentes se requiere un procedimiento separado y
revisado de purga por run o recreación autorizada de toda la base QA.

No implica pruebas live, accesos a cuentas ajenas ni lanzamiento productivo.
CI aprobado de esta PR es condición para su fusión.
