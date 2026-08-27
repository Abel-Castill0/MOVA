# MOVA — Exposición de credenciales en git history (F-26 / F-27)

**Fecha:** 2026-08-26. Documento dedicado, separado de `MOVA_QA_BASELINE.md`, porque este hallazgo dejó de ser "un detalle del saneamiento de QA" y pasó a ser un hallazgo de seguridad de infraestructura por derecho propio.

**Ningún valor secreto real aparece en este documento.** Cada fila describe qué se encontró, dónde, y en cuántos commits/archivos — nunca el contenido del secreto, ni siquiera un fragmento parcial que pudiera facilitar correlación con el valor real (esto incluye el hostname del proxy de Railway, deliberadamente omitido en todo el documento).

**No se intentó verificar si ninguna credencial listada aquí sigue activa.** Hacerlo requeriría conectarse a la base de datos de producción o iniciar sesión con una contraseña potencialmente comprometida — exactamente la acción insegura que esta investigación existe para evitar. El estado de "¿sigue funcionando?" es `UNKNOWN` para cada fila, no `activa` ni `inactiva`, hasta que el dueño de las cuentas lo confirme por el canal administrativo normal (panel de Railway, cambio de contraseña de la app).

---

## ⚠️ Corrección de alcance (esta ronda)

Una ronda anterior de esta misma auditoría documentó F-26 como "un script de depuración" (`qa/test-wizard-flow.mjs`) conteniendo la contraseña RAW de la base de datos de producción, ya eliminado del working tree. **Esa cobertura estaba incompleta.** Al continuar la búsqueda exhaustiva pedida explícitamente por el usuario, se encontró que:

1. **La misma contraseña exacta aparece en 6 archivos distintos, no 1**, repartidos en 5 commits.
2. **Uno de esos 6 archivos (`qa/end-to-end-welcome-email.mjs`) seguía tracked en HEAD y presente en el working tree en el momento de escribir esta corrección** — es decir, la afirmación previa de "ya eliminado del working tree" era falsa para ese archivo específico. Se detectó al auditar referencias al hostname del proxy de Railway en todo el repositorio (no solo en los archivos ya conocidos) y se eliminó del working tree en esta misma sesión, en el mismo commit de limpieza que ya se venía aplicando (sin tocar historial).

Esta sección queda documentada explícitamente en vez de simplemente corregir la tabla en silencio, siguiendo el principio ya establecido en esta auditoría: **"está cubierto" no es lo mismo que "es correcto"** — una afirmación de alcance de una ronda anterior no verificada de nuevo no debe tratarse como un hecho establecido.

---

## Inventario de hallazgos

| ID | Secreto | Severidad | ¿Tracked en HEAD antes de esta ronda? | Archivos distintos que lo contuvieron (histórico) | Commits en git history | ¿Pusheado a `origin`? |
|---|---|---|---|---|---|---|
| **F-26** | Contraseña RAW de la base de datos MySQL de producción (host, puerto, usuario, contraseña de conexión directa) | 🔴 **CRITICAL** | **Sí** — `qa/end-to-end-welcome-email.mjs` seguía tracked hasta esta ronda (ver corrección de alcance arriba) | **6**: `qa/test-wizard-flow.mjs`, `qa/end-to-end-welcome-email.mjs`, `qa/tests/10-create-report2.spec.js`, `qa/tests/11-check-email-report.spec.js`, `qa/tests/14-welcome-notification.spec.js`, `qa/tests/15-verify-email-welcome.spec.js` | 5 (`d7f288d`, `117dc36`, `8cbac89`, `469f848`, `4518666`) | Sí — confirmado ancestro de `origin/master` y `origin/Elias` |
| F-24A (parte) | Contraseña compartida de las 3 cuentas QA (admin/teacher/parent) | 🔴 HIGH | No (ya limpiada de los archivos actuales en una ronda anterior) | ~14 commits de varios archivos, no recontados archivo-por-archivo en esta ronda | ~14 | Sí — `origin/master` |
| **F-27** | Cookies de sesión real de admin de producción (`storageState` de Playwright: `mova_session` httpOnly + `XSRF-TOKEN`) | 🟠 HIGH histórico / 🟢 bajo actual (expiradas, verificado) | **Sí** — `qa/auth/admin.json` | 1 | 1 (`117dc36`) | Sí — `origin/master`, commit `117dc36` |
| — | Emails de las 3 cuentas QA | Informativo (no es un secreto por sí solo) | No | Varios, mismos archivos que la contraseña compartida | Varios | Sí |
| — | Client secret / refresh token de Gmail (QA) | — | No | 0 | 0 | No aplica |
| — | SID / auth token de Twilio (QA) | — | No | 0 | 0 | No aplica |
| — | Claves privadas (RSA/OpenSSH/PEM) | — | No | 0 | 0 | No aplica |
| — | Claves de API con formato reconocible (OpenAI `sk-`, AWS `AKIA` + 16 chars, Meta, Culqi, Pusher, Sentry DSN) | — | No | 0 coincidencias con el patrón completo (el único match de `AKIA` fue una coincidencia de 4 letras dentro de un bundle JS minificado, descartada al no cumplir el patrón `AKIA[0-9A-Z]{16}`) | 0 | No aplica |
| — | Root `.env` de la aplicación (Laravel) | — | No | 0 — nunca tracked | 0 | No aplica |
| — | Otro `storageState`/artefacto de sesión además de `qa/auth/admin.json` | — | No | 0 coincidencias encontradas (ver Metodología) | 0 | No aplica |
| — | `qa/node_modules` (higiene, no un secreto) | — | Anteriormente sí, ya limpiado en una ronda previa (commit `836250b`) | — | — | Ya no presente en HEAD |

