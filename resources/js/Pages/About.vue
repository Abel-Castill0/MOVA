<template>
  <Head title="Quiénes somos – MOVA" />
  <div class="min-h-screen bg-white">
    <LandingNavbar />

    <!-- Hero -->
    <section class="pt-32 pb-20 bg-gradient-to-br from-brand-900 to-brand-700 relative overflow-hidden">
      <div class="absolute inset-0 opacity-10" style="background-image: linear-gradient(rgba(255,255,255,.1) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.1) 1px,transparent 1px);background-size:60px 60px"></div>
      <div class="relative max-w-4xl mx-auto px-4 text-center text-white">
        <span class="inline-block px-4 py-1.5 bg-white/10 border border-white/20 rounded-full text-sm font-medium mb-6">Sobre nosotros</span>
        <h1 class="text-5xl sm:text-6xl font-black mb-6 leading-tight">
          Dos socios con una <span class="text-transparent bg-clip-text bg-gradient-to-r from-brand-300 to-cyan-300">misión clara</span>
        </h1>
        <p class="text-xl text-white/80 leading-relaxed max-w-2xl mx-auto">
          MOVA nació de la convicción de que cada estudiante merece acceso a un profesor experto, sin importar dónde esté. Somos un equipo pequeño con grandes metas.
        </p>
      </div>
      <!-- -bottom-1 + block: el <svg> es inline por defecto, lo que deja un
           hueco de subpíxel entre su borde inferior y la sección blanca
           siguiente. Ese hueco, sumado al antialiasing de la curva contra el
           azul, podía dibujar una línea gris finísima en la costura,
           especialmente notoria en pantallas de alta densidad (Retina). Se
           solapa 4px en vez de 1px porque el solape cae sobre blanco-sobre-
           blanco: no hay downside visual y da margen en cualquier DPR. -->
      <div class="absolute -bottom-1 left-0 right-0 leading-none">
        <svg viewBox="0 0 1440 60" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-full block"><path d="M0 60L1440 60L1440 30C1200 10 960 0 720 10C480 20 240 50 0 30Z" fill="white"/></svg>
      </div>
    </section>

    <!-- Stats -->
    <section class="py-16 bg-white">
      <div class="max-w-5xl mx-auto px-4">
        <div class="grid grid-cols-3 gap-8 text-center">
          <div v-for="stat in computedStats" :key="stat.label" class="p-6">
            <p class="text-5xl font-black text-brand-700">{{ stat.value }}</p>
            <p class="text-slate-500 mt-2 font-medium">{{ stat.label }}</p>
          </div>
        </div>
      </div>
    </section>

    <!-- Misión Visión Valores -->
    <section class="py-20 bg-slate-50">
      <div class="max-w-6xl mx-auto px-4">
        <div class="text-center mb-14">
          <h2 class="text-3xl font-black text-brand-900">Lo que nos mueve</h2>
        </div>
        <div class="grid md:grid-cols-3 gap-8">
          <div v-for="item in mvv" :key="item.title"
            class="bg-white rounded-2xl p-8 border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
            <div class="text-4xl mb-5">{{ item.icon }}</div>
            <h3 class="text-xl font-bold text-brand-900 mb-3">{{ item.title }}</h3>
            <p class="text-slate-500 text-sm leading-relaxed">{{ item.desc }}</p>
          </div>
        </div>
      </div>
    </section>

    <!-- Founders -->
    <section class="py-20 bg-white">
      <div class="max-w-4xl mx-auto px-4">
        <div class="text-center mb-14">
          <h2 class="text-3xl font-black text-brand-900">Los fundadores</h2>
          <p class="text-slate-500 mt-2">El equipo detrás de MOVA</p>
        </div>
        <div class="grid sm:grid-cols-2 gap-8">
          <div v-for="member in founders" :key="member.name"
            class="group bg-white border border-gray-100 rounded-2xl p-8 hover:border-brand-200 hover:shadow-xl transition-all text-center">
            <div class="w-24 h-24 rounded-2xl flex items-center justify-center text-white font-black text-3xl mx-auto mb-5 shadow-lg group-hover:scale-105 transition-transform"
              :style="{ background: member.color }">
              {{ member.initials }}
            </div>
            <h3 class="font-black text-xl text-slate-900 mb-1">{{ member.name }}</h3>
            <p class="text-brand-600 font-semibold text-sm mb-3">{{ member.role }}</p>
            <p class="text-slate-500 text-sm leading-relaxed">{{ member.desc }}</p>
          </div>
        </div>
      </div>
    </section>

    <!-- Story -->
    <section class="py-20 bg-gradient-to-br from-brand-900 to-brand-700 relative overflow-hidden">
      <div class="absolute inset-0 opacity-10" style="background-image: radial-gradient(circle, white 1px, transparent 1px); background-size: 40px 40px;"></div>
      <div class="relative max-w-3xl mx-auto px-4 text-center">
        <h2 class="text-3xl font-black text-white mb-6">Nuestra historia</h2>
        <p class="text-white/80 text-lg leading-relaxed mb-6">
          Todo empezó con una idea sencilla: los mejores profesores no deberían estar limitados a una ciudad, y los mejores estudiantes no deberían estar limitados por su código postal. MOVA nació para romper esa barrera.
        </p>
        <p class="text-white/70 leading-relaxed">
          Somos Abel y Elias, dos socios que combinamos tecnología y gestión para construir la plataforma de educación personalizada más accesible en español. Cada línea de código y cada decisión operativa está guiada por un solo objetivo: que cada alumno encuentre al profesor perfecto para él.
        </p>
      </div>
    </section>

    <!-- CTA -->
    <section class="py-20 bg-white">
      <div class="max-w-3xl mx-auto px-4 text-center">
        <h2 class="text-3xl font-black text-brand-900 mb-4">Únete a MOVA hoy</h2>
        <p class="text-slate-500 mb-8">Sé parte de la comunidad educativa que está cambiando el futuro del aprendizaje en línea.</p>
        <div class="flex flex-col sm:flex-row gap-4 justify-center">
          <Link :href="route('register')" class="px-8 py-4 bg-brand-600 text-white font-bold rounded-xl hover:bg-brand-700 transition-colors shadow-xl shadow-brand-600/20">
            Crear cuenta gratis
          </Link>
          <Link :href="route('marketplace')" class="px-8 py-4 bg-brand-50 text-brand-700 font-bold rounded-xl hover:bg-brand-100 transition-colors border border-brand-200">
            Explorar profesores
          </Link>
        </div>
      </div>
    </section>

    <LandingFooter />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import LandingNavbar from '@/Components/LandingNavbar.vue'
