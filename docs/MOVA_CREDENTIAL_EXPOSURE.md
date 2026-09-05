> **HISTORICAL SNAPSHOT** — verify against `docs/MOVA_SYSTEM_MAP.md` and current code.
> Documento de una fase anterior. Conservado como registro de decisiones, NO como
> descripcion fiable del comportamiento actual.

# MOVA — Exposición de credenciales en git history (F-26 / F-27 / F-24A)

**Fecha de última revisión:** 2026-08-27. Documento dedicado, separado de `MOVA_QA_BASELINE.md`, porque este hallazgo dejó de ser "un detalle del saneamiento de QA" y pasó a ser un hallazgo de seguridad de infraestructura por derecho propio.

**Ningún valor secreto real aparece en este documento.** Cada fila describe qué se encontró, dónde, y en cuántos commits/archivos — nunca el contenido del secreto, ni siquiera un fragmento parcial que pudiera facilitar correlación con el valor real (esto incluye el hostname del proxy de Railway, deliberadamente omitido en todo el documento).

**No se ha intentado, en ningún momento de esta auditoría, verificar si la credencial de F-26 sigue activa, ni reutilizar la cookie de F-27.** Hacerlo requeriría conectarse a la base de datos de producción o reproducir una sesión potencialmente comprometida — exactamente la acción insegura que esta investigación existe para evitar.

---

## Tres incidentes distintos — no un solo "problema de secretos"

Tratar esto como un incidente único genera un riesgo real: rotar las credenciales de QA (F-24A) puede dar la falsa sensación de que F-26 (la credencial de producción, muchísimo más grave) también quedó resuelta. **No es así — son tres incidentes independientes, con severidad y remediación propias**, y cada uno se cierra por separado:

| ID | Qué es | Severidad | Por qué es distinto de los otros dos |
|---|---|---|---|
| **F-26** | Contraseña RAW de la base de datos MySQL de **producción** | 🔴 **CRITICAL** | Acceso de escritura directo a toda la base de datos — bypasea toda la capa de autorización de la aplicación. El más grave de los tres. |
| **F-24A** | Contraseña compartida entre las 3 cuentas QA (admin/teacher/parent) | 🔴 HIGH | Cuentas de una plataforma de **QA/pruebas**, no la base de datos en sí. Compromete esas 3 cuentas si aún existen con esa contraseña; no da acceso directo a SQL. |
| **F-27** | Cookies de sesión de admin de **producción** (`storageState` de Playwright) | 🟠 HIGH histórico / 🟢 bajo actual (expiradas, verificado) | Es una sesión ya autenticada de una cuenta específica, no una credencial reutilizable — y ya expiró. |

Ninguno de los tres se considera cerrado por la resolución de otro. Los criterios de cierre de cada uno están en su propia sección más abajo.

---

## Las cinco dimensiones de exposición — marco obligatorio para F-26 y cualquier secreto futuro

Una versión anterior de este documento mezclaba "cuántos archivos contuvieron alguna vez el secreto" (una pregunta histórica) con "cuántos lo exponen hoy" (una pregunta sobre el estado actual) bajo la misma cifra, lo cual generaba una contradicción real entre distintas secciones del propio documento. La corrección: **estas cinco dimensiones nunca se mezclan, nunca comparten una sola cifra**:

| Dimensión | Pregunta que responde | Cambia con... |
|---|---|---|
| **Historical Exposure** | ¿En cuántos archivos/commits apareció alguna vez el secreto, a lo largo de toda la historia? | Nunca (es un hecho histórico fijo, salvo que se purgue el historial) |
| **Current HEAD Exposure** | ¿Sigue el secreto en el commit `HEAD` de este checkout local? | Un commit que lo elimina |
| **Current Remote Exposure** | ¿Sigue el secreto en `origin/master` (lo que un `git clone` trae hoy)? | Un `git push` |
| **Working Tree Exposure** | ¿Existe el archivo físicamente en disco ahora mismo? | Borrar/crear el archivo, sin necesidad de git |
| **Credential Validity** | ¿Sigue siendo válida la credencial contra el servicio real (Railway/MySQL)? | Solo una rotación real, hecha por el dueño de la cuenta |

