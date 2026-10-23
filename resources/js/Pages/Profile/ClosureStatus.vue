<script setup>
import { Head, Link } from '@inertiajs/vue3'
import MovaLogo from '@/Components/MovaLogo.vue'
defineProps({ receipt: Object })
</script>
<template>
  <Head title="Estado de mi solicitud" />
  <main class="min-h-screen bg-slate-50 px-4 py-10 sm:py-16"><div class="mx-auto max-w-2xl"><Link href="/" aria-label="MOVA"><MovaLogo variant="imagotipo" class="mb-8 h-9 w-auto" /></Link><section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-9">
    <p class="text-xs font-bold uppercase tracking-widest text-brand-600">Privacidad y cuenta</p><h1 class="mt-3 text-2xl font-extrabold text-slate-900">{{ receipt.status === 'responded' ? 'Tienes una respuesta' : 'Recibimos tu solicitud' }}</h1>
    <p class="mt-4 leading-relaxed text-slate-600">Tu acceso habitual está cerrado. La eliminación de tus datos sigue pendiente de revisión: no hemos borrado automáticamente alumnos, clases, créditos ni operaciones.</p>
    <dl class="my-6 rounded-2xl bg-slate-50 p-4 text-sm"><dt class="text-slate-500">Número de solicitud</dt><dd class="mt-1 break-all font-semibold text-slate-900">{{ receipt.reference }}</dd><dt class="mt-3 text-slate-500">Tipo</dt><dd class="mt-1 font-semibold text-slate-900">Solicitud de eliminación de cuenta y de datos personales</dd><dt class="mt-3 text-slate-500">Fecha de la solicitud</dt><dd class="mt-1 font-semibold text-slate-900"><time :datetime="receipt.created_at">{{ new Date(receipt.created_at).toLocaleDateString('es-PE', { day: 'numeric', month: 'long', year: 'numeric' }) }}</time></dd><dt class="mt-3 text-slate-500">Estado</dt><dd class="mt-1 font-semibold text-brand-700">{{ receipt.status === 'responded' ? 'Respuesta disponible · cuenta cerrada' : 'Pendiente de revisión · cuenta cerrada' }}</dd></dl>
    <div v-if="receipt.response" class="mb-6 whitespace-pre-wrap rounded-2xl border border-brand-100 bg-brand-50 p-4 text-sm leading-relaxed text-brand-900">{{ receipt.response }}</div>
    <p class="mb-3 text-sm font-semibold text-slate-900">Siguiente paso: {{ receipt.status === 'responded' ? 'lee la respuesta de arriba y escríbenos si necesitas aclarar algo.' : 'nuestro equipo revisará tu caso; no necesitas hacer nada más.' }}</p><p class="text-sm leading-relaxed text-slate-600">El plazo legal general de respuesta es de 10 días hábiles desde el día siguiente de tu solicitud. Te explicaremos qué datos pueden suprimirse y cuáles deben conservarse, con su justificación. Guarda este número; puedes volver a iniciar sesión para consultar el estado.</p>
    <a :href="`mailto:${$page.props.support.email}?subject=Solicitud%20de%20eliminación%20${receipt.reference}`" class="mt-6 inline-flex min-h-11 items-center rounded-xl bg-brand-600 px-5 font-bold text-white">Contactar con soporte</a>
    <div class="mt-6 flex flex-wrap gap-5 text-sm font-semibold text-brand-700"><a :href="route('legal.privacy')">Política de Privacidad</a><Link v-if="$page.props.auth.user" :href="route('logout')" method="post" as="button">Cerrar sesión</Link><Link v-else :href="route('login')">Iniciar sesión</Link></div>
  </section></div></main>
</template>
