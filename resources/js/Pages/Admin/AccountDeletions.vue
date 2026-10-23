<script setup>
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import InputError from '@/Components/InputError.vue'
import { ref } from 'vue'
defineProps({ requests: Object })
const selected = ref(null)
const form = useForm({ response: '' })
function select(item) { selected.value = item.id; form.response = item.response ?? ''; form.clearErrors() }
function submit() { form.patch(route('admin.account-deletions.update', selected.value), {preserveScroll:true, onSuccess:() => { selected.value = null }}) }
const stateLabel = (item) => item.status === 'completed' ? 'Cierre automático completado' : (item.status === 'responded' ? 'Solicitud anterior · respondida' : 'Solicitud anterior · espera reconfirmación del titular')
const needsAttention = (item) => item.cleanup_status === 'failed' || (item.outcome?.credits_refundable > 0) || (item.outcome?.unresolved_lessons?.length > 0)
</script>
<template><AppLayout title="Cierres de cuenta"><div class="mx-auto max-w-4xl space-y-5"><div class="rounded-2xl bg-white p-6"><h2 class="text-xl font-bold">Cierres de cuenta</h2><p class="mt-2 text-sm text-slate-600">Los titulares cierran y eliminan su cuenta por sí mismos: no hay aprobación que dar. Aquí solo se consultan los cierres, las incidencias excepcionales (limpieza fallida, clases sin cancelar, créditos dentro del plazo de devolución) y las solicitudes del flujo anterior que aún no se reconfirmaron. No hay reactivación: la identidad suprimida no se restaura.</p></div>
  <p v-if="!requests.data.length" class="rounded-2xl bg-white p-6 text-slate-500">No hay cierres registrados.</p>
  <article v-for="item in requests.data" :key="item.id" class="rounded-2xl border bg-white p-5 sm:p-6" :class="needsAttention(item) ? 'border-amber-300' : 'border-slate-100'"><div class="flex flex-wrap justify-between gap-3"><h3 class="font-bold">{{ item.status === 'completed' ? 'Cuenta cerrada' : item.user.name }}</h3><span class="text-xs font-semibold text-brand-700">{{ stateLabel(item) }}</span></div>
    <p class="mt-1 break-all text-sm text-slate-500"><template v-if="item.status !== 'completed'">{{ item.user.email }} · </template>{{ new Date(item.erased_at || item.created_at).toLocaleDateString('es-PE') }} · {{ item.reference }}</p>
    <p v-if="item.status === 'completed'" class="mt-4 text-sm text-slate-600">Tratamiento: {{ item.outcome?.classes_cancelled ?? 0 }} clases canceladas · {{ item.outcome?.requests_republished ?? 0 }} solicitudes republicadas · {{ item.outcome?.requests_need_new_confirmation ?? 0 }} esperan nueva confirmación de la familia · {{ item.outcome?.credits_forfeited ?? 0 }} créditos dados de baja · {{ item.outcome?.credits_refundable ?? 0 }} créditos dentro del plazo de devolución · {{ item.outcome?.students_anonymized ?? 0 }} alumnos anonimizados.</p>
    <p v-else class="mt-4 text-sm text-slate-600">Al solicitar: {{ item.snapshot.pending_classes }} clases pendientes · {{ item.snapshot.active_requests }} solicitudes activas · {{ item.snapshot.students }} alumnos · {{ item.snapshot.credits_available }} créditos disponibles.</p>
    <p v-if="item.cleanup_status === 'failed' || item.cleanup_status === 'pending'" class="mt-2 text-xs text-amber-800">Limpieza de archivos externos: {{ item.cleanup_status === 'failed' ? 'falló y se reintenta automáticamente' : 'en curso' }} ({{ item.cleanup_attempts }} intento(s)).</p>
    <p v-if="item.outcome?.credits_refundable > 0" class="mt-2 text-xs text-amber-800">Créditos comprados hace menos de 7 días: no se perdieron; revisa su derecho de devolución en el panel de pagos.</p>
    <p v-if="item.outcome?.unresolved_lessons?.length" class="mt-2 text-xs text-amber-800">Clases sin cancelar automáticamente (anomalía de ledger): {{ item.outcome.unresolved_lessons.join(', ') }}.</p>
    <template v-if="item.status !== 'completed'">
      <p v-if="item.response && selected !== item.id" class="mt-4 whitespace-pre-wrap text-sm">{{ item.response }}</p>
      <button v-if="selected !== item.id" @click="select(item)" class="mt-4 min-h-11 rounded-xl border border-brand-200 px-4 text-sm font-bold text-brand-700">Dejar una nota para el titular</button><form v-else @submit.prevent="submit" class="mt-5"><label :for="`response-${item.id}`" class="block text-sm font-bold">Nota visible para el titular</label><textarea :id="`response-${item.id}`" v-model="form.response" rows="4" required minlength="20" maxlength="5000" class="mt-2 w-full rounded-xl border-slate-300" /><InputError :message="form.errors.response" /><div class="mt-3 flex gap-4"><PrimaryButton :disabled="form.processing">Guardar nota</PrimaryButton><button type="button" @click="selected = null" :disabled="form.processing" class="min-h-11 px-3 text-sm font-semibold text-slate-700">Cancelar</button></div></form>
    </template>
  </article><nav aria-label="Paginación" class="flex flex-wrap gap-3"><Link v-if="requests.prev_page_url" :href="requests.prev_page_url">Anterior</Link><Link v-if="requests.next_page_url" :href="requests.next_page_url">Siguiente</Link></nav></div></AppLayout></template>