### F-26, evaluado en las cinco dimensiones (estado real, verificado ahora — no la última vez que se escribió este documento)

| Dimensión | Estado | Evidencia |
|---|---|---|
| **Historical Exposure** | 6 archivos distintos contuvieron la contraseña a lo largo de la historia: `qa/test-wizard-flow.mjs`, `qa/end-to-end-welcome-email.mjs`, `qa/check-twilio.mjs`, `qa/global-setup.js`, `qa/auth/admin.json`\* (F-27, cookie, no la contraseña — se incluye aquí solo por haber compartido el mismo commit de origen), `qa/tests/10-create-report2.spec.js`, `qa/tests/11-check-email-report.spec.js`, `qa/tests/14-welcome-notification.spec.js`, `qa/tests/15-verify-email-welcome.spec.js` — repartidos en 5 commits (`d7f288d`, `117dc36`, `8cbac89`, `469f848`, `4518666`) | `git log --all -S"<valor>"` + inspección commit por commit |
| **Current HEAD Exposure** | ✅ **LIMPIO** — cambió desde la última revisión de este documento. El commit local `6029ac4` (parte de un checkpoint de 12 commits, ninguno pusheado) elimina los 5 archivos que seguían en el árbol (`qa/test-wizard-flow.mjs`, `qa/end-to-end-welcome-email.mjs`, `qa/check-twilio.mjs`, `qa/global-setup.js`, `qa/auth/admin.json`) | `git cat-file -e HEAD:<ruta>` → falla para los 5 (ya no existen en `HEAD` local) |
| **Current Remote Exposure** | ❌ **SIGUE EXPUESTO** — `origin/master` no tiene el commit de limpieza; nada se ha pusheado | `git cat-file -e origin/master:<ruta>` → los 5 archivos existen, con contenido completo, en `origin/master` hoy |
| **Working Tree Exposure** | ✅ Limpio | Los 5 archivos no existen en disco |
| **Credential Validity** | ❌ `UNKNOWN` — se trata como **comprometida hasta que se rote**, no como "probablemente inactiva" | Deliberadamente no verificado; verificarlo requeriría usar la credencial comprometida |

\* `qa/auth/admin.json` es la ficha de F-27, no de F-26 — se referencia aquí solo porque `qa/end-to-end-welcome-email.mjs` original ronda de conteo la agrupó por error dentro de "los archivos de F-26". Ver la ficha F-27 dedicada más abajo para su propio detalle.

**Lectura correcta de esta tabla**: el commit local de limpieza (`6029ac4`) es real y verificable, pero **no cierra F-26** — mueve exactamente una dimensión (Current HEAD Exposure) de ❌ a ✅. Las otras cuatro (historical, remote, working tree ya estaba, credential validity) requieren acciones distintas y, en el caso de Remote y Credential Validity, una decisión y una acción del dueño de la cuenta que todavía no ha ocurrido.

---

## Rotación de credencial vs. limpieza del remoto vs. purga de historial — tres controles distintos, no uno

Una versión anterior de este documento afirmaba que "purgar el historial sin haber rotado antes no reduce el riesgo real, solo lo esconde." **Esa frase es imprecisa y queda corregida aquí explícitamente.** Los tres controles operan sobre superficies distintas y son complementarios, no sustitutos entre sí:

| Control | Qué hace | Qué NO hace |
|---|---|---|
| **Rotación de la credencial** | Invalida la capacidad de autenticarse con el valor filtrado — es la única acción que realmente "apaga" el secreto en sí | No borra el valor de ningún archivo, commit, clon existente, ni backup — el valor queda ahí, solo deja de servir para autenticar |
| **Limpieza del remoto actual** (commit + push de la eliminación) | Hace que cualquier `git clone` **desde este momento en adelante** ya no reciba el secreto en su `HEAD` | No afecta a clones que ya existían antes del push, ni al historial de commits ya pusheados anteriormente |
| **Purga de historial** (`git filter-repo`/BFG + force-push) | Reduce la disponibilidad **futura** del valor dentro del propio repositorio remoto (nadie que clone desde cero, después de la purga, puede recuperarlo con `git show`) | No revoca la credencial por sí sola, no afecta clones/forks/backups que ya existan fuera de ese remoto, y es una operación destructiva que exige coordinación con cualquier colaborador |

