<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import { gsap } from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import { ArrowDown, ArrowUpRight, Code2, Gem, Settings2, Target, Telescope } from 'lucide-vue-next'
import LandingNavbar from '@/Components/LandingNavbar.vue'
import LandingFooter from '@/Components/LandingFooter.vue'
import MovaLogo from '@/Components/MovaLogo.vue'

const props = defineProps({ stats: Object })
const page = ref(null)
const svgId = useId().replace(/:/g, '')
const titleWords = ['Dos', 'socios', 'con', 'una']
const showStats = computed(() => (props.stats?.classes ?? 0) >= 10 && (props.stats?.teachers ?? 0) >= 5)
const computedStats = computed(() => [
  { value: props.stats?.teachers ?? '—', label: 'Profesores verificados' },
  { value: props.stats?.classes ?? '—', label: 'Clases impartidas' },
  { value: props.stats?.students ?? '—', label: 'Familias en MOVA' },
])
const mvv = [
  { icon: Target, title: 'Misión', desc: 'Conectar a cada estudiante con el profesor perfecto a través de clases personalizadas en línea, accesibles desde cualquier lugar del mundo.' },
  { icon: Telescope, title: 'Visión', desc: 'Ser la plataforma líder de educación personalizada en español, donde la calidad docente no tenga fronteras geográficas.' },
  { icon: Gem, title: 'Valores', desc: 'Calidad, transparencia y accesibilidad. Creemos que la educación de calidad es un derecho, no un privilegio.' },
]
const founders = [
  {
    name: 'Abel Enrique Castillo Yarin', initials: 'AC', icon: Code2,
    role: 'Socio Fundador & Desarrollador Principal',
    desc: 'Encargado de toda la plataforma tecnológica de MOVA. Diseña y construye cada funcionalidad para que estudiantes y profesores tengan la mejor experiencia posible.',
  },
  {
    name: 'Elias Paz', initials: 'EP', icon: Settings2,
    role: 'Socio Fundador & Director Administrativo',
    desc: 'Encargado de la gestión y operaciones de MOVA. Asegura que cada proceso funcione con eficiencia y que la plataforma crezca de manera sostenible.',
  },
]
// Preserve a single accessible paragraph; visual word layers only change opacity.
const story = [
  'Todo empezó con una idea sencilla: los mejores profesores no deberían estar limitados a una ciudad, y los mejores estudiantes no deberían estar limitados por su código postal. MOVA nació para romper esa barrera.',
  'Somos Abel y Elias, dos socios que combinamos tecnología y gestión para construir la plataforma de educación personalizada más accesible en español. Cada línea de código y cada decisión operativa está guiada por un solo objetivo: que cada alumno encuentre al profesor perfecto para él.',
]
const storyWords = story.map((paragraph) => paragraph.split(' ').map((word, index, words) => ({
  text: word,
  highlight: (words.indexOf('romper') !== -1 && index >= words.indexOf('romper'))
    || (words.indexOf('perfecto') !== -1 && index >= words.indexOf('profesor') && index <= words.indexOf('perfecto')),
})))
const particles = Array.from({ length: 18 }, (_, index) => ({
  left: `${(index * 37 + 7) % 100}%`, top: `${(index * 23 + 13) % 90}%`,
  '--delay': `${-(index % 7)}s`, '--duration': `${6 + index % 5}s`,
}))
let context
let media
let disposed = false
let pointerCleanup = () => {}
let visibilityObserver

// Cached bounds and quickTo setters avoid layout reads on pointermove.
function bindPointerMotion() {
  const cleanups = []
  page.value.querySelectorAll('[data-tilt], [data-magnetic]').forEach((element) => {
    const magnetic = element.hasAttribute('data-magnetic')
    const target = magnetic ? element : element.querySelector('.tilt-surface')
    const light = element.querySelector('.pointer-light')
    let bounds
    const x = gsap.quickTo(target, magnetic ? 'x' : 'rotationY', { duration: 0.55, ease: 'power3.out' })
    const y = gsap.quickTo(target, magnetic ? 'y' : 'rotationX', { duration: 0.55, ease: 'power3.out' })
    const lightX = light && gsap.quickTo(light, 'x', { duration: 0.35, ease: 'power3.out' })
    const lightY = light && gsap.quickTo(light, 'y', { duration: 0.35, ease: 'power3.out' })
    const enter = () => { bounds = element.getBoundingClientRect() }
    const move = (event) => {
      if (!bounds) return
      const dx = event.clientX - bounds.left - bounds.width / 2
      const dy = event.clientY - bounds.top - bounds.height / 2
      x(magnetic ? dx * 0.13 : dx / bounds.width * 9)
      y(magnetic ? dy * 0.18 : -dy / bounds.height * 9)
      if (light) { lightX(dx); lightY(dy) }
    }
    const leave = () => { bounds = null; x(0); y(0) }
    element.addEventListener('pointerenter', enter)
    element.addEventListener('pointermove', move)
    element.addEventListener('pointerleave', leave)
    window.addEventListener('scroll', leave, { passive: true })
    cleanups.push(() => {
      element.removeEventListener('pointerenter', enter)
      element.removeEventListener('pointermove', move)
      element.removeEventListener('pointerleave', leave)
      window.removeEventListener('scroll', leave)
      ;[x, y, lightX, lightY].filter(Boolean).forEach((setter) => setter.tween.kill())
      gsap.set(target, { clearProps: 'transform' })
    })
  })
  return () => cleanups.forEach((cleanup) => cleanup())
}

