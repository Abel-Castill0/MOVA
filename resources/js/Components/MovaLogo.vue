<template>
  <!-- inheritAttrs:false + v-bind="$attrs" en el <img> para que la clase de
       tamaño que pasa el padre (ej. class="h-8 w-auto") aterrice en la imagen
       y no en el <picture>, que no tiene caja propia. -->
  <picture>
    <source :srcset="src + '.webp'" type="image/webp">
    <img
      v-bind="$attrs"
      :src="src + '.png'"
      :alt="alt"
      :width="dims.w"
      :height="dims.h"
      decoding="async"
    >
  </picture>
</template>

<script setup>
import { computed } from 'vue'

defineOptions({ inheritAttrs: false })

const props = defineProps({
  // imagotipo = isotipo + palabra "Mova" (horizontal, para navbar/footer)
  // isotipo   = solo la "M" del apretón de manos (cuadrado, para espacios reducidos)
  variant: {
    type: String,
    default: 'imagotipo',
    validator: (v) => ['imagotipo', 'isotipo'].includes(v),
  },
  // 'color' para fondos claros · 'blanco' para fondos oscuros (brand-800/900)
  theme: {
    type: String,
    default: 'color',
    validator: (v) => ['color', 'blanco'].includes(v),
  },
})

const src = computed(() =>
  `/images/brand/mova-${props.variant}` + (props.theme === 'blanco' ? '-blanco' : '')
)

// width/height reales del archivo — reservan la caja y evitan layout shift (CLS)
// mientras carga. El alto/ancho visual lo sigue mandando la clase del padre.
const dims = computed(() =>
  props.variant === 'isotipo' ? { w: 512, h: 561 } : { w: 720, h: 231 }
)

const alt = computed(() =>
  props.variant === 'isotipo' ? 'MOVA' : 'MOVA — clases particulares online'
)
</script>