**El orden correcto no es "rotar y luego, si acaso, purgar"** — es reconocer que los tres actúan sobre riesgos distintos y programarlos según su propio mérito:

1. **Rotar la credencial** — apaga el riesgo de autenticación real. Máxima prioridad, sin condiciones.
2. **Verificar sesiones/procesos afectados** — ver sección F-27 más abajo; no asumir que rotar invalida nada por sí solo.
3. **Verificar que la aplicación sigue sana** con la nueva credencial.
4. **Pushear la limpieza del remoto actual** (el commit `6029ac4`, ya hecho localmente) — esto es una mejora real e inmediata una vez rotada la credencial; no hay razón para posponerla más allá de eso.
5. **Re-auditar** `HEAD`/`origin/master` tras el push.
6. **Evaluar por separado**, con su propio análisis de costo/coordinación, si purgar el historial es necesario — considerando número de commits, colaboradores, forks, clones conocidos, CI/CD, backups.

---

## Ficha F-26 — checklist de remediación (reordenado)

**Nada de esta lista se ha ejecutado.** Los pasos 1-3 son responsabilidad exclusiva del dueño de la cuenta/repositorio, por el canal oficial de Railway — **esta sesión no debe recibir, ver, ni manejar la contraseña nueva ni la antigua en ningún momento**.

1. **Rotar la contraseña de la base de datos MySQL de producción** en el panel de Railway.
2. **Reiniciar/redeployar los componentes con conexiones persistentes** (web, queue worker, scheduler) — cambiar solo la variable de entorno no basta si los procesos no se reinician.
3. **Verificar salud de la aplicación con la nueva credencial**: que web/queue/scheduler conecten correctamente, sin errores de runtime, con los health checks en verde.

**A partir de aquí, solo tras confirmación explícita del usuario de que 1-3 ya ocurrieron:**

4. **Push del commit de limpieza ya preparado localmente** (`6029ac4` y los 11 commits posteriores del mismo checkpoint) — esto mueve "Current Remote Exposure" a ✅. No requiere `--force`, es un push normal.
5. **Re-verificar `origin/master`** tras el push: `git cat-file -e origin/<rama>:<ruta>` para los 5 archivos → debe fallar en los 5.
6. **Repetir la búsqueda de exposición completa** (ver Metodología) para confirmar que no se reintrodujo ningún secreto nuevo desde la última pasada.
7. **Auditar variables de entorno/configuración/CI-CD/documentación** por cualquier referencia residual a la credencial antigua.
8. **Documentar el resultado del Database Privilege Audit** (sección dedicada más abajo) — qué usuario/privilegios usa realmente producción.
9. **Evaluar, como decisión separada y posterior**, si purgar el historial de git es necesario (ver la sección de arriba) — no ejecutar `git filter-repo`/BFG/force-push sin una evaluación explícita y documentada aparte.
10. **Actualizar F-26 únicamente con evidencia** de los pasos anteriores — nunca por el simple paso del tiempo.

**No se intenta, en ningún paso de esta lista, confirmar que la contraseña antigua "ya no funciona" conectándose con ella.** Esa verificación, si el dueño de la cuenta la considera necesaria, debe hacerse por evidencia administrativa del propio proveedor (el panel de Railway ya confirma qué credencial está activa tras rotar) — nunca reintroduciendo el valor comprometido en un comando, log, prompt, test, terminal o documento de esta auditoría.

### Criterios de cierre de F-26

F-26 solo pasa a `CLOSED` cuando las cinco dimensiones estén en ✅/evidenciadas, no antes:

- [ ] Credencial antigua rotada — confirmado por el dueño de la cuenta, no por esta sesión.
- [ ] Aplicación verificada sana con la credencial nueva (health checks, sin errores de runtime).
- [ ] Current HEAD Exposure ✅ (ya cumplido, commit `6029ac4`).
- [ ] Current Remote Exposure ✅ (pendiente de push, gateado a que ocurra la rotación primero).
- [ ] Ningún secreto nuevo equivalente reintroducido (repetir barrido).
- [ ] Database Privilege Audit documentado (ver abajo) — no se exige "resuelto", se exige "documentado con lo que se pudo determinar".
- [ ] Historical Exposure evaluado explícitamente (decisión tomada y documentada sobre purgar o no — "decidimos no purgar por ahora" es un cierre válido de este punto, "no lo pensamos" no lo es).