onMounted(async () => {
  await nextTick()
  if (disposed) return
  gsap.registerPlugin(ScrollTrigger)
  const ease = 'expo.out'
  context = gsap.context(() => {
    gsap.fromTo('.reading-progress', { scaleX: 0 }, {
      scaleX: 1, ease: 'none',
      scrollTrigger: { trigger: page.value, start: 'top top', end: 'bottom bottom', scrub: true },
    })
    media = gsap.matchMedia()
    media.add({
      motion: '(prefers-reduced-motion: no-preference)',
      desktop: '(min-width: 768px)',
      pointer: '(hover: hover) and (pointer: fine)',
    }, ({ conditions }) => {
      if (!conditions.motion) return
      const select = gsap.utils.selector(page.value)
      gsap.timeline({ defaults: { ease } })
        .from('.hero-badge', { scale: 0.75, opacity: 0, duration: 0.9, ease: 'elastic.out(1, 0.65)' })
        .from('.word-reveal', { yPercent: 110, rotation: 3, opacity: 0, stagger: 0.09, duration: 1.2 }, 0.12)
        .fromTo('.word-haze', { opacity: 0.7 }, { opacity: 0, stagger: 0.09, duration: 0.8 }, 0.2)
        .from('.hero-description', { y: 24, opacity: 0, duration: 1 }, 0.6)
        .from('.hero-emblem, .scroll-cue', { y: 20, scale: 0.9, opacity: 0, duration: 1, stagger: 0.1 }, 0.75)

      const heroScroll = { trigger: '.about-hero', start: 'top top', end: 'bottom top', scrub: 0.8 }
      gsap.to('.hero-content', { y: 100, opacity: 0.2, ease: 'none', scrollTrigger: heroScroll })
      gsap.to('.hero-orbits', { rotation: 30, y: 110, ease: 'none', scrollTrigger: heroScroll })
      gsap.to('.hero-glow-one', { x: 90, y: 150, scale: 1.2, ease: 'none', scrollTrigger: heroScroll })
      gsap.to('.hero-glow-two', { x: -70, y: -90, scale: 0.8, ease: 'none', scrollTrigger: heroScroll })
      // Flatten a fixed SVG silhouette with scale instead of repainting its path.
      gsap.to('.hero-wave', { scaleY: 0.14, ease: 'none', scrollTrigger: heroScroll })
      select('.section-heading').forEach((heading) => gsap.from(heading, {
        y: 38, scale: 0.96, opacity: 0, ease,
        scrollTrigger: { trigger: heading, start: 'top 91%', end: 'top 68%', scrub: 0.6 },
      }))
      select('.principle-entry').forEach((card, index) => {
        gsap.from(card, {
          y: 70, rotationX: 25, opacity: 0, transformPerspective: 1000, ease,
          scrollTrigger: { trigger: card, start: `top ${91 - (conditions.desktop ? index * 4 : 0)}%`, end: 'top 55%', scrub: 0.7 },
        })
        gsap.from(card.querySelector('.icon-curtain'), {
          scaleY: 1, transformOrigin: 'center bottom', ease: 'power2.inOut',
          scrollTrigger: { trigger: card, start: 'top 78%', end: 'top 52%', scrub: 0.45 },
        })
      })
      gsap.from('.circuit-reveal', {
        scaleX: 0, transformOrigin: 'left center', ease: 'none',
        scrollTrigger: { trigger: '.principles-grid', start: 'top 82%', end: 'center 48%', scrub: 0.7 },
      })
      select('.founder-entry').forEach((card, index) => {
        gsap.from(card, {
          x: (index ? 1 : -1) * (conditions.desktop ? 110 : 35), y: 35,
          rotationY: index ? -12 : 12, opacity: 0, transformPerspective: 1000, ease,
          scrollTrigger: { trigger: card, start: 'top 91%', end: 'top 51%', scrub: 0.85 },
        })
        gsap.to(card.querySelector('.avatar-orbits'), {
          rotation: index ? -45 : 45, scale: 1.15, ease: 'none',
          scrollTrigger: { trigger: card, start: 'top bottom', end: 'bottom top', scrub: 0.8 },
        })
      })
      gsap.from('.bridge-energy', {
        scaleX: 0, opacity: 0, transformOrigin: 'center', ease,
        scrollTrigger: { trigger: '.founders-grid', start: 'top 75%', end: 'top 40%', scrub: 0.7 },
      })
      gsap.fromTo(select('.story-word-ink'), { opacity: 0 }, {
        opacity: 1, stagger: 0.12, duration: 0.35, ease: 'none',
        scrollTrigger: { trigger: '.story-stage', start: 'top 42%', end: 'bottom 90%', scrub: 0.45 },
      })
      gsap.fromTo('.story-radial', { yPercent: -35 }, {
        yPercent: 45, ease: 'none',
        scrollTrigger: { trigger: '.story-stage', start: 'top bottom', end: 'bottom top', scrub: 1 },
      })
      gsap.to('.story-orbit', {
        rotation: 65, scale: 1.15, ease: 'none',
        scrollTrigger: { trigger: '.story-stage', start: 'top bottom', end: 'bottom top', scrub: 1 },
      })
      gsap.from('.cta-portal', {
        scale: 0.87, y: 65, opacity: 0, ease,
        scrollTrigger: { trigger: '.about-cta', start: 'top 88%', end: 'top 40%', scrub: 0.75 },
      })
      if (conditions.pointer) pointerCleanup = bindPointerMotion()
      return () => { pointerCleanup(); pointerCleanup = () => {} }
    }, page.value)
  }, page.value)
  // Pause decorative CSS loops outside the viewport.
  visibilityObserver = new IntersectionObserver((entries) => {
    entries.forEach(({ target, isIntersecting }) => target.classList.toggle('motion-visible', isIntersecting))
  }, { rootMargin: '80px' })
  page.value.querySelectorAll('[data-motion-section]').forEach((section) => visibilityObserver.observe(section))
  document.fonts?.ready.then(() => { if (!disposed) ScrollTrigger.refresh() })
})

onBeforeUnmount(() => {
  disposed = true
  visibilityObserver?.disconnect()
  media?.revert()
  pointerCleanup()
  context?.revert()
})
</script>

