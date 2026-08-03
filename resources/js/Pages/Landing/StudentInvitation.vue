<template>
  <Head title="Aprende con los mejores – MOVA" />
  <div class="min-h-screen bg-white">
    <!-- Minimal navbar -->
    <nav class="fixed top-0 inset-x-0 z-50 bg-white border-b border-gray-100">
      <div class="max-w-5xl mx-auto px-4 h-14 flex items-center justify-between">
        <Link href="/" class="flex items-center">
          <MovaLogo class="h-7 w-auto" />
        </Link>
        <Link :href="route('login')" class="text-sm text-slate-500 hover:text-brand-600 transition-colors">¿Ya tienes cuenta? Inicia sesión</Link>
      </div>
    </nav>

    <!-- Hero -->
    <section class="pt-28 pb-20 bg-gradient-to-br from-indigo-900 via-brand-800 to-brand-700 relative overflow-hidden">
      <div class="absolute inset-0">
        <div class="shape s1"></div>
        <div class="shape s2"></div>
      </div>
      <div class="relative max-w-5xl mx-auto px-4 text-center">
        <div class="inline-flex items-center gap-2 px-4 py-2 bg-white/10 border border-white/20 rounded-full text-white/90 text-sm font-medium mb-8">
          🎓 Para estudiantes y familias
        </div>
        <h1 class="text-5xl sm:text-6xl lg:text-7xl font-black text-white mb-6 leading-tight">
          Aprende con el profesor <span class="text-transparent bg-clip-text bg-gradient-to-r from-green-300 to-cyan-300">perfecto para ti</span>
        </h1>
        <p class="text-xl text-white/80 max-w-2xl mx-auto mb-10 leading-relaxed">
          Encuentra tu profesor particular ideal, reserva clases online 1 a 1 y aprende a tu ritmo por videollamada.
        </p>
        <div class="flex flex-col sm:flex-row gap-4 justify-center">
          <Link :href="route('marketplace')"
            class="px-8 py-4 bg-white text-brand-800 font-black text-lg rounded-2xl hover:bg-brand-50 transition-all shadow-2xl shadow-black/20 hover:scale-105">
            Buscar clases ahora
          </Link>
          <Link href="/register?role=parent"
            class="px-8 py-4 bg-white/10 text-white font-bold text-lg rounded-2xl hover:bg-white/20 border border-white/30 transition-all hover:scale-105">
            Registrarme gratis
          </Link>
        </div>
        <p class="text-white/50 text-sm mt-5">Sin permanencia · Primera consulta gratuita</p>
      </div>
      <div class="absolute bottom-0 left-0 right-0">
        <svg viewBox="0 0 1440 60" fill="none" class="w-full"><path d="M0 60L1440 60L1440 20C1200 0 960 0 720 15C480 30 240 55 0 30Z" fill="white"/></svg>
      </div>
    </section>

    <!-- Video placeholder -->
    <section class="py-20 bg-white">
      <div class="max-w-3xl mx-auto px-4 text-center">
        <h2 class="text-3xl font-black text-brand-900 mb-3">¿Cómo funciona para ti?</h2>
        <p class="text-slate-500 mb-8">Mira este vídeo y descubre lo fácil que es aprender con MOVA</p>
        <div class="relative aspect-video bg-gradient-to-br from-indigo-900 to-brand-700 rounded-2xl overflow-hidden shadow-2xl cursor-pointer group">
          <div class="absolute inset-0 flex items-center justify-center">
            <div class="w-20 h-20 bg-white/20 hover:bg-white/30 rounded-full flex items-center justify-center transition-all group-hover:scale-110 shadow-xl">
              <svg class="w-8 h-8 text-white ml-1" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
            </div>
          </div>
          <div class="absolute bottom-6 left-6 right-6">
            <p class="text-white/70 text-sm">🎬 Video explicativo: Cómo encontrar tu profesor ideal en MOVA</p>
          </div>
        </div>
      </div>
    </section>

    <!-- Steps -->
    <section class="py-20 bg-slate-50">
      <div class="max-w-5xl mx-auto px-4">
        <div class="text-center mb-14">
          <h2 class="text-3xl font-black text-brand-900">Tan fácil como 1, 2, 3</h2>
        </div>
        <div class="grid md:grid-cols-3 gap-8">
          <div v-for="(step, i) in steps" :key="i"
            class="bg-white rounded-2xl p-7 border border-gray-100 hover:border-brand-200 hover:shadow-lg transition-all text-center">
            <div class="w-14 h-14 bg-brand-600 text-white rounded-xl flex items-center justify-center font-black text-xl mx-auto mb-4 shadow-lg shadow-brand-600/30">
              {{ step.icon }}
            </div>
            <h3 class="font-bold text-brand-900 text-lg mb-2">{{ step.title }}</h3>
            <p class="text-slate-500 text-sm leading-relaxed">{{ step.desc }}</p>
          </div>
        </div>
      </div>
    </section>

    <!-- Benefits -->
    <section class="py-20 bg-white">
      <div class="max-w-5xl mx-auto px-4">
        <div class="text-center mb-14">
          <h2 class="text-3xl font-black text-brand-900">¿Por qué MOVA?</h2>
        </div>
        <div class="grid md:grid-cols-2 gap-5">
          <div v-for="b in benefits" :key="b.title"
            class="flex items-start gap-4 p-5 rounded-xl border border-gray-100 hover:border-brand-200 hover:bg-brand-50/50 transition-all">
            <div class="text-2xl flex-shrink-0 mt-0.5">{{ b.icon }}</div>
            <div>
              <h4 class="font-bold text-slate-900 mb-1">{{ b.title }}</h4>
              <p class="text-slate-500 text-sm">{{ b.desc }}</p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- CTA Final -->
    <section class="py-20 bg-gradient-to-br from-indigo-900 to-brand-700">
      <div class="max-w-2xl mx-auto px-4 text-center">
        <h2 class="text-4xl font-black text-white mb-4">Empieza a aprender hoy</h2>
        <p class="text-white/70 mb-8">Miles de estudiantes ya aprenden con MOVA. ¿Te unes?</p>
        <div class="flex flex-col sm:flex-row gap-4 justify-center">
          <Link :href="route('marketplace')" class="px-8 py-4 bg-white text-brand-800 font-black rounded-2xl hover:bg-brand-50 transition-all shadow-xl hover:scale-105">
            Ver profesores disponibles
          </Link>
          <Link href="/register?role=parent" class="px-8 py-4 bg-white/10 text-white font-bold rounded-2xl hover:bg-white/20 border border-white/30 transition-all">
            Registrarme gratis
          </Link>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { Head, Link } from '@inertiajs/vue3'
