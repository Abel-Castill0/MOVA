<script setup>
import { ref, computed } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'

const user = computed(() => usePage().props.auth.user)
const fileInput = ref(null)
const preview = ref(null)

const form = useForm({ avatar: null })

function pickFile() {
  fileInput.value?.click()
}

function onFileChange(e) {
  const file = e.target.files?.[0]
  if (!file) return

  form.avatar = file
  form.clearErrors('avatar')

  const reader = new FileReader()
  reader.onload = () => { preview.value = reader.result }
  reader.readAsDataURL(file)
}

function submit() {
  form.post(route('profile.avatar'), {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
      form.reset()
      preview.value = null
      if (fileInput.value) fileInput.value.value = ''
    },
  })
}

// No borra el archivo en Cloudinary (ver ProfileController::removeAvatar) —
// solo desvincula avatar_url, el usuario vuelve a ver sus iniciales.
const removing = ref(false)
function removeAvatar() {
  removing.value = true
  router.delete(route('profile.avatar.remove'), {
    preserveScroll: true,
    onFinish: () => { removing.value = false },
  })
}
</script>

<template>
  <section>
    <header>
      <h2 class="text-lg font-bold text-slate-900">Foto de perfil</h2>
      <p class="mt-1 text-sm text-slate-500">Se muestra en tu perfil público y a las familias/profesores con los que interactúas.</p>
    </header>

    <form @submit.prevent="submit" class="mt-5 flex flex-col sm:flex-row sm:items-center gap-5">
      <div class="relative w-20 h-20 flex-shrink-0">
        <img v-if="preview || user.avatar_url" :src="preview || user.avatar_url" alt="Foto de perfil"
          class="w-20 h-20 rounded-full object-cover border border-gray-100 shadow-sm" />
        <div v-else class="w-20 h-20 rounded-full bg-gradient-to-br from-brand-500 to-brand-700 flex items-center justify-center text-white font-black text-2xl shadow-sm">
          {{ user.name?.charAt(0) }}
        </div>
      </div>

      <div class="flex-1">
        <input ref="fileInput" type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="onFileChange" />
        <div class="flex flex-wrap items-center gap-3">
          <button type="button" @click="pickFile"
            class="px-4 py-2 bg-white border border-gray-200 text-sm font-semibold text-slate-700 rounded-xl hover:border-brand-300 hover:shadow-sm transition-all">
            Elegir imagen
          </button>
          <button type="submit" v-if="form.avatar" :disabled="form.processing"
            class="px-4 py-2 bg-brand-600 text-white text-sm font-bold rounded-xl hover:bg-brand-700 active:scale-95 disabled:opacity-50 transition-all shadow-sm shadow-brand-600/20">
            {{ form.processing ? 'Subiendo...' : 'Guardar foto' }}
          </button>
          <button type="button" v-if="!form.avatar && user.avatar_url" @click="removeAvatar" :disabled="removing"
            class="px-4 py-2 text-sm font-semibold text-red-600 rounded-xl hover:bg-red-50 active:bg-red-100 disabled:opacity-50 transition-all">
            {{ removing ? 'Quitando...' : 'Quitar foto' }}
          </button>
          <p v-if="form.recentlySuccessful" class="text-sm text-green-600 font-medium">Guardada ✓</p>
        </div>
        <p class="mt-2 text-xs text-slate-400">JPG, PNG o WEBP. Máximo 4 MB.</p>
        <p v-if="form.errors.avatar" class="mt-1 text-xs text-red-500">{{ form.errors.avatar }}</p>
      </div>
    </form>
  </section>
</template>
