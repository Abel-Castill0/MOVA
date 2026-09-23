<template>
  <Head title="Libro de Reclamaciones – MOVA" />
  <div class="min-h-screen bg-white">
    <LandingNavbar />

    <section class="pt-28 pb-14 bg-gradient-to-br from-brand-900 to-brand-700">
      <div class="max-w-3xl mx-auto px-4 sm:px-6 text-white">
        <span class="inline-block px-3 py-1 bg-white/10 border border-white/20 rounded-full text-xs font-semibold uppercase tracking-widest mb-4">Legal</span>
        <h1 class="text-4xl sm:text-5xl font-black mb-3">Libro de Reclamaciones</h1>
        <p class="text-white/70">Conforme al Código de Protección y Defensa del Consumidor.</p>
      </div>
    </section>

    <main class="max-w-3xl mx-auto px-4 sm:px-6 -mt-8 pb-20">
      <div class="bg-white rounded-2xl border border-gray-100 shadow-xl shadow-brand-900/5 p-6 sm:p-10 space-y-8">

        <div v-if="filedCode" role="status" class="rounded-xl border border-green-200 bg-green-50 p-4 text-sm text-green-800">
          Registramos tu hoja con el código <strong class="font-mono">{{ filedCode }}</strong>.
          Te enviamos una copia a tu correo y responderemos en un plazo máximo de {{ responseDays }} días hábiles.
        </div>

        <section class="text-sm text-slate-600 space-y-1">
          <h2 class="text-lg font-black text-slate-900 mb-2">Proveedor</h2>
          <p>Razón social: <strong class="text-slate-800">{{ provider.business_name || 'Pendiente de publicación' }}</strong></p>
          <p>RUC: <strong class="text-slate-800">{{ provider.ruc || 'Pendiente de publicación' }}</strong></p>
          <p>Domicilio: <strong class="text-slate-800">{{ provider.address || 'Pendiente de publicación' }}</strong></p>
          <p>Contacto: <a :href="`mailto:${provider.support_email}`" class="text-brand-600 hover:underline">{{ provider.support_email }}</a></p>
        </section>

        <form class="space-y-6" novalidate @submit.prevent="submit">
          <fieldset class="space-y-4">
            <legend class="text-lg font-black text-slate-900">1. Tus datos</legend>
            <Field id="consumer_name" label="Nombre completo" :error="form.errors.consumer_name">
              <input id="consumer_name" v-model="form.consumer_name" class="input" autocomplete="name" required maxlength="150" />
            </Field>
            <div class="grid gap-4 sm:grid-cols-3">
              <Field id="document_type" label="Documento" :error="form.errors.document_type">
                <select id="document_type" v-model="form.document_type" class="input" required>
                  <option value="DNI">DNI</option>
                  <option value="CE">Carné de extranjería</option>
                  <option value="PASAPORTE">Pasaporte</option>
                </select>
              </Field>
              <Field id="document_number" label="Número" class="sm:col-span-2" :error="form.errors.document_number">
                <input id="document_number" v-model="form.document_number" class="input" required maxlength="20" />
              </Field>
            </div>
            <Field id="address" label="Domicilio" :error="form.errors.address">
              <input id="address" v-model="form.address" class="input" autocomplete="street-address" required maxlength="255" />
            </Field>
            <div class="grid gap-4 sm:grid-cols-2">
              <Field id="email" label="Correo electrónico" :error="form.errors.email">
                <input id="email" v-model="form.email" type="email" class="input" autocomplete="email" required maxlength="150" />
              </Field>
              <Field id="phone" label="Teléfono (opcional)" :error="form.errors.phone">
                <input id="phone" v-model="form.phone" type="tel" class="input" autocomplete="tel" maxlength="20" />
              </Field>
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-700">
              <input v-model="form.is_minor" type="checkbox" class="rounded border-gray-300 text-brand-600" />
              Soy menor de edad
            </label>
            <Field v-if="form.is_minor" id="guardian_name" label="Nombre del padre, madre o apoderado" :error="form.errors.guardian_name">
              <input id="guardian_name" v-model="form.guardian_name" class="input" required maxlength="150" />
            </Field>
          </fieldset>

          <fieldset class="space-y-4">
            <legend class="text-lg font-black text-slate-900">2. Bien contratado</legend>
            <div class="grid gap-4 sm:grid-cols-3">
              <Field id="good_type" label="Tipo" :error="form.errors.good_type">
                <select id="good_type" v-model="form.good_type" class="input" required>
                  <option value="servicio">Servicio</option>
                  <option value="producto">Producto</option>
                </select>
              </Field>
              <Field id="amount" label="Monto reclamado S/ (opcional)" class="sm:col-span-2" :error="form.errors.amount">
                <input id="amount" v-model="form.amount" type="number" min="0" step="0.01" inputmode="decimal" class="input" />
              </Field>
            </div>
            <Field id="good_description" label="Descripción" :error="form.errors.good_description">
              <input id="good_description" v-model="form.good_description" class="input" required maxlength="255" placeholder="Ej.: clase de Matemáticas del 12/09" />
            </Field>
          </fieldset>

          <fieldset class="space-y-4">
            <legend class="text-lg font-black text-slate-900">3. Detalle</legend>
            <div class="flex flex-wrap gap-4 text-sm text-slate-700" role="radiogroup" aria-label="Tipo de hoja">
              <label class="flex items-center gap-2"><input v-model="form.type" type="radio" value="reclamo" class="text-brand-600" /> Reclamo <span class="text-slate-400">(disconformidad con el servicio)</span></label>
              <label class="flex items-center gap-2"><input v-model="form.type" type="radio" value="queja" class="text-brand-600" /> Queja <span class="text-slate-400">(malestar con la atención)</span></label>
            </div>
            <p v-if="form.errors.type" class="text-sm text-red-600">{{ form.errors.type }}</p>
            <Field id="detail" label="Detalle" :error="form.errors.detail">
              <textarea id="detail" v-model="form.detail" rows="5" class="input" required maxlength="5000" />
            </Field>
            <Field id="consumer_request" label="Pedido" :error="form.errors.consumer_request">
              <textarea id="consumer_request" v-model="form.consumer_request" rows="3" class="input" required maxlength="2000" />
            </Field>
          </fieldset>

          <label class="flex items-start gap-2 text-sm text-slate-700">
            <input v-model="form.accepted" type="checkbox" class="mt-0.5 rounded border-gray-300 text-brand-600" />
            Declaro que la información proporcionada es verídica.
          </label>
          <p v-if="form.errors.accepted" class="text-sm text-red-600">{{ form.errors.accepted }}</p>

          <button type="submit" :disabled="form.processing"
            class="w-full sm:w-auto px-6 py-3 rounded-xl bg-brand-600 text-white font-semibold hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-50">
            {{ form.processing ? 'Enviando…' : 'Enviar hoja de reclamación' }}
          </button>
        </form>
      </div>
    </main>

    <LandingFooter />
  </div>