import LandingFooter from '@/Components/LandingFooter.vue'

const props = defineProps({ stats: Object })

const computedStats = computed(() => [
  { value: props.stats?.teachers ?? '—', label: 'Profesores verificados' },
  { value: props.stats?.classes  ?? '—', label: 'Clases impartidas' },
  { value: props.stats?.students ?? '—', label: 'Familias satisfechas' },
])

const mvv = [
  { icon: '🎯', title: 'Misión', desc: 'Conectar a cada estudiante con el profesor perfecto a través de clases personalizadas en línea, accesibles desde cualquier lugar del mundo.' },
  { icon: '🔭', title: 'Visión', desc: 'Ser la plataforma líder de educación personalizada en español, donde la calidad docente no tenga fronteras geográficas.' },
  { icon: '💎', title: 'Valores', desc: 'Calidad, transparencia y accesibilidad. Creemos que la educación de calidad es un derecho, no un privilegio.' },
]

const founders = [
  {
    name: 'Abel Enrique Castillo Yarin',
    initials: 'AC',
    role: 'Socio Fundador & Desarrollador Principal',
    desc: 'Encargado de toda la plataforma tecnológica de MOVA. Diseña y construye cada funcionalidad para que estudiantes y profesores tengan la mejor experiencia posible.',
    color: 'linear-gradient(135deg,#1D4ED8,#2563EB)',
  },
  {
    name: 'Elias Paz',
    initials: 'EP',
    role: 'Socio Fundador & Director Administrativo',
    desc: 'Encargado de la gestión y operaciones de MOVA. Asegura que cada proceso funcione con eficiencia y que la plataforma crezca de manera sostenible.',
    color: 'linear-gradient(135deg,#0F172A,#1E3A8A)',
  },
]
</script>
