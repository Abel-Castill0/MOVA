# Rediseño de «Quiénes somos»: scrollytelling MOVA

Fecha: 2026-10-22.

## Alcance

Se transformó la página estática `/quienes-somos` en una experiencia de
scrollytelling con la identidad visual de MOVA. La implementación está contenida
en `resources/js/Pages/About.vue`, un componente Vue 3 con `<script setup>`,
Inertia y estilos encapsulados.

La integración incluye únicamente ese componente y este documento. No modifica
backend, rutas, controladores, modelos, migraciones, base de datos, configuración,
dependencias, navbar ni footer compartidos. GSAP y los iconos utilizados ya están
declarados en el proyecto.

## Comportamiento implementado

- **Hero:** fondo azul profundo con orbes radiales, partículas y órbitas;
  parallax ligado al scroll; badge con entrada elástica y brillo; título revelado
  palabra por palabra, con máscaras y una capa desenfocada estática que pierde
  opacidad; «misión clara» con gradiente cian y luminosidad variable.
- **Transición del hero:** la silueta SVG de la onda se aplana mediante `scaleY`.
  No se recalcula su geometría en cada cuadro.
- **Misión, visión y valores:** entradas escalonadas en perspectiva 3D, circuito
  SVG que se expande con el scroll, iconos revelados mediante una cortina
  transformada, flotación sutil, inclinación con el cursor y luz localizada.
- **Fundadores:** entradas desde lados opuestos, avatares AC y EP con volumen,
  anillos y distintivos flotantes, y un puente luminoso central en escritorio.
  En móvil las tarjetas se apilan y se conectan verticalmente.
- **Nuestra historia:** lectura por palabras, desde una base al 25 % de opacidad
  hasta blanco, con «romper esa barrera» y «profesor perfecto» en cian. La
  iluminación avanza y retrocede con el scroll. El fondo combina puntos, una luz
  radial desplazable y órbitas.
- **Invitación final:** entrada mediante escala y desplazamiento; botones
  magnéticos con cursor preciso; destello diagonal en «Crear cuenta gratis» y
  respuesta de borde en «Explorar profesores».
- **Progreso:** línea luminosa fija debajo del navbar, calculada respecto al
  recorrido completo de la página, incluido el footer.

Se conservaron los textos originales, nombres, cargos, descripciones y enlaces.
También se mantiene el comportamiento existente de las cifras: solo aparecen
cuando hay al menos diez clases y cinco profesores verificados, usando las
propiedades recibidas de Inertia.

## Accesibilidad, adaptación y rendimiento

- Se conserva una única copia accesible de cada párrafo de la historia; las
  capas visuales están ocultas a lectores de pantalla. El título tiene una
  etiqueta accesible completa y los adornos se marcan como decorativos.
- `prefers-reduced-motion` desactiva las secuencias decorativas y mantiene el
  contenido visible. Los enlaces incluyen foco visible.
- La historia usa posición sticky en escritorios con altura suficiente. En
  móviles y ventanas de poca altura conserva el flujo normal para facilitar
  la lectura de todo el texto.
- La inclinación y el magnetismo se habilitan únicamente con hover y puntero
  preciso. Los límites de los elementos se leen al entrar con el puntero,
  evitando medir el diseño en cada movimiento.
- Las animaciones usan transformaciones y opacidad. El desenfoque y las
  sombras se mantienen estáticos; no se animan filtros, dimensiones ni la
  geometría de los trazados SVG.
- `IntersectionObserver` pausa los bucles decorativos fuera de pantalla.
  Al desmontar la página se eliminan observadores, listeners, tweens y
  ScrollTriggers mediante la limpieza del componente y los contextos de GSAP.

## Validación realizada

- Compilación del SFC y build de Vite correctos.
- Comparación de 22 fragmentos de contenido con la versión anterior, sin
  cambios de texto.
- Comprobación de que los keyframes CSS solo contienen `transform` y `opacity`.
- Revisión visual en el navegador local de Windows con anchos de contenido de
  1425 px y 304 px.
- Scroll de ida y vuelta en la historia: 79 palabras en total; se observaron
  4, 43 y 79 palabras iluminadas al avanzar, y 26 después de retroceder.
- Navegación desde «Crear cuenta gratis» a `/register` y retorno correctos;
  destino `/marketplace` conservado en «Explorar profesores».
- Página local con respuesta HTTP 200 y sin errores ni avisos de consola en
  las comprobaciones del rediseño.
- `git diff --check` correcto.

La compilación final se realizó en una copia temporal del frontend con sus
dependencias bloqueadas, porque las dependencias de la carpeta compartida
estaban cambiando durante el trabajo. El componente compilado coincide con el
archivo entregado.

No se ejecutaron pruebas de backend ni operaciones financieras: este cambio no
afecta esos flujos. No se ejecutaron migraciones ni seeders, ni se consultaron o
publicaron secretos.

## Visualización local y límites de la verificación

La página se comprobó en `http://localhost:8080/quienes-somos`, servida por el
contenedor existente `mova-web`. Como ese contenedor no comparte el código
fuente mediante un bind mount, se copiaron los assets compilados de frontend a
`/app/public/build/`. La página temporal usada para inspeccionar el escritorio
se eliminó al terminar.

Para futuras comprobaciones, después de compilar el frontend se pueden copiar
sus assets al contenedor local con `docker cp public/build/. mova-web:/app/public/build/`.
Esta copia local no sustituye el proceso habitual de construcción de la imagen.

Las capturas y el informe de verificación quedaron como artefactos locales en
`qa/reports/about-motion/`, ignorados por Git y excluidos de esta integración.

La revisión visual se limita al navegador local: no se probaron dispositivos
físicos iOS/Android ni Safari. La alternativa de movimiento reducido se revisó
en el código; no se emuló esa preferencia en el navegador. No se realizó un
despliegue a Azure como parte de este rediseño ni de su integración en Git.
