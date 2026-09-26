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
    <section class="relative min-h-screen flex items-center overflow-hidden bg-[#021840]" style="background: radial-gradient(circle at 10% 18%, #073D91 0%, transparent 45%), radial-gradient(circle at 90% 28%, #063882 0%, transparent 50%), radial-gradient(circle at 50% 95%, #032B67 0%, transparent 55%), #021840;">
      <!-- Background Glowing Hexagonal Grid & Ambient Light Blobs -->
      <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <!-- Ambient radial glow blooms -->
        <div class="absolute -top-24 -left-24 w-[34rem] h-[34rem] bg-blue-600/35 rounded-full blur-[110px]"></div>
        <div class="absolute top-1/4 -right-20 w-[36rem] h-[36rem] bg-blue-500/30 rounded-full blur-[120px]"></div>
        <div class="absolute -bottom-16 left-1/3 w-[32rem] h-[32rem] bg-blue-600/25 rounded-full blur-[100px]"></div>

        <!-- Subtle Orbital Rings from Mockup -->
        <div class="absolute -left-48 top-8 w-[38rem] h-[38rem] rounded-full border border-sky-400/35 shadow-[0_0_35px_rgba(56,189,248,0.25)] pointer-events-none"></div>
        <div class="absolute -right-48 -top-8 w-[42rem] h-[42rem] rounded-full border border-sky-400/40 shadow-[0_0_45px_rgba(56,189,248,0.3)] pointer-events-none"></div>

        <!-- Glowing Star Sparkles -->
        <div class="absolute top-[18%] left-[13%] w-1.5 h-1.5 bg-sky-300 rounded-full shadow-[0_0_10px_#38bdf8]"></div>
        <div class="absolute top-[32%] right-[16%] w-1.5 h-1.5 bg-sky-300 rounded-full shadow-[0_0_10px_#38bdf8]"></div>
        <div class="absolute bottom-[24%] right-[7%] w-2 h-2 bg-sky-300 rounded-full shadow-[0_0_12px_#38bdf8]"></div>

        <!-- Glowing Hexagonal Grid SVG Pattern -->
        <svg class="absolute inset-0 w-full h-full opacity-35" xmlns="http://www.w3.org/2000/svg">
          <defs>
            <pattern id="hex-grid" width="56" height="96" patternUnits="userSpaceOnUse">
              <path d="M28 0 L56 16 L56 48 L28 64 L0 48 L0 16 Z M28 64 L56 80 L56 112 L28 128 L0 112 L0 80 Z" 
                    fill="none" stroke="rgba(56, 189, 248, 0.42)" stroke-width="1.1" />
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
        <h1 class="hero-reveal text-4xl sm:text-5xl md:text-6xl lg:text-7xl font-black leading-[1.05] tracking-tight text-center mb-4 md:mb-6">
          <span class="block text-white">Aprende con</span>
          <span class="block text-amber-400">los mejores</span>
          <span class="block text-white">profesores</span>
        </h1>

        <!-- Subtítulo Centralizado -->
        <p class="hero-reveal text-xs sm:text-sm md:text-base lg:text-lg text-white/80 leading-relaxed mb-6 md:mb-8 max-w-[290px] sm:max-w-md md:max-w-xl mx-auto text-center font-normal">
          Conectamos estudiantes con profesores particulares expertos para clases en vivo por videollamada. Personalizado, flexible y efectivo.
        </p>

        <!-- Botones de Acción Desktop (Hidden on mobile) -->
        <div class="hero-reveal hidden md:flex flex-wrap items-center justify-center gap-4 mb-10">
          <Link :href="user ? route('dashboard') : route('marketplace')"
            class="px-8 py-3.5 bg-white text-slate-900 font-bold rounded-full hover:bg-slate-100 transition-all shadow-[0_10px_25px_rgba(0,0,0,0.25)] hover:scale-105 active:scale-95 flex items-center gap-2.5">
            <span>{{ user ? 'Ir a mi dashboard' : 'Buscar clases' }}</span>
            <span class="text-lg leading-none font-bold" aria-hidden="true">→</span>
          </Link>

          <!-- Botón naranja motion graphic para profesores: Ver solicitudes disponibles -->
          <Link v-if="isTeacher" :href="route('teacher.requests')"
            class="btn-motion-orange group">
            <span class="btn-pulse-dot-orange" aria-hidden="true"></span>
            <span>Ver solicitudes disponibles</span>
            <span class="btn-arrow-icon" aria-hidden="true">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
              </svg>
            </span>
          </Link>

          <!-- Botón verde motion graphic para padres: Reservar clase ahora -->
          <Link v-else-if="isParent" :href="route('class-requests.create')"
            class="btn-motion-green group">
            <span class="btn-pulse-dot" aria-hidden="true"></span>
            <span>Reservar clase ahora</span>
            <span class="btn-arrow-icon" aria-hidden="true">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
              </svg>
            </span>
          </Link>

          <!-- Botón para profesores autenticados u otros roles -->
          <Link v-else-if="user" :href="route('dashboard')"
            class="px-8 py-3.5 bg-white/5 hover:bg-white/15 text-white font-semibold rounded-full border border-white/25 backdrop-blur-sm transition-all hover:scale-105 active:scale-95">
            Ver mis clases
          </Link>

          <!-- Botón para invitados (no logeados) -->
          <Link v-else href="/register?role=teacher"
            class="px-8 py-3.5 bg-white/5 hover:bg-white/15 text-white font-semibold rounded-full border border-white/25 backdrop-blur-sm transition-all hover:scale-105 active:scale-95">
            Soy profesor
          </Link>
        </div>

        <!-- Botones de Acción Mobile (Tal cual la imagen mockup) -->
        <div class="hero-reveal md:hidden flex flex-col items-center gap-3 w-full max-w-[295px] mx-auto mb-6">
          <!-- CASO 1: USUARIO AUTENTICADO (Profesor, Padre u otro) -->
          <template v-if="user">
            <!-- Botón 1: Ir a mi dashboard -->
            <Link :href="route('dashboard')"
              class="w-full py-3.5 px-6 bg-white text-slate-900 font-bold rounded-full text-sm shadow-[0_8px_20px_rgba(0,0,0,0.2)] flex items-center justify-center gap-2 hover:bg-slate-50 active:scale-95 transition-all">
              <span>Ir a mi dashboard</span>
              <span class="text-base leading-none font-bold" aria-hidden="true">→</span>
            </Link>

            <!-- Botón 2 para profesores: Motion Orange pill "Ver solicitudes disponibles →" -->
            <Link v-if="isTeacher" :href="route('teacher.requests')"
              class="btn-motion-orange w-full py-3.5 px-6 rounded-full text-sm font-bold flex items-center justify-center gap-2 group active:scale-95 shadow-[0_8px_25px_rgba(234,88,12,0.35)]">
              <span class="btn-pulse-dot-orange" aria-hidden="true"></span>
              <span>Ver solicitudes disponibles</span>
              <span class="btn-arrow-icon" aria-hidden="true">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                </svg>
              </span>
            </Link>

            <!-- Botón 2 para padres: Motion Green pill "Reservar clase ahora →" -->
            <Link v-else-if="isParent" :href="route('class-requests.create')"
              class="btn-motion-green w-full py-3.5 px-6 rounded-full text-sm font-bold flex items-center justify-center gap-2 group active:scale-95 shadow-[0_8px_25px_rgba(16,185,129,0.35)]">
              <span class="btn-pulse-dot" aria-hidden="true"></span>
              <span>Reservar clase ahora</span>
              <span class="btn-arrow-icon" aria-hidden="true">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                </svg>
              </span>
            </Link>

            <!-- Botón 2 para otros roles autenticados: Ver mis clases -->
            <Link v-else :href="route('dashboard')"
              class="w-full py-3.5 px-6 bg-white/10 hover:bg-white/20 text-white font-bold rounded-full text-sm border border-white/25 backdrop-blur-md flex items-center justify-center gap-2 active:scale-95 transition-all">
              <span>Ver mis clases</span>
            </Link>
          </template>

          <!-- CASO 2: INVITADO / SIN SESIÓN ACTIVA (Coincide exacto con la maqueta) -->
          <template v-else>
            <!-- Botón 1: Buscar clases -->
            <Link :href="route('marketplace')"
              class="w-full py-3.5 px-6 bg-white text-slate-900 font-bold rounded-full text-sm shadow-[0_8px_20px_rgba(0,0,0,0.2)] flex items-center justify-center gap-2 hover:bg-slate-50 active:scale-95 transition-all">
              <span>Buscar clases</span>
              <span class="text-base leading-none font-bold" aria-hidden="true">→</span>
            </Link>

            <!-- Botón 2: Soy profesor -->
            <Link href="/register?role=teacher"
              class="w-full py-3.5 px-6 bg-white/10 hover:bg-white/20 text-white font-bold rounded-full text-sm border border-white/25 backdrop-blur-md flex items-center justify-center gap-2 active:scale-95 transition-all shadow-[0_4px_15px_rgba(0,0,0,0.15)]">
              <span>Soy profesor</span>
            </Link>
          </template>
        </div>

        <!-- Evidencia de plataforma con líneas divisorias laterales (Desktop only) -->
        <div class="hero-reveal hidden md:flex items-center justify-center gap-4 w-full max-w-lg mx-auto mb-8">
          <div class="h-px flex-1 bg-gradient-to-r from-transparent via-white/20 to-white/30"></div>
          <p class="text-xs sm:text-sm text-white/70 whitespace-nowrap">
            Cada profesor pasa por verificación antes de dictar su primera clase.
          </p>
          <div class="h-px flex-1 bg-gradient-to-l from-transparent via-white/20 to-white/30"></div>
        </div>

        <!-- Estadísticas en columnas verticales separadas por divisores (Desktop) -->
        <div class="hero-reveal hidden md:flex items-center justify-center gap-6 sm:gap-10">
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

        <!-- Estadísticas Mobile: Tarjeta Frosted Glass tal cual la imagen -->
        <div class="hero-reveal md:hidden w-full max-w-[325px] mx-auto bg-white/10 backdrop-blur-md rounded-2xl border border-white/15 p-3.5 shadow-xl">
          <div class="grid grid-cols-4 items-center text-center divide-x divide-white/15">
            <div class="flex flex-col items-center px-1">
              <span class="text-xl font-black text-white tabular-nums leading-none">{{ stats.teachers }}</span>
              <span class="text-[10px] text-white/70 mt-1 leading-tight font-medium">profesores</span>
            </div>
            <div class="flex flex-col items-center px-1">
              <span class="text-xl font-black text-white tabular-nums leading-none">{{ subjects.length }}</span>
              <span class="text-[10px] text-white/70 mt-1 leading-tight font-medium">materias</span>
            </div>
            <div class="flex flex-col items-center px-1">
              <span class="text-xl font-black text-white tabular-nums leading-none">{{ stats.students }}</span>
              <span class="text-[10px] text-white/70 mt-1 leading-tight font-medium">familias</span>
            </div>
            <div class="flex flex-col items-center px-1">
              <span class="text-xl font-black text-white tabular-nums leading-none">{{ stats.completed }}</span>
              <span class="text-[10px] text-white/70 mt-1 leading-tight font-medium">clases dictadas</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Wave: -bottom-1 + block + leading-none + scale-y-[1.02] -->
      <div class="absolute -bottom-1 left-0 right-0 leading-none scale-y-[1.02] origin-top pointer-events-none">
        <svg viewBox="0 0 1440 90" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-full block" preserveAspectRatio="none">
          <!-- Capas azules visibles en mobile para profundidad (Mockup) -->
          <path class="md:hidden" d="M0 45C320 15 640 75 960 40C1160 18 1320 60 1440 35V90H0Z" fill="#1D4ED8" fill-opacity="0.35"/>
          <path class="md:hidden" d="M0 60C280 30 580 80 880 55C1140 35 1320 70 1440 50V90H0Z" fill="#3B82F6" fill-opacity="0.25"/>
          <!-- Onda base -->
          <path d="M0 80L60 72C120 64 240 48 360 44C480 40 600 48 720 52C840 56 960 56 1080 50C1200 44 1320 32 1380 26L1440 20V90H0Z" class="fill-white md:fill-[#E5EEFB]"/>
        </svg>
      </div>

      <!-- Botón chevron circular en el centro de la ola (Mobile) -->
      <a href="#como-funciona"
         aria-label="Ver cómo funciona"
         class="md:hidden absolute bottom-3 left-1/2 -translate-x-1/2 z-20 w-8 h-8 rounded-full bg-blue-600/60 hover:bg-blue-600 border border-white/40 backdrop-blur-md flex items-center justify-center text-white shadow-lg shadow-blue-900/40 active:scale-95 transition-all">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7" />
        </svg>
      </a>
    </section>

    <!-- ── Cómo funciona ───────────────────────────────────────────────── -->
    <section id="como-funciona" class="pt-10 pb-12 md:py-24 bg-gradient-to-b from-white via-white to-[#E5EEFB] md:bg-none md:bg-[#E5EEFB] reveal-group scroll-mt-16 sm:scroll-mt-20">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Header Desktop (Hidden on mobile) -->
        <div class="reveal-item hidden md:block text-center mb-16">
          <h2 class="text-4xl font-black text-brand-900 text-balance">¿Cómo funciona MOVA?</h2>
          <p class="text-slate-600 mt-3 max-w-xl mx-auto text-pretty">En tres pasos sencillos estás aprendiendo con tu profesor ideal</p>
        </div>

        <!-- Header Mobile (Tal cual la imagen mockup) -->
        <div class="reveal-item md:hidden text-center mb-8">
          <div class="inline-flex items-center px-3.5 py-1 rounded-full bg-[#DCEBFF] text-[#2563EB] text-xs font-bold mb-3 shadow-2xs">
            ¿Cómo funciona?
          </div>
          <h2 class="text-2xl font-black text-slate-900 tracking-tight leading-tight mb-2">
            Tu próxima clase,<br />
            <span class="text-[#2563EB]">en solo 3 pasos</span>
          </h2>
          <p class="text-xs text-slate-500 font-normal max-w-xs mx-auto leading-relaxed">
            Un proceso simple, rápido y seguro para empezar a aprender.
          </p>
        </div>

        <!-- Grid de pasos Desktop (Hidden on mobile) -->
        <div class="hidden md:grid md:grid-cols-3 gap-8">
          <div v-for="(step, i) in steps" :key="i"
            class="reveal-item group relative bg-white rounded-2xl p-8 border border-gray-100 hover:border-brand-200 shadow-sm hover:shadow-xl hover:shadow-brand-900/5 transition-all duration-300 hover:-translate-y-2"
            @mouseenter="step.title === 'Aprende en vivo' ? playSquirrel() : null"
            @mouseleave="step.title === 'Aprende en vivo' ? stopSquirrel() : null">
            
            <!-- Animación de Ardilla Graduada -->
            <div v-if="step.title === 'Aprende en vivo'"
                 class="absolute bottom-full left-1/2 -translate-x-1/2 w-48 h-48 pointer-events-none z-10 overflow-hidden">
              <div class="w-full h-full transition-transform duration-500 ease-out flex items-end justify-center"
                   :class="squirrelVisible ? 'translate-y-0' : 'translate-y-full'">
                <video
                  ref="squirrelVideo"
                  src="/images/videos/ardillasinfondo.webm"
                  muted
                  playsinline
                  class="w-full object-contain"
                ></video>
              </div>
            </div>

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

        <!-- Fila de pasos Mobile (Tal cual la imagen mockup) -->
        <div class="reveal-item md:hidden flex items-start justify-between max-w-[340px] mx-auto px-1">
          <!-- Paso 1 -->
          <div class="flex flex-col items-center text-center w-20">
            <div class="relative w-16 h-16 rounded-2xl bg-[#D8E6FC] flex items-center justify-center shadow-xs">
              <!-- Calendario SVG -->
              <svg class="w-7 h-7 text-[#2563EB]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 15h2m2 0h2" />
              </svg>
              <!-- Badge 1 -->
              <span class="absolute -top-1.5 -right-1.5 w-5 h-5 rounded-full bg-[#2563EB] text-white text-[11px] font-black flex items-center justify-center ring-2 ring-white shadow-xs">1</span>
            </div>
            <p class="mt-2 text-[11px] font-bold text-slate-900 leading-tight">
              Solicita<br />tu clase
            </p>
          </div>

          <!-- Conector punteado 1 -->
          <div class="flex items-center justify-center pt-6 px-1 flex-1">
            <div class="border-t-2 border-dotted border-slate-300 w-full"></div>
          </div>

          <!-- Paso 2 -->
          <div class="flex flex-col items-center text-center w-20">
            <div class="relative w-16 h-16 rounded-2xl bg-[#FCE5CB] flex items-center justify-center shadow-xs">
              <!-- Usuario/Profesor SVG -->
              <svg class="w-7 h-7 text-[#F59E0B]" fill="currentColor" viewBox="0 0 24 24">
                <path fill-rule="evenodd" d="M12 12a5 5 0 100-10 5 5 0 000 10zm-7 8a7 7 0 1114 0H5z" clip-rule="evenodd" />
              </svg>
              <!-- Badge 2 -->
              <span class="absolute -top-1.5 -right-1.5 w-5 h-5 rounded-full bg-[#F59E0B] text-white text-[11px] font-black flex items-center justify-center ring-2 ring-white shadow-xs">2</span>
            </div>
            <p class="mt-2 text-[11px] font-bold text-slate-900 leading-tight">
              Un experto<br />acepta
            </p>
          </div>

          <!-- Conector punteado 2 -->
          <div class="flex items-center justify-center pt-6 px-1 flex-1">
            <div class="border-t-2 border-dotted border-slate-300 w-full"></div>
          </div>

          <!-- Paso 3 -->
          <div class="flex flex-col items-center text-center w-20">
            <div class="relative w-16 h-16 rounded-2xl bg-[#E6DBFB] flex items-center justify-center shadow-xs">
              <!-- Videocámara SVG -->
              <svg class="w-7 h-7 text-[#8B5CF6]" fill="currentColor" viewBox="0 0 24 24">
                <path d="M4 6a2 2 0 00-2 2v8a2 2 0 002 2h10a2 2 0 002-2v-2.586l3.293 3.293A1 1 0 0021 16V8a1 1 0 00-1.707-.707L16 10.586V8a2 2 0 00-2-2H4z" />
              </svg>
              <!-- Badge 3 -->
              <span class="absolute -top-1.5 -right-1.5 w-5 h-5 rounded-full bg-[#8B5CF6] text-white text-[11px] font-black flex items-center justify-center ring-2 ring-white shadow-xs">3</span>
            </div>
            <p class="mt-2 text-[11px] font-bold text-slate-900 leading-tight">
              Aprende<br />en vivo
            </p>
          </div>
        </div>
      </div>
    </section>

    <!-- ── Materias ────────────────────────────────────────────────────── -->
    <section id="materias" class="pt-1.5 sm:pt-2 pb-10 bg-[#E5EEFB] relative overflow-hidden reveal-group scroll-mt-16 sm:scroll-mt-20">
      <!-- Flecha ascendente y estrellas de crecimiento (Derecha superior) -->
      <div class="absolute top-2 right-2 sm:right-8 lg:right-16 w-44 sm:w-60 lg:w-64 h-32 pointer-events-none select-none z-0 opacity-80">
        <svg class="w-full h-full" viewBox="0 0 320 160" fill="none">
          <defs>
            <linearGradient id="growthArrowGrad" x1="0%" y1="100%" x2="100%" y2="0%">
              <stop offset="0%" stop-color="#93C5FD" stop-opacity="0.2" />
              <stop offset="50%" stop-color="#FFFFFF" stop-opacity="0.8" />
              <stop offset="100%" stop-color="#FFFFFF" stop-opacity="1" />
            </linearGradient>
            <filter id="arrowGlow" x="-20%" y="-20%" width="140%" height="140%">
              <feGaussianBlur stdDeviation="3" result="blur" />
              <feComposite in="SourceGraphic" in2="blur" operator="over" />
            </filter>
          </defs>
          <path d="M 15 145 C 80 145, 150 115, 220 72 C 260 50, 285 28, 304 16" 
                stroke="url(#growthArrowGrad)" stroke-width="3" stroke-linecap="round" filter="url(#arrowGlow)" />
          <path d="M 284 15 L 305 16 L 304 37" 
                stroke="#FFFFFF" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
          <circle cx="115" cy="125" r="3" fill="#FFFFFF" opacity="0.9" />
          <circle cx="205" cy="82" r="3.5" fill="#FEF08A" opacity="0.9" filter="url(#arrowGlow)" />
          <circle cx="260" cy="50" r="3" fill="#FFFFFF" opacity="0.9" />
        </svg>
        <span class="absolute top-4 right-16 text-amber-300 text-base animate-pulse">✦</span>
        <span class="absolute top-14 right-36 text-amber-200 text-xs">✦</span>
      </div>

      <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- ── Cabecera ────────────────────────────────────────────────── -->
        <div class="reveal-item text-center mb-4 sm:mb-4.5">
          <!-- Chip Superior -->
          <div class="inline-flex items-center gap-2 px-3.5 py-0.5 rounded-full bg-white/70 border border-blue-200/60 shadow-xs backdrop-blur-md mb-1.5 text-[10px] font-bold tracking-widest text-blue-600/90 uppercase">
            <span>Aprende</span>
            <span class="text-blue-300">•</span>
            <span>Explora</span>
            <span class="text-blue-300">•</span>
            <span>Crece</span>
          </div>

          <!-- Título Principal -->
          <h2 class="text-3xl sm:text-4xl lg:text-5xl font-black text-slate-900 tracking-tight text-balance">
            Materias <span class="text-blue-600">disponibles</span>
          </h2>

          <!-- Subtítulo -->
          <p class="text-slate-600 text-xs sm:text-sm max-w-xl mx-auto mt-1 font-normal leading-relaxed text-pretty">
            Empieza hoy y descubre nuevas áreas que seguirán creciendo contigo.
          </p>

          <!-- 3 Beneficios con iconos -->
          <div class="flex flex-wrap items-center justify-center gap-4 sm:gap-6 mt-2.5">
            <div class="inline-flex items-center gap-1.5 text-xs sm:text-xs font-semibold text-slate-700">
              <span class="text-sm sm:text-base">📘</span>
              <span>Aprende a tu ritmo</span>
            </div>
            <div class="inline-flex items-center gap-1.5 text-xs sm:text-xs font-semibold text-slate-700">
              <span class="text-sm sm:text-base">📊</span>
              <span>Explora nuevas áreas</span>
            </div>
            <div class="inline-flex items-center gap-1.5 text-xs sm:text-xs font-semibold text-slate-700">
              <span class="text-sm sm:text-base">⭐</span>
              <span>Un futuro con más oportunidades</span>
            </div>
          </div>
        </div>

        <!-- ── Cuadrícula de Materias Activas ───────────────────────────── -->
        <div class="reveal-item grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-2.5 sm:gap-3">
          <Link v-for="s in subjects" :key="s.id"
            :href="`/class-requests/create?subject_id=${s.id}`"
            class="group bg-white/95 hover:bg-white backdrop-blur-md rounded-2xl p-2.5 sm:p-3 flex items-center gap-2.5 sm:gap-3 shadow-xs hover:shadow-lg hover:shadow-blue-500/10 border border-white/80 hover:border-blue-200 transition-all duration-300 hover:-translate-y-0.5">
            
            <!-- Contenedor del Icono con fondo pastel -->
            <div :class="['w-10 h-10 rounded-xl flex items-center justify-center text-base sm:text-lg shadow-2xs flex-shrink-0 transition-transform duration-300 group-hover:scale-105', subjectIcon(s.name).bg]">
              <span v-if="subjectIcon(s.name).type === 'text'" class="font-black text-xs tracking-tight">{{ subjectIcon(s.name).val }}</span>
              <span v-else>{{ subjectIcon(s.name).val }}</span>
            </div>

            <!-- Nombre y Nivel -->
            <div class="min-w-0 flex-1">
              <p class="text-xs sm:text-sm font-bold text-slate-900 group-hover:text-blue-600 transition-colors truncate">
                {{ s.name }}
              </p>
              <div class="mt-0.5">
                <span :class="['text-[10px] font-semibold px-2 py-0.5 rounded-full inline-block capitalize', levelBadge(s.level)]">
                  {{ s.level }}
                </span>
              </div>
            </div>

            <!-- Botón circular flecha derecha -->
            <div class="w-5 h-5 rounded-full bg-blue-50/80 group-hover:bg-blue-600 text-blue-500 group-hover:text-white flex items-center justify-center transition-all duration-200 flex-shrink-0 ml-auto shadow-2xs">
              <svg class="w-3 h-3 transform group-hover:translate-x-0.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
              </svg>
            </div>
          </Link>
        </div>

        <!-- ── Tarjeta Frosted Glass: ¡Esto es solo el comienzo! ──────────── -->
        <div class="reveal-item mt-4 sm:mt-4.5 max-w-5xl mx-auto relative rounded-3xl bg-white/75 backdrop-blur-2xl border border-white/90 shadow-lg shadow-blue-500/5 p-3.5 sm:py-4 sm:px-7 text-center overflow-hidden">
          
          <!-- Trayectoria de vuelo punteada SVG -->
          <svg class="absolute inset-0 w-full h-full pointer-events-none select-none z-0" viewBox="0 0 900 320" fill="none" preserveAspectRatio="none">
            <path d="M 80 180 C 150 180, 210 130, 190 105 C 170 80, 130 130, 210 180 C 350 250, 580 250, 700 170 C 740 145, 770 160, 755 180 C 735 200, 700 170, 740 135 L 775 110" 
                  stroke="#93C5FD" stroke-width="2" stroke-dasharray="6 6" fill="none" opacity="0.75" />
          </svg>

          <!-- Estrellas flotantes dentro de la tarjeta -->
          <span class="absolute top-1/3 left-6 sm:left-12 text-amber-400 text-lg sm:text-xl pointer-events-none select-none animate-pulse">✦</span>
          <span class="absolute bottom-1/3 right-8 sm:right-16 text-amber-300 text-sm sm:text-base pointer-events-none select-none">✦</span>

          <!-- Avión de papel 3D azul -->
          <div class="absolute top-2.5 sm:top-3 right-4 sm:right-7 pointer-events-none select-none z-10 transform -rotate-12 hover:rotate-0 transition-transform">
            <svg class="w-9 sm:w-11 h-9 sm:h-11 drop-shadow-md" viewBox="0 0 100 100" fill="none">
              <polygon points="10,48 90,15 48,88 44,58" fill="#3B82F6" />
              <polygon points="44,58 90,15 48,88" fill="#2563EB" />
              <polygon points="10,48 90,15 44,58" fill="#60A5FA" />
              <polygon points="44,58 48,88 62,64" fill="#1D4ED8" />
            </svg>
          </div>

          <!-- Contenido de la Tarjeta -->
          <div class="relative z-10">
            <!-- Chip superior -->
            <div class="inline-flex items-center gap-1.5 px-3 py-0.5 rounded-full bg-blue-100/90 border border-blue-200/80 text-blue-700 font-bold text-[11px] shadow-xs mb-1.5">
              <span class="text-amber-400">✨</span>
              <span>Nuevas materias próximamente</span>
            </div>

            <!-- Título de la tarjeta -->
            <h3 class="text-lg sm:text-xl lg:text-2xl font-black text-slate-900 tracking-tight">
              ¡Esto es solo el comienzo!
            </h3>

            <!-- Párrafo explicativo -->
            <p class="text-slate-500 text-xs sm:text-xs max-w-xl mx-auto mt-0.5 leading-relaxed font-normal">
              Seguimos trabajando para traerte más materias y nuevas áreas de conocimiento.<br class="hidden sm:inline" />
              Muy pronto tendrás aún más opciones para seguir aprendiendo y alcanzar tus metas.
            </p>

            <!-- 5 Tarjetas Placeholder de materias por venir (Ocultas en celular) -->
            <div class="hidden sm:grid sm:grid-cols-3 md:grid-cols-5 gap-2 sm:gap-3 mt-3.5 sm:mt-4">
              <div class="bg-white/60 hover:bg-white/80 backdrop-blur-md rounded-2xl py-2 sm:py-2.5 px-2.5 flex flex-col items-center justify-center gap-1 border border-white/80 shadow-xs transition-all duration-200 hover:-translate-y-0.5 select-none">
                <div class="w-8 h-8 rounded-lg bg-blue-50/80 flex items-center justify-center text-base sm:text-lg text-blue-500 shadow-2xs">
                  🧪
                </div>
                <span class="text-[10px] sm:text-[11px] font-semibold text-slate-500">Muy pronto</span>
              </div>

              <div class="bg-white/60 hover:bg-white/80 backdrop-blur-md rounded-2xl py-2 sm:py-2.5 px-2.5 flex flex-col items-center justify-center gap-1 border border-white/80 shadow-xs transition-all duration-200 hover:-translate-y-0.5 select-none">
                <div class="w-8 h-8 rounded-lg bg-blue-50/80 flex items-center justify-center text-base sm:text-lg text-blue-500 shadow-2xs">
                  🎨
                </div>
                <span class="text-[10px] sm:text-[11px] font-semibold text-slate-500">Muy pronto</span>
              </div>

              <div class="bg-white/60 hover:bg-white/80 backdrop-blur-md rounded-2xl py-2 sm:py-2.5 px-2.5 flex flex-col items-center justify-center gap-1 border border-white/80 shadow-xs transition-all duration-200 hover:-translate-y-0.5 select-none">
                <div class="w-8 h-8 rounded-lg bg-blue-50/80 flex items-center justify-center text-base sm:text-lg text-blue-500 shadow-2xs">
                  🌍
                </div>
                <span class="text-[10px] sm:text-[11px] font-semibold text-slate-500">Muy pronto</span>
              </div>

              <div class="bg-white/60 hover:bg-white/80 backdrop-blur-md rounded-2xl py-2 sm:py-2.5 px-2.5 flex flex-col items-center justify-center gap-1 border border-white/80 shadow-xs transition-all duration-200 hover:-translate-y-0.5 select-none">
                <div class="w-8 h-8 rounded-lg bg-blue-50/80 flex items-center justify-center text-base sm:text-lg text-blue-500 shadow-2xs">
                  👥
                </div>
                <span class="text-[10px] sm:text-[11px] font-semibold text-slate-500">Muy pronto</span>
              </div>

              <div class="bg-white/60 hover:bg-white/80 backdrop-blur-md rounded-2xl py-2 sm:py-2.5 px-2.5 flex flex-col items-center justify-center gap-1 border border-white/80 shadow-xs transition-all duration-200 hover:-translate-y-0.5 select-none">
                <div class="w-8 h-8 rounded-lg bg-blue-50/80 flex items-center justify-center text-sm sm:text-base text-blue-500 font-black shadow-2xs tracking-widest">
                  •••
                </div>
                <span class="text-[10px] sm:text-[11px] font-semibold text-slate-500">Muy pronto</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ── Profesores destacados ───────────────────────────────────────── -->
    <section id="profesores" class="pt-8 pb-14 sm:py-24 bg-[#E5EEFB] reveal-group scroll-mt-16 sm:scroll-mt-20">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="reveal-item flex flex-col md:flex-row md:items-end justify-between gap-4 sm:gap-6 mb-8 sm:mb-14">
          <div class="max-w-2xl">
            <!-- Chip / Badge de categoría -->
            <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-blue-100/90 border border-blue-200/80 text-blue-700 text-xs font-bold mb-2.5 shadow-2xs">
              <span class="text-amber-500">⭐</span>
              <span>Docentes certificados</span>
            </div>

            <h2 class="text-2xl sm:text-3xl lg:text-4xl font-black text-brand-900 tracking-tight leading-tight">
              Profesores <span class="text-brand-600">destacados</span>
            </h2>

            <p class="text-slate-600 text-xs sm:text-base mt-2 leading-relaxed font-normal">
              Aprende con docentes particulares expertos, evaluados pedagógicamente y con experiencia comprobada en clases en vivo.
            </p>

            <!-- Badges de confianza en móvil y escritorio (llenan el ancho equilibradamente) -->
            <div class="flex items-center gap-2 mt-3.5 flex-wrap">
              <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-white/90 border border-blue-100/80 text-[11px] font-semibold text-slate-700 shadow-2xs">
                <span class="text-emerald-500 font-bold">✓</span> Validación pedagógica
              </span>
              <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-white/90 border border-blue-100/80 text-[11px] font-semibold text-slate-700 shadow-2xs">
                <span class="text-amber-400 font-bold">★</span> 5.0 en valoraciones
              </span>
            </div>
          </div>

          <!-- Controles de navegación Desktop -->
          <div v-if="featuredTeachers.length" class="hidden sm:flex items-center gap-2 flex-shrink-0 mb-1">
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

    <!-- Widget de Chatbot Provisional -->
    <ChatbotWidget v-model="isChatOpen" />
  </div>
