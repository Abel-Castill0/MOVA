<script setup>
import { computed } from 'vue'

/**
 * Movi, la ardilla de MOVA. Una sola fuente para todas sus poses (WebP optimizados en public/images/mascot).
 *
 * - `lee`      → leyendo su libro (estado de espera / ayuda).
 * - `saluda`   → saludando con la burbuja (bienvenida, chat).
 * - `feliz`    → graduada contenta (éxito, estado vacío positivo).
 * - `solicita` → buscando clase con lupa y lista de materias (paso 1 "Solicita tu clase"). Soporta `animated`.
 * - `experto`  → profesor experto verificado con calendario confirmado (paso 2 "Un experto acepta"). Soporta `animated`.
 * - `celebra`  → graduada celebrando con la laptop (logros, hitos, paso 3 "Aprende en vivo"). Con `animated` reproduce la secuencia; con
 *                prefers-reduced-motion reducido o sin `animated` muestra la pose final estática.
 *
 * Es decorativa por defecto (alt vacío): el texto cercano ya dice lo importante. Pasa `alt` solo si la imagen
 * aporta información que no está en el texto.
 */
const props = defineProps({
  pose: { type: String, default: 'saluda', validator: (v) => ['lee', 'saluda', 'feliz', 'solicita', 'experto', 'celebra'].includes(v) },
  size: { type: Number, default: 96 },
  animated: { type: Boolean, default: false },
  alt: { type: String, default: '' },
})

const ASSETS = {
  lee: { small: 'movi-lee-192', large: 'movi-lee-512', w: 512, h: 489 },
  saluda: { small: 'movi-saluda-192', large: 'movi-saluda-512', w: 512, h: 489 },
  feliz: { small: 'movi-feliz-420', large: 'movi-feliz-420', w: 420, h: 338 },
  solicita: { small: 'movi-solicita-200', large: 'movi-solicita-420', anim: 'movi-solicita-anim', w: 420, h: 338 },
  experto: { small: 'movi-experto-200', large: 'movi-experto-420', anim: 'movi-experto-anim', w: 420, h: 338 },
  celebra: { small: 'movi-celebra-200', large: 'movi-celebra-420', anim: 'movi-celebra-anim', w: 420, h: 338 },
}

const asset = computed(() => ASSETS[props.pose])
const staticSrc = computed(() => `/images/mascot/${props.size > 200 || props.pose === 'feliz' ? asset.value.large : asset.value.small}.webp`)
const animSrc = computed(() => (props.animated && asset.value?.anim ? `/images/mascot/${asset.value.anim}.webp` : null))
const height = computed(() => Math.round((props.size * asset.value.h) / asset.value.w))
</script>

<template>
  <picture class="inline-block shrink-0 select-none" :style="{ width: size + 'px', height: height + 'px' }">
    <source v-if="animSrc" media="(prefers-reduced-motion: reduce)" :srcset="staticSrc" type="image/webp" />
    <img
      :src="animSrc || staticSrc"
      :alt="alt"
      :width="size"
      :height="height"
      decoding="async"
      loading="lazy"
      draggable="false"
      class="block h-full w-full object-contain pointer-events-none"
    />
  </picture>
</template>
