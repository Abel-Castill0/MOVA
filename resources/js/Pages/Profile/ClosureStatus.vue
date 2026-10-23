<script setup>
import { ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import MovaLogo from '@/Components/MovaLogo.vue'
defineProps({ receipt: Object, cancellableLessons: { type: Array, default: () => [] } })
const confirming = ref(null)
const cancelForm = useForm({ reason: 'Cuenta cerrada por su titular' })
const when = (iso) => new Date(iso).toLocaleString('es-PE', { dateStyle: 'full', timeStyle: 'short' })
function cancelLesson(id) { cancelForm.post(route('lessons.cancel', id), { preserveScroll: true, onFinish: () => { confirming.value = null } }) }
</script>
<template>
  <Head title="Estado de mi solicitud" />
  <main class="min-h-screen bg-slate-50 px-4 py-10 sm:py-16"><div class="mx-auto max-w-2xl"><Link href="/" aria-label="MOVA"><MovaLogo variant="imagotipo" class="mb-8 h-9 w-auto" /></Link><section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-9">
    <p class="text-xs font-bold uppercase tracking-widest text-brand-600">Privacidad y cuenta</p><h1 class="mt-3 text-2xl font-extrabold text-slate-900">{{ receipt.status === 'responded' ? 'Tienes una respuesta' : 'Recibimos tu solicitud' }}</h1>
    <p class="mt-4 leading-relaxed text-slate-600">Tu acceso habitual está cerrado. La eliminación de tus datos sigue pendiente de revisión: no hemos borrado automáticamente alumnos, clases, créditos ni operaciones.</p>
    <dl class="my-6 rounded-2xl bg-slate-50 p-4 text-sm"><dt class="text-slate-500">Número de solicitud</dt><dd class="mt-1 break-all font-semibold text-slate-900">{{ receipt.reference }}</dd><dt class="mt-3 text-slate-500">Tipo</dt><dd class="mt-1 font-semibold text-slate-900">Solicitud de eliminación de cuenta y de datos personales</dd><dt class="mt-3 text-slate-500">Fecha de la solicitud</dt><dd class="mt-1 font-semibold text-slate-900"><time :datetime="receipt.created_at">{{ new Date(receipt.created_at).toLocaleDateString('es-PE', { day: 'numeric', month: 'long', year: 'numeric' }) }}</time></dd><dt class="mt-3 text-slate-500">Estado</dt><dd class="mt-1 font-semibold text-brand-700">{{ receipt.status === 'responded' ? 'Respuesta disponible · cuenta cerrada' : 'Pendiente de revisión · cuenta cerrada' }}</dd></dl>
    <div v-if="receipt.response" class="mb-6 whitespace-pre-wrap rounded-2xl border border-brand-100 bg-brand-50 p-4 text-sm leading-relaxed text-brand-900">{{ receipt.response }}</div>
    <p class="mb-3 text-sm font-semibold text-slate-900">Siguiente paso: {{ receipt.status === 'responded' ? 'lee la respuesta de arriba y escríbenos si necesitas aclarar algo.' : 'nuestro equipo revisará tu caso; no necesitas hacer nada más.' }}</p><p class="text-sm leading-relaxed text-slate-600">El plazo legal general de respuesta es de 10 días hábiles desde el día siguiente de tu solicitud. Te explicaremos qué datos pueden suprimirse y cuáles deben conservarse, con su justificación. Guarda este número; puedes volver a iniciar sesión para consultar el estado.</p>
    <section v-if="cancellableLessons.length" aria-labelledby="pending-classes" class="mt-6 rounded-2xl border border-slate-200 p-4"><h2 id="pending-classes" class="text-sm font-bold text-slate-900">Clases programadas antes del cierre</h2><p class="mt-1 text-sm text-slate-600">Siguen vigentes para la otra persona. Puedes cancelarlas aquí; los créditos reservados se devuelven según las reglas de MOVA.</p>
      <p v-if="cancelForm.errors.cancel" role="alert" class="mt-2 text-sm text-rose-700">{{ cancelForm.errors.cancel }}</p>
      <ul class="mt-3 space-y-3"><li v-for="lesson in cancellableLessons" :key="lesson.id" class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-slate-50 p-3 text-sm"><span class="font-semibold text-slate-900">{{ when(lesson.start_time) }} · {{ lesson.duration_minutes }} min</span>
        <span v-if="confirming === lesson.id" class="flex gap-2"><button type="button" :disabled="cancelForm.processing" @click="cancelLesson(lesson.id)" class="min-h-11 rounded-xl bg-rose-600 px-4 font-bold text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-400">Confirmar cancelación</button><button type="button" @click="confirming = null" class="min-h-11 rounded-xl border border-slate-300 px-4 font-bold text-slate-700">Conservar</button></span>
        <button v-else type="button" @click="confirming = lesson.id" class="min-h-11 rounded-xl border border-rose-200 px-4 font-bold text-rose-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-400">Cancelar clase</button></li></ul></section>
    <a :href="`mailto:${$page.props.support.email}?subject=Solicitud%20de%20eliminación%20${receipt.reference}`" class="mt-6 inline-flex min-h-11 items-center rounded-xl bg-brand-600 px-5 font-bold text-white">Contactar con soporte</a>
    <div class="mt-6 flex flex-wrap gap-5 text-sm font-semibold text-brand-700"><a :href="route('legal.privacy')">Política de Privacidad</a><Link v-if="$page.props.auth.user" :href="route('logout')" method="post" as="button">Cerrar sesión</Link><Link v-else :href="route('login')">Iniciar sesión</Link></div>
  </section></div></main>
</template>
