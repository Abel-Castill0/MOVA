<script setup>
import { computed, ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import MovaLogo from '@/Components/MovaLogo.vue'
import DeleteUserForm from './Partials/DeleteUserForm.vue'
const props = defineProps({ receipt: Object, cancellableLessons: { type: Array, default: () => [] }, accountSummary: Object, status: String })
// `completed`: cierre y supresión ya procesados. Otro estado = solicitud del flujo anterior, pendiente de reconfirmar.
const done = computed(() => props.receipt.status === 'completed')
const outcome = computed(() => props.receipt.outcome || {})
const confirming = ref(null)
const cancelForm = useForm({ reason: 'Cuenta cerrada por su titular' })
const when = (iso) => new Date(iso).toLocaleString('es-PE', { dateStyle: 'full', timeStyle: 'short' })
const day = (iso) => new Date(iso).toLocaleDateString('es-PE', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'America/Lima' })
function cancelLesson(id) { cancelForm.post(route('lessons.cancel', id), { preserveScroll: true, onFinish: () => { confirming.value = null } }) }
const treatment = computed(() => {
  const o = outcome.value
  const lines = ['Tu acceso fue revocado y cerramos todas tus sesiones.']
  lines.push(o.classes_cancelled ? `Cancelamos ${o.classes_cancelled} clase(s) futura(s) y avisamos a la otra parte.` : 'No tenías clases futuras por cancelar.')
  if (o.requests_republished) lines.push(`${o.requests_republished} solicitud(es) de familias volvieron a la búsqueda de profesor.`)
  if (o.requests_need_new_confirmation) lines.push(`${o.requests_need_new_confirmation} familia(s) recibirán un aviso para confirmar una nueva solicitud.`)
  if (o.credits_forfeited) lines.push(`${o.credits_forfeited} crédito(s) sin usar se dieron de baja con un asiento en el registro, según lo que aceptaste.`)
  if (o.credits_refundable) lines.push(`${o.credits_refundable} crédito(s) están dentro del plazo de devolución: no se perdieron. Para tramitar su devolución, escríbenos con tu constancia de cierre.`)
  lines.push('Suprimimos o anonimizamos tus datos personales en MOVA (contacto, sesiones, avisos y datos de tus alumnos).')
  lines.push('Conservamos, con acceso restringido, los registros financieros, de clases, reclamaciones y constancias legales que MOVA está obligada a mantener.')
  return lines
})
</script>
<template>
  <Head :title="done ? 'Tu cuenta fue cerrada' : 'Completa la eliminación de tu cuenta'" />
  <main class="min-h-screen bg-slate-50 px-4 py-10 sm:py-16"><div class="mx-auto max-w-2xl"><Link href="/" aria-label="MOVA"><MovaLogo variant="imagotipo" class="mb-8 h-9 w-auto" /></Link>
    <section v-if="done" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-9" aria-labelledby="closed-title">
      <p class="text-xs font-bold uppercase tracking-widest text-brand-600">Privacidad y cuenta</p>
      <h1 id="closed-title" class="mt-3 text-2xl font-extrabold text-slate-900">Tu cuenta de MOVA ha sido cerrada</h1>
      <p class="mt-4 leading-relaxed text-slate-600">Gracias por haber sido parte de MOVA. Esto es lo que hicimos por ti:</p>
      <ul class="mt-4 space-y-3 text-sm leading-relaxed text-slate-700"><li v-for="line in treatment" :key="line" class="flex gap-3"><span aria-hidden="true" class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-brand-500"></span><span>{{ line }}</span></li></ul>
      <p v-if="receipt.cleanup_status === 'pending' || receipt.cleanup_status === 'failed'" role="status" class="mt-5 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">El borrado de archivos externos (como tu foto) sigue en curso: MOVA lo reintenta automáticamente y lo revisa si persiste. No necesitas hacer nada.</p>
      <p class="mt-5 text-sm leading-relaxed text-slate-600">Puedes volver a registrarte cuando quieras con el mismo correo, como padre o como profesor. Tu cuenta nueva empezará desde cero: sin clases, solicitudes, créditos ni permisos anteriores.</p>
      <dl class="my-6 rounded-2xl bg-slate-50 p-4 text-sm"><dt class="text-slate-500">Constancia de cierre</dt><dd class="mt-1 break-all font-semibold text-slate-900">{{ receipt.reference }}</dd><dt class="mt-3 text-slate-500">Fecha</dt><dd class="mt-1 font-semibold text-slate-900"><time :datetime="receipt.erased_at || receipt.created_at">{{ day(receipt.erased_at || receipt.created_at) }}</time></dd></dl>
      <div class="flex flex-col gap-3 sm:flex-row"><Link :href="route('register')" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-brand-600 px-5 font-bold text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">Crear una cuenta nueva</Link><Link href="/" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-5 font-bold text-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">Volver al inicio</Link></div>
      <p class="mt-6 text-xs text-slate-500">¿Dudas sobre tus datos? Escríbenos a <a :href="`mailto:${$page.props.support.email}?subject=Cierre%20de%20cuenta%20${encodeURIComponent(receipt.reference)}`" class="font-semibold text-brand-700 underline">{{ $page.props.support.email }}</a> indicando este número.</p>
    </section>
    <section v-else class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-9" aria-labelledby="legacy-title">
      <p class="text-xs font-bold uppercase tracking-widest text-brand-600">Privacidad y cuenta</p>
      <h1 id="legacy-title" class="mt-3 text-2xl font-extrabold text-slate-900">Completa la eliminación de tu cuenta</h1>
      <p class="mt-4 leading-relaxed text-slate-600">Solicitaste eliminar tu cuenta el <time :datetime="receipt.created_at">{{ day(receipt.created_at) }}</time> y tu acceso habitual está cerrado. Ya no necesitas esperar a soporte: puedes completar la eliminación ahora, tú mismo, confirmando de nuevo tu identidad y las consecuencias.</p>
      <p v-if="receipt.response" class="mt-4 whitespace-pre-wrap rounded-2xl border border-brand-100 bg-brand-50 p-4 text-sm leading-relaxed text-brand-900">{{ receipt.response }}</p>
      <div v-if="accountSummary" class="mt-6 rounded-2xl border border-slate-200 p-4"><DeleteUserForm :summary="accountSummary" :status="status" legacy /></div>
      <section v-if="cancellableLessons.length" aria-labelledby="pending-classes" class="mt-6 rounded-2xl border border-slate-200 p-4"><h2 id="pending-classes" class="text-sm font-bold text-slate-900">Clases programadas antes del cierre</h2><p class="mt-1 text-sm text-slate-600">Al completar la eliminación se cancelarán automáticamente. También puedes cancelarlas una por una aquí.</p>
        <p v-if="cancelForm.errors.cancel" role="alert" class="mt-2 text-sm text-rose-700">{{ cancelForm.errors.cancel }}</p>
        <ul class="mt-3 space-y-3"><li v-for="lesson in cancellableLessons" :key="lesson.id" class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-slate-50 p-3 text-sm"><span class="font-semibold text-slate-900">{{ when(lesson.start_time) }} · {{ lesson.duration_minutes }} min</span>
          <span v-if="confirming === lesson.id" class="flex gap-2"><button type="button" :disabled="cancelForm.processing" @click="cancelLesson(lesson.id)" class="min-h-11 rounded-xl bg-rose-600 px-4 font-bold text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-400">Confirmar cancelación</button><button type="button" @click="confirming = null" class="min-h-11 rounded-xl border border-slate-300 px-4 font-bold text-slate-700">Conservar</button></span>
          <button v-else type="button" @click="confirming = lesson.id" class="min-h-11 rounded-xl border border-rose-200 px-4 font-bold text-rose-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-400">Cancelar clase</button></li></ul></section>
      <div class="mt-6 flex flex-wrap gap-5 text-sm font-semibold text-brand-700"><a :href="route('legal.privacy')">Política de Privacidad</a><Link :href="route('logout')" method="post" as="button">Cerrar sesión</Link></div>
    </section>
  </div></main>
</template>