**Los 6 archivos de F-26 y el archivo de F-27 ya fueron eliminados del disco (working tree)** (los 5 primeros de F-26 vía la eliminación de specs históricos en `4518666`; `qa/end-to-end-welcome-email.mjs` en esta misma sesión). **Corrección: esto NO detiene la exposición hacia adelante**, a diferencia de lo que una versión anterior de este documento afirmaba — ninguna de estas eliminaciones se comiteó. Verificado con `git cat-file -e HEAD:<ruta>`: los 3 archivos de F-26 y el de F-27 siguen presentes con contenido completo tanto en HEAD como en `origin/master` (son el mismo commit exacto, sin divergencia) — cualquiera que clone el repositorio hoy recibe los secretos igual que antes de esta auditoría. Ver la tabla de 4 capas más abajo para el detalle exacto por archivo.

---

## Estado de exposición en 4 capas (F-26) — CORREGIDO, la versión anterior de esta tabla estaba mal verificada

**Corrección importante:** una versión anterior de esta misma tabla afirmaba "HEAD actual: ✅ Limpio" basándose en que `git ls-files` no listaba los archivos. Eso fue un error — no se verificó realmente, se asumió. Al re-comprobar con `git cat-file -e HEAD:<ruta>` y comparando `git rev-parse HEAD` contra `git rev-parse origin/master` (son el mismo commit exacto, `692b365`, sin divergencia), se confirmó que **los 3 archivos de F-26 siguen presentes, con su contenido completo, en el commit HEAD actual y en `origin/master`** — es decir, en lo que cualquiera que clone el repositorio hoy recibe. Además, de los 3 archivos, solo `qa/end-to-end-welcome-email.mjs` fue removido con `git rm` (staged); los otros dos (`qa/test-wizard-flow.mjs`, `qa/auth/admin.json`) fueron borrados del disco en una ronda anterior sin `git rm`, así que ni siquiera están fuera del índice de git — `git ls-files` los sigue listando como tracked. La única capa que alguna vez estuvo realmente limpia es el working tree (el disco, ahora mismo).

La pregunta "¿está cerrado F-26?" no tiene una sola respuesta — depende de la capa. **F-26 permanece ABIERTO — las 4 filas siguen en ❌ salvo la primera**:

| Capa | Estado | Verificado por |
|---|---|---|
| **Working tree** (archivos en disco ahora mismo) | ✅ Limpio | Grep del valor exacto de la contraseña, el hostname y el puerto sobre el árbol completo — 0 coincidencias |
| **Índice de git (staging area)** | ❌ Parcialmente sucio | `git ls-files` sigue listando `qa/test-wizard-flow.mjs` y `qa/auth/admin.json` como tracked (nunca se hizo `git rm` sobre ellos, solo se borraron del disco) |
| **HEAD actual / `origin/master`** (lo que un `git clone` trae hoy) | ❌ **Sigue exponiendo el secreto — idéntico a `origin/master`, sin divergencia** | `git cat-file -e HEAD:<ruta>` confirma los 3 archivos presentes con contenido completo en el commit `692b365`, que es exactamente `origin/master` hoy |
| **Git history** (commits antiguos, alcanzables con `git log`/`git show`) | ❌ **Sigue conteniendo el secreto** | `git log --all -S"<valor>"` sigue devolviendo los 5 commits — no se ha purgado ni reescrito el historial |
| **Credencial real** (¿la contraseña sigue siendo válida en Railway?) | ❌ **Estado `UNKNOWN`, se asume válida hasta rotación confirmada** | No verificado deliberadamente (verificarlo requeriría conectarse con la credencial potencialmente comprometida) |

