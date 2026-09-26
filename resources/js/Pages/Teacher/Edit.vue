<template>
  <AppLayout title="Mi perfil" compact>
    <div class="w-full max-w-6xl mx-auto space-y-3.5 pb-2">
      <!-- Encabezado de página -->
      <div>
        <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Mi perfil</h1>
        <p class="text-xs text-slate-500 mt-0.5">
          Edita tu información profesional. Esta información se mostrará en tu perfil público y te ayudará a conectar con más estudiantes.
        </p>
      </div>

      <form @submit.prevent="submit" class="space-y-3.5">
        <!-- Grid de 2 columnas para las 4 primeras tarjetas -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3.5">

          <!-- 1. Perfil público -->
          <div class="bg-white rounded-2xl border border-slate-100 shadow-xs p-4 sm:p-5 flex flex-col justify-between">
            <div>
              <div class="flex items-center gap-2 mb-0.5">
                <svg class="w-4 h-4 text-blue-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
                <h2 class="text-sm sm:text-base font-bold text-slate-900 tracking-tight">Perfil público</h2>
              </div>
              <p class="text-[11px] text-slate-400 ml-6">
                Esta información se muestra en tu perfil público y a las familias con las que interactúas.
              </p>
            </div>

            <div class="mt-3.5 flex flex-col sm:flex-row items-center gap-4">
              <!-- Avatar + botón de elegir foto -->
              <div class="flex items-center gap-3.5 flex-1">
                <div class="relative w-16 h-16 flex-shrink-0">
                  <img
                    v-if="(preview || user?.avatar_url) && !avatarError"
                    :src="preview || user.avatar_url"
                    alt="Foto de perfil"
                    @error="avatarError = true"
                    class="w-16 h-16 rounded-full object-cover border border-slate-100 shadow-xs"
                  />
                  <div
                    v-else
                    class="w-16 h-16 rounded-full bg-[#034ea2] flex items-center justify-center text-white text-2xl font-extrabold shadow-xs select-none"
                  >
                    {{ user?.name?.charAt(0) || 'P' }}
                  </div>

                  <!-- Botón de cámara flotante -->
                  <button
                    type="button"
                    @click="pickFile"
                    title="Subir foto de perfil"
                    class="absolute -bottom-1 -right-1 w-6 h-6 bg-white rounded-full border border-slate-200 shadow-xs flex items-center justify-center text-blue-600 hover:bg-slate-50 active:scale-95 transition"
                  >
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                  </button>
                </div>

                <div>
                  <input
                    ref="fileInput"
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    class="hidden"
                    @change="onFileChange"
                  />
                  <button
                    type="button"
                    @click="pickFile"
                    class="inline-flex items-center gap-1.5 px-3 py-1 bg-white border border-blue-200 text-blue-600 hover:bg-blue-50 text-xs font-semibold rounded-xl shadow-2xs transition active:scale-95"
                  >
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    Elegir imagen
                  </button>
                  <p class="text-[10px] text-slate-400 mt-1">JPG, PNG o WEBP. Máximo 4 MB.</p>
                  <p v-if="avatarForm.processing" class="text-[10px] text-blue-600 font-medium mt-0.5">Subiendo...</p>
                  <p v-if="avatarSaved" class="text-[10px] text-emerald-600 font-medium mt-0.5">Foto actualizada ✓</p>
                  <p v-if="avatarForm.errors.avatar" class="text-[10px] text-red-500 mt-0.5">{{ avatarForm.errors.avatar }}</p>
                </div>
              </div>

              <!-- Caja de código de profesor -->
              <div class="bg-[#f0f6ff] border border-blue-100 rounded-2xl p-3 flex-1 flex flex-col items-center justify-center text-center">
                <div class="flex items-center gap-1.5 text-xs font-bold text-slate-800">
                  <div class="w-4 h-4 rounded-md bg-blue-100 text-blue-600 inline-flex items-center justify-center text-[10px] font-black">
                    #
                  </div>
                  <span>Tu código de profesor</span>
                </div>

                <button
                  type="button"
                  @click="copyReferralCode"
                  title="Haz clic para copiar tu código"
                  class="my-1.5 w-full max-w-[180px] py-1 bg-[#e0edff] hover:bg-blue-100/80 border border-blue-200/60 rounded-xl text-center cursor-pointer transition active:scale-95 shadow-2xs"
                >
                  <span v-if="copied" class="text-xs font-bold text-emerald-600">¡Copiado! ✓</span>
                  <span v-else class="text-sm font-extrabold tracking-widest text-[#155dfc] font-mono">
                    {{ profile?.referral_code || 'JKVSST' }}
                  </span>
                </button>

                <p class="text-[10px] text-slate-500 leading-tight">
                  Compártelo con un alumno después de su primera clase para que pueda pedirte la próxima vez.
                </p>
              </div>
            </div>
          </div>

          <!-- 2. Sobre ti -->
          <div class="bg-white rounded-2xl border border-slate-100 shadow-xs p-4 sm:p-5 flex flex-col justify-between">
            <div>
              <div class="flex items-center gap-2 mb-0.5">
                <svg class="w-4 h-4 text-blue-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <h2 class="text-sm sm:text-base font-bold text-slate-900 tracking-tight">Sobre ti</h2>
              </div>
              <p class="text-[11px] text-slate-400 ml-6">
                Cuéntanos un poco sobre tu experiencia, enfoque de enseñanza y lo que te hace un gran profesor.
              </p>
            </div>

            <div class="mt-2.5">
              <label class="block text-[11px] font-semibold text-slate-700 mb-1">Bio</label>
              <textarea
                v-model="form.bio"
                rows="3"
                maxlength="500"
                placeholder="Profesor de prueba generado para pruebas E2E locales."
                class="w-full border border-slate-200 rounded-2xl p-2.5 text-xs sm:text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition resize-none"
              ></textarea>
              <div class="text-[10px] text-slate-400 text-right mt-0.5 font-medium">
                {{ form.bio?.length || 0 }}/500
              </div>
            </div>
          </div>

          <!-- 3. Tarifa automática -->
          <div class="bg-white rounded-2xl border border-slate-100 shadow-xs p-4 sm:p-5 flex flex-col justify-between">
            <div>
              <div class="flex items-center gap-2 mb-0.5">
                <svg class="w-4 h-4 text-blue-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <h2 class="text-sm sm:text-base font-bold text-slate-900 tracking-tight">Tarifa automática</h2>
              </div>
              <p class="text-[11px] text-slate-400 ml-6">
                Tu tarifa se actualiza automáticamente según tus clases completadas y calificación promedio.
              </p>
            </div>

            <div class="mt-3">
              <div class="bg-slate-50/80 border border-slate-200/80 rounded-2xl px-4 py-3 flex items-center gap-3">
                <span class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">
                  S/ {{ Number(profile?.hourly_rate ?? 20).toFixed(2) }}
                </span>
                <span :class="tier.badgeClass" class="px-2.5 py-0.5 rounded-full text-xs font-bold">
                  {{ tier.label }}
                </span>
              </div>
            </div>
          </div>

          <!-- 4. Métodos de pago -->
          <div class="bg-white rounded-2xl border border-slate-100 shadow-xs p-4 sm:p-5 flex flex-col justify-between">
            <div>
              <div class="flex items-center gap-2 mb-0.5">
                <svg class="w-4 h-4 text-blue-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                </svg>
                <h2 class="text-sm sm:text-base font-bold text-slate-900 tracking-tight">Métodos de pago</h2>
              </div>
              <p class="text-[11px] text-slate-400 ml-6">
                Los padres verán estos números para confirmar el pago offline de tus clases.
              </p>
            </div>

            <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
              <!-- Yape -->
              <div>
                <label class="block text-[11px] font-semibold text-slate-700 mb-1">Número Yape</label>
                <div class="relative flex items-center">
                  <div class="absolute left-3 flex items-center pointer-events-none select-none">
                    <div class="w-5 h-5 rounded-full bg-[#742284] flex items-center justify-center text-white text-[7.5px] font-black italic tracking-tighter shadow-2xs">
                      yape
                    </div>
                  </div>
                  <input
                    v-model="form.yape_number"
                    type="text"
                    maxlength="20"
                    placeholder="999111222"
                    class="w-full pl-10 pr-3 py-2 bg-white border border-slate-200 rounded-2xl text-xs sm:text-sm font-medium text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition"
                  />
                </div>
                <p v-if="form.errors.yape_number" class="text-[11px] text-red-500 mt-0.5">{{ form.errors.yape_number }}</p>
              </div>

              <!-- Plin -->
              <div>
                <label class="block text-[11px] font-semibold text-slate-700 mb-1">Número Plin</label>
                <div class="relative flex items-center">
                  <div class="absolute left-3 flex items-center pointer-events-none select-none">
                    <div class="w-5 h-5 rounded-full bg-[#00d0b7] flex items-center justify-center text-white text-[7.5px] font-black tracking-tighter shadow-2xs">
                      plin
                    </div>
                  </div>
                  <input
                    v-model="form.plin_number"
                    type="text"
                    maxlength="20"
                    placeholder="999111222"
                    class="w-full pl-10 pr-3 py-2 bg-white border border-slate-200 rounded-2xl text-xs sm:text-sm font-medium text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition"
                  />
                </div>
                <p v-if="form.errors.plin_number" class="text-[11px] text-red-500 mt-0.5">{{ form.errors.plin_number }}</p>
              </div>
            </div>
          </div>

        </div>

        <!-- 5. Materias (Full Width) -->
        <div class="bg-white rounded-2xl border border-slate-100 shadow-xs p-4 sm:p-5">
          <div>
            <div class="flex items-center gap-2 mb-0.5">
              <svg class="w-4 h-4 text-blue-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
              </svg>
              <h2 class="text-sm sm:text-base font-bold text-slate-900 tracking-tight">Materias</h2>
            </div>
            <p class="text-[11px] text-slate-400 ml-6">
              Selecciona las materias que enseñas. Puedes agregar nuevas materias si no están en la lista.
            </p>
          </div>

          <div class="mt-3">
            <!-- Chips de materias con botón de remoción / alternar ✕ -->
            <div class="flex flex-wrap items-center gap-2 mb-3">
              <!-- Materias existentes en la base de datos -->
              <button
                v-for="s in subjects"
                :key="s.id"
                type="button"
                @click="toggleSubject(s.id)"
                :class="[
                  'inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium transition-all select-none',
                  form.subject_ids.includes(s.id)
                    ? 'bg-blue-100 text-blue-700 border border-blue-200/80 shadow-2xs hover:bg-blue-200/70'
                    : 'bg-slate-50/70 text-slate-700 border border-slate-200/80 hover:bg-slate-100 hover:border-slate-300'
                ]"
              >
                <span>{{ s.name }}</span>
                <span class="text-[10px] opacity-60 hover:opacity-100">✕</span>
              </button>

              <!-- Materias personalizadas añadidas por el usuario -->
              <button
                v-for="name in form.subject_names"
                :key="name"
                type="button"
                @click="removeSubjectName(name)"
                class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-700 border border-blue-200/80 shadow-2xs hover:bg-blue-200/70 select-none"
              >
                <span>{{ name }}</span>
                <span class="text-[10px] opacity-60 hover:opacity-100">✕</span>
              </button>
            </div>

            <!-- Input para añadir nueva materia -->
            <div class="flex items-center gap-2.5">
              <input
                v-model="subjectDraft"
                type="text"
                list="subject-suggestions"
                placeholder="Escribe una materia nueva..."
                class="flex-1 px-3.5 py-2 bg-white border border-slate-200 rounded-2xl text-xs sm:text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition placeholder:text-slate-400"
                @keydown.enter.prevent="addSubjectName"
              />
              <datalist id="subject-suggestions">
                <option v-for="s in subjects" :key="s.id" :value="s.name" />
              </datalist>
              <button
                type="button"
                @click="addSubjectName"
                class="px-4 py-2 bg-white border border-blue-200 text-blue-600 hover:bg-blue-50 text-xs sm:text-sm font-semibold rounded-2xl transition shadow-2xs active:scale-95"
              >
                Agregar
              </button>
            </div>
          </div>
        </div>

        <!-- Botón Guardar cambios alineado a la derecha -->
        <div class="flex justify-end pt-1">
          <button
            type="submit"
            :disabled="form.processing"
            class="inline-flex items-center gap-2 px-5 py-2 bg-[#155dfc] hover:bg-blue-700 active:scale-95 text-white font-bold text-sm rounded-xl shadow-md shadow-blue-500/20 transition disabled:opacity-50"
          >
            <!-- Ícono clásico de Guardar (Disquete) -->
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/>
              <path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/>
              <path d="M7 3v4a1 1 0 0 0 1 1h7"/>
            </svg>
            <span>{{ form.processing ? 'Guardando...' : 'Guardar cambios' }}</span>
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
  profile: Object,
  subjects: Array,
})

