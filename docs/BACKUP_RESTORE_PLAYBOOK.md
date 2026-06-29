# MOVA — Backup & Restore Playbook

> Última revisión: 2026-06-29

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
