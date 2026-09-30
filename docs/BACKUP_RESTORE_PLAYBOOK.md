# MOVA — Backup & Restore Playbook

> **CURRENT TARGET_PRODUCTION:** Azure MySQL Flexible Server. La fuente de
> verdad de estado y gates es [MOVA V1 Completion Ledger](release/MOVA_V1_COMPLETION_LEDGER.md).
> Las secciones 1–8 son **HISTÓRICAS / LEGACY Railway** (última revisión
> 2026-06-29), preservadas como contexto de rollback solo si ese entorno sigue
> siendo aplicable. No acreditan que Railway sirva hoy el PUBLIC_APEX.

## Ruta primaria de restauración para el cutover Azure

La consulta **LIVE_STAGING** de solo lectura del 2026-09-29 encontró
`mova-mysql-splisbj6ldoqw` en estado `Ready`, MySQL 8.4, retención de backup
de 7 días y geo-backup deshabilitado. `earliestRestoreDate` existía en esa
consulta; comprobar de nuevo su valor y la ventana efectiva antes de elegir
la hora de recuperación. Ningún servidor de restore temporal aparecía en el
inventario de ese resource group; el snapshot histórico de una prueba anterior
no prueba que siga existiendo.

1. **STOP y autorización:** identificar el incidente, la base que sirve el
   PUBLIC_APEX, la pérdida de datos aceptable y el titular que autoriza la
   restauración. Congelar escrituras de web, worker y scheduler de forma
   coordinada. No ejecutar migraciones como parte automática del arranque;
   `php artisan migrate --force` exige revisión individual de migraciones y
   autorización para el entorno real.
2. **Punto de restauración:** consultar el servidor origen y su
   `earliestRestoreDate` con Azure CLI de solo lectura; elegir una hora UTC
   dentro de la ventana, anterior al incidente. Confirmar el identificador
   exacto de la instancia fuente y un nombre nuevo para el destino.
3. **Restaurar a servidor NUEVO:** usar Azure MySQL Flexible Server PITR
   (`az mysql flexible-server restore` con `--source-server`, `--restore-time`
   y un `--name` nuevo). Nunca sobrescribir el origen. Esta operación crea
   infraestructura y requiere autorización explícita; este documento no la
   ejecuta.
4. **Verificar dentro de la red privada:** confirmar `Ready`, conexión TLS,
   estado de migraciones, conteos de tablas clave (`users`, `students`,
   `student_data_consents`, `classes`, `credit_transactions`,
   `recharge_requests`, `payment_orders`) y reconciliación de ledger en modo
   de solo lectura. Comparar con evidencia previa y documentar diferencias
   esperadas. Probar flujos de autenticación y salud en un entorno aislado.
5. **Cutover supervisado:** actualizar `DB_HOST` de web, worker y scheduler a
   la nueva instancia de forma coordinada, verificar revisiones, colas,
   jobs, saldos y acceso. Conservar el origen hasta que el titular acepte el
   resultado. La actualización de Azure y DNS requiere autorización aparte.
6. **Evidencia y limpieza:** registrar hora UTC, servidor fuente/destino,
   resultados sin datos personales y responsable. Destruir una copia temporal
   únicamente tras autorización y verificación de que ya no es necesaria.

**STOP de rollback de consentimiento:** una vez existan filas reales en
`student_data_consents`, revertir la migración
`2026_09_29_000001_create_student_data_consents_table.php` ejecuta
`dropIfExists` y borra evidencia de consentimiento. No hacer rollback
automático de esa migración. Preservar y verificar una copia de auditoría,
detener el procedimiento y decidir la recuperación con el titular. No crear
consentimiento retroactivo para alumnos históricos.

## Archivo histórico LEGACY Railway (secciones 1–8)

Los pasos siguientes pertenecen al entorno Railway anterior. Confirmar
primero qué sistema sirve el PUBLIC_APEX y qué fuente de datos se intenta
recuperar; no aplicar estos comandos a Azure.

## Índice

