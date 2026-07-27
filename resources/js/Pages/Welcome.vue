<template>
  <Head title="MOVA – Conectamos estudiantes con profesores" />

  <div class="min-h-screen bg-white">
    <LandingNavbar />

    <!-- ── Hero ──────────────────────────────────────────────────────────── -->
    <section class="relative min-h-screen flex items-center overflow-hidden bg-gradient-to-br from-brand-900 via-brand-800 to-brand-700">
      <!-- Animated background shapes -->
      <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
        <div class="shape shape-4"></div>
        <!-- Grid overlay -->
        <div class="absolute inset-0 opacity-10" style="background-image: linear-gradient(rgba(255,255,255,.1) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.1) 1px,transparent 1px);background-size:60px 60px"></div>
      </div>

      <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-24 pb-20">
        <div class="grid lg:grid-cols-2 gap-16 items-center">
          <div class="text-white">
            <div class="hero-reveal inline-flex items-center gap-2 px-3 py-1.5 bg-white/10 backdrop-blur rounded-full text-sm font-medium mb-6 border border-white/20">
              <span class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></span>
              Plataforma educativa en vivo
            </div>
            <h1 class="hero-reveal text-5xl sm:text-6xl lg:text-7xl font-black leading-none mb-6">
              <span class="block">Aprende con</span>
              <span class="block text-transparent bg-clip-text bg-gradient-to-r from-brand-300 to-cyan-300">los mejores</span>
              <span class="block">profesores</span>
            </h1>
            <p class="hero-reveal text-xl text-white/80 leading-relaxed mb-10 max-w-lg">
              Conectamos estudiantes con profesores particulares expertos para clases en vivo por videollamada. Personalizado, flexible y efectivo.
            </p>
            <div class="hero-reveal flex flex-wrap gap-4">
              <Link :href="user ? route('dashboard') : route('marketplace')"
                class="px-8 py-4 bg-white text-brand-800 font-bold rounded-xl hover:bg-brand-50 transition-all shadow-xl shadow-black/20 hover:scale-105 active:scale-95">
                {{ user ? 'Ir a mi dashboard' : 'Buscar clases' }}
              </Link>
              <Link :href="user ? route('dashboard') : '/register?role=teacher'"
                class="px-8 py-4 bg-white/10 text-white font-bold rounded-xl hover:bg-white/20 transition-all border border-white/30 backdrop-blur hover:scale-105 active:scale-95">
                {{ user ? 'Ver mis clases' : 'Soy profesor' }}
              </Link>
            </div>

            <div class="hero-reveal grid grid-cols-2 sm:grid-cols-4 gap-y-6 gap-x-4 mt-12 pt-8 border-t border-white/10">
              <div class="text-center sm:text-left">
                <p class="text-3xl font-black text-white">{{ stats.teachers }}+</p>
                <p class="text-sm text-white/60">Profesores</p>
              </div>
              <div class="text-center sm:text-left">
                <p class="text-3xl font-black text-white">{{ subjects.length }}</p>
                <p class="text-sm text-white/60">Materias</p>
              </div>
              <div class="text-center sm:text-left">
                <p class="text-3xl font-black text-white">{{ stats.students }}+</p>
                <p class="text-sm text-white/60">Familias</p>
              </div>
              <div class="text-center sm:text-left">
                <p class="text-3xl font-black text-white">{{ stats.completed }}+</p>
                <p class="text-sm text-white/60">Clases dictadas</p>
              </div>
            </div>
          </div>

          <!-- 3D Card visual -->
          <div class="hero-reveal hidden lg:flex justify-center items-center perspective-1000">
            <div class="relative w-80 h-96 transform rotate-y-6 hover:rotate-y-0 transition-transform duration-700" style="transform-style: preserve-3d; transform: perspective(1000px) rotateY(-8deg) rotateX(4deg);">
              <div class="absolute inset-0 bg-white/10 backdrop-blur-xl rounded-3xl border border-white/20 shadow-2xl p-6 flex flex-col gap-4">
                <div class="flex items-center gap-3">
                  <div class="w-12 h-12 bg-gradient-to-br from-brand-400 to-brand-600 rounded-full flex items-center justify-center text-white font-bold text-lg">A</div>
                  <div>
                    <p class="text-white font-semibold">Ana García</p>
                    <p class="text-white/60 text-sm">Profesora de Matemáticas</p>
                  </div>
                  <div class="ml-auto">
                    <span class="px-2 py-1 bg-green-400/20 text-green-300 text-xs rounded-full font-medium">Verificada ✓</span>
                  </div>
                </div>
                <div class="flex gap-1">
                  <span v-for="i in 5" :key="i" class="text-yellow-400 text-lg">★</span>
                </div>
                <p class="text-white/70 text-sm leading-relaxed">"Las clases con MOVA son increíbles. Mejoro mi nivel cada semana con mi profesor personalizado."</p>
                <div class="mt-auto bg-white/10 rounded-xl p-4">
                  <p class="text-white/60 text-xs mb-1">Próxima clase</p>
                  <div class="flex items-center gap-2">
                    <div class="w-6 h-6 bg-brand-500 rounded-full flex items-center justify-center">
                      <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h6a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/><path d="M14 6a2 2 0 012-2h2a2 2 0 012 2v8a2 2 0 01-2 2h-2a2 2 0 01-2-2V6z"/></svg>
                    </div>
                    <p class="text-white text-sm font-medium">Álgebra – Hoy 18:00h</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Wave -->
      <div class="absolute bottom-0 left-0 right-0">
        <svg viewBox="0 0 1440 80" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-full">
          <path d="M0 80L60 72C120 64 240 48 360 44C480 40 600 48 720 52C840 56 960 56 1080 50C1200 44 1320 32 1380 26L1440 20V80H0Z" fill="white"/>
        </svg>
      </div>
    </section>

    <!-- ── Cómo funciona ───────────────────────────────────────────────── -->
    <section id="como-funciona" class="py-24 bg-white reveal-group">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="reveal-item text-center mb-16">
          <span class="text-sm font-semibold text-brand-600 uppercase tracking-widest">Proceso simple</span>
          <h2 class="text-4xl font-black text-brand-900 mt-2">¿Cómo funciona MOVA?</h2>
          <p class="text-slate-500 mt-3 max-w-xl mx-auto">En tres pasos sencillos estás aprendiendo con tu profesor ideal</p>
        </div>
        <div class="grid md:grid-cols-3 gap-8">
          <div v-for="(step, i) in steps" :key="i"
            class="reveal-item group relative bg-white rounded-2xl p-8 border border-gray-100 hover:border-brand-200 shadow-sm hover:shadow-xl hover:shadow-brand-900/5 transition-all duration-300 hover:-translate-y-2">
            <div class="absolute -top-4 left-8 w-8 h-8 bg-brand-600 text-white rounded-full flex items-center justify-center font-black text-sm shadow-lg shadow-brand-600/30">
              {{ i + 1 }}
            </div>
            <div class="w-14 h-14 bg-brand-50 group-hover:bg-brand-100 rounded-xl flex items-center justify-center mb-5 transition-colors text-2xl">
              {{ step.icon }}
            </div>
            <h3 class="text-xl font-bold text-brand-900 mb-2">{{ step.title }}</h3>
            <p class="text-slate-500 text-sm leading-relaxed">{{ step.desc }}</p>
          </div>
        </div>
      </div>
    </section>

    <!-- ── Materias ────────────────────────────────────────────────────── -->
    <section id="materias" class="py-24 bg-slate-50 reveal-group">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="reveal-item text-center mb-16">
          <span class="text-sm font-semibold text-brand-600 uppercase tracking-widest">Catálogo</span>
          <h2 class="text-4xl font-black text-brand-900 mt-2">Materias disponibles</h2>
          <p class="text-slate-500 mt-3">Encuentra un experto en cualquier área de conocimiento</p>
        </div>
        <div class="reveal-item grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
          <Link v-for="s in subjects" :key="s.id"
            :href="`/marketplace?subject_id=${s.id}`"
            class="group bg-white border border-gray-100 hover:border-brand-300 rounded-xl p-4 text-center hover:shadow-md hover:shadow-brand-900/5 transition-all duration-200 hover:-translate-y-0.5">
            <div class="text-2xl mb-2">{{ subjectEmoji(s.name) }}</div>
            <p class="text-sm font-semibold text-slate-800 group-hover:text-brand-600 transition-colors">{{ s.name }}</p>
            <span :class="['text-xs px-2 py-0.5 rounded-full mt-1 inline-block capitalize', levelColor(s.level)]">{{ s.level }}</span>
          </Link>
        </div>
        <div class="reveal-item text-center mt-10">
          <Link :href="route('marketplace')" class="inline-flex items-center gap-2 px-6 py-3 bg-brand-600 text-white font-semibold rounded-xl hover:bg-brand-700 transition-colors shadow-lg shadow-brand-600/20">
            Ver todos los profesores
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
          </Link>
        </div>
      </div>
    </section>

    <!-- ── Profesores destacados ───────────────────────────────────────── -->
    <section id="profesores" class="py-24 bg-white reveal-group">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="reveal-item flex flex-wrap items-end justify-between gap-6 mb-16">
          <div>
            <span class="text-sm font-semibold text-brand-600 uppercase tracking-widest">Expertos</span>
            <h2 class="text-4xl font-black text-brand-900 mt-2">Profesores destacados</h2>
            <p class="text-slate-500 mt-3">Todos verificados y con experiencia comprobada</p>
          </div>
          <div v-if="featuredTeachers.length" class="hidden sm:flex items-center gap-2">
            <button class="teachers-prev w-11 h-11 rounded-full border border-gray-200 flex items-center justify-center text-slate-500 hover:text-brand-600 hover:border-brand-300 transition-colors">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </button>
            <button class="teachers-next w-11 h-11 rounded-full border border-gray-200 flex items-center justify-center text-slate-500 hover:text-brand-600 hover:border-brand-300 transition-colors">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </button>
          </div>
        </div>

        <div v-if="featuredTeachers.length" class="reveal-item">
          <Swiper
            :modules="[Pagination, Navigation, A11y]"
            :space-between="24"
            :slides-per-view="1.15"
            :breakpoints="{ 640: { slidesPerView: 2, spaceBetween: 24 }, 1024: { slidesPerView: 3, spaceBetween: 28 } }"
            :pagination="{ el: '.teachers-pagination', clickable: true }"
            :navigation="{ prevEl: '.teachers-prev', nextEl: '.teachers-next' }"
            class="teachers-swiper !pb-4"
          >
            <SwiperSlide v-for="t in featuredTeachers" :key="t.id" class="h-auto py-1">
              <div class="group h-full bg-white rounded-2xl border border-gray-100 hover:border-brand-200 p-6 shadow-sm hover:shadow-xl hover:shadow-brand-900/10 transition-all duration-300 hover:-translate-y-1">
                <div class="flex items-start gap-4 mb-4">
                  <div class="w-14 h-14 bg-gradient-to-br from-brand-500 to-brand-700 rounded-full flex items-center justify-center text-white font-black text-xl flex-shrink-0">
                    {{ t.user?.name?.charAt(0) ?? '?' }}
                  </div>
                  <div class="min-w-0">
                    <p class="font-bold text-slate-900 truncate">{{ t.user?.name }}</p>
                    <div v-if="t.avg_rating" class="flex items-center gap-1 mt-0.5">
                      <div class="flex gap-0.5">
                        <span v-for="i in 5" :key="i" class="text-sm" :class="i <= Math.round(t.avg_rating) ? 'text-yellow-400' : 'text-gray-200'">★</span>
                      </div>
                      <span class="text-xs text-slate-400">{{ Number(t.avg_rating).toFixed(1) }}</span>
                    </div>
                    <span v-else class="inline-block mt-0.5 text-xs px-2 py-0.5 bg-brand-50 text-brand-600 rounded-full font-medium">Nuevo en MOVA</span>
                    <p class="text-xs text-slate-400 mt-0.5">{{ t.classes_count }} clases impartidas</p>
                  </div>
                  <span class="ml-auto text-xs px-2 py-1 bg-green-50 text-green-700 rounded-full font-medium border border-green-100 flex-shrink-0">✓ Verificado</span>
                </div>
                <p class="text-sm text-slate-500 mb-4 line-clamp-2">{{ t.bio || 'Profesor experto en MOVA.' }}</p>
                <div class="flex flex-wrap gap-1.5 mb-4">
                  <span v-for="s in (t.subjects || []).slice(0, 3)" :key="s.id"
                    class="text-xs bg-brand-50 text-brand-700 px-2.5 py-1 rounded-full font-medium border border-brand-100">
                    {{ s.name }}
                  </span>
                </div>
                <div class="flex items-center justify-between pt-4 border-t border-gray-50">
                  <span class="font-black text-brand-700 text-lg">S/{{ parseFloat(t.hourly_rate).toFixed(0) }}<span class="text-sm font-normal text-slate-400">/h</span></span>
                  <Link :href="`/marketplace`"
                    class="px-4 py-2 bg-brand-600 text-white text-sm font-semibold rounded-lg hover:bg-brand-700 transition-colors group-hover:shadow-md group-hover:shadow-brand-600/20">
                    Ver perfil
                  </Link>
                </div>
              </div>
            </SwiperSlide>
          </Swiper>
          <div class="teachers-pagination flex justify-center gap-1.5 mt-8"></div>
        </div>
        <div v-else class="reveal-item text-center py-16">
          <p class="text-slate-400">No hay profesores verificados aún. <Link :href="route('landing.teacher')" class="text-brand-600 hover:underline">¿Eres profesor? Únete</Link></p>
        </div>
      </div>
    </section>

    <!-- ── Testimonios ────────────────────────────────────────────────── -->
    <section v-if="testimonials.length" class="py-24 bg-gradient-to-br from-brand-900 to-brand-800 relative overflow-hidden reveal-group">
      <div class="absolute inset-0 opacity-10" style="background-image: radial-gradient(circle at 20% 50%, white 1px, transparent 1px), radial-gradient(circle at 80% 50%, white 1px, transparent 1px); background-size: 30px 30px;"></div>
      <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="reveal-item text-center mb-16">
          <span class="text-sm font-semibold text-brand-300 uppercase tracking-widest">Testimonios</span>
          <h2 class="text-4xl font-black text-white mt-2">Lo que dicen nuestros usuarios</h2>
        </div>
        <div class="grid md:grid-cols-3 gap-6">
          <div v-for="(t, i) in testimonials" :key="i"
            class="reveal-item bg-white/10 backdrop-blur-sm rounded-2xl p-6 border border-white/10 shadow-lg shadow-black/10">
            <div class="flex gap-0.5 mb-4">
              <span v-for="i in 5" :key="i" class="text-yellow-400">★</span>
            </div>
            <p class="text-white/80 text-sm leading-relaxed mb-5">"{{ t.quote }}"</p>
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 bg-gradient-to-br from-brand-400 to-brand-600 rounded-full flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                {{ t.name.charAt(0) }}
              </div>
              <div>
                <p class="text-white font-semibold text-sm">{{ t.name }}</p>
                <p class="text-white/50 text-xs">Padre/madre de familia</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ── CTA Final ──────────────────────────────────────────────────── -->
    <section class="py-24 bg-slate-50 reveal-group">
      <div class="max-w-4xl mx-auto px-4">
        <div class="reveal-item relative overflow-hidden bg-white rounded-3xl border border-gray-100 shadow-xl shadow-brand-900/5 px-8 py-16 text-center">
          <div class="absolute -top-24 -right-24 w-64 h-64 bg-brand-100 rounded-full opacity-40 blur-3xl"></div>
          <div class="absolute -bottom-24 -left-24 w-64 h-64 bg-cyan-100 rounded-full opacity-40 blur-3xl"></div>
          <div class="relative">
            <h2 class="text-4xl font-black text-brand-900 mb-4">¿Listo para empezar?</h2>
            <p class="text-slate-500 text-lg mb-10">Únete a MOVA hoy y transforma tu manera de aprender o enseñar.</p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
              <Link :href="route('register')"
                class="px-8 py-4 bg-brand-600 text-white font-bold rounded-xl hover:bg-brand-700 transition-all shadow-xl shadow-brand-600/25 hover:scale-105">
                Crear cuenta gratis
              </Link>
              <Link :href="route('marketplace')"
                class="px-8 py-4 bg-brand-50 text-brand-700 font-bold rounded-xl hover:bg-brand-100 transition-all border border-brand-200">
                Explorar profesores
              </Link>
            </div>
          </div>
        </div>
      </div>
    </section>

    <LandingFooter />
  </div>
