<template>
  <Head>
    <title>MOVA – Conectamos estudiantes con profesores</title>
    <meta name="description" content="Encuentra profesores particulares verificados para clases en vivo por videollamada. Matemáticas, inglés, ciencias y más. Solicita tu clase gratis en minutos." />
    <meta property="og:title" content="MOVA – Conectamos estudiantes con profesores" />
    <meta property="og:description" content="Encuentra profesores particulares verificados para clases en vivo por videollamada. Solicita tu clase gratis en minutos." />
    <meta property="og:type" content="website" />
  </Head>

  <div class="min-h-screen bg-white">
    <LandingNavbar />

    <!-- ── Hero ──────────────────────────────────────────────────────────── -->
    <section class="relative min-h-screen flex items-center overflow-hidden bg-gradient-to-br from-[#051329] via-[#092550] to-[#0D346D]">
      <!-- Background Glowing Hexagonal Grid & Ambient Light Blobs -->
      <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <!-- Ambient radial glow blooms -->
        <div class="absolute -top-32 -left-32 w-96 h-96 bg-brand-500/20 rounded-full blur-[100px]"></div>
        <div class="absolute top-1/3 -right-24 w-[30rem] h-[30rem] bg-brand-400/15 rounded-full blur-[120px]"></div>
        <div class="absolute -bottom-20 left-1/3 w-80 h-80 bg-accent-500/10 rounded-full blur-[90px]"></div>

        <!-- Subtle Orbital Rings from Mockup -->
        <div class="absolute -left-48 top-8 w-[38rem] h-[38rem] rounded-full border border-sky-400/20 shadow-[0_0_30px_rgba(56,189,248,0.1)] pointer-events-none"></div>
        <div class="absolute -right-48 -top-8 w-[42rem] h-[42rem] rounded-full border border-sky-400/20 shadow-[0_0_30px_rgba(56,189,248,0.1)] pointer-events-none"></div>

        <!-- Glowing Star Sparkles -->
        <div class="absolute top-[18%] left-[13%] w-1.5 h-1.5 bg-sky-300 rounded-full shadow-[0_0_10px_#38bdf8]"></div>
        <div class="absolute top-[32%] right-[16%] w-1.5 h-1.5 bg-sky-300 rounded-full shadow-[0_0_10px_#38bdf8]"></div>
        <div class="absolute bottom-[24%] right-[7%] w-2 h-2 bg-sky-300 rounded-full shadow-[0_0_12px_#38bdf8]"></div>

        <!-- Glowing Hexagonal Grid SVG Pattern -->
        <svg class="absolute inset-0 w-full h-full opacity-25" xmlns="http://www.w3.org/2000/svg">
          <defs>
            <pattern id="hex-grid" width="56" height="96" patternUnits="userSpaceOnUse">
              <path d="M28 0 L56 16 L56 48 L28 64 L0 48 L0 16 Z M28 64 L56 80 L56 112 L28 128 L0 112 L0 80 Z" 
                    fill="none" stroke="rgba(56, 189, 248, 0.35)" stroke-width="1.1" />
            </pattern>
          </defs>
          <rect width="100%" height="100%" fill="url(#hex-grid)" />
        </svg>

        <!-- Math Symbols Anti-Gravity Floating Field (parallax on mouse move) -->
        <span class="math-sym math-sym-1" data-depth="0.04" aria-hidden="true">+</span>
        <span class="math-sym math-sym-2" data-depth="0.07" aria-hidden="true">−</span>
        <span class="math-sym math-sym-3" data-depth="0.05" aria-hidden="true">÷</span>
        <span class="math-sym math-sym-4" data-depth="0.09" aria-hidden="true">×</span>
        <span class="math-sym math-sym-5" data-depth="0.03" aria-hidden="true">√</span>
        <span class="math-sym math-sym-6" data-depth="0.06" aria-hidden="true">∑</span>
        <span class="math-sym math-sym-7" data-depth="0.08" aria-hidden="true">π</span>
        <span class="math-sym math-sym-8" data-depth="0.05" aria-hidden="true">∞</span>
        <span class="math-sym math-sym-9" data-depth="0.10" aria-hidden="true">∫</span>
        <span class="math-sym math-sym-10" data-depth="0.04" aria-hidden="true">Δ</span>
        <span class="math-sym math-sym-11" data-depth="0.06" aria-hidden="true">%</span>
      </div>

      <div class="relative z-[1] max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 pt-16 pb-24 sm:pt-20 sm:pb-28 text-center flex flex-col items-center">
        <!-- Plataforma educativa en vivo Badge with Soft Pulsating Radial Glow -->
        <div class="hero-reveal relative z-10 inline-flex items-center gap-2.5 px-4 py-1.5 bg-sky-500/10 backdrop-blur-md rounded-full text-xs sm:text-sm font-medium mb-6 border border-sky-400/30 shadow-[0_0_20px_rgba(56,189,248,0.25)]">
          <span class="relative flex h-2.5 w-2.5">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-80"></span>
            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-green-400 shadow-[0_0_10px_#4ade80]"></span>
          </span>
          <span class="text-white">Plataforma educativa en vivo</span>
        </div>

        <!-- Titular Principal Centralizado -->
        <h1 class="hero-reveal text-5xl sm:text-6xl lg:text-7xl font-black leading-[1.04] tracking-tight text-center mb-6">
          <span class="block text-white">Aprende con</span>
          <span class="block text-amber-400">los mejores</span>
          <span class="block text-white">profesores</span>
        </h1>

        <!-- Subtítulo Centralizado -->
        <p class="hero-reveal text-base sm:text-lg text-white/80 leading-relaxed mb-8 max-w-xl mx-auto text-center font-normal">
          Conectamos estudiantes con profesores particulares expertos para clases en vivo por videollamada. Personalizado, flexible y efectivo.
        </p>

        <!-- Botones de Acción (Pill con flecha) -->
        <div class="hero-reveal flex flex-wrap items-center justify-center gap-4 mb-10">
          <Link :href="user ? route('dashboard') : route('marketplace')"
            class="px-8 py-3.5 bg-white text-slate-900 font-bold rounded-full hover:bg-slate-100 transition-all shadow-[0_10px_25px_rgba(0,0,0,0.25)] hover:scale-105 active:scale-95 flex items-center gap-2.5">
            <span>{{ user ? 'Ir a mi dashboard' : 'Buscar clases' }}</span>
            <span class="text-lg leading-none font-bold" aria-hidden="true">→</span>
          </Link>
          <Link :href="user ? route('dashboard') : '/register?role=teacher'"
            class="px-8 py-3.5 bg-white/5 hover:bg-white/15 text-white font-semibold rounded-full border border-white/25 backdrop-blur-sm transition-all hover:scale-105 active:scale-95">
            {{ user ? 'Ver mis clases' : 'Soy profesor' }}
          </Link>
        </div>

        <!-- Evidencia de plataforma con líneas divisorias laterales -->
        <div class="hero-reveal flex items-center justify-center gap-4 w-full max-w-lg mx-auto mb-8">
          <div class="h-px flex-1 bg-gradient-to-r from-transparent via-white/20 to-white/30"></div>
          <p class="text-xs sm:text-sm text-white/70 whitespace-nowrap">
            Cada profesor pasa por verificación antes de dictar su primera clase.
          </p>
          <div class="h-px flex-1 bg-gradient-to-l from-transparent via-white/20 to-white/30"></div>
        </div>

        <!-- Estadísticas en columnas verticales separadas por divisores -->
        <div class="hero-reveal flex items-center justify-center gap-6 sm:gap-10">
          <div class="flex flex-col items-center">
            <span class="text-2xl sm:text-3xl font-black text-white tabular-nums">{{ stats.teachers }}</span>
            <span class="text-xs sm:text-sm text-white/60 mt-1">profesores</span>
          </div>
          <div class="h-8 w-px bg-white/20"></div>
          <div class="flex flex-col items-center">
            <span class="text-2xl sm:text-3xl font-black text-white tabular-nums">{{ subjects.length }}</span>
            <span class="text-xs sm:text-sm text-white/60 mt-1">materias</span>
          </div>
          <div class="h-8 w-px bg-white/20"></div>
          <div class="flex flex-col items-center">
            <span class="text-2xl sm:text-3xl font-black text-white tabular-nums">{{ stats.students }}</span>
            <span class="text-xs sm:text-sm text-white/60 mt-1">familias</span>
          </div>
          <div class="h-8 w-px bg-white/20"></div>
          <div class="flex flex-col items-center">
            <span class="text-2xl sm:text-3xl font-black text-white tabular-nums">{{ stats.completed }}</span>
            <span class="text-xs sm:text-sm text-white/60 mt-1">clases dictadas</span>
          </div>
        </div>
      </div>

      <!-- Wave: -bottom-1 + block + leading-none + scale-y-[1.02] evita la
           línea gris de costura que deja el hueco de subpíxel del <svg>
           inline contra la sección blanca siguiente (ver About.vue para el
           detalle completo). -->
      <div class="absolute -bottom-1 left-0 right-0 leading-none scale-y-[1.02] origin-top">
        <svg viewBox="0 0 1440 80" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-full block">
          <path d="M0 80L60 72C120 64 240 48 360 44C480 40 600 48 720 52C840 56 960 56 1080 50C1200 44 1320 32 1380 26L1440 20V80H0Z" fill="#E5EEFB"/>
        </svg>
      </div>
    </section>

    <!-- ── Cómo funciona ───────────────────────────────────────────────── -->
    <section id="como-funciona" class="py-24 bg-[#E5EEFB] reveal-group">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="reveal-item text-center mb-16">
          <h2 class="text-4xl font-black text-brand-900 text-balance">¿Cómo funciona MOVA?</h2>
          <p class="text-slate-600 mt-3 max-w-xl mx-auto text-pretty">En tres pasos sencillos estás aprendiendo con tu profesor ideal</p>
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
    <section id="materias" class="py-24 bg-[#E5EEFB] reveal-group">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="reveal-item text-center mb-16">
          <h2 class="text-4xl font-black text-brand-900 text-balance">Materias disponibles</h2>
          <p class="text-slate-600 mt-3 text-pretty">Encuentra un experto en cualquier área de conocimiento</p>
        </div>
        <div class="reveal-item grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
          <Link v-for="s in subjects" :key="s.id"
            :href="`/class-requests/create?subject_id=${s.id}`"
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
    <section id="profesores" class="py-24 bg-[#E5EEFB] reveal-group">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="reveal-item flex flex-wrap items-end justify-between gap-6 mb-16">
          <div>
            <h2 class="text-4xl font-black text-brand-900 text-balance">Profesores destacados</h2>
            <p class="text-slate-600 mt-3 text-pretty">Todos verificados y con experiencia comprobada</p>
          </div>
          <div v-if="featuredTeachers.length" class="hidden sm:flex items-center gap-2">
            <button class="teachers-prev w-11 h-11 rounded-full bg-white border border-gray-200/80 shadow-sm flex items-center justify-center text-slate-500 hover:text-brand-600 hover:border-brand-300 transition-colors">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </button>
            <button class="teachers-next w-11 h-11 rounded-full bg-white border border-gray-200/80 shadow-sm flex items-center justify-center text-slate-500 hover:text-brand-600 hover:border-brand-300 transition-colors">
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
                        <span v-for="i in 5" :key="i" class="text-sm" :class="i <= Math.round(t.avg_rating) ? 'text-accent-400' : 'text-gray-200'">★</span>
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
                  <Link :href="route('teachers.show', t.id)"
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
          <h2 class="text-4xl font-black text-white text-balance">Lo que dicen nuestros usuarios</h2>
        </div>
        <div class="grid md:grid-cols-3 gap-6">
          <div v-for="(t, i) in testimonials" :key="i"
            class="reveal-item bg-white/10 rounded-2xl p-6 border border-white/10 shadow-lg shadow-black/10">
            <div class="flex gap-0.5 mb-4">
              <span v-for="i in 5" :key="i" class="text-accent-400">★</span>
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
    <section class="py-24 bg-[#E5EEFB] reveal-group">
      <div class="max-w-4xl mx-auto px-4">
        <div class="reveal-item relative overflow-hidden bg-white rounded-3xl border border-white/80 shadow-xl shadow-brand-900/5 px-8 py-16 text-center">
          <div class="absolute -top-24 -right-24 w-64 h-64 bg-brand-100 rounded-full opacity-40 blur-3xl"></div>
          <div class="absolute -bottom-24 -left-24 w-64 h-64 bg-accent-100 rounded-full opacity-40 blur-3xl"></div>
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
import { onMounted, onBeforeUnmount } from 'vue'
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
  // La regla global de `prefers-reduced-motion` en app.css sólo alcanza a CSS.
  // GSAP anima por JS, así que necesita este guard explícito. Se usa `.from()`
  // a propósito: el estado final es el natural del elemento, de modo que si
  // salimos temprano (o si el JS nunca corre) el contenido queda visible.
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return

  // Hero: entrada inmediata al cargar, sin depender de scroll.
  gsap.from('.hero-reveal', {
    opacity: 0,
    y: 24,
    duration: 0.7,
    ease: 'power3.out',
    stagger: 0.1,
  })

  // Resto de secciones: fade-in-up con stagger sutil al entrar en viewport.
  document.querySelectorAll('.reveal-group').forEach((group) => {
    const items = group.querySelectorAll('.reveal-item')
    if (!items.length) return

    gsap.from(items, {
      opacity: 0,
      y: 28,
      duration: 0.6,
      ease: 'power3.out',
      stagger: { amount: Math.min(items.length * 0.08, 0.6) },
      immediateRender: false,
      scrollTrigger: {
        trigger: group,
        start: 'top 82%',
        once: true,
      },
    })
  })

  // Las webfonts cambian la altura del contenido después de que ScrollTrigger
  // calculó los puntos de disparo; sin este refresh los triggers quedan
  // corridos respecto de dónde terminó realmente cada sección.
  document.fonts?.ready.then(() => ScrollTrigger.refresh())

  // ── Parallax de símbolos matemáticos al mover el mouse ──────────────────
  const mathSymbols = document.querySelectorAll('.math-sym')
  let rafId = null
  let targetX = 0, targetY = 0
  let currentX = 0, currentY = 0

  function onMouseMove(e) {
    targetX = (e.clientX / window.innerWidth  - 0.5)
    targetY = (e.clientY / window.innerHeight - 0.5)
  }

  function animateSymbols() {
    currentX += (targetX - currentX) * 0.06
    currentY += (targetY - currentY) * 0.06
    mathSymbols.forEach((el) => {
      const depth = parseFloat(el.dataset.depth ?? 0.05)
      el.style.setProperty('--mx', `${currentX * depth * 220}px`)
      el.style.setProperty('--my', `${currentY * depth * 160}px`)
    })
    rafId = requestAnimationFrame(animateSymbols)
  }

  window.addEventListener('mousemove', onMouseMove, { passive: true })
  rafId = requestAnimationFrame(animateSymbols)

  onBeforeUnmount(() => {
    window.removeEventListener('mousemove', onMouseMove)
    cancelAnimationFrame(rafId)
  })
})
</script>

