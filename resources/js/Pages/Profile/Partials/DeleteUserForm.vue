<script setup>
import { computed, ref, nextTick } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import DangerButton from '@/Components/DangerButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import TextInput from '@/Components/TextInput.vue'
const props = defineProps({ summary: { type: Object, required: true }, status: String, legacy: Boolean })
const user = computed(() => usePage().props.auth.user)
const open = ref(false), method = ref('password')
const form = useForm({ password: '', code: '', confirmation: false, accept_credit_loss: false, credit_loss_amount: 0, scope: 'account' })
const codeForm = useForm({})
const teacher = computed(() => props.summary.professional_profile)
const forfeit = computed(() => props.summary.credits_forfeitable || 0)
const refundable = computed(() => props.summary.credits_refundable || 0)
const stats = computed(() => [
  ['Clases futuras', props.summary.future_classes], ['Solicitudes activas', props.summary.active_requests],
  ...(teacher.value ? [['Créditos disponibles', props.summary.credits_available], ['Créditos que se perderían', forfeit.value], ['Pagos pendientes', props.summary.pending_payments]] : []),
  ...(props.summary.students ? [['Alumnos vinculados', props.summary.students]] : []),
])
const canSubmit = computed(() => form.confirmation && (!forfeit.value || form.accept_credit_loss) && !form.processing)
function close() { if (form.processing) return; open.value = false; method.value = 'password'; form.reset(); form.clearErrors(); codeForm.clearErrors() }
function submit() {
  if (method.value === 'password') form.code = ''; else form.password = ''
  // Importe que el titular vio y aceptó: el servidor rechaza si el real es mayor.
  form.credit_loss_amount = forfeit.value
  form.delete(route('profile.destroy'), { preserveScroll: true, onFinish: () => form.reset('password', 'code'), onError: (errors) => { if (errors.accept_credit_loss) router.reload({ only: ['accountSummary'] }); nextTick(() => document.getElementById(method.value === 'password' ? 'closure-password' : 'closure-code')?.focus()) } })
}
</script>
<template>
  <section>
    <h2 class="text-lg font-bold text-slate-900">Eliminar mi cuenta</h2>
    <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ legacy ? 'Tu solicitud anterior sigue sin completarse. Puedes completar ahora la eliminación de forma automática: no necesitas esperar a soporte.' : 'Puedes cerrar tu cuenta y eliminar tus datos personales tú mismo, sin esperar aprobación. Algunos registros pueden mantenerse cuando exista una obligación legal o contractual.' }}</p>
    <button type="button" @click="open = true" class="mt-5 min-h-11 rounded-xl border border-rose-200 px-4 py-2.5 text-sm font-bold text-rose-700 transition-colors hover:bg-rose-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-400">{{ legacy ? 'Completar la eliminación de mi cuenta' : 'Eliminar mi cuenta' }}</button>
    <Modal :show="open" :closeable="!form.processing" max-width="2xl" title-id="closure-title" @close="close">
      <form @submit.prevent="submit" class="bg-white text-slate-900 p-5 sm:p-7">
        <p class="text-xs font-bold uppercase tracking-widest text-brand-600">Privacidad y cuenta</p><h2 id="closure-title" tabindex="0" class="mt-2 text-xl font-extrabold text-slate-900">Antes de eliminar tu cuenta</h2>
        <p class="mt-3 text-sm leading-relaxed text-slate-600">Al confirmar, cerraremos tu acceso de inmediato, gestionaremos tus clases y suprimiremos o anonimizaremos tus datos personales. <strong class="font-semibold text-slate-900">Esta acción no se puede deshacer</strong>, pero podrás crear una cuenta nueva con el mismo correo cuando quieras.</p>
        <p v-if="summary.roles.length > 1" class="mt-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">Tu identidad tiene varios roles. Esto cierra toda tu cuenta, incluidos tus perfiles de padre y profesor.</p>
        <dl class="my-4 grid grid-cols-2 gap-2"><div v-for="[label, value] in stats" :key="label" class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">{{ label }}</dt><dd class="mt-1 text-lg font-bold text-slate-900">{{ value }}</dd></div></dl>
        <ul class="list-disc space-y-2 pl-5 text-sm leading-relaxed text-slate-600">
          <li v-if="teacher">Ocultaremos tu perfil y cancelaremos tus clases futuras, avisando a las familias. Las solicitudes que no dependían de ti volverán a la búsqueda de profesor; las que eran solo para ti se cierran y la familia confirmará una nueva.</li>
          <li v-if="summary.students || !teacher">Cancelaremos tus solicitudes y tus clases futuras y avisaremos a los profesores. No republicamos solicitudes de una cuenta cerrada. Los datos de tus alumnos se anonimizan.</li>
          <li v-if="teacher && forfeit">Perderás <strong class="font-semibold text-slate-900">{{ forfeit }} crédito(s)</strong> sin usar: no se transfieren ni se restauran al volver a registrarte. Queda un registro contable.</li>
          <li v-if="teacher && refundable">Tus <strong class="font-semibold text-slate-900">{{ refundable }} crédito(s)</strong> comprados hace menos de 7 días no se pierden: conservan su derecho de devolución: para tramitarla, escríbenos con tu constancia de cierre.</li>
          <li>Eliminamos tus datos de contacto, foto, sesiones y avisos. Conservamos, con acceso restringido, los registros financieros, de clases, reclamaciones y constancias legales que MOVA debe mantener.</li>
          <li>Tu correo y tu teléfono quedan libres: si vuelves a registrarte empezarás desde cero, sin clases, solicitudes, créditos ni permisos anteriores.</li>
        </ul>
        <fieldset class="mt-5 border-t border-slate-100 pt-5"><legend class="sr-only">Confirma tu identidad</legend><p class="font-bold text-slate-900">Confirma que eres tú</p>
          <div class="my-3 flex flex-wrap gap-4 text-sm"><label class="flex min-h-11 items-center gap-2"><input v-model="method" type="radio" value="password" name="identity-method" class="focus-visible:ring-2 focus-visible:ring-brand-500" />Contraseña actual</label><label v-if="user?.email_verified" class="flex min-h-11 items-center gap-2"><input v-model="method" type="radio" value="email" name="identity-method" class="focus-visible:ring-2 focus-visible:ring-brand-500" />Código por correo (también para Google)</label></div>
          <div v-if="method === 'password'"><InputLabel for="closure-password" value="Contraseña actual" /><TextInput id="closure-password" v-model="form.password" type="password" autocomplete="current-password" class="mt-1 w-full" /></div>
          <div v-else><SecondaryButton type="button" :disabled="codeForm.processing" @click="codeForm.post(route('account.closure.code'), {preserveScroll:true})">{{ codeForm.processing ? 'Enviando…' : 'Enviar código a mi correo verificado' }}</SecondaryButton><p role="status" aria-live="polite" class="my-2 text-sm text-emerald-700">{{ status === 'closure-code-sent' && !form.errors.password && !form.errors.code ? 'Código enviado. Revisa tu correo; caduca en 10 minutos.' : '' }}</p><InputError role="alert" :message="codeForm.errors.code" /><InputLabel for="closure-code" value="Código de 6 dígitos" class="mt-3" /><TextInput id="closure-code" v-model="form.code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="mt-1 w-full" /></div>
          <InputError role="alert" :message="form.errors.password || form.errors.code || form.errors.closure" class="mt-2" />
        </fieldset>
        <label class="mt-5 flex items-start gap-3 rounded-xl bg-slate-50 p-3 text-sm text-slate-700"><input v-model="form.confirmation" type="checkbox" class="mt-0.5 rounded border-slate-400 text-brand-600" /><span>Quiero eliminar toda mi cuenta. He leído las consecuencias y entiendo que no se puede deshacer.</span></label><InputError role="alert" :message="form.errors.confirmation || form.errors.scope" />
        <label v-if="forfeit" class="mt-3 flex items-start gap-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-900"><input v-model="form.accept_credit_loss" type="checkbox" class="mt-0.5 rounded border-amber-500 text-brand-600" /><span>Acepto perder mis {{ forfeit }} crédito(s) sin usar.</span></label><InputError role="alert" :message="form.errors.accept_credit_loss" />
        <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><SecondaryButton type="button" :disabled="form.processing" @click="close">Conservar mi cuenta</SecondaryButton><DangerButton type="submit" :disabled="!canSubmit">{{ form.processing ? 'Eliminando tu cuenta…' : 'Eliminar mi cuenta' }}</DangerButton></div>
      </form>
    </Modal>
  </section>
</template>
