<template>
  <Head title="Únete como profesor – MOVA" />
  <div class="min-h-screen bg-white">
    <!-- Minimal navbar -->
    <nav class="fixed top-0 inset-x-0 z-50 bg-white border-b border-gray-100">
      <div class="max-w-5xl mx-auto px-4 h-14 flex items-center justify-between">
        <Link href="/" class="flex items-center gap-2">
          <div class="w-7 h-7 bg-gradient-to-br from-brand-600 to-brand-800 rounded-lg flex items-center justify-center">
            <span class="text-white font-black text-xs">M</span>
          </div>
          <span class="font-black text-lg text-brand-900">MOVA</span>
        </Link>
        <Link :href="route('login')" class="text-sm text-slate-500 hover:text-brand-600 transition-colors">¿Ya tienes cuenta? Inicia sesión</Link>
      </div>
    </nav>

    <!-- Hero -->
    <section class="pt-28 pb-20 bg-gradient-to-br from-brand-900 via-brand-800 to-indigo-900 relative overflow-hidden">
      <div class="absolute inset-0">
        <div class="shape s1"></div>
        <div class="shape s2"></div>
        <div class="shape s3"></div>
      </div>
      <div class="relative max-w-5xl mx-auto px-4 text-center">
        <div class="inline-flex items-center gap-2 px-4 py-2 bg-white/10 border border-white/20 rounded-full text-white/90 text-sm font-medium mb-8">
          👨‍🏫 Para profesores
        </div>
        <h1 class="text-5xl sm:text-6xl lg:text-7xl font-black text-white mb-6 leading-tight">
          Enseña en línea y <span class="text-transparent bg-clip-text bg-gradient-to-r from-yellow-300 to-orange-300">gana dinero</span> desde casa
        </h1>
        <p class="text-xl text-white/80 max-w-2xl mx-auto mb-10 leading-relaxed">
          Únete a MOVA como profesor particular. Tú pones el precio, el horario y las materias. Nosotros te conectamos con estudiantes que te necesitan.
        </p>
        <Link href="/register?role=teacher"
          class="inline-flex items-center gap-3 px-10 py-5 bg-white text-brand-800 font-black text-lg rounded-2xl hover:bg-brand-50 transition-all shadow-2xl shadow-black/20 hover:scale-105 active:scale-95">
          Registrarme como profesor gratis
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
        </Link>
        <p class="text-white/50 text-sm mt-4">Sin cuota de inscripción · Sin permanencia</p>
      </div>
      <div class="absolute bottom-0 left-0 right-0">
        <svg viewBox="0 0 1440 60" fill="none" class="w-full"><path d="M0 60L1440 60L1440 20C1200 0 960 0 720 15C480 30 240 55 0 30Z" fill="white"/></svg>
      </div>
    </section>

    <!-- Video placeholder -->
    <section class="py-20 bg-white">
      <div class="max-w-3xl mx-auto px-4 text-center">
        <h2 class="text-3xl font-black text-brand-900 mb-3">Descubre cómo funciona</h2>
        <p class="text-slate-500 mb-8">Un vídeo explicativo de cómo MOVA funciona para profesores</p>
        <div class="relative aspect-video bg-gradient-to-br from-brand-900 to-brand-700 rounded-2xl overflow-hidden shadow-2xl shadow-brand-900/20 cursor-pointer group">
          <div class="absolute inset-0 flex items-center justify-center">
            <div class="w-20 h-20 bg-white/20 hover:bg-white/30 rounded-full flex items-center justify-center transition-all group-hover:scale-110 shadow-xl">
              <svg class="w-8 h-8 text-white ml-1" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
            </div>
          </div>
          <div class="absolute bottom-6 left-6 right-6">
            <p class="text-white/70 text-sm">🎬 Video explicativo: Cómo funciona MOVA para profesores</p>
          </div>
          <!-- Decorative -->
          <div class="absolute top-4 right-4 flex gap-1">
            <div class="w-3 h-3 rounded-full bg-red-400"></div>
            <div class="w-3 h-3 rounded-full bg-yellow-400"></div>
            <div class="w-3 h-3 rounded-full bg-green-400"></div>
          </div>
        </div>
      </div>
    </section>

    <!-- Features -->
    <section class="py-20 bg-slate-50">
      <div class="max-w-5xl mx-auto px-4">
        <div class="text-center mb-14">
          <h2 class="text-3xl font-black text-brand-900">¿Por qué enseñar en MOVA?</h2>
        </div>
        <div class="grid md:grid-cols-3 gap-6">
          <div v-for="f in features" :key="f.title"
            class="bg-white rounded-2xl p-7 border border-gray-100 hover:border-brand-200 hover:shadow-lg transition-all">
            <div class="text-4xl mb-4">{{ f.icon }}</div>
            <h3 class="text-lg font-bold text-brand-900 mb-2">{{ f.title }}</h3>
            <p class="text-slate-500 text-sm leading-relaxed">{{ f.desc }}</p>
          </div>
        </div>
      </div>
    </section>

    <!-- Steps -->
    <section class="py-20 bg-white">
      <div class="max-w-4xl mx-auto px-4">
        <div class="text-center mb-14">
          <h2 class="text-3xl font-black text-brand-900">Empieza en 3 pasos</h2>
        </div>
        <div class="space-y-6">
          <div v-for="(step, i) in steps" :key="i"
            class="flex items-start gap-6 bg-slate-50 rounded-2xl p-6 border border-gray-100">
            <div class="w-12 h-12 bg-brand-600 text-white rounded-xl flex items-center justify-center font-black text-lg flex-shrink-0 shadow-lg shadow-brand-600/30">
              {{ i + 1 }}
            </div>
            <div>
              <h3 class="font-bold text-brand-900 text-lg">{{ step.title }}</h3>
              <p class="text-slate-500 text-sm mt-1">{{ step.desc }}</p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Final CTA -->
    <section class="py-20 bg-gradient-to-br from-brand-900 to-brand-700">
      <div class="max-w-2xl mx-auto px-4 text-center">
        <h2 class="text-4xl font-black text-white mb-4">¿Empezamos?</h2>
        <p class="text-white/70 mb-8">Regístrate gratis en menos de 2 minutos y empieza a dar clases esta semana.</p>
        <Link href="/register?role=teacher"
          class="inline-flex items-center gap-3 px-10 py-5 bg-white text-brand-800 font-black text-lg rounded-2xl hover:bg-brand-50 transition-all shadow-xl hover:scale-105">
          Registrarme como profesor
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
        </Link>
      </div>
    </section>
  </div>
