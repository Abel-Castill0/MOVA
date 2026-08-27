<script setup>
import { computed } from 'vue'

/**
 * Implementación real de PrimaryButton/SecondaryButton/DangerButton — los
 * tres hoy se contradicen entre sí (radio, padding, tipografía y color de
 * foco distintos; ninguno con estado de carga ni 44px garantizados). Este
 * componente unifica eso; los tres wrappers existentes pasan a ser capas
 * finas encima, MISMA API de cada uno (mismo nombre de archivo, mismos
 * props/slot/eventos que ya usan sus ~14 call-sites reales — inventariado
 * antes de tocar nada, no asumido).
 */
const props = defineProps({
  variant: { type: String, default: 'primary' }, // primary | secondary | danger
  size: { type: String, default: 'default' },     // default | sm
  type: { type: String, default: 'button' },
  disabled: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
})

// loading fuerza disabled aunque el caller no lo pida explícitamente — un
// botón en carga nunca debe poder volver a dispararse.
const isDisabled = computed(() => props.disabled || props.loading)

const variantClasses = computed(() => ({
  primary:
    'bg-brand-600 text-white hover:bg-brand-700 active:bg-brand-800 shadow-elevation-1',
  secondary:
    'bg-surface text-ink border border-line-strong hover:bg-canvas',
  // Rojo sólido dedicado (no el --danger-text de los tintes de estado: ese
  // token está calibrado para texto-sobre-tinte, no para fondo-sólido — se
  // mantienen separados aunque hoy ambos pasen contraste, para no acoplar
  // dos usos distintos a un mismo valor). Verificado: blanco/red-600 = 4.83:1.
  danger:
    'bg-red-600 text-white hover:bg-red-700 active:bg-red-800',
}[props.variant]))

const sizeClasses = computed(() => ({
  default: 'min-h-[44px] px-5 py-2.5 text-sm',
  sm: 'min-h-[36px] px-3.5 py-2 text-xs',
}[props.size]))
</script>

<template>
  <button
    :type="type"
    :disabled="isDisabled"
    :aria-busy="loading"
    :class="[
      'inline-flex items-center justify-center gap-2 rounded-card font-semibold',
      'transition-[transform,background-color] duration-micro ease-out-expo',
      'active:scale-[0.97]',
      'focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2',
      'disabled:opacity-50 disabled:pointer-events-none',
      variantClasses,
      sizeClasses,
    ]"
  >
    <svg
      v-if="loading"
      class="h-4 w-4 animate-spin"
      viewBox="0 0 24 24"
      fill="none"
      aria-hidden="true"
    >
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
    </svg>
    <slot />
  </button>
</template>
