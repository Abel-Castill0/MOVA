<script setup>
import { computed, ref, nextTick } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import DangerButton from '@/Components/DangerButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import TextInput from '@/Components/TextInput.vue'
const props = defineProps({ summary: { type: Object, required: true }, status: String })
const user = computed(() => usePage().props.auth.user)
const open = ref(false), method = ref('password')
const form = useForm({ password: '', code: '', confirmation: false, scope: 'account' })
const codeForm = useForm({})
const stats = computed(() => [
  ['Clases pendientes', props.summary.pending_classes], ['Solicitudes activas', props.summary.active_requests],
  ...(props.summary.professional_profile ? [['Créditos disponibles', props.summary.credits_available], ['Créditos reservados', props.summary.credits_reserved], ['Pagos pendientes', props.summary.pending_payments], ['Pagos en revisión', props.summary.payment_reviews]] : []),
  ...(props.summary.students ? [['Alumnos vinculados', props.summary.students]] : []),
])
function close() { if (form.processing) return; open.value = false; method.value = 'password'; form.reset(); form.clearErrors(); codeForm.clearErrors() }
function submit() {
  if (method.value === 'password') form.code = ''; else form.password = ''
  form.delete(route('profile.destroy'), { preserveScroll: true, onFinish: () => form.reset('password', 'code'), onError: () => nextTick(() => document.getElementById(method.value === 'password' ? 'closure-password' : 'closure-code')?.focus()) })
}
</script>
<template>
  <section>
    <h2 class="text-lg font-bold text-slate-900">Eliminar mi cuenta</h2>
    <p class="mt-2 text-sm leading-relaxed text-slate-600">Puedes solicitar la eliminación de tu cuenta y de los datos personales que ya no sea necesario conservar. Algunas operaciones y registros pueden mantenerse cuando exista una obligación legal o contractual.</p>
    <button type="button" @click="open = true" class="mt-5 min-h-11 rounded-xl border border-rose-200 px-4 py-2.5 text-sm font-bold text-rose-700 transition-colors hover:bg-rose-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-400">Eliminar mi cuenta</button>
    <Modal :show="open" :closeable="!form.processing" max-width="2xl" title-id="closure-title" @close="close">
      <form @submit.prevent="submit" class="bg-white text-slate-900 p-5 sm:p-7">
        <p class="text-xs font-bold uppercase tracking-widest text-brand-600">Privacidad y cuenta</p><h2 id="closure-title" tabindex="0" class="mt-2 text-xl font-extrabold text-slate-900">Antes de cerrar tu cuenta</h2>
        <p class="mt-3 text-sm leading-relaxed text-slate-600">Cerraremos tu acceso al dashboard y registraremos tu solicitud de eliminación. Podrás iniciar sesión únicamente para consultar su estado. Esto no significa que tus datos se hayan eliminado todavía.</p>
        <p v-if="summary.roles.length > 1" class="mt-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">Tu identidad tiene varios roles. Esta solicitud cierra toda tu cuenta, incluidos tus perfiles de padre y profesor; no solo el perfil que estás usando.</p>
        <dl class="my-4 grid grid-cols-2 gap-2"><div v-for="[label, value] in stats" :key="label" class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">{{ label }}</dt><dd class="mt-1 text-lg font-bold text-slate-900">{{ value }}</dd></div></dl>
        <ul class="list-disc space-y-2 pl-5 text-sm leading-relaxed text-slate-600"><li v-if="summary.professional_profile">Ocultaremos tu perfil público y tus ofertas. Tus créditos no se gastan, reembolsan ni pierden automáticamente por esta solicitud.</li><li v-if="summary.pending_classes || summary.active_requests">No cancelaremos clases ni solicitudes automáticamente. Soporte revisará cómo resolver las obligaciones pendientes con las otras personas.</li><li v-if="summary.students">Los registros de tus alumnos no se borrarán ni anonimizarán automáticamente.</li><li>Detendremos los avisos rutinarios. Conservaremos los datos y archivos necesarios para evaluar la solicitud, las clases y las operaciones que deban justificarse.</li><li>El plazo legal general de respuesta a la cancelación es de 10 días hábiles desde el día siguiente de la solicitud. Es un plazo de respuesta, no una promesa de borrado total.</li></ul>
        <fieldset class="mt-5 border-t border-slate-100 pt-5"><legend class="sr-only">Confirma tu identidad</legend><p class="font-bold text-slate-900">Confirma que eres tú</p>
          <div class="my-3 flex flex-wrap gap-4 text-sm"><label class="flex min-h-11 items-center gap-2"><input v-model="method" type="radio" value="password" name="identity-method" class="focus-visible:ring-2 focus-visible:ring-brand-500" />Contraseña actual</label><label v-if="user.email_verified" class="flex min-h-11 items-center gap-2"><input v-model="method" type="radio" value="email" name="identity-method" class="focus-visible:ring-2 focus-visible:ring-brand-500" />Código por correo (también para Google)</label></div>
          <div v-if="method === 'password'"><InputLabel for="closure-password" value="Contraseña actual" /><TextInput id="closure-password" v-model="form.password" type="password" autocomplete="current-password" class="mt-1 w-full" /></div>
          <div v-else><SecondaryButton type="button" :disabled="codeForm.processing" @click="codeForm.post(route('account.closure.code'), {preserveScroll:true})">{{ codeForm.processing ? 'Enviando…' : 'Enviar código a mi correo verificado' }}</SecondaryButton><p role="status" aria-live="polite" class="my-2 text-sm text-emerald-700">{{ status === 'closure-code-sent' && !form.errors.password && !form.errors.code ? 'Código enviado. Revisa tu correo; caduca en 10 minutos.' : '' }}</p><InputError role="alert" :message="codeForm.errors.code" /><InputLabel for="closure-code" value="Código de 6 dígitos" class="mt-3" /><TextInput id="closure-code" v-model="form.code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="mt-1 w-full" /></div>
          <InputError role="alert" :message="form.errors.password || form.errors.code" class="mt-2" />
        </fieldset>
        <label class="mt-5 flex items-start gap-3 rounded-xl bg-slate-50 p-3 text-sm text-slate-700"><input v-model="form.confirmation" type="checkbox" class="mt-0.5 rounded border-slate-400 text-brand-600" /><span>Solicito cerrar toda mi cuenta y revisar la eliminación de mis datos. He leído las consecuencias.</span></label><InputError :message="form.errors.confirmation || form.errors.scope" />
        <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><SecondaryButton type="button" :disabled="form.processing" @click="close">Conservar mi cuenta</SecondaryButton><DangerButton type="submit" :disabled="form.processing || !form.confirmation">{{ form.processing ? 'Registrando solicitud…' : 'Cerrar cuenta y enviar solicitud' }}</DangerButton></div>
      </form>
    </Modal>
  </section>
</template>
