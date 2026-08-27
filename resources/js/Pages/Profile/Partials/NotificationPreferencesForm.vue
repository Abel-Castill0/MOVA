<template>
  <section>
    <header>
      <h2 class="text-lg font-medium text-gray-900">Notificaciones por WhatsApp</h2>

      <p class="mt-1 text-sm text-gray-600">
        Elige si quieres recibir avisos de tus clases por WhatsApp. Seguirás recibiéndolos por correo
        electrónico en cualquier caso.
      </p>
    </header>

    <div class="mt-6 rounded-xl border border-gray-200 p-4">
      <label class="flex cursor-pointer items-start gap-3">
        <input
          type="checkbox"
          class="mt-0.5 h-5 w-5 rounded border-gray-300 text-brand-600 focus:ring-brand-500 disabled:opacity-50"
          :checked="optedIn"
          :disabled="saving"
          @change="toggle($event.target.checked)"
        />
        <span class="text-sm">
          <span class="font-semibold text-gray-900">Recibir avisos por WhatsApp</span>
          <span class="mt-0.5 block text-gray-500">
            Recordatorios de clase, confirmaciones y cambios de horario.
          </span>
        </span>
      </label>

      <!--
        Distinción importante y deliberada: darse de baja NO invalida el
        teléfono ni impide verificarlo. El código de verificación es un mensaje
        de seguridad que pide el propio usuario, no una notificación.
      -->
      <p class="mt-3 border-t border-gray-100 pt-3 text-xs text-gray-500">
        Esto no afecta al código de verificación de tu teléfono: ese mensaje se envía siempre que tú lo
        solicites, porque es parte del acceso a tu cuenta.
      </p>

      <p v-if="!phoneVerified" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">
        Verifica tu teléfono para poder recibir avisos por WhatsApp.
      </p>

      <p v-if="error" class="mt-3 text-sm font-semibold text-red-600">{{ error }}</p>

      <Transition
        enter-active-class="transition ease-in-out"
        enter-from-class="opacity-0"
        leave-active-class="transition ease-in-out"
        leave-to-class="opacity-0"
      >
        <p v-if="saved" class="mt-3 text-sm text-gray-600">Guardado.</p>
      </Transition>
    </div>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'

const page = usePage()
const optedIn = computed(() => page.props.auth?.user?.whatsapp_opt_in ?? false)
const phoneVerified = computed(() => page.props.auth?.user?.phone_verified ?? false)

const saving = ref(false)
const saved = ref(false)
const error = ref('')

function toggle(value) {
  if (saving.value) return
  saving.value = true
  error.value = ''
  saved.value = false

  router.patch(route('profile.notifications.update'), { whatsapp: value }, {
    preserveScroll: true,
    onSuccess: () => {
      saved.value = true
      setTimeout(() => { saved.value = false }, 2500)
    },
    onError: () => { error.value = 'No se pudo guardar la preferencia. Inténtalo de nuevo.' },
    onFinish: () => { saving.value = false },
  })
}
</script>