</template>

<script setup>
import { onMounted } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import { Swiper, SwiperSlide } from 'swiper/vue'
import { Pagination, Navigation, A11y } from 'swiper/modules'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import LandingNavbar from '@/Components/LandingNavbar.vue'
import LandingFooter from '@/Components/LandingFooter.vue'
import 'swiper/css'
import 'swiper/css/pagination'
import 'swiper/css/navigation'

const props = defineProps({
  subjects:         { type: Array,  default: () => [] },
  featuredTeachers: { type: Array,  default: () => [] },
  stats:            { type: Object, default: () => ({ teachers: 0, students: 0, completed: 0 }) },
  testimonials:     { type: Array,  default: () => [] },
})

const user = usePage().props.auth?.user

const steps = [
  { icon: '🔍', title: 'Solicita tu clase', desc: 'Elige la materia y el nivel que necesitas. Nuestro sistema encuentra los mejores profesores disponibles para ti.' },
  { icon: '🧑‍🏫', title: 'Un experto acepta', desc: 'Un profesor verificado revisará tu solicitud y propondrá un horario que se adapte a tu disponibilidad.' },
  { icon: '🎓', title: 'Aprende en vivo', desc: 'Conéctate por videollamada y aprende de manera personalizada, con ejercicios y seguimiento en tiempo real.' },
]

