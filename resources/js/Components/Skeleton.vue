<script setup>
/**
 * Bloque de carga con forma — reemplaza los spinners genéricos que hoy son
 * casi la única señal de carga en toda la app (1 archivo con animate-pulse
 * en todo el producto, hallazgo de la auditoría de diseño). Un skeleton que
 * anticipa la forma real del contenido reduce la sensación de espera mejor
 * que un spinner centrado — el usuario ya ve dónde va a aparecer cada cosa.
 *
 * variant="text" para líneas de texto (con altura/ancho propios via class),
 * variant="block" para tarjetas/imágenes/avatares — misma superficie
 * (bg-line, visible sobre bg-surface/bg-canvas en ambos temas) y misma
 * animación (animate-pulse de Tailwind, ya respeta prefers-reduced-motion
 * vía la regla global en app.css).
 */
defineProps({
  variant: { type: String, default: 'block' }, // 'block' | 'text'
})
</script>

<template>
  <div
    :class="[
      'animate-pulse bg-line',
      variant === 'text' ? 'rounded-chip h-4 w-full' : 'rounded-control h-full w-full',
    ]"
    aria-hidden="true"
  />
</template>
