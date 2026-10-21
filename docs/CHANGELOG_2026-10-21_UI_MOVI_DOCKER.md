> Estado: CURRENT · 2026-10-21

# Registro de Cambios UI / Mascota Movi y Despliegue Local Docker (2026-10-21)

**Fecha y hora:** 2026-10-21 (01:17 – 02:27 UTC-5)  
**Rama base:** `master`  
**Propósito de este documento:** Proveer contexto exacto, trazable y verificable para cualquier agente de IA o desarrollador que continúe el trabajo sobre este workspace.

---

## 1. Entorno Local Docker Configurado

Se clonó el repositorio oficial `https://github.com/Abel-Castill0/MOVA.git` sobre la rama `master` y se levantó utilizando el `Dockerfile` multi-stage de producción (FrankenPHP + PHP 8.3 + Caddy + assets compilados con Vite):

- **Red Docker:** `mova-net`
- **Base de datos (`mova-mysql`):** Imagen `mysql:8.4`, puerto host `3307 -> 3306`, base de datos `mova`, migraciones completas ejecutadas (`php artisan migrate --force`) y datos iniciales sembrados (`DatabaseSeeder`: `RoleSeeder`, `SubjectSeeder`, profesores y padres de prueba).
- **Aplicación Web (`mova-web`):** Imagen `mova:latest` construida desde `Dockerfile`, expuesta en `http://localhost:8080`.
  - Endpoints de salud verificados: `/healthz` (`200 OK`) y `/readyz` (`200 {"status":"ready","checks":{"database":"ok","migrations":"ok","cache":"ok"}}`).

---

## 2. Resumen de Cambios Implementados en Código y Activos

### 2.1. Ocultación de tarifa por hora en "Profesores destacados" (`Welcome.vue`)
- **Archivo:** `resources/js/Pages/Welcome.vue` (líneas 479–485)
- **Cambio:** En las tarjetas del carrusel de la sección **Profesores destacados** (`#profesores`), se eliminó el elemento `<span>` que mostraba el precio por hora (`S/{{ parseFloat(t.hourly_rate).toFixed(0) }}/h`).
- **Alineación:** El contenedor inferior de la tarjeta cambió de `justify-between` a `justify-end` para mantener el botón **Ver perfil** alineado limpiamente a la derecha.

### 2.2. Nuevas animaciones de la mascota Movi en "¿Cómo funciona MOVA?" (`MoviMascot.vue`, `Welcome.vue`, `public/images/mascot/*`)
- **Objetivo:** Que las 3 tarjetas de pasos en desktop (`Solicita tu clase`, `Un experto acepta` y `Aprende en vivo`) muestren a la ardilla **Movi** asomándose con una animación WebP personalizada al hacer hover (`@mouseenter` / `@mouseleave`), con un estilo visual 100 % unificado (línea definida, ojos brillantes con lentes redondos, polera azul con logo MOVA).
- **Activos creados/actualizados en `public/images/mascot/`:**
  1. **Paso 1 — "Solicita tu clase" (`pose="solicita"`):** Movi con lentes y polera azul de MOVA sosteniendo una lupa y una lista/checklist de materias, con destellos amarillos animados.
     - `public/images/mascot/movi-solicita-200.webp` (200×161 px, estático pequeño)
     - `public/images/mascot/movi-solicita-420.webp` (420×338 px, estático grande)
     - `public/images/mascot/movi-solicita-anim.webp` (360×290 px, 18 frames animados en bucle, fondo transparente)
  2. **Paso 2 — "Un experto acepta" (`pose="experto"`):** Movi con birrete académico, lentes y polera azul de MOVA dando un pulgar arriba y mostrando un calendario con check verde de verificación.
     - `public/images/mascot/movi-experto-200.webp` (200×161 px, estático pequeño)
     - `public/images/mascot/movi-experto-420.webp` (420×338 px, estático grande)
     - `public/images/mascot/movi-experto-anim.webp` (360×290 px, 18 frames animados en bucle, fondo transparente)
  3. **Paso 3 — "Aprende en vivo" (`pose="celebra"`):** Rehecho al mismo estilo visual que los pasos 1 y 2 (Movi con birrete, lentes redondos, polera azul de MOVA y laptop plateada con el logo de MOVA celebrando con ambos brazos arriba).
     - `public/images/mascot/movi-celebra-200.webp` (200×161 px, actualizado)
     - `public/images/mascot/movi-celebra-420.webp` (420×338 px, actualizado)
     - `public/images/mascot/movi-celebra-anim.webp` (360×290 px, 18 frames animados en bucle, actualizado y optimizado de 767 KB a 489 KB)
- **Componente `resources/js/Components/MoviMascot.vue`:**
  - Se amplió el validador de la prop `pose` para admitir `['lee', 'saluda', 'feliz', 'solicita', 'experto', 'celebra']`.
  - Se registraron `solicita` y `experto` en el mapa `ASSETS` junto con su propiedad `anim`.
  - `animSrc` ahora resuelve dinámicamente `/images/mascot/${asset.value.anim}.webp` para cualquier pose que defina `anim` cuando `:animated="true"` está activo (manteniendo el `<source media="(prefers-reduced-motion: reduce)">` hacia la imagen estática por accesibilidad).
