# Product

## Register

product

## Users

Padres/apoderados, profesores particulares y administradores de MOVA, una
plataforma peruana de tutorías online que conecta estudiantes menores de edad
con profesores verificados. Los padres usan la plataforma para solicitar
clases para sus hijos, aprobar/pagar, seguir el progreso vía reportes de
aprendizaje y calificar profesores — en general desde el celular, en momentos
cortos entre tareas del día a día, no sesiones largas de "trabajo".

## Product Purpose

Dar a un padre confianza total sobre la educación online de su hijo: a quién
está aprendiendo con esa persona, si el pago quedó registrado, cómo le fue en
cada clase. El profesor necesita gestionar su agenda, sus créditos y sus
solicitudes con la misma seriedad que un negocio real. Éxito = el padre nunca
tiene que preguntarse "¿esto es seguro/serio?", y el profesor nunca pierde de
vista una acción pendiente (reporte, solicitud, recarga).

## Brand Personality

Elegante, profesional, "caro" — sin ser corporativo/frío. Cálido lo justo
para tratarse de la educación de un hijo, pero con la seriedad de una
plataforma que maneja dinero y datos de menores. Tres palabras: confiable,
cuidada, cercana.

## Anti-references

- SaaS genérico tipo clon de Notion/Linear: cards idénticas repetidas sin
  jerarquía, iconos flotantes sin contexto, gradientes decorativos gratuitos.
- Panel denso tipo banca/ERP: tablas sobrecargadas, espaciado apretado, que
  transmita "trabajo" en vez de cuidado por la educación de un hijo.
- Infantil en exceso: MOVA le habla al padre, no al niño — evitar colores
  primarios muy saturados o iconografía de app infantil.

## Design Principles

1. **La confianza se ve, no se dice.** Cada pantalla debe reducir la
   incertidumbre del padre (estado de pago, calificación del profesor,
   próximo paso) sin que tenga que preguntar o navegar a buscarlo.
2. **Consistencia entre roles.** Padre, profesor y landing comparten el mismo
   lenguaje visual (paleta `brand` azul, `rounded-2xl`, `border-gray-100`,
   Inter, sombras suaves) — un cambio de rol no debe sentirse como otro
   producto.
3. **Acción contextual sobre navegación.** Cuando una clase necesita una
   acción (pagar, calificar, unirse), el botón vive junto al estado, no
   detrás de un enlace a otra página.
4. **Movimiento con propósito, nunca decorativo.** Las animaciones (GSAP
   ScrollTrigger, stagger sutil) refuerzan jerarquía de lectura; nunca
   bloquean contenido detrás de una clase disparada por JS, y siempre honran
   `prefers-reduced-motion`.
5. **Espacio en blanco generoso.** Preferir aire y jerarquía tipográfica
   sobre añadir más cards o más bordes de color.

## Accessibility & Inclusion

- `prefers-reduced-motion: reduce` es obligatorio en toda animación nueva
  (GSAP/ScrollTrigger): alternativa sin movimiento (aparición instantánea),
  nunca contenido oculto a la espera de una animación que no se disparará.
- Contraste WCAG AA como mínimo (texto de cuerpo ≥4.5:1, texto grande ≥3:1);
  nunca usar gris claro "por elegancia" si compromete legibilidad — parte de
  la base de usuarios (padres, no necesariamente tech-savvy) depende de
  poder leer estados financieros y de progreso sin esfuerzo.
