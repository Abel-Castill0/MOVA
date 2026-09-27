<template>
  <AppLayout title="Mis hijos">
    <div class="space-y-6">
      <div class="flex items-center justify-between">
        <h2 class="text-2xl font-black text-slate-900">Mis hijos</h2>
        <Link :href="route('students.create')"
          class="px-5 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-2xl hover:bg-brand-700 active:scale-95 transition-all shadow-md shadow-brand-600/25">
          + Añadir hijo
        </Link>
      </div>

      <div v-if="!students.length" class="text-center py-16 bg-white rounded-3xl border border-gray-100 shadow-sm">
        <div class="mb-3 flex justify-center text-slate-300">
          <Icon name="my-students" :size="48" :stroke-width="1.5" />
        </div>
        <p class="text-slate-500 mb-4 font-medium">Aún no has añadido ningún hijo</p>
        <Link :href="route('students.create')" class="px-5 py-2 bg-brand-600 text-white text-sm font-bold rounded-2xl hover:bg-brand-700 transition-colors inline-block">Añadir ahora</Link>
      </div>

      <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="s in students" :key="s.id"
          class="bg-white rounded-3xl border border-gray-100 p-5 sm:p-6 hover:border-brand-300 hover:shadow-md transition-all">
          <div class="flex items-start justify-between">
            <div>
              <p class="font-semibold text-slate-900">{{ s.full_name }}</p>
              <p class="text-sm text-slate-500 capitalize">{{ s.grade_level }}</p>
              <p v-if="s.school" class="text-xs text-slate-400 mt-1">{{ s.school }}</p>
            </div>
            <div class="flex gap-1">
              <Link :href="route('students.edit', s.id)"
                class="text-xs text-brand-600 hover:text-brand-800 hover:bg-brand-50 px-2.5 py-1.5 rounded-lg transition-colors font-medium">
                Editar
              </Link>
              <button type="button"
                class="text-xs text-red-500 hover:text-red-700 hover:bg-red-50 px-2.5 py-1.5 rounded-lg transition-colors font-medium"
                @click="deleteTarget = s">Eliminar</button>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!--
      F-13: era un window.confirm() con el texto "¿Eliminar este estudiante?".
      Se trata de datos de un menor, así que la confirmación debe nombrar al
      alumno y decir qué se pierde, no preguntar en abstracto. Además el
      confirm() nativo no permite bloquear el doble envío.
    -->
    <Modal :show="!!deleteTarget" max-width="md" @close="closeDelete">
      <div v-if="deleteTarget" class="p-6">
        <h3 class="text-lg font-black text-slate-900">Eliminar a {{ deleteTarget.full_name }}</h3>
        <p class="mt-2 text-sm text-slate-600">
          Se eliminará su ficha y dejará de aparecer en tus solicitudes. Esta acción no se puede deshacer.
        </p>
        <p class="mt-3 rounded-xl bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">
          El historial de clases ya realizadas se conserva por motivos contables.
        </p>

        <p v-if="deleteError" class="mt-3 text-sm font-semibold text-red-600">{{ deleteError }}</p>

        <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
          <SecondaryButton type="button" :disabled="deleting" @click="closeDelete">Cancelar</SecondaryButton>
          <DangerButton type="button" :loading="deleting" @click="submitDelete">
            {{ deleting ? 'Eliminando…' : 'Eliminar' }}
          </DangerButton>
        </div>
      </div>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import Icon from '@/Components/Icon.vue'

defineProps({ students: Array })

const deleteTarget = ref(null)
const deleting = ref(false)
const deleteError = ref('')

function closeDelete() {
  if (deleting.value) return
  deleteTarget.value = null
  deleteError.value = ''
}

function submitDelete() {
  if (deleting.value) return
  deleting.value = true
  router.delete(route('students.destroy', deleteTarget.value.id), {
    preserveScroll: true,
    onSuccess: () => { deleteTarget.value = null },
    // F-19: sin esto, un fallo al eliminar dejaba el modal abierto sin explicar nada.
    onError: () => { deleteError.value = 'No se pudo eliminar. Inténtalo de nuevo o contacta a soporte.' },
    onFinish: () => { deleting.value = false },
  })
}
</script>
