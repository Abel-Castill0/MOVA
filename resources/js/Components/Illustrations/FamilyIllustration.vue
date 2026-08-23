<!--
  Variante "padre/alumno" de TeacherIllustration.vue — mismo motivo de
  videollamada, pero con avatar de alumno + chip de calificación/reporte en
  vez de materia/verificación, para que el panel refuerce lo que un padre
  valora (seguimiento, progreso), no lo que valora un profesor.
-->
<template>
  <svg viewBox="0 0 400 320" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-full h-auto">
    <circle cx="340" cy="250" r="90" fill="#F59E0B" opacity="0.12" />
    <circle cx="60" cy="60" r="70" fill="#FFFFFF" opacity="0.08" />

    <rect x="60" y="40" width="280" height="200" rx="20" fill="#FFFFFF" fill-opacity="0.08" stroke="#FFFFFF" stroke-opacity="0.25" stroke-width="1.5" />
    <rect x="60" y="40" width="280" height="34" rx="20" fill="#FFFFFF" fill-opacity="0.10" />
    <circle cx="78" cy="57" r="4" fill="#F59E0B" />
    <circle cx="92" cy="57" r="4" fill="#FFFFFF" fill-opacity="0.4" />
    <circle cx="106" cy="57" r="4" fill="#FFFFFF" fill-opacity="0.4" />

    <!-- Dos avatares (alumno + profesor en la clase) -->
    <circle cx="165" cy="150" r="40" fill="#FFFFFF" fill-opacity="0.14" />
    <circle cx="165" cy="134" r="15" fill="#FFFFFF" fill-opacity="0.55" />
    <path d="M132 184c0-20 15-33 33-33s33 13 33 33" fill="#FFFFFF" fill-opacity="0.55" />

    <circle cx="248" cy="150" r="34" fill="#FFFFFF" fill-opacity="0.10" />
    <circle cx="248" cy="136" r="12" fill="#FFFFFF" fill-opacity="0.4" />
    <path d="M221 178c0-16 12-27 27-27s27 11 27 27" fill="#FFFFFF" fill-opacity="0.4" />

    <!-- Chip de calificación -->
    <g transform="translate(90, 250)">
      <rect width="140" height="34" rx="17" fill="#FFFFFF" />
      <text x="16" y="22" font-size="14" fill="#F59E0B">★★★★★</text>
      <text x="98" y="22" font-family="Plus Jakarta Sans, sans-serif" font-size="12" font-weight="700" fill="#0D409A">5.0</text>
    </g>

    <!-- Chip de reporte pedagógico -->
    <g transform="translate(250, 20)">
      <rect width="110" height="30" rx="15" fill="#F59E0B" />
      <text x="14" y="20" font-family="Plus Jakarta Sans, sans-serif" font-size="12" font-weight="700" fill="#092550">📋 Reporte</text>
    </g>
  </svg>
</template>