**Lo que esto significa en la práctica: ninguna de las acciones tomadas hasta ahora detuvo la exposición hacia adelante.** Solo se limpió una copia local en disco de esta máquina — cualquier otra persona (u otra máquina) que clone `origin/master` en este momento obtiene exactamente los mismos 3 secretos que si esta auditoría nunca hubiera ocurrido. La afirmación anterior ("esto detiene la exposición hacia adelante") era falsa y queda corregida aquí explícitamente en vez de simplemente editada en silencio.

**La forma de mover la fila de HEAD/`origin/master` a ✅ no requiere rotar nada ni reescribir historial** — es un commit normal, hacia adelante, que borra 3 archivos (exactamente lo que ya está pendiente en el working tree/staging), seguido de un push normal (fast-forward, sin `--force`). Esto es una operación completamente distinta y muchísimo más segura que "purgar el historial" (`git filter-repo`/BFG, que sí requiere `--force-push` y reescribe commits ya existentes) — confundir ambas fue parte del error de esta sección. Rotar la contraseña sigue siendo necesario para la fila de "credencial real"; purgar el historial sigue siendo una decisión separada y posterior para la fila de "git history" — pero commitear+pushear la eliminación de estos 3 archivos es seguro, reversible (`git revert`), y no tiene que esperar a ninguna de las otras dos.

Aplica el mismo razonamiento a F-27: su cookie ya expiró (bajo riesgo real), pero el archivo `qa/auth/admin.json` en sí sigue en HEAD/`origin/master` sin commitear su eliminación.

---

## Ficha enriquecida — F-27 (cookies de sesión de admin)

| Campo | Detalle |
|---|---|
| **Exposición** | `qa/auth/admin.json` — un `storageState` de Playwright generado por el ahora eliminado `global-setup.js`, que iniciaba sesión contra la producción real y guardaba las cookies resultantes. Tracked y pusheado en el commit `117dc36`, junto con una gran cantidad de archivos no relacionados (incluye `qa/node_modules`, ver commit stat). |
| **Validez actual** | **Expirada, verificado** — el campo `expires` de la cookie `mova_session` calcula a `2026-06-25T05:12:32Z`, y "hoy" en esta sesión es 2026-08-26: han pasado ~2 meses desde la expiración. Esto se calculó localmente (`new Date(<epoch>*1000).toISOString()`), no se verificó contra el servidor real (verificarlo requeriría usarla). |
| **Riesgo actual** | 🟢 Bajo — una cookie de sesión expirada no es utilizable para autenticarse, incluso si alguien la extrae del historial de git hoy. El riesgo histórico (mientras estuvo vigente, entre su creación y su expiración) no puede evaluarse retroactivamente: no hay forma de saber si alguien la usó en esa ventana. |
| **Causa raíz** | El patrón de `storageState` autenticado contra producción real, guardado como archivo en disco dentro del propio repositorio, sin que ningún `.gitignore` lo excluyera en ese momento. `global-setup.js` era además huérfano — ningún config de Playwright lo invocaba — por lo que su único efecto observable era este archivo. |
| **Corrección preventiva** | `qa/auth/` fue añadido a `.gitignore` en esta sesión (regla dedicada, con comentario explicando el origen F-27) — esto evita que un `storageState` *futuro* vuelva a entrar en git, pero no afecta al que ya está en HEAD/`origin/master` (`.gitignore` no destrackea un archivo ya tracked). Si en el futuro se necesita un patrón de `storageState` reutilizable (p. ej. para GAP-08), debe generarse contra cuentas de un seeder local, nunca contra producción. |
| **Corrección aplicada, estado real** | Borrado del disco únicamente — **no comiteado**. Sigue presente con contenido completo en HEAD y en `origin/master` (ver tabla de 4 capas de F-26 arriba, aplica igual aquí). Confirmado con `git cat-file -e HEAD:qa/auth/admin.json`. |

---

## Metodología de la búsqueda (para que quede repetible)

Se buscó por **valor**, no solo por nombre de archivo — el mismo enfoque que ya había encontrado la contraseña compartida en una ronda anterior, ahora aplicado sistemáticamente, y ampliado en esta ronda a una segunda pasada sobre el árbol de trabajo completo (no solo sobre los archivos ya sospechosos):