<template>
  <Head title="Quiénes somos – MOVA" />
  <div ref="page" class="about-page">
    <LandingNavbar />
    <div class="reading-track" aria-hidden="true"><div class="reading-progress"></div></div>
    <main id="about-content">
      <section class="about-hero" aria-labelledby="about-title" data-motion-section>
        <div class="hero-grid" aria-hidden="true"></div>
        <div class="hero-glow hero-glow-one" aria-hidden="true"></div>
        <div class="hero-glow hero-glow-two" aria-hidden="true"></div>
        <div class="hero-orbits" aria-hidden="true">
          <div class="hero-orbit orbit-one"><i></i></div>
          <div class="hero-orbit orbit-two"><i></i></div>
          <div class="hero-orbit orbit-three"></div>
          <span class="orbit-star star-one"></span>
          <span class="orbit-star star-two"></span>
        </div>
        <div class="particles" aria-hidden="true">
          <i v-for="(particle, index) in particles" :key="index" :style="particle" :class="{ amber: index % 6 === 0 }"></i>
        </div>
        <div class="hero-content">
          <div class="hero-badge">
            <span class="badge-dot" aria-hidden="true"></span>
            Sobre nosotros
            <span class="badge-shimmer" aria-hidden="true"></span>
          </div>
          <h1 id="about-title" aria-label="Dos socios con una misión clara">
            <span class="title-line" aria-hidden="true"><span v-for="word in titleWords" :key="word" class="word-mask"><span class="word-reveal">{{ word }}<span class="word-haze">{{ word }}</span></span>{{ ' ' }}</span></span>
            <span class="title-line title-highlight" aria-hidden="true"><span v-for="word in ['misión', 'clara']" :key="word" class="word-mask"><span class="word-reveal" :data-word="word">{{ word }}<span class="word-haze">{{ word }}</span></span>{{ ' ' }}</span></span>
          </h1>
          <p class="hero-description">MOVA nació de la convicción de que cada estudiante merece acceso a un profesor experto, sin importar dónde esté. Somos un equipo pequeño con grandes metas.</p>
          <div class="hero-emblem" aria-hidden="true">
            <span class="emblem-line"></span>
            <span class="emblem-satellite"></span>
            <div class="emblem-glass"><MovaLogo theme="blanco" class="w-[79px] h-auto" /></div>
            <span class="emblem-satellite"></span>
            <span class="emblem-line"></span>
          </div>
          <a class="scroll-cue" href="#lo-que-nos-mueve" aria-label="Ir a Lo que nos mueve">
            <ArrowDown :size="18" :stroke-width="1.5" />
          </a>
        </div>
        <svg class="hero-wave" viewBox="0 0 1440 100" preserveAspectRatio="none" aria-hidden="true">
          <path d="M0 55C360 120 980-35 1440 55V100H0Z" fill="#F8FAFC" />
        </svg>
      </section>
      <section v-if="showStats" class="about-stats" aria-label="MOVA en cifras">
        <div v-for="stat in computedStats" :key="stat.label"><p>{{ stat.value }}</p><span>{{ stat.label }}</span></div>
      </section>
      <section id="lo-que-nos-mueve" class="about-principles" aria-labelledby="principles-title" data-motion-section>
        <div class="section-shell">
          <div class="section-heading">
            <span class="heading-spark" aria-hidden="true"></span>
            <h2 id="principles-title">Lo que nos mueve</h2>
          </div>
          <div class="principles-grid">
            <div class="principles-circuit" aria-hidden="true">
              <svg viewBox="0 0 1000 120" preserveAspectRatio="none">
                <defs>
                  <linearGradient :id="`${svgId}-circuit`">
                    <stop stop-color="#2563EB" />
                    <stop offset="0.5" stop-color="#38BDF8" />
                    <stop offset="1" stop-color="#2563EB" />
                  </linearGradient>
                </defs>
                <path d="M0 60H100Q120 60 140 38T180 60H440Q460 60 480 82T520 60H800Q820 60 840 38T880 60H1000" stroke="#dce7f7" stroke-width="1.5" fill="none" />
                <g class="circuit-reveal">
                  <path d="M0 60H100Q120 60 140 38T180 60H440Q460 60 480 82T520 60H800Q820 60 840 38T880 60H1000" :stroke="`url(#${svgId}-circuit)`" stroke-width="2" fill="none" />
                  <circle cx="500" cy="70" r="5" fill="#38BDF8" />
                </g>
              </svg>
            </div>
            <div v-for="(item, index) in mvv" :key="item.title" class="principle-entry" data-tilt>
              <article class="principle-card tilt-surface">
                <div class="card-spotlight" aria-hidden="true"><span class="pointer-light"></span></div>
                <div class="principle-top">
                  <div class="principle-icon">
                    <component :is="item.icon" :size="29" :stroke-width="1.35" aria-hidden="true" />
                    <span class="icon-curtain" aria-hidden="true"></span>
                  </div>
                  <span class="principle-number" aria-hidden="true">0{{ index + 1 }}</span>
                </div>
                <h3>{{ item.title }}</h3>
                <p>{{ item.desc }}</p>
                <span class="card-baseline" aria-hidden="true"></span>
              </article>
            </div>
          </div>
        </div>
      </section>
      <section class="about-founders" aria-labelledby="founders-title" data-motion-section>
        <div class="section-shell">
          <div class="section-heading">
            <span class="heading-spark" aria-hidden="true"></span>
            <h2 id="founders-title">Los fundadores</h2>
            <p>El equipo detrás de MOVA</p>
          </div>
          <div class="founders-grid">
            <div class="founders-bridge" aria-hidden="true">
              <div class="bridge-energy"><span></span><i></i><span></span></div>
            </div>
            <div v-for="(member, index) in founders" :key="member.initials" class="founder-entry" :class="{ 'founder-night': index === 1 }" data-tilt>
              <article class="founder-card tilt-surface">
                <div class="card-spotlight" aria-hidden="true"><span class="pointer-light"></span></div>
                <div class="founder-visual" aria-hidden="true">
                  <div class="avatar-grid"></div>
                  <div class="avatar-orbits"><span></span><span></span><i></i></div>
                  <div class="founder-avatar">{{ member.initials }}<span class="avatar-sheen"></span></div>
                  <div class="founder-token"><component :is="member.icon" :size="22" :stroke-width="1.5" /></div>
                  <span class="founder-spark"></span>
                </div>
                <div class="founder-info">
                  <h3>{{ member.name }}</h3>
                  <p class="founder-role">{{ member.role }}</p>
                  <p class="founder-description">{{ member.desc }}</p>
                </div>
              </article>
            </div>
          </div>
        </div>
      </section>
      <section class="story-stage" aria-labelledby="story-title" data-motion-section>
        <div class="story-sticky">
          <div class="story-dots" aria-hidden="true"></div>
          <div class="story-radial" aria-hidden="true"></div>
          <div class="story-orbit" aria-hidden="true"></div>
          <div class="story-content">
            <div class="story-heading">
              <span class="story-heading-line" aria-hidden="true"></span>
              <h2 id="story-title">Nuestra historia</h2>
            </div>
            <div class="story-copy">
              <p v-for="(paragraph, index) in storyWords" :key="index" :class="{ 'story-secondary': index === 1 }">
                <span class="sr-only">{{ story[index] }}</span>
                <span aria-hidden="true"><span v-for="(word, wordIndex) in paragraph" :key="wordIndex" class="story-word" :class="{ 'story-highlight': word.highlight }"><span class="story-word-base">{{ word.text }}</span><span class="story-word-ink">{{ word.text }}</span>{{ ' ' }}</span></span>
              </p>
            </div>
            <div class="story-endmark" aria-hidden="true"><span></span><i></i><span></span></div>
          </div>
        </div>
      </section>
      <section class="about-cta" aria-labelledby="cta-title" data-motion-section>
        <div class="cta-portal">
          <div class="cta-rings" aria-hidden="true"><span></span><span></span><span></span></div>
          <div class="cta-mark" aria-hidden="true"><MovaLogo theme="color" class="w-[74px] h-auto" /></div>
          <h2 id="cta-title">Únete a MOVA hoy</h2>
          <p>Sé parte de la comunidad educativa que está cambiando el futuro del aprendizaje en línea.</p>
          <div class="cta-actions">
            <Link :href="route('register')" class="cta-button cta-primary" data-magnetic>
              <span>Crear cuenta gratis</span>
              <ArrowUpRight :size="18" aria-hidden="true" />
              <span class="button-shine" aria-hidden="true"></span>
            </Link>
            <Link :href="route('marketplace')" class="cta-button cta-secondary" data-magnetic>
              <span>Explorar profesores</span>
              <ArrowUpRight :size="18" aria-hidden="true" />
            </Link>
          </div>
        </div>
      </section>
    </main>
    <LandingFooter />
  </div>