</template>

<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import { Swiper, SwiperSlide } from 'swiper/vue'
import { Pagination, Navigation, A11y } from 'swiper/modules'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import LandingNavbar from '@/Components/LandingNavbar.vue'
import LandingFooter from '@/Components/LandingFooter.vue'
import ChatbotWidget from '@/Components/ChatbotWidget.vue'
import 'swiper/css'
import 'swiper/css/pagination'
import 'swiper/css/navigation'

const props = defineProps({
  subjects:         { type: Array,  default: () => [] },
  featuredTeachers: { type: Array,  default: () => [] },
  stats:            { type: Object, default: () => ({ teachers: 0, students: 0, completed: 0 }) },
  testimonials:     { type: Array,  default: () => [] },
})

const page = usePage()
const user = computed(() => page.props.auth?.user)
const isParent = computed(() => {
  const roles = user.value?.roles ?? []
  return roles.includes('parent') || roles.includes('student')
})
const isTeacher = computed(() => (user.value?.roles ?? []).includes('teacher'))

const isChatOpen = ref(false)

const steps = [
  { icon: '🔍', title: 'Solicita tu clase', desc: 'Elige la materia y el nivel que necesitas. Nuestro sistema encuentra los mejores profesores disponibles para ti.' },
  { icon: '🧑‍🏫', title: 'Un experto acepta', desc: 'Un profesor verificado revisará tu solicitud y propondrá un horario que se adapte a tu disponibilidad.' },
  { icon: '🎓', title: 'Aprende en vivo', desc: 'Conéctate por videollamada y aprende de manera personalizada, con ejercicios y seguimiento en tiempo real.' },
]

