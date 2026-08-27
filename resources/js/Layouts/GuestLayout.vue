<template>
  <Head :title="title" />
  <div class="min-h-screen bg-white lg:grid lg:grid-cols-2">
    <!-- Panel de marca: solo desktop. Mismo lenguaje del hero de Welcome.vue
         (gradiente brand-900→700, grid decorativo sutil) para que llegar al
         login se sienta como parte de MOVA, no como un formulario genérico
         de terceros. En mobile/tablet este panel desaparece entero — ahí la
         confianza la carga el logo + el propio formulario, no hace falta
         duplicar la superficie de marca. -->
    <div class="hidden lg:flex flex-col justify-between relative overflow-hidden bg-gradient-to-br from-brand-900 via-brand-800 to-brand-700 px-12 py-10">
      <div class="absolute inset-0 opacity-10" style="background-image: linear-gradient(rgba(255,255,255,.1) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.1) 1px,transparent 1px);background-size:60px 60px"></div>
      <Link href="/" class="relative flex items-center">
        <MovaLogo theme="blanco" class="h-9 w-auto" />
      </Link>

      <!-- Ilustración: varía según el rol (?role= en registro; ausente en
           login, donde no hay forma de saberlo de antemano). SVG propio, no
           foto de stock — sin licencia que gestionar, coherente con el
           MovaLogo.vue inline que ya usa este mismo layout. -->
      <div class="relative max-w-sm w-full mx-auto px-2">
        <component :is="illustration" />
      </div>

      <div class="relative max-w-sm">
        <p class="text-2xl font-black text-white leading-snug mb-3 text-balance">{{ copy.title }}</p>
        <p class="text-white/70 text-sm leading-relaxed text-pretty">{{ copy.subtitle }}</p>
      </div>
      <p class="relative text-white/40 text-xs">© {{ year }} MOVA</p>
    </div>

    <!-- Panel de formulario -->
    <div class="flex flex-col min-h-screen lg:min-h-0">
      <header class="lg:hidden flex items-center px-4 sm:px-8 py-4 border-b border-gray-100">
        <Link href="/" class="flex items-center">
          <MovaLogo class="h-8 w-auto" />
        </Link>
      </header>

      <!-- Flash messages -->
      <div v-if="flash.success || flash.error" class="px-4 sm:px-8 pt-6">
        <div v-if="flash.success" class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl text-sm flex items-center gap-2">
          <Icon name="flash-success" :size="18" label="Éxito" class="flex-shrink-0" /> {{ flash.success }}
        </div>
        <div v-if="flash.error" class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl text-sm flex items-center gap-2">
          <Icon name="flash-error" :size="18" label="Error" class="flex-shrink-0" /> {{ flash.error }}
        </div>
      </div>

      <main class="flex-1 flex items-center justify-center px-4 sm:px-8 py-10">
        <div class="w-full" :class="maxWidth">
          <slot />
        </div>
      </main>

      <footer class="px-4 sm:px-8 py-6 flex flex-wrap gap-4 justify-center text-xs text-gray-400">
        <Link :href="route('legal.terms')" class="hover:text-brand-600 transition-colors">Términos y Condiciones</Link>
        <Link :href="route('legal.privacy')" class="hover:text-brand-600 transition-colors">Política de Privacidad</Link>
        <a href="mailto:m0v4class@gmail.com" class="hover:text-brand-600 transition-colors">Soporte</a>
        <span class="text-gray-300">·</span>
        <span>© {{ year }} MOVA. Todos los derechos reservados.</span>
      </footer>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import MovaLogo from '@/Components/MovaLogo.vue'
import Icon from '@/Components/Icon.vue'
import TeacherIllustration from '@/Components/Illustrations/TeacherIllustration.vue'
import FamilyIllustration from '@/Components/Illustrations/FamilyIllustration.vue'

const props = defineProps({
  title: String,
  maxWidth: { type: String, default: 'max-w-sm' },
  // 'teacher' | 'parent' | null — quien nos llama (Register.vue con
  // ?role=, o su propia selección en el paso 1) decide esto; Login.vue no
  // lo pasa porque no hay forma de saber el rol antes de autenticar.
  role: { type: String, default: null },
})

const flash = computed(() => usePage().props.flash ?? {})
const year = new Date().getFullYear()

const illustration = computed(() => props.role === 'teacher' ? TeacherIllustration : FamilyIllustration)

// Tres variantes, no dos: `role` distingue "vengo de Register.vue con un
// rol ya elegido" (teacher/parent) de "vengo de Login.vue, sin rol" (null)
// — antes login y registro-padre compartían el mismo texto, que además
// describía un mecanismo que ya no existe ("tú decides quién enseña" — el
// padre ya no elige profesor directamente, ver HANDOFF_FINAL.md §21).
const copy = computed(() => {
  if (props.role === 'teacher') {
    return {
      title: 'Únete al equipo de profesores mejor respaldado.',
      subtitle: 'Tú dedícate a enseñar, MOVA se encarga de llenarte la agenda.',
    }
  }
  if (props.role === 'parent') {
    return {
      title: 'Clases particulares en vivo, con profesores verificados.',
      subtitle: 'Envía tu solicitud y el primer profesor disponible de la materia te contacta directamente.',
    }
  }
  // Login.vue: sirve a padres y profesores por igual, sin saber cuál antes
  // de autenticar — copy neutro, sin hablar solo del padre.
  return {
    title: 'Profesores particulares verificados, en vivo por videollamada.',
    subtitle: 'Inicia sesión para gestionar tus clases, solicitudes o tu perfil.',
  }
})
</script>
