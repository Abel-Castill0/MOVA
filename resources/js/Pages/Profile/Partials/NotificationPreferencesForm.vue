<template>
  <section>
    <header>
      <h2 class="text-lg font-medium text-gray-900">Notificaciones de clases por WhatsApp</h2>
    </header>

    <!-- El estado lo calcula el servidor (WhatsAppConsent::status); nunca se deduce de una casilla del navegador. -->
    <div class="mt-4 rounded-xl border p-4" :class="status === 'active' ? 'border-emerald-200 bg-emerald-50/60' : 'border-gray-200'" aria-live="polite">
      <template v-if="status === 'active'">
        <p class="font-semibold text-emerald-800">Notificaciones de clases por WhatsApp: activas.</p>
        <p class="mt-1 text-sm text-gray-600">
          MOVA te avisará cuando se confirme una clase, cambie un horario o se acerque tu próxima sesión.
        </p>
        <button
          type="button"
          class="mt-3 text-sm font-semibold text-rose-700 underline underline-offset-4 hover:text-rose-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-400 disabled:opacity-50"
          :disabled="saving"
          @click="save(false)"
        >Desactivar avisos por WhatsApp</button>
      </template>

      <template v-else-if="status === 'needs_verification'">
        <p class="font-semibold text-gray-900">Avisos por WhatsApp autorizados, falta verificar tu teléfono.</p>
        <p class="mt-1 text-sm text-gray-600">
          <template v-if="hasPhone">Confirma tu número con un código y los avisos empezarán a llegar automáticamente.</template>
          <template v-else>Añade tu número de teléfono en «Información personal» y confírmalo con un código.</template>
          Mientras tanto te avisamos por correo y dentro de MOVA.
        </p>
        <div class="mt-3 flex flex-wrap items-center gap-4">
          <Link
            v-if="hasPhone"
            :href="route('phone.verification.notice')"
            class="inline-flex min-h-10 items-center rounded-lg bg-brand-600 px-4 text-sm font-bold text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400"
          >Verificar mi teléfono</Link>
          <button type="button" class="text-sm font-semibold text-gray-600 underline underline-offset-4 disabled:opacity-50" :disabled="saving" @click="save(false)">No quiero avisos por WhatsApp</button>
        </div>
      </template>

      <template v-else>
        <p class="font-semibold text-gray-900">
          {{ status === 'revoked' ? 'Desactivaste los avisos por WhatsApp.' : 'Aún no recibes avisos por WhatsApp.' }}
        </p>
        <p class="mt-1 text-sm text-gray-600">{{ consentText }}</p>
        <button
          type="button"
          class="mt-3 inline-flex min-h-10 items-center rounded-lg bg-brand-600 px-4 text-sm font-bold text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 disabled:opacity-50"
          :disabled="saving"
          @click="save(true)"
        >Activar avisos por WhatsApp</button>
        <p v-if="!phoneVerified" class="mt-2 text-xs text-gray-500">Después confirmarás tu número con un código.</p>
      </template>

      <p class="mt-3 border-t border-gray-100 pt-3 text-xs text-gray-500">
        También puedes darte de baja escribiendo «BAJA» al WhatsApp de MOVA. El código de verificación de tu teléfono no depende de esta opción.
      </p>

      <p v-if="error" class="mt-3 text-sm font-semibold text-red-600">{{ error }}</p>
    </div>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'

const consentText = 'Quiero recibir por WhatsApp notificaciones de MOVA sobre mis reservas, confirmaciones, cambios de horario y recordatorios de clases. Puedo desactivarlas cuando quiera.'

const page = usePage()
const status = computed(() => page.props.auth?.user?.whatsapp_status ?? 'not_authorized')
const phoneVerified = computed(() => page.props.auth?.user?.phone_verified ?? false)
const hasPhone = computed(() => Boolean(page.props.auth?.user?.phone))

const saving = ref(false)
const error = ref('')

function save(value) {
  if (saving.value) return
  saving.value = true
  error.value = ''

  router.patch(route('profile.notifications.update'), { whatsapp: value }, {
    preserveScroll: true,
    onSuccess: () => {
      // Recién autorizado y sin teléfono verificado: el siguiente paso útil es el código.
      if (value && !phoneVerified.value && hasPhone.value) router.visit(route('phone.verification.notice'))
    },
    onError: () => { error.value = 'No se pudo guardar la preferencia. Inténtalo de nuevo.' },
    onFinish: () => { saving.value = false },
  })
}
</script>