const squirrelVideo = ref(null)
const squirrelVisible = ref(false)

function playSquirrel() {
  squirrelVisible.value = true
  const videoEl = Array.isArray(squirrelVideo.value) ? squirrelVideo.value[0] : squirrelVideo.value
  if (videoEl) {
    videoEl.currentTime = 0
    videoEl.play().catch(e => console.error("Video play error:", e))
  }
}

function stopSquirrel() {
  squirrelVisible.value = false
  const videoEl = Array.isArray(squirrelVideo.value) ? squirrelVideo.value[0] : squirrelVideo.value
  if (videoEl) {
    setTimeout(() => {
      if (!squirrelVisible.value) {
        videoEl.pause()
        videoEl.currentTime = 0
      }
    }, 500)
  }
}

function subjectEmoji(name) {
  const map = { 'Matemáticas': '📐', 'Física': '⚡', 'Química': '🧪', 'Inglés': '🇬🇧', 'Francés': '🇫🇷', 'Programación': '💻', 'Historia': '📜', 'Biología': '🔬', 'Música': '🎵', 'Arte': '🎨', 'Economía': '📊', 'Derecho': '⚖️', 'Contabilidad': '🧮', 'Cálculo': '∫', 'Estadística': '📈' }
  return Object.entries(map).find(([k]) => name.includes(k))?.[1] ?? '📚'
}

