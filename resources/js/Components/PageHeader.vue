<template>
  <header class="flex flex-col gap-4 border-b border-slate-200/80 pb-5 sm:flex-row sm:items-end sm:justify-between">
    <div class="min-w-0 space-y-1">
      <p v-if="eyebrow" class="text-xs font-bold uppercase tracking-wider text-brand-700">{{ eyebrow }}</p>
      <h1 class="text-2xl font-bold leading-tight tracking-tight text-slate-900 sm:text-[28px]">{{ title }}</h1>
      <p v-if="description" class="max-w-2xl text-sm leading-relaxed text-slate-600">{{ description }}</p>
    </div>
    <div v-if="$slots.actions" class="flex shrink-0 flex-wrap items-center gap-2">
      <slot name="actions" />
    </div>
  </header>
</template>

<script setup>
defineProps({
  title: { type: String, required: true },
  description: { type: String, default: '' },
  eyebrow: { type: String, default: '' },
})
</script>
