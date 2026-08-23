<!--
  Ilustración del panel de marca en registro/login cuando el rol es
  "profesor" — SVG propio en vez de foto de stock: sin costo de licencia,
  sin riesgo de derechos de imagen, y consistente con el resto de la app
  (MovaLogo.vue también es SVG inline). Motivo: una videollamada — es
  literalmente el producto (clase 1 a 1 por Jitsi), no una escena genérica
  de "profesor sonriendo a cámara" que no dice nada específico de MOVA.
-->
<template>
  <svg viewBox="0 0 400 320" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-full h-auto">
    <!-- Blobs decorativos, muy sutiles -->
    <circle cx="60" cy="250" r="90" fill="#F59E0B" opacity="0.12" />
    <circle cx="340" cy="60" r="70" fill="#FFFFFF" opacity="0.08" />

    <!-- Marco de videollamada -->
    <rect x="60" y="40" width="280" height="200" rx="20" fill="#FFFFFF" fill-opacity="0.08" stroke="#FFFFFF" stroke-opacity="0.25" stroke-width="1.5" />
    <rect x="60" y="40" width="280" height="34" rx="20" fill="#FFFFFF" fill-opacity="0.10" />
    <circle cx="78" cy="57" r="4" fill="#F59E0B" />
    <circle cx="92" cy="57" r="4" fill="#FFFFFF" fill-opacity="0.4" />
    <circle cx="106" cy="57" r="4" fill="#FFFFFF" fill-opacity="0.4" />

    <!-- Avatar del profesor (abstracto) -->
    <circle cx="200" cy="150" r="46" fill="#FFFFFF" fill-opacity="0.14" />
    <circle cx="200" cy="132" r="18" fill="#FFFFFF" fill-opacity="0.55" />
    <path d="M160 190c0-24 18-40 40-40s40 16 40 40" fill="#FFFFFF" fill-opacity="0.55" />

    <!-- Ícono de pizarra/materia flotando a la izquierda del marco -->
    <rect x="66" y="150" width="10" height="10" rx="2" fill="#FFFFFF" fill-opacity="0.3" />

    <!-- Chip "Verificado por MOVA" -->
    <!-- width=190 (no 150): a 150px de ancho el texto (13px/700, ~135px)
         se salía del pill blanco y sus últimas letras quedaban sobre el
         fondo oscuro con #0D409A — casi invisibles por falta de contraste.
         Medido con getBBox() en navegador: texto ocupa hasta x≈174 partiendo
         del chip en x=0, así que 190 deja margen real a la derecha. -->
    <g transform="translate(90, 250)">
      <rect width="190" height="34" rx="17" fill="#FFFFFF" />
      <circle cx="22" cy="17" r="9" fill="#22C55E" />
      <path d="M18 17l3 3 6-6" stroke="white" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" fill="none" />
      <text x="40" y="22" font-family="Plus Jakarta Sans, sans-serif" font-size="13" font-weight="700" fill="#0D409A">Verificado por MOVA</text>
    </g>

    <!-- Chip de materia -->
    <g transform="translate(250, 20)">
      <rect width="110" height="30" rx="15" fill="#F59E0B" />
      <text x="16" y="20" font-family="Plus Jakarta Sans, sans-serif" font-size="12" font-weight="700" fill="#092550">Matemáticas</text>
    </g>
  </svg>
</template>
