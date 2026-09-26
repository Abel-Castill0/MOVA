# 📚 Resumen Cambios para Claude 26-09-26 by Antigravity

Este documento detalla todas las modificaciones, características y optimizaciones de diseño e ingeniería implementadas recientemente en el proyecto **MOVA**, organizadas por módulo.

---

## 1. 🐿️ Módulo Chatbot Movi (Asistente Virtual con IA)

Se implementó el asistente virtual oficial de MOVA, **Movi**, con integración completa de frontend, backend y proveedor de IA generativa (Google Gemini API).

### 1.1 Backend y Arquitectura
* **Servicio Central (`app/Services/ChatbotService.php`):**
  - Manejo de llamadas HTTP seguras contra Google Generative Language API.
  - Implementación de cascada de modelos de respaldo: modelo primario `gemini-3.5-flash-lite`, con respaldo en `gemini-3.5-flash` y `gemini-3.8-flash`.
  - Soporte de configuración de timeout, temperatura y tokens en `config/chatbot.php`.
  - Manejo de entorno local: se agregó `withoutVerifying()` únicamente en entorno local para evitar bloqueos por certificados CA en Windows cURL.
  - Historial conversacional recortado a los últimos 6 turnos para optimizar latencia y consumo de tokens.
* **Controlador (`app/Http/Controllers/ChatbotController.php`):**
  - Endpoint `/chatbot/message` que valida el mensaje de entrada y el array de historial.
  - Middleware de protección contra abuso: `throttle:30,1` (máximo 30 mensajes por minuto por IP).
  - Exclusión de validación CSRF en `app/Http/Middleware/VerifyCsrfToken.php` para evitar errores 419 en sesiones públicas anónimas.
* **Pruebas Automatizadas (`tests/Feature/ChatbotEndpointTest.php`):**
  - 4 tests con 12 aserciones pasando al 100%: validación de campos obligatorios, fallback cuando no hay API Key, respuesta exitosa mockeada y manejo elegante de caídas del proveedor.

### 1.2 Prompt de Sistema y Reglas Pedagógicas
El prompt del sistema (`buildSystemPrompt`) fue calibrado con instrucciones específicas:
* **Identidad:** *"Eres Movi, el asistente virtual oficial de MOVA (una plataforma EdTech peruana con enfoque social). Tu único objetivo es guiar a padres, alumnos y profesores sobre cómo usar la plataforma. Eres amable, paciente y usas un lenguaje muy sencillo, sin tecnicismos."*
* **Regla estricta contra resolución de tareas:** *"Nunca resuelvas problemas de matemáticas o escolares; si te piden ayuda con una tarea, explícales que nuestros profesores están listos para ayudarles y dales el enlace para solicitar una clase."*
* **Límite de longitud:** Máximo 2 párrafos cortos y concisos.
* **Enlaces interactivos con corchetes:**
  - Registro: `[Crear cuenta]`
  - Pedir profesor: `[Solicitar Clase]`
  - Postular como practicante/docente: `[Voluntariado]`
* **Creadores y Fundadores Oficiales:**
  - Configurado para declarar sin dudar y con orgullo a **Elias J. Paz** y **Abel Castillo** como los creadores y fundadores de MOVA.

### 1.3 Interfaz de Usuario (`resources/js/Components/ChatbotWidget.vue`)
* **Mascota reactiva:**
  - Ilustración de la ardilla graduada leyendo en estado reposo (`ardillachatbot1.png`).
  - Transición fluida a ardilla saludando con burbuja al pasar el cursor (`ardillachatbot2.png`).
* **Transformación dinámica de enlaces:**
  - Los textos entre corchetes generados por el LLM se formatean automáticamente en botones de acción tipo píldora (`<a href="...">`):
    - `[Crear cuenta]` ➔ Botón azul con redirección a `/register`.
    - `[Solicitar Clase]` ➔ Botón ámbar con redirección a `/class-requests/create`.
    - `[Voluntariado]` ➔ Botón verde con redirección a `/invitacion/profesor`.
* **Estilizado de la ventana:**
  - Borde definido en color **plomo oscuro** (`border-2 border-slate-600 dark:border-slate-600`).
  - Animación de entrada con curvatura `rounded-3xl` y desenfoque de fondo.