<style scoped>
/* ── Símbolos Matemáticos Flotantes (Campo Anti-Gravedad) ─────────────────── */
/*  El JS aplica translate() inline vía rAF para el parallax de mouse.         */
/*  Las animaciones CSS suman un float vertical independiente.                 */
.math-sym {
  position: absolute;
  pointer-events: none;
  z-index: 0;
  will-change: transform;
  font-family: 'Georgia', 'Times New Roman', serif;
  font-weight: 700;
  line-height: 1;
  user-select: none;
  --mx: 0px;   /* Parallax X del cursor — actualizado por JS */
  --my: 0px;   /* Parallax Y del cursor — actualizado por JS */
  /* Texto con glow semitransparente en tono azul-cian */
  color: rgba(147, 210, 255, 0.55);
  text-shadow:
    0 0 18px rgba(56, 189, 248, 0.6),
    0 0 40px rgba(56, 189, 248, 0.25);
}

/* Posiciones y tamaños individuales de cada símbolo (según Mockup) */
.math-sym-1  { top: 9%;   left: 3%;    font-size: 4.2rem; animation: mathFloat1  8s ease-in-out infinite 0s;   }
.math-sym-2  { top: 38%;  left: 2%;    font-size: 3.2rem; animation: mathFloat2 10s ease-in-out infinite 1.2s; }
.math-sym-3  { top: 24%;  left: 20%;   font-size: 3.4rem; animation: mathFloat3  9s ease-in-out infinite 0.5s; }
.math-sym-4  { top: 55%;  left: 9%;    font-size: 4rem;   animation: mathFloat1 11s ease-in-out infinite 2s;   }
.math-sym-5  { top: 11%;  right: 9%;   font-size: 3.8rem; animation: mathFloat2  7s ease-in-out infinite 0.8s; }
.math-sym-6  { top: 28%;  right: 4%;   font-size: 3.5rem; animation: mathFloat3 12s ease-in-out infinite 1.5s; }
.math-sym-7  { top: 68%;  right: 13%;  font-size: 3.4rem; animation: mathFloat1  9s ease-in-out infinite 3s;   }
.math-sym-8  { top: 83%;  right: 17%;  font-size: 3.6rem; animation: mathFloat2 13s ease-in-out infinite 0.3s; }
.math-sym-9  { top: 48%;  right: 7%;   font-size: 4.8rem; animation: mathFloat3  8s ease-in-out infinite 1.8s; }
.math-sym-10 { top: 76%;  left: 2.5%;  font-size: 3.4rem; animation: mathFloat1 10s ease-in-out infinite 0.6s; }
.math-sym-11 { top: 64%;  left: 19%;   font-size: 3.2rem; animation: mathFloat2 11s ease-in-out infinite 2.4s; }

