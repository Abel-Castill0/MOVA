<template>
  <Teleport to="body">
    <Transition
      enter-active-class="ease-out duration-200"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="ease-in duration-150"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div v-if="show" class="fixed inset-0 z-[9999] bg-slate-950 flex flex-col">
        <div class="flex-shrink-0 flex items-center justify-between gap-3 px-4 sm:px-6 py-3 bg-slate-900 border-b border-white/10">
          <p class="text-white font-semibold text-sm sm:text-base truncate">
            🎥 {{ lesson?.class_request?.subject?.name ?? 'Sala Virtual' }}
          </p>
          <button @click="$emit('close')" type="button"
            class="flex-shrink-0 px-4 py-2.5 bg-white/10 hover:bg-white/20 active:scale-95 text-white text-xs sm:text-sm font-semibold rounded-lg transition-all">
            Cerrar sala y volver a MOVA
          </button>
        </div>

        <div class="flex-1 flex items-center justify-center overflow-hidden">
          <div v-if="error" class="p-8 text-center max-w-sm">
            <div class="text-4xl mb-3">⚠️</div>
            <p class="text-white font-semibold">{{ error }}</p>
          </div>
          <div v-else id="jitsi-container" class="w-full h-[85vh] sm:h-[90vh]" allow="camera; microphone; fullscreen; display-capture"></div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
defineProps({
  show: { type: Boolean, default: false },
  lesson: { type: Object, default: null },
  error: { type: String, default: '' },
})
defineEmits(['close'])
</script>