---

## 2. 👨‍🏫 Rediseño de la Vista "Mi perfil" del Profesor (`resources/js/Pages/Teacher/Edit.vue`)

Se rediseñó por completo la página para ajustarse fielmente a la interfaz propuesta, optimizando la distribución para que quepa en una sola pantalla sin necesidad de scroll.

### 2.1 Estructura en 5 Tarjetas Modulares
1. **Perfil público:**
   - Círculo de avatar en azul profundo con inicial en blanco y botón flotante de cámara.
   - Botón *"Elegir imagen"* con validación de formatos (JPG, PNG, WEBP máx 4MB) y subida instantánea mediante `profile.avatar`.
   - Manejo de fallback con `@error` para evitar iconos de imágenes rotas.
   - Módulo destacado a la derecha con el **código de profesor** (`JKVSST`), copiado interactivo en un clic al portapapeles y texto de recomendación para compartirlo con los alumnos.
2. **Sobre ti:**
   - Área de texto para la Bio del docente con contador dinámico de caracteres (`/500`) alineado a la esquina inferior derecha.
3. **Tarifa automática:**
   - Presentación de tarifa por hora (`S/ 20.00`) junto a su badge de nivel correspondiente (`Base`, `Experto`, `Élite`).
4. **Métodos de pago:**
   - Inputs independientes para **Número Yape** y **Número Plin**.
   - Integración visual de los distintivos circulares oficiales de Yape (púrpura) y Plin (turquesa) dentro de los campos.
5. **Materias (ancho completo):**
   - Etiquetas tipo píldora interactivas con el símbolo `✕`.
   - Diferenciación visual: materias seleccionadas en azul claro (`bg-blue-100 text-blue-700`) y disponibles en gris suave.
   - Buscador/input para agregar materias personalizadas nuevas con autocompletado datalist.

### 2.2 Optimización de Espacios y Pantalla Única
* Se activó la propiedad `compact` en `AppLayout` para reducir el padding global vertical.
* Se ajustaron los gaps de `gap-6` a `gap-3.5` y los paddings de tarjetas a `p-4 sm:p-5`.
* El botón **"Guardar cambios"** (azul con ícono de disquete) queda perfectamente visible en el viewport sin necesidad de desplazarse.

---

## 3. 🎯 Botón CTA "Solicitar una clase" en Dashboard de Padres (`resources/js/Pages/Dashboard/Parent.vue`)

Se transformó el botón principal de la sección *Acciones rápidas* para maximizar la tasa de conversión (click-through rate):

* **Color:** Verde esmeralda vibrante (`from-emerald-500 via-green-500 to-emerald-600`).
* **Animación en Reposo (Idle):**
  - Capa de resplandor ambiental inferior (`cta-glow-ambient`) que respira suavemente en un ciclo sinusoidal de 3.5 segundos sin alterar el botón físico.
  - Haz de luz diagonal continuo (`cta-shimmer-sweep`) que recorre el botón cada 4.2 segundos.
* **Transición en Hover Ultra-Fluida:**
  - Se eliminó el reseteo brusco (`animation: none;`) que causaba tirones visuales.
  - Elevación con curva de desaceleración suave (`transition: transform 0.55s cubic-bezier(0.16, 1, 0.3, 1)`).
  - Cambio de color implementado con fundido de opacidad (`transition-opacity duration-700 ease-out`), garantizando 60fps sin saltos de gradiente.
  - Micro-animación sincronizada en el ícono de clase (ligera rotación) y avance de la flecha lateral.

---

## 4. 🚀 Control de Versiones y Despliegue

* **Rama de trabajo:** `Elias-rama` sincronizada con `origin/Elias-rama`.
* **Hash del commit:** `131b5ed`
* **Compilación de frontend:** Vite completó el bundle sin advertencias ni errores (`npm run build`).
* **Seguridad:** No se expusieron claves API ni variables de entorno; el archivo `.env` se mantiene protegido en `.gitignore`.
* **Pruebas del sistema:** Más de 150 pruebas unitarias y de integración pasando con éxito en la suite de Laravel.
