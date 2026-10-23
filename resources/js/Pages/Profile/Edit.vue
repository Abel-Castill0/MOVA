<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icon from '@/Components/Icon.vue'
import DeleteUserForm from './Partials/DeleteUserForm.vue'
import UpdateAvatarForm from './Partials/UpdateAvatarForm.vue'
import NotificationPreferencesForm from './Partials/NotificationPreferencesForm.vue'
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue'
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue'
const props = defineProps({ mustVerifyEmail: Boolean, status: String, section: String, accountSummary: Object, canRequestClosure: Boolean })
const user = computed(() => usePage().props.auth.user)
const active = ref(props.section || 'personal')
watch(() => props.section, value => { active.value = value || 'personal' })
const tabs = [{ id: 'personal', label: 'Información personal', icon: 'profile' }, { id: 'security', label: 'Seguridad', icon: 'lock' }, { id: 'privacy', label: 'Privacidad y cuenta', icon: 'settings' }]
async function goToEmail() { active.value = 'personal'; await nextTick(); document.getElementById('email')?.focus() }
function moveTab(event, index) {
  if (!['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(event.key)) return
  event.preventDefault()
  const next = event.key === 'Home' ? 0 : event.key === 'End' ? 2 : (index + (event.key === 'ArrowRight' ? 1 : -1) + 3) % 3
  active.value = tabs[next].id
  document.getElementById(`profile-tab-${active.value}`)?.focus()
}
</script>
<template>
  <AppLayout :title="section === 'security' ? 'Configuración de cuenta' : 'Mi perfil'">
    <div class="mx-auto w-full max-w-5xl space-y-6 pb-10">
      <header class="relative overflow-hidden rounded-3xl border border-white/80 bg-white/80 p-6 shadow-sm sm:p-8">
        <div aria-hidden="true" class="absolute -right-12 -top-20 h-56 w-56 rounded-full bg-brand-100/60" />
        <div class="relative flex items-center gap-4 sm:gap-6">
          <img v-if="user.avatar_url" :src="user.avatar_url" alt="" class="h-16 w-16 shrink-0 rounded-2xl object-cover ring-4 ring-white sm:h-20 sm:w-20" />
          <div v-else class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-brand-600 text-2xl font-extrabold text-white shadow-lg shadow-brand-200/50 sm:h-20 sm:w-20">{{ user.name?.charAt(0)?.toUpperCase() }}</div>
          <div class="min-w-0"><p class="mb-1 text-xs font-bold uppercase tracking-widest text-brand-600">Tu espacio en MOVA</p><h2 class="break-words text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">{{ user.name }}</h2><p class="mt-2 text-sm text-slate-500">Tus datos, tu seguridad y tus decisiones, en un solo lugar.</p></div>
        </div>
      </header>
      <div role="tablist" aria-label="Secciones de mi perfil" class="flex gap-1 rounded-2xl border border-white bg-white/60 p-1.5 shadow-sm">
        <button v-for="(tab, index) in tabs" :key="tab.id" :id="`profile-tab-${tab.id}`" role="tab" :aria-selected="active === tab.id" :aria-controls="`profile-panel-${tab.id}`" :tabindex="active === tab.id ? 0 : -1" @click="active = tab.id" @keydown="moveTab($event, index)" :class="active === tab.id ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-600 hover:bg-white'" class="flex min-h-12 flex-1 items-center justify-center gap-2 rounded-xl px-2 py-2.5 text-xs font-bold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 motion-reduce:transition-none sm:text-sm"><Icon :name="tab.icon" :size="17" class="hidden sm:block" />{{ tab.label }}</button>
      </div>
      <section v-show="active === 'personal'" id="profile-panel-personal" role="tabpanel" aria-labelledby="profile-tab-personal" class="space-y-5">
        <div class="profile-card"><UpdateAvatarForm /></div>
        <div class="profile-card"><UpdateProfileInformationForm :must-verify-email="mustVerifyEmail" :status="status" /></div>
        <Link v-if="user.roles.includes('teacher')" :href="route('teacher.profile')" class="flex items-center justify-between gap-4 rounded-2xl border border-brand-200 bg-brand-50 p-5 text-brand-900 focus-visible:ring-2 focus-visible:ring-brand-500"><div><h3 class="font-bold">Tu perfil como profesor</h3><p class="mt-1 text-sm">Materias, presentación y disponibilidad. Conservamos el proceso de verificación de MOVA.</p></div><span aria-hidden="true">→</span></Link>
        <p v-else class="px-1 text-sm text-slate-500">Los datos de tus alumnos se gestionan en sus secciones del dashboard, separados de tu información personal.</p>
      </section>
      <section v-show="active === 'security'" id="profile-panel-security" role="tabpanel" aria-labelledby="profile-tab-security" class="space-y-5">
        <div class="profile-card"><div class="flex items-start gap-3"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><Icon name="lock" :size="20" /></span><div><h2 class="font-bold text-slate-900">Tu acceso, protegido</h2><p class="mt-1 break-all text-sm text-slate-600">{{ user.email }}</p><p class="mt-2 text-xs font-semibold" :class="user.email_verified ? 'text-emerald-700' : 'text-amber-700'">{{ user.email_verified ? 'Correo verificado' : 'Correo pendiente de verificación' }}</p><button class="mt-3 text-sm font-bold text-brand-600 underline underline-offset-4" @click="goToEmail">Administrar mi correo</button></div></div></div>
        <div class="profile-card"><UpdatePasswordForm /><p class="mt-5 border-t border-slate-100 pt-4 text-sm text-slate-500">Al cambiar tu contraseña, las otras sesiones deberán iniciar sesión de nuevo. Si accedes con Google y no tienes contraseña, puedes usar «Olvidé mi contraseña» al iniciar sesión.</p></div>
      </section>
      <section v-show="active === 'privacy'" id="profile-panel-privacy" role="tabpanel" aria-labelledby="profile-tab-privacy" class="space-y-5">
        <div class="profile-card"><NotificationPreferencesForm /></div>
        <div class="profile-card"><h2 class="text-lg font-bold text-slate-900">Tú decides sobre tus datos</h2><p class="mt-2 text-sm leading-relaxed text-slate-600">Usamos tu información para gestionar tu cuenta y tus clases. Puedes consultar las condiciones y contactar con soporte para ejercer tus derechos sobre tus datos personales.</p><div class="mt-4 flex flex-wrap gap-x-5 gap-y-3 text-sm font-bold text-brand-600"><a :href="route('legal.privacy')" target="_blank" rel="noopener">Política de Privacidad ↗</a><a :href="route('legal.terms')" target="_blank" rel="noopener">Términos y Condiciones ↗</a><a :href="`mailto:${$page.props.support.email}`">Contactar con soporte</a></div></div>
        <div v-if="canRequestClosure" class="profile-card border-rose-100"><DeleteUserForm :summary="accountSummary" :status="status" /></div>
      </section>
    </div>
  </AppLayout>
</template>
<style scoped>.profile-card { @apply rounded-2xl border border-slate-100 bg-white p-5 shadow-sm sm:p-7; }</style>