function subjectIcon(name) {
  const n = name || ''
  if (n.includes('Ciencias')) return { type: 'emoji', val: '🍃', bg: 'bg-emerald-50 text-emerald-500' }
  if (n.includes('Física')) return { type: 'emoji', val: '⚡', bg: 'bg-orange-50 text-orange-500' }
  if (n.includes('Inglés')) return { type: 'text', val: 'GB', bg: 'bg-blue-50 text-blue-600' }
  if (n.includes('Lengua')) return { type: 'emoji', val: '📖', bg: 'bg-fuchsia-50 text-fuchsia-600' }
  if (n.includes('Matemáticas') || n.includes('Cálculo')) return { type: 'emoji', val: '📐', bg: 'bg-blue-50 text-blue-600' }
  if (n.includes('Programación')) return { type: 'emoji', val: '💻', bg: 'bg-sky-50 text-sky-600' }
  if (n.includes('Química')) return { type: 'emoji', val: '🧪', bg: 'bg-cyan-50 text-cyan-600' }
  if (n.includes('Historia')) return { type: 'emoji', val: '📜', bg: 'bg-amber-50 text-amber-600' }
  if (n.includes('Biología')) return { type: 'emoji', val: '🔬', bg: 'bg-emerald-50 text-emerald-600' }
  if (n.includes('Música')) return { type: 'emoji', val: '🎵', bg: 'bg-violet-50 text-violet-600' }
  if (n.includes('Arte')) return { type: 'emoji', val: '🎨', bg: 'bg-pink-50 text-pink-600' }
  return { type: 'emoji', val: '📚', bg: 'bg-blue-50 text-blue-600' }
}