```bash
# Push status de commits específicos, contra TODAS las ramas remotas (no solo origin/master)
git ls-remote origin
git merge-base --is-ancestor <commit> origin/<rama>

# Búsqueda por valor exacto conocido, en TODO el historial
git log --all -S"<fragmento del secreto>" --oneline

# Para cada commit que la pickaxe devuelve, identificar el/los archivo(s)
# exacto(s) que lo contienen (un commit puede tocar decenas de archivos,
# la pickaxe no dice cuál) -- este paso es el que reveló que el alcance
# real de F-26 eran 6 archivos, no 1:
git show <commit> -- . | grep -B <N> "<fragmento>" | grep "^diff --git"

# Búsqueda por patrón de forma (sin conocer el valor de antemano)
git log --all -p -G"AKIA[0-9A-Z]{16}" -- .
git log --all -p -G"sk-[A-Za-z0-9]{20,}" -- .
git log --all -p -G"BEGIN (RSA |OPENSSH )?PRIVATE KEY" -- .

# Artefactos de sesión/autenticación por nombre de archivo alguna vez añadido
git log --all --diff-filter=A --name-only | grep -iE "storagestate|auth.*\.json$|session.*\.json$"

# Confirmación de que un archivo actual no esté tracked
git ls-files <ruta>

# Búsqueda del valor exacto (contraseña, hostname, puerto) sobre el ÁRBOL
# DE TRABAJO ACTUAL completo, no solo sobre los archivos ya conocidos —
# el paso que encontró que qa/end-to-end-welcome-email.mjs seguía
# presente y tracked en HEAD:
grep -r "<fragmento del secreto>" .
```

Repositorio confirmado **privado** vía `gh repo view` — limita el radio de exposición a colaboradores con acceso, no lo elimina.

---

## Privilegios de la credencial de base de datos expuesta (F-26)

El usuario relevó (con explícita cautela) si la credencial expuesta correspondía a un usuario de MySQL con privilegios acotados a la aplicación o a algo más amplio (root/admin de la instancia). Investigado desde el código:

- `config/database.php` define la conexión `mysql` con `username`/`password` leídos directamente de `env('DB_USERNAME')`/`env('DB_PASSWORD')` — **una sola credencial, sin distinción entre un rol de aplicación y un rol administrativo**. El código de Laravel no impone ni asume ningún nivel de privilegio particular; usa lo que sea que esas variables de entorno contengan.
- El script `qa/end-to-end-welcome-email.mjs` (ya eliminado) se conectaba con `dbQuery()` usando explícitamente el usuario `"root"` en el DSN (`new PDO("mysql:host=...;dbname=railway", "root", ...)`), lo que sugiere — sin ser una prueba concluyente sobre la instancia real — que al menos ese script asumía acceso de nivel administrativo, no un usuario de aplicación con permisos acotados a las tablas de MOVA.
- **No hay forma de confirmar desde el código del repositorio si el usuario de MySQL de producción realmente tiene privilegios de `root`/administrador de la instancia, o si "root" en ese DSN era simplemente el nombre del usuario de aplicación provisto por Railway** (Railway suele nombrar así al usuario por defecto de sus instancias gestionadas, sin que eso implique necesariamente privilegios de superusuario de todo el clúster). Esto **debe confirmarlo quien administra la instancia de MySQL en el panel de Railway** — no se puede ni se debe adivinar, y no se intentó determinar conectándose a la base de datos.
- Independientemente de la respuesta, la recomendación de remediación no cambia: cualquier credencial con acceso de lectura/escritura directo a la base de datos de producción, filtrada en texto plano y pusheada a un repositorio, se trata como compromiso total de los datos accesibles con esa credencial — no se necesita confirmar "qué tan root es root" para justificar la rotación inmediata.

---

## 🔴 ACTION REQUIRED — checklist de remediación explícito y reproducible

**Nada de esta lista se ha ejecutado.** Se presenta como una secuencia numerada para que, cuando el usuario confirme que la rotación manual ya ocurrió, la sesión (esta u otra) pueda ejecutar únicamente los pasos de verificación (6 en adelante) sin necesidad de re-derivar el plan desde cero. Los pasos 1-5 son responsabilidad exclusiva del dueño de las cuentas/repositorio; ningún paso de esta lista se ejecuta automáticamente por esta sesión sin confirmación explícita del usuario.