---

## Ficha F-27 — cookies de sesión de admin de producción

| Campo | Detalle |
|---|---|
| **Exposición** | `qa/auth/admin.json` — `storageState` de Playwright generado por el ahora eliminado `global-setup.js`. Tracked y pusheado en el commit `117dc36`. |
| **Validez de la sesión (cookie en sí)** | **Expirada, verificado** — el campo `expires` de `mova_session` calcula a `2026-06-25T05:12:32Z`; "hoy" son ~2 meses después. Calculado localmente, no contra el servidor real. |
| **Estado de revocación (corrección obligatoria)** | Una versión anterior de este documento afirmaba: *"Rotar la contraseña de la cuenta invalida cualquier sesión activa que dependiera de ella de todos modos."* **Esa afirmación se retira — no debe darse por cierta sin verificarla.** Cambiar una contraseña no garantiza universalmente que las sesiones ya autenticadas (tokens/cookies emitidos antes del cambio) queden invalidadas — depende enteramente de cómo la aplicación implemente la invalidación de sesión (p. ej. si la sesión se valida contra un hash de la contraseña vigente en cada request, un cambio la invalida; si se valida solo contra un token de sesión independiente almacenado en `sessions`/Redis/etc., no necesariamente). **Esto debe verificarse contra el mecanismo real de sesión de MOVA (`config/session.php`, driver configurado, y si `EncryptCookies`/`VerifyCsrfToken` dependen de algo ligado a la contraseña), no asumirse.** Marcado aquí como pendiente de verificación explícita, no como resuelto. |
| **Riesgo actual** | 🟢 Bajo — la cookie específica de este archivo está expirada por el paso del tiempo, no por ninguna acción de revocación tomada. Ese es un hecho distinto de "las sesiones se revocan al rotar", que sigue sin confirmarse. |
| **Causa raíz** | `storageState` autenticado contra producción real, guardado en el propio repositorio, sin `.gitignore` en ese momento. `global-setup.js` era huérfano (ningún config lo invocaba). |
| **Corrección preventiva** | `qa/auth/` añadido a `.gitignore` — evita reincidencia futura, no afecta lo ya tracked. |
| **Corrección aplicada, estado real** | Eliminado en el commit local `6029ac4` (mismo checkpoint que F-26) — limpio en `HEAD` local, **sigue en `origin/master`** hasta el push post-rotación. |

### Criterios de cierre de F-27

- [ ] El artefacto ya no está en `HEAD` local — ✅ cumplido.
- [ ] El artefacto ya no está en `origin/master` — pendiente de push.
- [ ] `qa/auth/` protegido en `.gitignore` contra reincidencia — ✅ cumplido.
- [ ] Mecanismo real de invalidación de sesión de MOVA verificado (no asumido) — **pendiente**, ver la corrección de arriba.
- [ ] Confirmado que no existe otro `storageState`/artefacto equivalente bajo control del proyecto — ✅ cumplido (ver Metodología, búsqueda por patrón de nombre de archivo).
- [ ] El proceso de generación de sesiones QA a futuro no vuelve a autenticarse contra producción — pendiente de decisión de diseño si se retoma un flujo de `storageState` (ver nota en la ficha).

---

## Ficha F-24A — contraseña compartida de cuentas QA

| Campo | Detalle |
|---|---|
| **Exposición** | Contraseña única compartida entre las 3 cuentas QA (admin/teacher/parent), en ~14 commits de varios archivos ya eliminados del árbol actual en una ronda anterior. |
| **Estado HEAD/remoto actual** | No tracked en ningún archivo actual — limpio en ambas dimensiones. Sigue en el historial (Historical Exposure). |
| **Remediación pendiente** | Rotar las 3 cuentas QA con una contraseña **distinta por rol** (no volver a compartir una sola), y eliminar/regenerar cualquier `storageState` que exista fuera del repositorio (máquinas de desarrollo) generado con la contraseña anterior. |
| **Relación con F-26** | **Ninguna dependencia entre sí** — rotar esto no resuelve ni sustituye la rotación de F-26. Se documenta y se cierra por separado. |