import MovaLogo from '@/Components/MovaLogo.vue'

const steps = [
  { icon: '🔍', title: 'Elige tu materia', desc: 'Explora nuestro catálogo de materias y niveles. Filtra por precio, disponibilidad y valoraciones.' },
  { icon: '📝', title: 'Solicita un profesor', desc: 'Envía una solicitud explicando lo que necesitas. El profesor propone un horario y lo confirmas.' },
  { icon: '🎥', title: 'Aprende por videollamada', desc: 'Entra a la videollamada con un clic. El enlace se genera automáticamente para cada clase.' },
]

const benefits = [
  { icon: '✅', title: 'Profesores verificados', desc: 'Todos los profesores pasan por un proceso de verificación antes de publicar clases.' },
  { icon: '⏱️', title: 'Horarios flexibles', desc: 'Clases de mañana, tarde o noche. Adapta el aprendizaje a tu agenda.' },
  { icon: '💻', title: '100% online', desc: 'No tienes que desplazarte. Aprende desde casa con la misma calidad que en persona.' },
  { icon: '👨‍👩‍👧', title: 'Control parental', desc: 'Los padres pueden supervisar y aprobar las solicitudes de clase de sus hijos.' },
]
</script>

<style scoped>
.shape { position: absolute; opacity: 0.1; animation: floatS 7s ease-in-out infinite; border-radius: 50%; }
.s1 { width: 200px; height: 200px; background: white; top: 10%; right: 5%; animation-delay: 0s; }
.s2 { width: 100px; height: 100px; background: white; bottom: 20%; left: 5%; animation-delay: 2s; }
@keyframes floatS { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-20px); } }
</style>