1. **Rotar la contraseña de la base de datos MySQL de producción** en el panel de Railway. Prioridad máxima — acceso de base de datos bypasea toda autorización de la aplicación (ledger, datos de usuarios y de menores, todo).
2. **Reiniciar todos los componentes con conexiones persistentes a esa base de datos** tras rotar — el proceso web, el queue worker, y el scheduler pueden mantener conexiones abiertas con la credencial anterior; cambiar solo la variable de entorno en Railway no basta si los procesos no se reinician.
3. **Rotar la contraseña compartida de las 3 cuentas QA** (admin/teacher/parent), usando una contraseña distinta por rol al recrearla.
4. **No es necesario invalidar manualmente las cookies de `qa/auth/admin.json`** — ya expiraron (verificado, ver ficha F-27 arriba). Rotar la contraseña de la cuenta invalida cualquier sesión activa que dependiera de ella de todos modos.
5. **Regenerar o eliminar cualquier `storageState` generado con las credenciales QA anteriores** — si existiera alguno fuera del repositorio (en una máquina de desarrollo, por ejemplo), queda inválido tras el paso 3, pero debe borrarse explícitamente en vez de dejarse "por si acaso".

**A partir de aquí, solo tras confirmación explícita del usuario de que 1-5 ya ocurrieron:**

6. **Verificar que MOVA se conecta correctamente con la nueva credencial** — un despliegue o reinicio de los componentes que use la nueva variable de entorno, confirmando que la aplicación arranca y sirve tráfico normalmente.
7. **Confirmar que la credencial antigua quedó invalidada** — un intento de conexión con la contraseña anterior debe fallar. (Este intento, si se hace, debe hacerse desde un canal controlado por el dueño de la cuenta, nunca reproduciendo el valor de la contraseña antigua en ningún log o documento de esta auditoría.)
8. **Auditar variables de entorno, configuración, CI/CD y documentación** en busca de cualquier referencia residual a la credencial antigua (Railway env vars de otros servicios, `.env` locales de otros colaboradores, secretos de CI/CD, cualquier doc interno no cubierto por esta auditoría).
9. **Comprobar que ningún secreto real haya sido reintroducido** en el árbol de trabajo desde que se escribió este documento — repetir la búsqueda por valor y por patrón de la sección de Metodología.
10. **Volver a ejecutar la búsqueda de exposición completa** (la metodología de este documento) para confirmar que no aparecieron nuevos hallazgos desde la última pasada.
11. **Actualizar F-26 y la Production Gate únicamente con evidencia** de los pasos anteriores — no marcar como cerrado por el simple paso del tiempo o porque "ya se pidió rotar".
12. **No mostrar ni registrar ninguna credencial real** en ningún paso de esta lista, en ningún commit, log, o documento — incluida la nueva contraseña rotada.
13. **Ejecutar la suite completa de tests** (`php artisan test`) tras todo lo anterior y reportar cualquier regresión — algunos tests de integración podrían depender indirectamente de variables de entorno de base de datos.
14. **Decidir, solo después de 1-13, si vale la pena purgar el historial de git** (`git filter-repo`/BFG). Es una reescritura destructiva que exige force-push y coordinación con cualquier colaborador que tenga el repo clonado. Purgar el historial sin haber rotado antes no reduce el riesgo real, solo lo esconde; y purgarlo es una decisión independiente de la rotación, no un paso automático posterior.

**Esta sesión, en este turno, no ejecuta ningún paso a partir del 6** — el usuario indicó explícitamente que la fase de verificación posterior a la rotación se ejecuta únicamente cuando confirme que la rotación manual (pasos 1-5) ya ocurrió.

---

## Qué no se encontró (para que la ausencia de evidencia no se lea como "no se buscó")

No se encontraron coincidencias con los patrones y fuentes inspeccionados (detallados en Metodología) para:

- Ninguna clave privada (RSA/OpenSSH/PEM) en ningún commit, en ninguna rama.
- Ninguna clave de API de OpenAI, AWS, Meta, Culqi, Pusher o Sentry con un valor real — los únicos placeholders encontrados (`PUSHER_APP_SECRET=`, `SENTRY_LARAVEL_DSN=`) están vacíos, consistentes con `.env.example`.
- El root `.env` de la aplicación nunca fue tracked.
- Ningún otro artefacto de sesión (`storageState`, cookies, tokens) además de `qa/auth/admin.json`.
- Ningún reporte/traza de Playwright (`qa/reports/`, `qa/test-results/`) fue tracked nunca.

Esto no es una garantía de que no exista ningún otro secreto en el repositorio — como demostró la corrección de alcance de esta misma ronda (F-26 pasó de "1 archivo" a "6 archivos" al repetir la búsqueda con más profundidad), una pasada anterior que no encontró algo no es evidencia de que ese algo no exista. Es el resultado de la búsqueda realizada, con su metodología documentada arriba para que pueda repetirse o ampliarse — y que debería, de hecho, repetirse periódicamente, no darse por completa de forma permanente.