function levelColor(level) {
  return { primaria: 'bg-green-50 text-green-700', secundaria: 'bg-blue-50 text-blue-700', universidad: 'bg-purple-50 text-purple-700', todos: 'bg-slate-100 text-slate-600' }[level] ?? 'bg-gray-50 text-gray-600'
}

function levelBadge(level) {
  const l = (level || '').toLowerCase()
  if (l === 'primaria') return 'bg-emerald-50 text-emerald-600 border border-emerald-100/90'
  if (l === 'secundaria') return 'bg-blue-50 text-blue-600 border border-blue-100/90'
  if (l === 'universidad') return 'bg-purple-50 text-purple-600 border border-purple-100/90'
  return 'bg-slate-100 text-slate-500 border border-slate-200/70'
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

@media (max-width: 768px) {
  .math-sym {
    opacity: 0.45;
  }
  .math-sym-1  { font-size: 2.5rem; left: 3%; top: 14%; }
  .math-sym-2  { font-size: 2rem; }
  .math-sym-3  { font-size: 2.2rem; }
  .math-sym-4  { font-size: 2.4rem; left: 4%; }
  .math-sym-5  { font-size: 2.5rem; right: 5%; top: 15%; }
  .math-sym-6  { font-size: 2.2rem; right: 4%; }
  .math-sym-7  { font-size: 2.2rem; right: 6%; }
  .math-sym-8  { font-size: 2.3rem; right: 6%; }
  .math-sym-9  { font-size: 2.8rem; right: 4%; }
  .math-sym-10 { font-size: 2.2rem; left: 4%; }
  .math-sym-11 { font-size: 2rem; }
}

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

/* ── Botones Motion Graphic: Verde (Padre) y Naranja (Profesor) ──────────── */
.btn-motion-green,
.btn-motion-orange {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.625rem;
  padding: 0.875rem 2rem;
  border-radius: 9999px;
  font-weight: 800;
  font-size: 0.9375rem;
  color: #ffffff;
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.25);
  cursor: pointer;
  overflow: hidden;
  text-decoration: none;
  transition:
    transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1),
    box-shadow 0.35s ease,
    border-color 0.3s ease,
    background 0.3s ease;
  will-change: transform, box-shadow;
}

