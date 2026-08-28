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
          <div class="min-w-0 flex items-start gap-2">
            <Icon name="join-room" :size="18" class="text-white/70 flex-shrink-0 mt-0.5" />
            <div class="min-w-0">
              <p class="text-white font-semibold text-sm sm:text-base truncate">
                {{ lesson?.class_request?.subject?.name ?? 'Sala Virtual' }}
              </p>
            <!-- Solo aparece para el padre (su `lesson` trae teacher_profile
                 cargado); el profesor ya conoce su propio código, así que su
                 objeto `lesson` no lo incluye y esta línea no se renderiza
                 para él. Es el único lugar de la app donde el alumno puede
                 anotar el código durante la clase para repetir con este
                 profesor más adelante. -->
              <p v-if="lesson?.teacher_profile?.referral_code" class="text-xs text-white/60 mt-0.5">
                Código del profesor: <span class="font-mono tracking-widest text-white/90">{{ lesson.teacher_profile.referral_code }}</span>
                — pídelo para tu próxima solicitud
              </p>
            </div>
          </div>
          <button @click="$emit('close')" type="button"
            class="flex-shrink-0 min-h-[44px] px-4 py-2.5 bg-white/10 hover:bg-white/20 active:scale-[0.97] text-white text-xs sm:text-sm font-semibold rounded-control transition-[transform,background-color] duration-micro ease-out-expo focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-950">
            Cerrar sala y volver a MOVA
          </button>
        </div>

        <div class="flex-1 relative overflow-hidden">
          <div v-if="error" class="absolute inset-0 flex items-center justify-center">
            <div class="p-8 text-center max-w-sm">
              <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-white/10 flex items-center justify-center">
                <Icon name="warning" :size="24" class="text-white/80" />
              </div>
              <p class="text-white font-semibold">{{ error }}</p>
            </div>
          </div>

          <template v-else>
            <div id="jitsi-container" class="w-full h-[85vh] sm:h-[90vh]" allow="camera; microphone; fullscreen; display-capture"></div>

            <!-- Estado explícito entre "la modal se abrió" y "el contenido
                 apareció" — antes era pantalla negra sin ningún indicio.
                 Se superpone sobre #jitsi-container (que debe seguir
                 existiendo en el DOM para que openJitsi() lo encuentre) en
                 vez de ocultarlo con v-if.

                 pointer-events-none es deliberado, no cosmético: si la
                 pestaña se pone en segundo plano justo durante esta
                 transición (el usuario cambia de app mientras "Conectando…"
                 está en pantalla — un escenario real, no hipotético), el
                 navegador puede retrasar el evento transitionend del que
                 <Transition> depende para desmontar este overlay. Sin
                 pointer-events-none, un overlay que quedó en opacity:0 pero
                 técnicamente aún montado bloquearía todos los clics sobre el
                 iframe de Jitsi debajo — invisible pero funcionalmente
                 inutilizable. Con pointer-events-none, incluso en ese caso
                 límite, la llamada real sigue siendo interactuable. -->
            <div v-if="connecting" class="absolute inset-0 flex items-center justify-center bg-slate-950 pointer-events-none">
              <div class="text-center">
                <Icon name="connecting" :size="28" class="text-white/60 mx-auto animate-spin" />
                <p class="text-white/60 text-sm mt-3">Conectando a la sala…</p>
              </div>
            </div>
          </template>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import Icon from '@/Components/Icon.vue'

defineProps({
  show: { type: Boolean, default: false },
  lesson: { type: Object, default: null },
  error: { type: String, default: '' },
  connecting: { type: Boolean, default: false },
})
defineEmits(['close'])
</script>