---

## Database Privilege Audit — tarea formal, no resuelta en este documento

El DSN encontrado en `qa/end-to-end-welcome-email.mjs` (ya eliminado) usaba explícitamente el usuario `"root"`. Esto **no prueba** que la credencial de producción tenga privilegios de superusuario de la instancia — Railway suele nombrar así al usuario de aplicación por defecto de sus instancias gestionadas, sin que eso implique necesariamente privilegios administrativos de todo el clúster. Pero tampoco debe asumirse lo contrario ni olvidarse. Se registra aquí como una tarea formal, **P1/P2 independiente de F-26**, pendiente de que el administrador de la instancia (no esta sesión, no conectándose con la credencial comprometida) responda:

- ¿Qué usuario de MySQL usa realmente producción hoy?
- ¿Qué privilegios tiene ese usuario (`SHOW GRANTS` desde el panel de administración, no desde código)?
- ¿Necesita la aplicación privilegios de `CREATE`/`DROP`/`ALTER` en tiempo de ejecución, o solo en el momento de correr migraciones (que podría ser un usuario/momento distinto)?
- ¿Existe (o debería crearse, al rotar) un usuario de aplicación dedicado con privilegios acotados a las tablas de MOVA, separado de cualquier usuario administrativo de la instancia?
- ¿Qué usuario provisiona Railway por defecto, y se puede aplicar el principio de mínimo privilegio sin romper el despliegue actual?

No se intentó ni se intentará determinar esto conectándose a la base de datos con la credencial expuesta. Este ítem queda como parte de los criterios de cierre de F-26 (documentado, no necesariamente resuelto con un cambio de privilegios en este ciclo).

---

## Metodología de la búsqueda (para que quede repetible)

```bash
# Push status de commits específicos, contra TODAS las ramas remotas
git ls-remote origin
git merge-base --is-ancestor <commit> origin/<rama>

# Búsqueda por valor exacto conocido, en TODO el historial
git log --all -S"<fragmento del secreto>" --oneline

# Identificar el archivo exacto dentro de un commit que toca muchos archivos
git show <commit> -- . | grep -B <N> "<fragmento>" | grep "^diff --git"

# Búsqueda por patrón de forma (sin conocer el valor de antemano)
git log --all -p -G"AKIA[0-9A-Z]{16}" -- .
git log --all -p -G"sk-[A-Za-z0-9]{20,}" -- .
git log --all -p -G"BEGIN (RSA |OPENSSH )?PRIVATE KEY" -- .

# Artefactos de sesión/autenticación por nombre de archivo alguna vez añadido
git log --all --diff-filter=A --name-only | grep -iE "storagestate|auth.*\.json$|session.*\.json$"

# Estado exacto de un archivo en HEAD/remoto/índice — las tres preguntas nunca se mezclan
git cat-file -e HEAD:<ruta>            # ¿existe en HEAD local?
git cat-file -e origin/master:<ruta>   # ¿existe en el remoto?
git ls-files <ruta>                    # ¿está en el índice/staging?

# Búsqueda sobre el ÁRBOL DE TRABAJO actual completo, no solo archivos sospechosos
grep -r "<fragmento del secreto>" .
```

Repositorio confirmado **privado** vía `gh repo view` — limita el radio de exposición a colaboradores con acceso, no lo elimina.

---

## Qué no se encontró (para que la ausencia de evidencia no se lea como "no se buscó")

No se encontraron coincidencias con los patrones y fuentes inspeccionados (detallados en Metodología) para:

- Ninguna clave privada (RSA/OpenSSH/PEM) en ningún commit, en ninguna rama.
- Ninguna clave de API de OpenAI, AWS, Meta, Culqi, Pusher o Sentry con un valor real.
- El root `.env` de la aplicación nunca fue tracked.
- Ningún otro artefacto de sesión (`storageState`, cookies, tokens) además de `qa/auth/admin.json`.
- Ningún reporte/traza de Playwright fue tracked nunca.

Esto no es una garantía permanente — es el resultado de la búsqueda realizada hasta la fecha de última revisión, con metodología documentada para repetirla.
