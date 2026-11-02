# MOVA — aislamiento del arnés de concurrencia de seguridad (02-11-2026)

## Alcance y hallazgos verificables

Herramienta exclusivamente de **QA sintética**: `mova:qa-security-race`,
`scripts/qa-security-race.sh`. No es el endpoint público de Libro de
Reclamaciones ni modifica su algoritmo de correlativos.

| ID | Riesgo anterior | Remediación |
| --- | --- | --- |
| QA-S-01 | `setup(complaints-*)` eliminaba **todas** las hojas y `complaint_sequences` del año vigente | Cada ejecución reserva atómicamente un año futuro sintético 2030–2037, sin borrar datos del año real |
| QA-S-02 | `--cleanup` borraba **todos** los admins `qa-race-*` y hojas con email compartido, incluso de otra prueba simultánea | Cleanup exige `--scenario` exacto. MFA elimina solo el admin señalado; reclamaciones solo su año y correo/ID de escenario, y aborta si detecta hojas ajenas |
| QA-S-03 | El script imponía `APP_ENV=testing` y utilizaba archivos `/tmp/qa_race_*` globales | El runner exige variables explícitas y conexión QA loopback/Compose, usa directorio temporal único y cleanup con trap por ID |
| QA-S-04 | Las excepciones de workers se mostraban con mensaje SQL/PII y devolvían salida exitosa | El error devuelve exit code no cero y solo nombre de excepción |
| QA-S-05 | Los casos de carrera MFA y reclamaciones no se ejecutaban en GitHub Actions | Cuatro carreras independientes (6 workers cada una) se incluyen en el job `mysql` |

### Seguridad de la conexión

El comando exige `--connection=mysql_qa`, verifica
`QaDatabaseGuard::assertSafeEnvironment()`,
`QaDatabaseGuard::assertLocalQaConnection('mysql_qa')` y
`QaDatabaseGuard::assertDatabase('mysql_qa', 'mova_qa')`.
La configuración efectiva de Laravel (incluyendo `.env` y caché) no puede
resolver a una base remota. No usar estos comandos en producción.

### Semántica de las carreras

- `totp`: como máximo **1** confirmación de un mismo código.
- `recovery`: exactamente **1** consumo del mismo código de recuperación.
- `complaints-empty`: exactamente N hojas nuevas, contador N y correlativos 1…N.
- `complaints-seeded`: 3 hojas previas del mismo escenario y N nuevas con
  correlativos 4…N+3, sin huecos.

Los años 2030–2037 se seleccionan por reserva atómica y **solo si aún son
futuros**. Este rango respeta el límite de `TIMESTAMP` de MySQL (2038);
años como 5000 provocarían errores en las columnas `created_at` y
`updated_at`. El comando fija el reloj de Carbon **solo dentro de cada proceso** durante
`Complaint::file`. En cleanup verifica pertenencia de cada hoja por email
único de escenario antes de borrar un contador, y revierte el reloj siempre.
Cuando se agote el rango, el arnés fallará cerrado; su horizonte temporal
deberá revisarse antes de 2038 junto al tipo de columnas de fecha.

### Ejecución

```bash
APP_ENV=testing DB_CONNECTION=mysql_qa DB_HOST=127.0.0.1 bash scripts/qa-security-race.sh totp 6
APP_ENV=testing DB_CONNECTION=mysql_qa DB_HOST=127.0.0.1 bash scripts/qa-security-race.sh recovery 6
APP_ENV=testing DB_CONNECTION=mysql_qa DB_HOST=127.0.0.1 bash scripts/qa-security-race.sh complaints-empty 6
APP_ENV=testing DB_CONNECTION=mysql_qa DB_HOST=127.0.0.1 bash scripts/qa-security-race.sh complaints-seeded 6
```

Estas variables son ilustrativas; MySQL `mova_qa` debe existir y estar
configurado. En Docker Compose QA se admite `DB_HOST=mysql_qa` únicamente
con la identidad de contenedor `php_qa`.

## Pruebas de regresión

`tests/Feature/QaSecurityRaceGuardTest.php`:

1. La ausencia de `--connection` o una conexión distinta nunca toca hojas.
2. La configuración `mysql_qa` que apunta a otra base falla cerrada.
3. El setup de un año sintético no altera la hoja/contador del año real.
4. Dos escenarios simultáneamente existentes conservan hojas y secuencias
   mutuamente aisladas; limpiar uno no elimina el otro.
5. Omitir `--scenario` bloquea cleanup de reclamaciones y de MFA.
6. Si aparece una hoja ajena en el año reservado, cleanup aborta.
7. Limpiar un admin MFA no borra otro escenario QA.
8. Una excepción inesperada de worker produce exit code de error.

CI aporta además pruebas de carga concurrente real MySQL sobre el mismo
escenario. No confundir **CI verde** con una auditoría ISO certificada, ni con
pruebas de seguridad de infraestructura productiva.

## Evidencia pendiente

Tras publicar esta rama, conservar el enlace del job MySQL de la PR con las
cuatro líneas `GREEN` y el E2E completo antes de fusionar a `master`.
El entorno local de edición no tiene PHP ni motor Docker, por lo que no se
afirma haber ejecutado PHPUnit aquí.