</template>

<script setup>
import { Head, Link } from '@inertiajs/vue3'

const features = [
  { icon: '🗓️', title: 'Elige tu horario', desc: 'Tú decides cuándo y cuánto enseñas. Total flexibilidad para compatibilizar con tu vida.' },
  { icon: '💰', title: 'Gana dinero enseñando', desc: 'Establece tu propia tarifa por hora y recibe pagos de forma segura. Sin intermediarios.' },
  { icon: '🎥', title: 'Clases 1 a 1 por videollamada', desc: 'Todas las clases son en vivo, individuales y por videollamada. El enlace se genera automáticamente.' },
]

const steps = [
  { title: 'Crea tu perfil', desc: 'Regístrate, añade tu bio, materias que impartes y tu tarifa por hora.' },
  { title: 'Espera solicitudes o publica ofertas', desc: 'Los estudiantes te contactan directamente o tú puedes publicar anuncios de clases disponibles.' },
  { title: 'Da tu primera clase', desc: 'Acepta la solicitud, acuerda el horario y conéctate por videollamada. ¡Así de fácil!' },
]
</script>

<style scoped>
.shape { position: absolute; opacity: 0.1; animation: floatS 7s ease-in-out infinite; border-radius: 16px; }
.s1 { width: 100px; height: 100px; background: white; top: 20%; right: 10%; animation-delay: 0s; }
.s2 { width: 60px; height: 60px; background: white; top: 60%; left: 8%; border-radius: 50%; animation-delay: 3s; }
.s3 { width: 140px; height: 140px; background: white; bottom: 25%; right: 20%; animation-delay: 1.5s; transform: rotate(45deg); }
@keyframes floatS { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-20px); } }
</style>