- **Componente `resources/js/Pages/Welcome.vue`:**
  - Cada elemento del arreglo `steps` incluye ahora su propiedad `pose` (`'solicita'`, `'experto'`, `'celebra'`).
  - Los estados reactivos `squirrelShown` y `squirrelVisible` pasaron de booleanos simples a arreglos por índice (`ref([false, false, false])`), junto con `playSquirrel(index)` y `stopSquirrel(index)`, de modo que cada tarjeta monta de forma diferida (lazy en el primer hover) y anima su propia ardilla independientemente.

### 2.3. Corrección de solapamiento de la ardilla central sobre el título (`Welcome.vue`)
- **Archivo:** `resources/js/Pages/Welcome.vue` (línea 262)
- **Problema:** Al hacer hover sobre la tarjeta central (**"Un experto acepta"**), la ardilla emergente (`h-48` / ~140 px visibles sobre el borde superior de la tarjeta) se superponía al título `¿Cómo funciona MOVA?` y su subtítulo porque el encabezado tenía solo `mb-16` (`64px`).
- **Solución:** Se incrementó el margen inferior del bloque de encabezado en desktop de `mb-16` a `mb-36` (`144px`), dejando espacio suficiente para que la animación de la tarjeta central se despliegue completa sin tocar el texto.

### 2.4. Corrección del destello/línea blanca en `LandingNavbar` al hacer scroll hacia arriba (`LandingNavbar.vue`)
- **Archivo:** `resources/js/Components/LandingNavbar.vue` (líneas 2–3)
- **Problema:** El `<nav>` tenía `transition-all duration-300` y solo incluía las clases `border-b border-gray-100` cuando `isSolid` era `true` (`window.scrollY > 20`). Al subir al tope de la página (`isSolid -> false`), se quitaban ambas clases de golpe: `border-bottom-width` tardaba `300ms` en interpolarse de `1px` a `0px`, y durante esos `300ms` el borde tomaba el color por defecto (`currentColor` / claro), produciendo una línea blanca visible sobre el fondo azul del hero.
- **Solución:** Se mantuvo `border-b` fijo en la clase base del `<nav>`, alternando únicamente entre `border-gray-100` (estado sólido) y `border-transparent shadow-none` (estado transparente sobre el hero), y se restringió la propiedad de transición a `transition-[background-color,border-color,box-shadow] duration-300`.

---

## 3. Inventario Exacto de Archivos Modificados y Nuevos

| Estado Git | Archivo | Descripción |
|---|---|---|
| `M` | `resources/js/Pages/Welcome.vue` | Sin precio en profesores destacados; soporte de Movi por tarjeta en los 3 pasos; `mb-36` en header de "¿Cómo funciona MOVA?" |
| `M` | `resources/js/Components/MoviMascot.vue` | Soporte de poses `solicita` y `experto` con versión animada WebP y fallback estático |
| `M` | `resources/js/Components/LandingNavbar.vue` | Fix de transición de `border-b` (`border-transparent`) para eliminar línea blanca al subir el scroll |
| `M` | `public/images/mascot/movi-celebra-200.webp` | Pose estática pequeña rediseñada al nuevo estilo unificado |
| `M` | `public/images/mascot/movi-celebra-420.webp` | Pose estática grande rediseñada al nuevo estilo unificado |
| `M` | `public/images/mascot/movi-celebra-anim.webp` | Animación WebP de "Aprende en vivo" rediseñada al nuevo estilo unificado |
| `A` (`??`) | `public/images/mascot/movi-solicita-200.webp` | Pose estática pequeña para paso 1 ("Solicita tu clase") |
| `A` (`??`) | `public/images/mascot/movi-solicita-420.webp` | Pose estática grande para paso 1 ("Solicita tu clase") |
| `A` (`??`) | `public/images/mascot/movi-solicita-anim.webp` | Animación WebP para paso 1 ("Solicita tu clase") |
| `A` (`??`) | `public/images/mascot/movi-experto-200.webp` | Pose estática pequeña para paso 2 ("Un experto acepta") |
| `A` (`??`) | `public/images/mascot/movi-experto-420.webp` | Pose estática grande para paso 2 ("Un experto acepta") |
| `A` (`??`) | `public/images/mascot/movi-experto-anim.webp` | Animación WebP para paso 2 ("Un experto acepta") |
| `M` | `docs/README.md` | Enlace a este documento de registro de cambios |
| `A` (`??`) | `docs/CHANGELOG_2026-10-21_UI_MOVI_DOCKER.md` | Este documento de referencia para agentes e historial |

---

## 4. Cómo Reconstruir y Actualizar el Contenedor Local

Si otro agente realiza cambios adicionales en `resources/js/` o `public/`, puede reconstruir y recrear el contenedor web ejecutando en PowerShell:

```powershell
docker build -t mova:latest .
$appKey = (docker inspect mova-web --format '{{range .Config.Env}}{{println .}}{{end}}' | Select-String -Pattern "^APP_KEY=").ToString().Trim()
docker stop mova-web
docker rm mova-web
docker run -d --name mova-web --network mova-net -p 8080:8080 `
  -e APP_ENV=local -e APP_DEBUG=true -e $appKey -e APP_URL=http://localhost:8080 `
  -e DB_CONNECTION=mysql -e DB_HOST=mova-mysql -e DB_PORT=3306 -e DB_DATABASE=mova `
  -e DB_USERNAME=root -e DB_PASSWORD=mova_local_root `
  -e MAIL_MAILER=array -e CACHE_DRIVER=file -e SESSION_DRIVER=file -e QUEUE_CONNECTION=database `
  mova:latest
```