function subjectEmoji(name) {
  const map = { 'Matemáticas': '📐', 'Física': '⚡', 'Química': '🧪', 'Inglés': '🇬🇧', 'Francés': '🇫🇷', 'Programación': '💻', 'Historia': '📜', 'Biología': '🔬', 'Música': '🎵', 'Arte': '🎨', 'Economía': '📊', 'Derecho': '⚖️', 'Contabilidad': '🧮', 'Cálculo': '∫', 'Estadística': '📈' }
  return Object.entries(map).find(([k]) => name.includes(k))?.[1] ?? '📚'
}

function levelColor(level) {
  return { primaria: 'bg-green-50 text-green-700', secundaria: 'bg-blue-50 text-blue-700', universidad: 'bg-purple-50 text-purple-700', todos: 'bg-slate-100 text-slate-600' }[level] ?? 'bg-gray-50 text-gray-600'
}

gsap.registerPlugin(ScrollTrigger)

onMounted(() => {
  // Hero: entrada inmediata al cargar, sin depender de scroll.
  gsap.from('.hero-reveal', {
    opacity: 0,
    y: 24,
    duration: 0.8,
    ease: 'power2.out',
    stagger: 0.12,
  })

  // Resto de secciones: fade-in-up con stagger sutil al entrar en viewport.
  document.querySelectorAll('.reveal-group').forEach((group) => {
    const items = group.querySelectorAll('.reveal-item')
    if (!items.length) return

    gsap.from(items, {
      opacity: 0,
      y: 28,
      duration: 0.7,
      ease: 'power2.out',
      stagger: { amount: Math.min(items.length * 0.08, 0.6) },
      scrollTrigger: {
        trigger: group,
        start: 'top 82%',
      },
    })
  })
})
</script>