</template>

<script setup>
import { h } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import LandingNavbar from '@/Components/LandingNavbar.vue'
import LandingFooter from '@/Components/LandingFooter.vue'

const props = defineProps({
  provider:     { type: Object, required: true },
  responseDays: { type: Number, required: true },
  prefill:      { type: Object, default: null },
  filedCode:    { type: String, default: null },
})

const Field = (p, { slots }) => h('div', { class: p.class }, [
  h('label', { for: p.id, class: 'block text-sm font-medium text-slate-700 mb-1' }, p.label),
  slots.default?.(),
  p.error ? h('p', { class: 'mt-1 text-sm text-red-600' }, p.error) : null,
])
Field.props = ['id', 'label', 'error', 'class']

const blank = () => ({
  type: 'reclamo',
  consumer_name: props.prefill?.consumer_name ?? '',
  document_type: 'DNI',
  document_number: '',
  address: '',
  phone: props.prefill?.phone ?? '',
  email: props.prefill?.email ?? '',
  is_minor: false,
  guardian_name: '',
  good_type: 'servicio',
  amount: '',
  good_description: '',
  detail: '',
  consumer_request: '',
  accepted: false,
})

const form = useForm(blank())

function submit() {
  if (form.processing) return
  form.transform((d) => ({ ...d, amount: d.amount === '' ? null : d.amount }))
    .post(route('complaints.store'), {
      preserveScroll: true,
      onSuccess: () => { form.defaults(blank()); form.reset() },
    })
}
</script>

<style scoped>
.input {
  @apply block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500;
}
</style>
