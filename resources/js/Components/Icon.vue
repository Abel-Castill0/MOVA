<script setup>
import { computed } from 'vue'
import { iconFor } from '@/utils/icons'

/**
 * Envoltorio único para todo icono de Lucide en MOVA. Distingue
 * explícitamente los dos casos de accesibilidad en vez de ponerle
 * aria-hidden a todo por reflejo:
 *
 * - Decorativo (el texto adyacente ya dice lo mismo, ej. un icono junto a
 *   "Dashboard" en el nav): no pasar `label` → aria-hidden="true".
 * - Con significado propio (icono-solo-botón, un estado sin texto visible):
 *   pasar `label` → aria-hidden desaparece, aria-label queda, y el icono
 *   pasa a ser el nombre accesible real del control.
 */
const props = defineProps({
  name: { type: String, required: true },
  size: { type: [Number, String], default: 20 },
  strokeWidth: { type: [Number, String], default: 2 },
  label: { type: String, default: null },
})

const component = computed(() => iconFor(props.name))
</script>

<template>
  <component
    :is="component"
    :size="size"
    :stroke-width="strokeWidth"
    :aria-hidden="label ? undefined : 'true'"
    :aria-label="label ?? undefined"
  />
</template>