<style scoped>
.shape {
  position: absolute;
  border-radius: 12px;
  opacity: 0.15;
  animation: floatShape 8s ease-in-out infinite;
}
.shape-1 {
  width: 120px; height: 120px;
  background: linear-gradient(135deg, #60A5FA, #3B82F6);
  top: 15%; right: 15%;
  animation-delay: 0s;
  transform: rotate(20deg);
}
.shape-2 {
  width: 80px; height: 80px;
  background: linear-gradient(135deg, #93C5FD, #60A5FA);
  top: 60%; right: 8%;
  animation-delay: 2s;
  border-radius: 50%;
  transform: rotate(45deg);
}
.shape-3 {
  width: 60px; height: 60px;
  background: linear-gradient(135deg, #BFDBFE, #93C5FD);
  top: 30%; left: 5%;
  animation-delay: 4s;
  transform: rotate(15deg);
}
.shape-4 {
  width: 150px; height: 150px;
  background: linear-gradient(135deg, #1D4ED8, #2563EB);
  bottom: 20%; left: 10%;
  animation-delay: 1s;
  border-radius: 30px;
  transform: rotate(-30deg);
}
@keyframes floatShape {
  0%, 100% { transform: translateY(0) rotate(var(--rot, 20deg)); }
  50% { transform: translateY(-25px) rotate(calc(var(--rot, 20deg) + 10deg)); }
}

/* Swiper — paginación sutil acorde a la paleta de marca */
.teachers-swiper {
  overflow: visible;
}
.teachers-pagination :deep(.swiper-pagination-bullet) {
  width: 6px;
  height: 6px;
  background: theme('colors.brand.100');
  opacity: 1;
  margin: 0 3px !important;
  transition: width 0.25s ease, background-color 0.25s ease;
  border-radius: 999px;
}
.teachers-pagination :deep(.swiper-pagination-bullet-active) {
  width: 22px;
  background: theme('colors.brand.600');
}
</style>
