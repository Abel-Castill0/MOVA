<script setup>
import { computed, ref } from 'vue'
import { Check, Search, X } from 'lucide-vue-next'
import { subjectIcon } from '@/utils/subjectIcon'

/**
 * Selector de materias del catálogo cerrado (App\Support\SubjectCatalog): buscador instantáneo y chips marcables
 * agrupados por categoría. No permite escribir materias nuevas: el valor es una lista de ids existentes.
 * Lo usan el registro del profesor, la configuración inicial y la edición del perfil.
 */
const props = defineProps({
  subjects: { type: Array, required: true }, // [{ id, name, level, icon, category, category_label }]
  max: { type: Number, default: 15 },
  inputId: { type: String, default: 'subject-picker-search' },
})

const selected = defineModel({ type: Array, default: () => [] })

const query = ref('')

// Búsqueda sin tildes ni mayúsculas («algebra» encuentra «Álgebra»).
const fold = (text) => (text || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim()

const groups = computed(() => {
  const q = fold(query.value)
  const byCategory = new Map()
  for (const subject of props.subjects) {
    if (q && !fold(subject.name).includes(q) && !fold(subject.category_label).includes(q)) continue
    const label = subject.category_label || 'Otras'
    if (!byCategory.has(label)) byCategory.set(label, [])
    byCategory.get(label).push(subject)
  }
  return [...byCategory.entries()].map(([label, items]) => ({ label, items }))
})

const selectedSubjects = computed(() => props.subjects.filter((s) => selected.value.includes(s.id)))
const atMax = computed(() => selected.value.length >= props.max)

function toggle(id) {
  if (selected.value.includes(id)) {
    selected.value = selected.value.filter((item) => item !== id)
  } else if (!atMax.value) {
    selected.value = [...selected.value, id]
  }
}
</script>

<template>
  <div class="space-y-3">
    <label :for="inputId" class="sr-only">Buscar materia</label>
    <div class="relative">
      <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
      <input
        :id="inputId"
        v-model="query"
        type="search"
        autocomplete="off"
        placeholder="Buscar materia (ej. Álgebra, Inglés)"
        class="h-11 w-full rounded-xl border border-slate-200 bg-white pl-9 pr-3 text-sm text-slate-900 placeholder:text-slate-500 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
        @keydown.enter.prevent
      />
    </div>

    <!-- Elegidas: siempre visibles aunque el filtro las oculte de la lista. -->
    <div class="flex flex-wrap items-center gap-2" aria-live="polite">
      <span class="text-xs font-semibold text-slate-500">
        {{ selected.length ? `${selected.length} de ${max} elegidas` : 'Aún no elegiste materias' }}
      </span>
      <button
        v-for="s in selectedSubjects"
        :key="s.id"
        type="button"
        class="inline-flex items-center gap-1 rounded-full bg-brand-600 px-2.5 py-1 text-xs font-semibold text-white transition-colors hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-300"
        :aria-label="`Quitar ${s.name}`"
        @click="toggle(s.id)"
      >
        {{ s.name }}
        <X class="h-3.5 w-3.5" aria-hidden="true" />
      </button>
    </div>

    <div class="max-h-[22rem] space-y-4 overflow-y-auto overscroll-contain rounded-xl border border-slate-100 bg-slate-50/60 p-3">
      <fieldset v-for="group in groups" :key="group.label">
        <legend class="mb-2 text-[11px] font-bold uppercase tracking-wide text-slate-500">{{ group.label }}</legend>
        <div class="grid grid-cols-1 gap-2 min-[380px]:grid-cols-2">
          <label
            v-for="s in group.items"
            :key="s.id"
            :class="[
              'flex min-h-[44px] cursor-pointer items-center gap-2.5 rounded-xl border px-3 py-2 text-sm transition-colors focus-within:ring-2 focus-within:ring-brand-300',
              selected.includes(s.id)
                ? 'border-brand-500 bg-brand-50 font-semibold text-brand-800'
                : (atMax ? 'cursor-not-allowed border-slate-200 bg-white text-slate-400' : 'border-slate-200 bg-white text-slate-700 hover:border-brand-300'),
            ]"
          >
            <input
              type="checkbox"
              class="sr-only"
              :value="s.id"
              :checked="selected.includes(s.id)"
              :disabled="atMax && !selected.includes(s.id)"
              @change="toggle(s.id)"
            />
            <span :class="['flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-lg', subjectIcon(s.name, s.icon).bg]">
              <component :is="subjectIcon(s.name, s.icon).icon" class="h-4 w-4" :stroke-width="2" aria-hidden="true" />
            </span>
            <span class="min-w-0 flex-1 leading-tight">{{ s.name }}</span>
            <Check v-if="selected.includes(s.id)" class="h-4 w-4 flex-shrink-0 text-brand-600" aria-hidden="true" />
          </label>
        </div>
      </fieldset>
      <p v-if="!groups.length" class="py-6 text-center text-sm text-slate-500">
        No encontramos «{{ query }}» en el catálogo. Si enseñas otra materia, escríbenos y la evaluamos.
      </p>
    </div>
  </div>
</template>