1. [Frecuencia recomendada](#1-frecuencia-recomendada)
2. [Exportar backup desde Railway](#2-exportar-backup-desde-railway)
3. [Guardar backup local de forma segura](#3-guardar-backup-local-de-forma-segura)
4. [Restaurar en ambiente no productivo](#4-restaurar-en-ambiente-no-productivo)
5. [Restaurar producción (último recurso)](#5-restaurar-producción-último-recurso)
6. [Checklist pre-restauración](#6-checklist-pre-restauración)
7. [Checklist post-restauración](#7-checklist-post-restauración)
8. [Qué NO hacer](#8-qué-no-hacer)

---

## 1. Frecuencia recomendada

| Entorno | Frecuencia | Retención |
|---------|-----------|-----------|
| Producción (Railway) | Antes de cada deploy que incluya migraciones | 30 días |
| Producción (Railway) | Backup semanal mínimo | 30 días |
| Local de desarrollo | Antes de ejecutar seeders o limpiezas de datos | 7 días |

Railway MySQL incluye backups automáticos en planes de pago. Verificar en el panel de Railway → servicio MySQL → "Backups" si está activo.

---

## 2. Exportar backup desde Railway

### 2.1 Obtener las credenciales de conexión

Las variables de entorno de la base de datos en Railway se encuentran en:
Panel Railway → Proyecto → Servicio MySQL → Variables

Las variables necesarias son:
- `MYSQL_HOST` (o `MYSQLHOST`)
- `MYSQL_PORT` (o `MYSQLPORT`)
- `MYSQL_DATABASE` (o `MYSQLDATABASE`)
- `MYSQL_USER` (o `MYSQLUSER`)
- `MYSQL_PASSWORD` (o `MYSQLPASSWORD`)

> **No imprimir ni copiar estas credenciales en chats, commits, ni documentos compartidos.**

### 2.2 Exportar con mysqldump

Desde la terminal local, con el proxy de Railway activo:

```bash
mysqldump \
  --host=<PROXY_HOST> \
  --port=<PROXY_PORT> \
  --user=<MYSQL_USER> \
  --password \
  --single-transaction \
  --skip-lock-tables \
  --routines \
  --triggers \
  <MYSQL_DATABASE> \
  > backup_mova_$(date +%Y%m%d_%H%M%S).sql
```

- `--single-transaction`: garantiza consistencia sin bloquear tablas (requiere InnoDB).
- `--skip-lock-tables`: necesario cuando el usuario no tiene LOCK TABLES.
- Se pedirá la contraseña de forma interactiva, no incluirla en el comando.

### 2.3 Comprimir el backup

```bash
gzip backup_mova_YYYYMMDD_HHMMSS.sql
```

Resultado: `backup_mova_YYYYMMDD_HHMMSS.sql.gz` (típicamente 1-5 MB para MOVA en beta).

---

## 3. Guardar backup local de forma segura

- Guardar en directorio fuera del repositorio git, por ejemplo: `~/backups/mova/`
- Nunca guardar dentro de `ProyectoMOVA/` (riesgo de commit accidental).
- No subir a GitHub, Google Drive, ni servicios de nube compartida.
- Verificar que `.gitignore` incluye `*.sql` y `*.sql.gz`.
- Para redundancia, copiar a un disco externo o unidad cifrada.

```bash
mkdir -p ~/backups/mova
cp backup_mova_*.sql.gz ~/backups/mova/
```

---

## 4. Restaurar en ambiente no productivo

**Siempre probar la restauración en local o staging antes de tocar producción.**

### 4.1 Crear base de datos local de prueba

```bash
mysql -u root -p -e "CREATE DATABASE mova_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 4.2 Descomprimir y restaurar

```bash
gunzip -c backup_mova_YYYYMMDD_HHMMSS.sql.gz | mysql \
  --host=127.0.0.1 \
  --user=root \
  --password \
  mova_restore_test
```

### 4.3 Verificar integridad

```sql
USE mova_restore_test;
SHOW TABLES;
SELECT COUNT(*) FROM users;
SELECT COUNT(*) FROM teacher_profiles;
SELECT COUNT(*) FROM student_diagnostics;
SELECT * FROM failed_jobs;
```

Si los conteos son coherentes con lo esperado, el backup es válido.

---

## 5. Restaurar producción (último recurso)

> Usar SOLO si la base de producción está corrupta o se perdieron datos críticos.
> Implica downtime y pérdida de datos desde el momento del backup.

### 5.1 Activar modo mantenimiento

```bash
php artisan down --message="Mantenimiento en curso. Volvemos pronto." --retry=60
```

En Railway, esto puede hacerse también desde el panel deteniendo el servicio web temporalmente.

### 5.2 Validar el backup antes de restaurar

Seguir el paso 4 completo con una base local. Solo continuar si la restauración local fue exitosa.

### 5.3 Restaurar en Railway

```bash
mysql \
  --host=<PROXY_HOST> \
  --port=<PROXY_PORT> \
  --user=<MYSQL_USER> \
  --password \
  <MYSQL_DATABASE> \
  < backup_mova_YYYYMMDD_HHMMSS.sql
```

Si el archivo está comprimido:

```bash
gunzip -c backup_mova_YYYYMMDD_HHMMSS.sql.gz | mysql \
  --host=<PROXY_HOST> \
  --port=<PROXY_PORT> \
  --user=<MYSQL_USER> \
  --password \
  <MYSQL_DATABASE>
```

### 5.4 Ejecutar migraciones pendientes

Si el backup es anterior al deploy más reciente, puede haber migraciones no aplicadas:

```bash
php artisan migrate --force
```

### 5.5 Levantar el sitio

```bash
php artisan up
```

Verificar: `GET /healthz` debe devolver 200.

---

## 6. Checklist pre-restauración

- [ ] Tengo el backup descargado y verificado (tamaño no es 0 bytes)
- [ ] Probé la restauración exitosamente en ambiente local
- [ ] Notifiqué al equipo del downtime esperado
- [ ] Activé modo mantenimiento (`php artisan down`)
- [ ] Tengo acceso al proxy de Railway y las credenciales listas (sin imprimirlas)
- [ ] Sé qué datos se perderán desde el momento del backup hasta ahora
- [ ] Tomé nota del estado actual de `failed_jobs` y `jobs`

---

## 7. Checklist post-restauración

- [ ] `GET /healthz` devuelve 200
- [ ] `php artisan migrate:status` — todas las migraciones aparecen como "Ran"
- [ ] Login de admin funciona
- [ ] Login de padre funciona
- [ ] Login de profesor funciona
- [ ] `SELECT COUNT(*) FROM failed_jobs` — idealmente 0
- [ ] `SELECT COUNT(*) FROM jobs` — idealmente 0 (o valor esperado)
- [ ] Notificaciones en cola no muestran errores en logs
- [ ] `php artisan up` ejecutado y sitio accesible

---

## 8. Qué NO hacer

- **No** incluir credenciales de Railway en el comando al hacer commits o capturas de pantalla.
- **No** restaurar directamente en producción sin probar en local primero.
- **No** ejecutar `php artisan db:seed` después de restaurar sin confirmar que los datos de producción no se sobreescriban.
- **No** compartir el archivo `.sql` o `.sql.gz` por email, Slack, ni servicios de nube pública.
- **No** usar `--force` en migraciones destructivas sin revisar la migración línea por línea.
- **No** dejar activado el modo mantenimiento si el sitio ya está funcionando.
- **No** eliminar backups sin tener al menos otro válido de la misma semana.
- **No** asumir que Railway hace backups automáticos sin verificarlo en el panel.

---

## 9. Azure (MySQL Flexible Server) — evidencia y ejemplo histórico

Este bloque conserva el ejemplo de una prueba anterior. Usar la ruta primaria
al inicio de este documento y verificar nombres, ventana PITR y estado actual
antes de cualquier operación. **No ejecutar literalmente nombres temporales
o comandos de creación/eliminación de este ejemplo sin autorización.**

En la consulta LIVE_STAGING del 2026-09-29: retención 7 días, PITR
disponible por `earliestRestoreDate`, sin geo-backup.

**Restore de prueba (nunca sobre el origen):**
```bash
az mysql flexible-server restore -g mova-prod-rg --name mova-mysql-restoretest \
  --source-server mova-mysql-splisbj6ldoqw --restore-time <UTC ISO> --no-wait
az mysql flexible-server show -g mova-prod-rg -n mova-mysql-restoretest --query state   # Ready
```
El servidor restaurado hereda red privada (snet-mysql + Private DNS) y el usuario admin del origen. Verificación de datos: solo desde dentro de la VNet (Container Apps Job con la misma imagen y `DB_HOST=mova-mysql-restoretest.mysql.database.azure.com`), ejecutando `php artisan migrate:status` y `php artisan mova:reconcile-ledger` (solo lectura) y comparando conteos de `users`, `students`, `classes`, `credit_transactions`, `recharge_requests`, `payment_orders` con el origen.

**Ejemplo histórico de limpieza** (el servidor temporal no aparecía en el
inventario de 2026-09-29; verificar identidad y autorización primero):
```bash
az mysql flexible-server delete -g mova-prod-rg -n mova-mysql-restoretest --yes
```

**Restore real (último recurso):** restaurar a servidor NUEVO por PITR, validar como arriba, y cambiar `DB_HOST` de web/worker/scheduler a la nueva instancia (nueva revisión); el servidor original se conserva hasta confirmar.

**Paridad de esquema** (mismo query en origen y copia; hash idéntico = OK):
```sql
SELECT COUNT(*), MD5(GROUP_CONCAT(CONCAT_WS(':',table_name,column_name,column_type,is_nullable,column_key)
  ORDER BY table_name,column_name)) FROM information_schema.columns WHERE table_schema='mova';
```