</template>

<style scoped>
.about-page {
  --nav-height: 64px;
  --navy: #0a2558;
  --electric: #2563eb;
  --cyan: #38bdf8;
  --ice: #f8fafc;
  --motion-ease: cubic-bezier(0.16, 1, 0.3, 1);
  background: white;
  color: var(--navy);
  overflow-x: clip;
}
.reading-track {
  position: fixed;
  z-index: 49;
  top: var(--nav-height);
  inset-inline: 0;
  height: 2px;
  pointer-events: none;
}
.reading-progress {
  height: 100%;
  background: linear-gradient(90deg, #2563eb, #38bdf8, #a5e4ff);
  box-shadow: 0 0 12px #38bdf899;
  transform: scaleX(0);
  transform-origin: left;
}
.section-shell {
  width: min(1160px, calc(100% - 64px));
  margin-inline: auto;
}
.about-hero {
  min-height: 790px;
  min-height: min(900px, 100svh);
  position: relative;
  display: grid;
  place-items: center;
  isolation: isolate;
  overflow: hidden;
  background: linear-gradient(125deg, #0a2558 12%, #0d3069 56%, #0f3878);
  color: white;
  padding: 140px 24px 104px;
}
.hero-grid {
  position: absolute;
  inset: 0;
  z-index: -2;
  background-image: radial-gradient(#83b7f438 .8px, transparent .8px);
  background-size: 32px 32px;
  mask-image: linear-gradient(transparent, black 35%, black 65%, transparent);
}
.hero-grid::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(90deg, transparent calc(50% - .5px), #93c5fd10 50%, transparent calc(50% + .5px));
}
.hero-glow {
  position: absolute;
  width: 650px;
  aspect-ratio: 1;
  z-index: -1;
  pointer-events: none;
  border-radius: 50%;
  background: radial-gradient(circle, #38bdf830, #2563eb0a 53%, transparent 70%);
}
.hero-glow-one {
  left: -260px;
  top: -180px;
}
.hero-glow-two {
  right: -230px;
  bottom: -310px;
  background: radial-gradient(circle, #38bdf82b, transparent 65%);
}
.hero-content {
  width: min(1050px, 100%);
  min-width: 0;
  justify-self: center;
  text-align: center;
  position: relative;
  z-index: 2;
}
.hero-badge {
  display: inline-flex;
  align-items: center;
  position: relative;
  gap: 9px;
  padding: 9px 17px;
  border: 1px solid #b2d9ff35;
  border-radius: 99px;
  background: #ffffff08;
  overflow: hidden;
  font-size: 12px;
  font-weight: 500;
  letter-spacing: .025em;
  margin-bottom: 30px;
}
.badge-dot {
  width: 5px;
  height: 5px;
  background: #fbbf24;
  box-shadow: 0 0 10px #fbbf2490;
  border-radius: 50%;
}
.badge-shimmer {
  position: absolute;
  inset: 0;
  background: linear-gradient(105deg, transparent 35%, #b5e5ff30 50%, transparent 65%);
  transform: translate3d(-120%, 0, 0);
  animation: shimmer 7s var(--motion-ease) infinite;
}
.about-hero h1 {
  font-size: clamp(48px, 6.5vw, 88px);
  font-weight: 800;
  letter-spacing: -.065em;
  line-height: 1.12;
  margin: 0;
}
.title-line {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  column-gap: .22em;
}
.word-mask {
  display: inline-block;
  overflow: hidden;
  vertical-align: top;
  padding: .02em .035em .14em;
  margin: -.02em -.035em -.14em;
}
.word-reveal {
  position: relative;
  display: inline-block;
}
.word-haze {
  position: absolute;
  inset: 0;
  opacity: 0;
  filter: blur(7px);
  pointer-events: none;
}
.title-highlight .word-reveal {
  color: transparent;
  background: linear-gradient(110deg, #60a5fa 8%, #80e5ff 52%, #38bdf8 94%);
  background-clip: text;
}
.title-highlight {
  position: relative;
  text-shadow: 0 12px 65px #38bdf824;
}
.title-highlight::after {
  content: '';
  width: 130px;
  height: 1px;
  position: absolute;
  left: calc(50% - 65px);
  bottom: -17px;
  background: linear-gradient(90deg, transparent, #7dd3fc80, transparent);
}
.title-highlight .word-haze {
  color: #38bdf8;
}
.hero-description {
  font-size: clamp(15px, 1.35vw, 17px);
  line-height: 1.9;
  color: #d4e0f3;
  max-width: 585px;
  margin: 36px auto 0;
  text-wrap: pretty;
}
.hero-orbits {
  position: absolute;
  width: 1200px;
  height: 750px;
  left: calc(50% - 600px);
  top: calc(50% - 320px);
  pointer-events: none;
  z-index: -1;
}
.hero-orbit {
  border: 1px solid #93c5fd17;
  border-radius: 50%;
  position: absolute;
}
.orbit-one {
  inset: 80px -60px;
  transform: rotate(-24deg);
}
.orbit-two {
  inset: -80px 200px;
  transform: rotate(50deg);
  border-color: #93c5fd10;
}
.orbit-three {
  inset: 155px -100px;
  transform: rotate(20deg);
}
.hero-orbit i {
  position: absolute;
  width: 6px;
  height: 6px;
  top: 15%;
  left: 15%;
  border-radius: 50%;
  background: #7dd3fc;
  box-shadow: 0 0 18px #38bdf8;
}
.orbit-two i {
  top: 88%;
  left: 70%;
  width: 4px;
  height: 4px;
  background: #fbbf24;
  box-shadow: 0 0 16px #fbbf2480;
}
.orbit-star {
  position: absolute;
  height: 18px;
  width: 18px;
  background: #7dd3fc;
  clip-path: polygon(50% 0, 58% 42%, 100% 50%, 58% 58%, 50% 100%, 42% 58%, 0 50%, 42% 42%);
}
.star-one {
  left: 13%;
  top: 38%;
}
.star-two {
  right: 12%;
  top: 66%;
  width: 12px;
  height: 12px;
  background: #fbbf24;
}
.particles {
  position: absolute;
  inset: 0;
  pointer-events: none;
}
.particles i {
  position: absolute;
  width: 3px;
  height: 3px;
  border-radius: 50%;
  background: #a4d8fb;
  opacity: .4;
  animation: particle-float var(--duration) ease-in-out var(--delay) infinite alternate;
}
.particles i.amber {
  background: #fbbf24;
  width: 2px;
  height: 2px;
}
.hero-emblem {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 15px;
  margin: 33px auto 23px;
}
.emblem-glass {
  display: grid;
  place-items: center;
  width: 123px;
  height: 63px;
  border: 1px solid #a5d9ff30;
  border-radius: 18px;
  background: linear-gradient(145deg, #ffffff12, #ffffff03);
  box-shadow: inset 0 1px 1px #ffffff18, 0 14px 40px #03163130;
  transform: rotate(-5deg);
}
.emblem-logo {
  width: 79px;
  height: auto;
}
.emblem-line {
  width: 66px;
  height: 1px;
  background: linear-gradient(90deg, transparent, #7dd3fc60);
}
.emblem-line:last-child {
  transform: rotate(180deg);
}
.emblem-satellite {
  width: 4px;
  height: 4px;
  border-radius: 50%;
  background: #38bdf8;
  box-shadow: 0 0 10px #38bdf8;
}
.scroll-cue {
  width: 33px;
  height: 43px;
  border: 1px solid #a3cdff35;
  border-radius: 99px;
  display: grid;
  place-items: center;
  color: #c5e3ff;
  margin-inline: auto;
  transition: transform .4s var(--motion-ease);
}
.scroll-cue:hover {
  transform: translate3d(0, 4px, 0);
}
.scroll-cue svg {
  animation: scroll-nudge 2.8s var(--motion-ease) infinite;
}
.hero-wave {
  position: absolute;
  bottom: -1px;
  left: 0;
  width: 100%;
  height: 95px;
  transform-origin: center bottom;
  z-index: 3;
  pointer-events: none;
}
.about-stats {
  display: flex;
  justify-content: center;
  flex-wrap: wrap;
  gap: 48px;
  text-align: center;
  padding: 48px 24px;
  background: var(--ice);
}
.about-stats p {
  font-size: 36px;
  font-weight: 800;
  color: var(--electric);
}
.about-stats span {
  font-size: 14px;
  color: #64748b;
}
.about-principles {
  padding: 82px 0 114px;
  background: var(--ice);
  scroll-margin-top: 100px;
}
.section-heading {
  position: relative;
  text-align: center;
  margin-bottom: 54px;
}
.heading-spark {
  display: block;
  width: 5px;
  height: 24px;
  border-radius: 8px;
  margin: 0 auto 19px;
  background: linear-gradient(#38bdf8, #2563eb);
  transform: rotate(28deg);
}
.section-heading h2, .about-cta h2 {
  font-weight: 800;
  font-size: clamp(28px, 3.2vw, 42px);
  line-height: 1.2;
  letter-spacing: -.045em;
}
.section-heading > p {
  margin-top: 14px;
  font-size: 15px;
  color: #64748b;
}
.principles-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 24px;
  position: relative;
  perspective: 1000px;
}
.principles-circuit {
  position: absolute;
  left: 8%;
  right: 8%;
  top: -42px;
  height: 120px;
}
.principles-circuit svg {
  width: 100%;
  height: 100%;
}
.principle-entry, .founder-entry {
  position: relative;
  perspective: 1000px;
  min-width: 0;
}
.tilt-surface {
  position: relative;
  height: 100%;
  transform-style: preserve-3d;
}
.principle-card {
  padding: 34px 32px 38px;
  background: white;
  border: 1px solid #e6ecf5;
  border-radius: 20px;
  box-shadow: 0 6px 15px -8px #0a25580a;
}
.principle-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 33px;
}
.principle-icon {
  position: relative;
  display: grid;
  place-items: center;
  width: 57px;
  height: 57px;
  border: 1px solid #dfeaff;
  border-radius: 16px;
  background: #f0f6ff;
  color: #1d5bd8;
  overflow: hidden;
}
.principle-icon svg {
  animation: icon-float 5s ease-in-out infinite;
}
.principle-entry:nth-child(3) .principle-icon svg {
  animation-delay: -1.8s;
}
.principle-entry:nth-child(4) .principle-icon svg {
  animation-delay: -3.4s;
}
.icon-curtain {
  position: absolute;
  inset: 0;
  background: #f0f6ff;
  transform: scaleY(0);
}
.principle-number {
  color: #c2cfe1;
  font-size: 12px;
  font-weight: 500;
  letter-spacing: .05em;
  font-variant-numeric: tabular-nums;
}
.principle-card h3 {
  font-weight: 800;
  font-size: 22px;
  letter-spacing: -.035em;
  margin-bottom: 15px;
}
.principle-card p {
  color: #5c6d85;
  font-size: 14px;
  line-height: 1.9;
}
.card-baseline {
  display: block;
  margin-top: 28px;
  width: 32px;
  height: 2px;
  background: #a5cdfc;
  transform-origin: left;
  transition: transform .6s var(--motion-ease);
}
.principle-entry:hover .card-baseline {
  transform: scaleX(2.6);
}
.card-spotlight {
  position: absolute;
  inset: -1px;
  border-radius: inherit;
  overflow: hidden;
  pointer-events: none;
  z-index: 0;
}
.pointer-light {
  position: absolute;
  width: 330px;
  height: 330px;
  left: calc(50% - 165px);
  top: calc(50% - 165px);
  border-radius: 50%;
  background: radial-gradient(circle, #38bdf83d, transparent 65%);
  opacity: 0;
  transition: opacity .4s ease;
}
.principle-entry:hover .pointer-light, .founder-entry:hover .pointer-light {
  opacity: 1;
}
.principle-card > :not(.card-spotlight), .founder-card > :not(.card-spotlight) {
  position: relative;
}
.principle-card::after, .founder-card::after {
  content: '';
  position: absolute;
  inset: -1px;
  border: 1px solid #60a5fa;
  border-radius: inherit;
  opacity: 0;
  pointer-events: none;
  transition: opacity .4s ease;
}
.principle-entry:hover .principle-card::after, .founder-entry:hover .founder-card::after {
  opacity: .5;
}
.about-founders {
  background: white;
  padding: 104px 0 120px;
}
.founders-grid {
  position: relative;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 100px;
  max-width: 1000px;
  margin-inline: auto;
}
.founder-card {
  border-radius: 24px;
  border: 1px solid #e6ecf5;
  background: linear-gradient(160deg, #f8fbff, white 65%);
  box-shadow: 0 25px 60px -45px #0a255838;
  overflow: hidden;
}
.founder-visual {
  height: 240px;
  display: grid;
  place-items: center;
  isolation: isolate;
}
.avatar-grid {
  position: absolute;
  inset: 0;
  z-index: -2;
  background-image: linear-gradient(#2563eb07 1px, transparent 1px), linear-gradient(90deg, #2563eb07 1px, transparent 1px);
  background-size: 28px 28px;
  mask-image: radial-gradient(ellipse, black, transparent 70%);
}
.founder-avatar {
  position: relative;
  width: 108px;
  height: 108px;
  border: 1px solid #ffffff70;
  border-radius: 27px;
  display: grid;
  place-items: center;
  font-size: 35px;
  font-weight: 800;
  color: white;
  letter-spacing: -.045em;
  background: linear-gradient(145deg, #2968f3, #1d4ed8);
  box-shadow: inset 0 2px 1px #ffffff55, inset -3px -4px 8px #163f9c50, 0 22px 35px -12px #1d5bd865;
  transform: rotate(-8deg);
  overflow: hidden;
}
.founder-night .founder-avatar {
  background: linear-gradient(145deg, #193a76, #0c1d3e);
  transform: rotate(8deg);
  box-shadow: inset 0 2px 1px #ffffff35, inset -3px -4px 8px #020e2940, 0 22px 35px -12px #0a255865;
}
.avatar-sheen {
  position: absolute;
  inset: -60%;
  background: linear-gradient(120deg, transparent 35%, #ffffff1f 50%, transparent 65%);
  animation: shimmer 8s var(--motion-ease) infinite;
}
.avatar-orbits {
  position: absolute;
  width: 196px;
  height: 196px;
  z-index: -1;
  border: 1px solid #2563eb16;
  border-radius: 50%;
}
.avatar-orbits span {
  position: absolute;
  inset: 18px;
  border: 1px solid #2563eb1a;
  border-radius: 50%;
}
.avatar-orbits span:nth-child(2) {
  inset: -20px;
  border-style: dashed;
  border-color: #2563eb12;
}
.avatar-orbits i {
  position: absolute;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  top: 31px;
  left: 17px;
  background: #38bdf8;
  box-shadow: 0 0 13px #38bdf890;
}
.founder-token {
  position: absolute;
  left: calc(50% + 61px);
  top: 52px;
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  border: 1px solid #d7e5f7;
  border-radius: 13px;
  background: #ffffffeb;
  box-shadow: 0 6px 20px #0a25580c;
  color: #1d5bd8;
  transform: rotate(13deg);
  animation: token-float 6s ease-in-out infinite;
}
.founder-night .founder-token {
  left: calc(50% - 102px);
  top: 143px;
  animation-delay: -3s;
}
.founder-spark {
  position: absolute;
  width: 5px;
  height: 5px;
  border-radius: 50%;
  left: calc(50% - 87px);
  top: 159px;
  background: #fbbf24;
}
.founder-night .founder-spark {
  left: calc(50% + 78px);
  top: 75px;
}
.founder-info {
  text-align: center;
  padding: 0 30px 36px;
}
.founder-info h3 {
  font-weight: 800;
  font-size: 20px;
  line-height: 1.45;
  letter-spacing: -.04em;
}
.founder-role {
  font-size: 11px;
  font-weight: 600;
  color: #1d5bd8;
  margin-top: 9px;
}
.founder-description {
  color: #5c6d85;
  font-size: 13px;
  line-height: 1.9;
  margin-top: 19px;
}
.founders-bridge {
  position: absolute;
  top: 120px;
  left: calc(50% - 64px);
  width: 128px;
  z-index: 2;
  pointer-events: none;
}
.bridge-energy {
  display: flex;
  align-items: center;
  gap: 7px;
}
.bridge-energy span {
  height: 1px;
  flex: 1;
  background: linear-gradient(90deg, #2563eb30, #38bdf8);
}
.bridge-energy span:last-child {
  transform: rotate(180deg);
}
.bridge-energy i {
  width: 13px;
  height: 13px;
  border-radius: 4px;
  background: #f0faff;
  border: 1px solid #38bdf8;
  box-shadow: 0 0 18px #38bdf866;
  transform: rotate(45deg);
  animation: bridge-pulse 3s ease-in-out infinite;
}
.story-stage {
  position: relative;
  min-height: 170svh;
  background: #0a2558;
  color: white;
}
.story-sticky {
  position: sticky;
  top: var(--nav-height);
  min-height: calc(100svh - var(--nav-height));
  display: grid;
  align-items: center;
  padding: 72px 32px;
  isolation: isolate;
  overflow: hidden;
  background: linear-gradient(130deg, #0a2558, #0f3878);
}
.story-dots {
  position: absolute;
  inset: 0;
  z-index: -3;
  background-image: radial-gradient(#a5d8ff40 1px, transparent 1px);
  background-size: 30px 30px;
  opacity: .5;
  mask-image: linear-gradient(90deg, black, #0004, black);
}
.story-radial {
  position: absolute;
  width: 900px;
  height: 900px;
  left: calc(50% - 450px);
  top: calc(50% - 450px);
  z-index: -2;
  background: radial-gradient(ellipse, #38bdf81a, transparent 62%);
  pointer-events: none;
}
.story-orbit {
  position: absolute;
  width: 1100px;
  height: 1100px;
  border: 1px solid #7dd3fc12;
  border-radius: 50%;
  left: calc(50% - 550px);
  top: calc(50% - 550px);
  z-index: -1;
}
.story-orbit::before {
  content: '';
  position: absolute;
  inset: 65px;
  border: 1px dashed #7dd3fc12;
  border-radius: 50%;
}
.story-orbit::after {
  content: '';
  position: absolute;
  left: 13.5%;
  top: 15%;
  width: 5px;
  height: 5px;
  border-radius: 50%;
  background: #fbbf24;
  box-shadow: 0 0 20px #fbbf2440;
}
.story-content {
  width: min(930px, 100%);
  margin-inline: auto;
}
.story-heading {
  display: flex;
  align-items: center;
  gap: 17px;
  margin-bottom: 38px;
}
.story-heading h2 {
  font-size: 14px;
  font-weight: 600;
  letter-spacing: .015em;
  color: #d6e8ff;
}
.story-heading-line {
  width: 35px;
  height: 1px;
  background: #38bdf8;
}
.story-copy p {
  font-size: clamp(24px, 2.5vw, 34px);
  font-weight: 500;
  line-height: 1.65;
  letter-spacing: -.035em;
  text-wrap: pretty;
}
.story-copy p.story-secondary {
  font-size: clamp(17px, 1.6vw, 21px);
  line-height: 1.85;
  margin-top: 30px;
  max-width: 845px;
  letter-spacing: -.015em;
}
.story-word {
  display: inline-block;
  position: relative;
  white-space: pre;
}
.story-word-base {
  opacity: .25;
}
.story-word-ink {
  position: absolute;
  left: 0;
  top: 0;
  opacity: 1;
}
.story-highlight .story-word-ink {
  color: #7dd3fc;
  text-shadow: 0 0 22px #38bdf820;
}
.story-endmark {
  margin-top: 42px;
  display: flex;
  align-items: center;
  gap: 12px;
}
.story-endmark span {
  width: 35px;
  height: 1px;
  background: #7dd3fc35;
}
.story-endmark i {
  width: 5px;
  height: 5px;
  border-radius: 50%;
  background: #fbbf24;
}
.about-cta {
  position: relative;
  padding: 112px 32px 120px;
  background: white;
  overflow: hidden;
}
.cta-portal {
  position: relative;
  isolation: isolate;
  text-align: center;
  max-width: 1000px;
  margin-inline: auto;
  padding: 40px 0;
}
.cta-rings {
  position: absolute;
  inset: -80px 0;
  z-index: -1;
  pointer-events: none;
  display: grid;
  place-items: center;
  mask-image: linear-gradient(transparent, black 30%, black 70%, transparent);
}
.cta-rings span {
  position: absolute;
  width: 500px;
  height: 500px;
  border: 1px solid #2563eb0b;
  border-radius: 50%;
}
.cta-rings span:nth-child(2) {
  width: 690px;
  height: 690px;
}
.cta-rings span:nth-child(3) {
  width: 880px;
  height: 880px;
}
.cta-mark {
  margin: 0 auto 28px;
  width: 108px;
  height: 62px;
  display: grid;
  place-items: center;
  border: 1px solid #e3ecfa;
  border-radius: 18px;
  background: #fff;
  box-shadow: 0 10px 25px -10px #0a255817;
  transform: rotate(-5deg);
}
.cta-mark svg {
  width: 74px;
  height: auto;
}
.about-cta h2 {
  font-size: clamp(32px, 4vw, 52px);
}
.about-cta p {
  color: #64748b;
  font-size: 15px;
  max-width: 520px;
  margin: 23px auto 32px;
  line-height: 1.85;
  text-wrap: pretty;
}
.cta-actions {
  display: flex;
  justify-content: center;
  flex-wrap: wrap;
  gap: 14px;
}
.cta-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 20px;
  border-radius: 12px;
  min-height: 56px;
  padding: 16px 24px;
  font-size: 13px;
  font-weight: 700;
  position: relative;
  overflow: hidden;
  transition: transform .5s var(--motion-ease);
}
.cta-button > svg {
  transition: transform .5s var(--motion-ease);
}
.cta-button:hover > svg {
  transform: translate3d(2px, -2px, 0);
}
.cta-primary {
  background: #1d5bd8;
  color: white;
  box-shadow: 0 10px 30px -8px #2563eb66;
  border: 1px solid #2563eb;
}
.cta-secondary {
  color: #174384;
  background: #f8faff;
  border: 1px solid #d5e2f4;
}
.cta-secondary::after {
  content: '';
  position: absolute;
  inset: 0;
  border: 1px solid #2563eb;
  border-radius: inherit;
  opacity: 0;
  transition: opacity .4s ease;
}
.cta-secondary:hover::after {
  opacity: .65;
}
.button-shine {
  position: absolute;
  inset: -60%;
  background: linear-gradient(115deg, transparent 42%, #ffffff2b 50%, transparent 58%);
  transform: translate3d(-110%, 0, 0);
  animation: shimmer 5s var(--motion-ease) infinite;
}
.about-page a:focus-visible {
  outline: 3px solid #38bdf8;
  outline-offset: 6px;
}
.title-highlight .word-reveal::after {
  content: attr(data-word);
  position: absolute;
  inset: 0;
  color: #a5eeff;
  opacity: 0;
  pointer-events: none;
  animation: gradient-breathe 6s ease-in-out infinite;
}
[data-motion-section]:not(.motion-visible) :is(.badge-shimmer, .particles i, .scroll-cue svg, .principle-icon svg, .avatar-sheen, .founder-token, .bridge-energy i, .button-shine) {
  animation-play-state: paused;
}
[data-motion-section]:not(.motion-visible) .title-highlight .word-reveal::after {
  animation-play-state: paused;
}
@keyframes shimmer {
  0%, 65% {
    transform: translate3d(-110%, 0, 0);
  }
  95%, 100% {
    transform: translate3d(110%, 0, 0);
  }
}
@keyframes particle-float {
  from {
    transform: translate3d(0, 0, 0);
    opacity: .15;
  }
  to {
    transform: translate3d(14px, -25px, 0);
    opacity: .65;
  }
}
@keyframes scroll-nudge {
  0%, 100% {
    transform: translate3d(0, -3px, 0);
    opacity: .6;
  }
  50% {
    transform: translate3d(0, 4px, 0);
    opacity: 1;
  }
}
@keyframes icon-float {
  0%, 100% {
    transform: translate3d(0, 2px, 0);
  }
  50% {
    transform: translate3d(0, -2px, 0);
  }
}
@keyframes token-float {
  0%, 100% {
    transform: translate3d(0, 0, 0) rotate(13deg);
  }
  50% {
    transform: translate3d(0, -9px, 0) rotate(4deg);
  }
}
@keyframes bridge-pulse {
  0%, 100% {
    transform: rotate(45deg) scale(.85);
    opacity: .65;
  }
  50% {
    transform: rotate(45deg) scale(1.1);
    opacity: 1;
  }
}
@keyframes gradient-breathe {
  0%, 100% {
    opacity: 0;
  }
  50% {
    opacity: .45;
  }
}
@media (min-width: 1600px) {
  .about-hero {
    min-height: 880px;
  }
}
@media (max-width: 1023px) {
  .principle-card {
    padding: 27px 23px;
  }
  .founders-grid {
    gap: 56px;
  }
  .founder-info {
    padding-inline: 23px;
  }
  .founder-info h3 {
    font-size: 18px;
  }
  .founders-bridge {
    width: 82px;
    left: calc(50% - 41px);
  }
  .founder-role {
    line-height: 1.7;
  }
}
@media (max-width: 767px) {
  .section-shell {
    width: min(540px, calc(100% - 40px));
  }
  .about-hero {
    min-height: 760px;
    padding: 128px 22px 108px;
  }
  .about-hero h1 {
    font-size: clamp(44px, 8.6vw, 66px);
    letter-spacing: -.055em;
  }
  .hero-badge {
    margin-bottom: 29px;
  }
  .hero-description {
    font-size: 15px;
    max-width: 440px;
    margin-top: 35px;
  }
  .hero-emblem {
    margin-top: 29px;
  }
  .hero-orbits {
    transform: scale(.7);
  }
  .hero-wave {
    height: 60px;
  }
  .hero-glow {
    width: 440px;
  }
  .hero-glow-one {
    left: -240px;
  }
  .hero-glow-two {
    right: -200px;
    bottom: -170px;
  }
  .about-principles {
    padding: 62px 0 72px;
  }
  .section-heading {
    margin-bottom: 38px;
  }
  .principles-grid {
    grid-template-columns: 1fr;
    gap: 17px;
  }
  .principle-card {
    padding: 26px 28px;
  }
  .principle-top {
    margin-bottom: 22px;
  }
  .principle-icon {
    width: 50px;
    height: 50px;
  }
  .principle-card h3 {
    font-size: 21px;
    margin-bottom: 10px;
  }
  .principle-card p {
    max-width: 400px;
  }
  .card-baseline {
    margin-top: 22px;
  }
  .principles-circuit {
    display: none;
  }
  .about-founders {
    padding: 72px 0 80px;
  }
  .founders-grid {
    grid-template-columns: 1fr;
    gap: 28px;
  }
  .founder-visual {
    height: 220px;
  }
  .founder-info {
    padding: 0 28px 32px;
  }
  .founder-info h3 {
    font-size: 21px;
  }
  .founder-role {
    font-size: 11px;
  }
  .founder-description {
    max-width: 375px;
    margin-inline: auto;
    font-size: 14px;
  }
  .founders-bridge {
    display: none;
  }
  .founder-entry:not(.founder-night)::after {
    content: '';
    position: absolute;
    top: 100%;
    left: 50%;
    height: 28px;
    width: 1px;
    background: linear-gradient(#2563eb30, #38bdf8, #2563eb30);
  }
  .story-stage {
    min-height: auto;
  }
  .story-sticky {
    position: relative;
    min-height: auto;
    padding: 60px 25px;
  }
  .story-heading {
    margin-bottom: 28px;
  }
  .story-copy p {
    font-size: clamp(22px, 4.3vw, 30px);
    line-height: 1.6;
  }
  .story-copy p.story-secondary {
    font-size: 16px;
    line-height: 1.8;
    margin-top: 25px;
  }
  .story-endmark {
    margin-top: 30px;
  }
  .about-cta {
    padding: 72px 24px 80px;
  }
  .about-cta p {
    font-size: 14px;
  }
  .cta-actions {
    gap: 12px;
  }
  .cta-button {
    gap: 16px;
  }
}
@media (max-width: 380px) {
  .about-hero h1 {
    font-size: 39px;
  }
  .hero-description {
    font-size: 14px;
  }
  .founder-info h3 {
    font-size: 19px;
  }
  .story-sticky {
    padding-inline: 21px;
  }
  .story-copy p {
    font-size: 21px;
  }
  .story-copy p.story-secondary {
    font-size: 15px;
  }
  .cta-actions {
    flex-direction: column;
  }
  .cta-button {
    width: 100%;
  }
}
/* A short viewport must never trap the reading copy behind a sticky panel. */
@media (max-height: 740px), (prefers-reduced-motion: reduce) {
  .story-stage {
    min-height: auto;
  }
  .story-sticky {
    position: relative;
    min-height: auto;
    padding-block: 80px;
  }
}
@media (prefers-reduced-motion: reduce) {
  .about-page *, .about-page *::before, .about-page *::after {
    animation: none !important;
    transition: none !important;
  }
  .story-word-base {
    opacity: 1;
  }
  .story-word-ink {
    opacity: 1 !important;
  }
  .hero-orbits {
    transform: none;
  }
}
</style>