/* ── Variante Verde (Padre) ── */
.btn-motion-green {
  background: linear-gradient(135deg, #10b981 0%, #059669 50%, #047857 100%);
  border: 1px solid rgba(167, 243, 208, 0.55);
  box-shadow:
    0 4px 18px rgba(16, 185, 129, 0.4),
    0 0 25px rgba(52, 211, 153, 0.25),
    inset 0 1px 0 rgba(255, 255, 255, 0.35);
}

.btn-motion-green::before {
  content: '';
  position: absolute;
  inset: -3px;
  border-radius: 9999px;
  background: linear-gradient(135deg, #34d399, #10b981, #059669);
  z-index: -1;
  opacity: 0.45;
  filter: blur(10px);
  transition: opacity 0.4s ease, filter 0.4s ease;
}

/* ── Variante Naranja (Profesor) ── */
.btn-motion-orange {
  background: linear-gradient(135deg, #f97316 0%, #ea580c 50%, #c2410c 100%);
  border: 1px solid rgba(254, 215, 170, 0.55);
  box-shadow:
    0 4px 18px rgba(234, 88, 12, 0.4),
    0 0 25px rgba(249, 115, 22, 0.25),
    inset 0 1px 0 rgba(255, 255, 255, 0.35);
}

.btn-motion-orange::before {
  content: '';
  position: absolute;
  inset: -3px;
  border-radius: 9999px;
  background: linear-gradient(135deg, #fb923c, #ea580c, #c2410c);
  z-index: -1;
  opacity: 0.45;
  filter: blur(10px);
  transition: opacity 0.4s ease, filter 0.4s ease;
}

/* Destello de luz diagonal (shimmer / streak) estilo Motion Graphics */
.btn-motion-green::after,
.btn-motion-orange::after {
  content: '';
  position: absolute;
  top: -60%;
  left: -70%;
  width: 50%;
  height: 220%;
  background: linear-gradient(
    90deg,
    rgba(255, 255, 255, 0) 0%,
    rgba(255, 255, 255, 0.6) 50%,
    rgba(255, 255, 255, 0) 100%
  );
  transform: rotate(26deg) translateX(-120%);
  transition: transform 0.7s cubic-bezier(0.4, 0, 0.2, 1);
  pointer-events: none;
}

/* Hover: Rebote spring elástico, resplandor amplificado y barrido de luz */
.btn-motion-green:hover {
  transform: translateY(-2px) scale(1.05);
  border-color: rgba(209, 250, 229, 0.95);
  background: linear-gradient(135deg, #34d399 0%, #10b981 45%, #059669 100%);
  box-shadow:
    0 10px 32px rgba(16, 185, 129, 0.55),
    0 0 50px rgba(52, 211, 153, 0.65),
    inset 0 1px 1px rgba(255, 255, 255, 0.55);
}

.btn-motion-green:hover::before {
  opacity: 0.9;
  filter: blur(16px);
}

.btn-motion-orange:hover {
  transform: translateY(-2px) scale(1.05);
  border-color: rgba(255, 237, 213, 0.95);
  background: linear-gradient(135deg, #fb923c 0%, #ea580c 45%, #c2410c 100%);
  box-shadow:
    0 10px 32px rgba(234, 88, 12, 0.55),
    0 0 50px rgba(249, 115, 22, 0.65),
    inset 0 1px 1px rgba(255, 255, 255, 0.55);
}

.btn-motion-orange:hover::before {
  opacity: 0.9;
  filter: blur(16px);
}

.btn-motion-green:hover::after,
.btn-motion-orange:hover::after {
  transform: rotate(26deg) translateX(600%);
}

.btn-motion-green:active {
  transform: translateY(1px) scale(0.97);
  box-shadow:
    0 3px 12px rgba(16, 185, 129, 0.45),
    0 0 20px rgba(52, 211, 153, 0.35);
}

.btn-motion-orange:active {
  transform: translateY(1px) scale(0.97);
  box-shadow:
    0 3px 12px rgba(234, 88, 12, 0.45),
    0 0 20px rgba(249, 115, 22, 0.35);
}

/* Ícono flecha con micro-animación spring */
.btn-motion-green .btn-arrow-icon,
.btn-motion-orange .btn-arrow-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.btn-motion-green:hover .btn-arrow-icon,
.btn-motion-orange:hover .btn-arrow-icon {
  transform: translateX(4px) scale(1.15);
}

/* Indicador pulsante verde vivo (Padre) */
.btn-pulse-dot {
  position: relative;
  display: inline-flex;
  width: 7px;
  height: 7px;
  border-radius: 9999px;
  background-color: #ffffff;
  box-shadow: 0 0 8px #6ee7b7;
  flex-shrink: 0;
}

.btn-pulse-dot::before {
  content: '';
  position: absolute;
  inset: -3px;
  border-radius: 9999px;
  background-color: #a7f3d0;
  opacity: 0.8;
  animation: pingPulse 2s cubic-bezier(0, 0, 0.2, 1) infinite;
}

/* Indicador pulsante naranja vivo (Profesor) */
.btn-pulse-dot-orange {
  position: relative;
  display: inline-flex;
  width: 7px;
  height: 7px;
  border-radius: 9999px;
  background-color: #ffffff;
  box-shadow: 0 0 8px #fdba74;
  flex-shrink: 0;
}

.btn-pulse-dot-orange::before {
  content: '';
  position: absolute;
  inset: -3px;
  border-radius: 9999px;
  background-color: #fed7aa;
  opacity: 0.8;
  animation: pingPulse 2s cubic-bezier(0, 0, 0.2, 1) infinite;
}

@keyframes pingPulse {
  75%, 100% {
    transform: scale(2.4);
    opacity: 0;
  }
}
</style>