const user = computed(() => usePage().props.auth.user)

const form = useForm({
  bio: props.profile?.bio ?? '',
  yape_number: props.profile?.yape_number ?? '',
  plin_number: props.profile?.plin_number ?? '',
  mentorship_slots_total: props.profile?.mentorship_slots_total ?? 0,
  subject_ids: props.profile?.subjects?.map(s => s.id) ?? [],
  subject_names: [],
})

// Avatar upload form
const fileInput = ref(null)
const preview = ref(null)
const avatarSaved = ref(false)
const avatarError = ref(false)

const avatarForm = useForm({
  avatar: null,
})

function pickFile() {
  fileInput.value?.click()
}

function onFileChange(e) {
  const file = e.target.files?.[0]
  if (!file) return

  avatarError.value = false
  avatarForm.avatar = file
  avatarForm.clearErrors('avatar')

  const reader = new FileReader()
  reader.onload = () => {
    preview.value = reader.result
  }
  reader.readAsDataURL(file)

  // Subir automáticamente
  avatarForm.post(route('profile.avatar'), {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
      avatarSaved.value = true
      setTimeout(() => { avatarSaved.value = false }, 3000)
    },
  })
}

// Escala de tiers
const tier = computed(() => {
  const rate = Number(props.profile?.hourly_rate ?? 20)
  if (rate >= 30) return { label: 'Élite', badgeClass: 'bg-amber-100 text-amber-700' }
  if (rate >= 25) return { label: 'Experto', badgeClass: 'bg-blue-100 text-blue-700' }
  return { label: 'Base', badgeClass: 'bg-slate-200 text-slate-700' }
})

// Código de profesor
const copied = ref(false)
async function copyReferralCode() {
  try {
    await navigator.clipboard.writeText(props.profile?.referral_code || '')
  } catch {
    return
  }
  copied.value = true
  setTimeout(() => { copied.value = false }, 2000)
}

// Materias: Alternar selección
function toggleSubject(id) {
  if (form.subject_ids.includes(id)) {
    form.subject_ids = form.subject_ids.filter(item => item !== id)
  } else {
    form.subject_ids.push(id)
  }
}

// Materias: Agregar nueva
const subjectDraft = ref('')

function addSubjectName() {
  const value = subjectDraft.value.trim()
  if (!value) return

  const existing = props.subjects?.find(s => s.name.toLowerCase() === value.toLowerCase())
  if (existing) {
    if (!form.subject_ids.includes(existing.id)) {
      form.subject_ids.push(existing.id)
    }
  } else if (!form.subject_names.some(n => n.toLowerCase() === value.toLowerCase())) {
    form.subject_names.push(value)
  }
  subjectDraft.value = ''
}

function removeSubjectName(name) {
  form.subject_names = form.subject_names.filter(n => n !== name)
}

function submit() {
  form.patch(route('teacher.profile.update'), {
    preserveScroll: true,
  })
}
</script>