/* Tres variantes de flotado para riqueza visual */
/* Las animaciones incorporan var(--mx) / var(--my) para componer             */
/* el parallax del mouse con el float vertical sin conflictos.                */
@keyframes mathFloat1 {
  0%, 100% { transform: translate(var(--mx), calc(var(--my) + 0px))   rotate(0deg);  }
  33%       { transform: translate(var(--mx), calc(var(--my) - 22px)) rotate(4deg);  }
  66%       { transform: translate(var(--mx), calc(var(--my) - 10px)) rotate(-3deg); }
}
@keyframes mathFloat2 {
  0%, 100% { transform: translate(var(--mx), calc(var(--my) + 0px))   rotate(0deg);  }
  40%       { transform: translate(var(--mx), calc(var(--my) - 18px)) rotate(-5deg); }
  70%       { transform: translate(var(--mx), calc(var(--my) - 28px)) rotate(3deg);  }
}
@keyframes mathFloat3 {
  0%, 100% { transform: translate(var(--mx), calc(var(--my) + 0px))   scale(1);      }
  50%       { transform: translate(var(--mx), calc(var(--my) - 24px)) scale(1.06);   }
}


/* Swiper — paginación sutil acorde a la paleta de marca */
.teachers-swiper {
  overflow-x: clip;
  overflow-y: visible;
}
.teachers-pagination :deep(.swiper-pagination-bullet) {
  width: 6px;
  height: 6px;
  background: theme('colors.brand.300');
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
