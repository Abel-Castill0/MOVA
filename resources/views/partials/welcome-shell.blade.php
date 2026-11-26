{{--
    Esqueleto estático del Hero de la portada para invitados.

    MOVA no usa SSR: sin esto, un móvil en LTE veía la pantalla en blanco hasta descargar y ejecutar todo el JS
    de la portada. Este HTML llega con la primera respuesta y se pinta de inmediato (con el CSS de app.css, que ya
    es bloqueante), así que el título, el subtítulo y los botones son el primer contenido visible.

    resources/js/app.js lo elimina en cuanto Vue monta la página real (mismo frame, sin parpadeo). Si el JS
    fallara, queda como una portada mínima con enlaces normales que siguen funcionando.

    Debe reflejar el Hero de resources/js/Pages/Welcome.vue (clases y textos). Si cambias uno, cambia el otro.
--}}
@php
    $shellStats = $page['props']['stats'] ?? [];
    $shellSubjects = count($page['props']['subjects'] ?? []);
    $shellShowStats = ($shellStats['completed'] ?? 0) >= 10 && ($shellStats['teachers'] ?? 0) >= 5;
@endphp
<div id="mova-shell" class="min-h-screen bg-white">
    <section class="mova-hero-bg relative min-h-screen flex items-center overflow-hidden">
        <div class="absolute inset-0 overflow-hidden pointer-events-none" aria-hidden="true">
            <div class="absolute -left-48 top-8 w-[38rem] h-[38rem] rounded-full border border-sky-400/35 shadow-[0_0_35px_rgba(56,189,248,0.25)]"></div>
            <div class="absolute -right-48 -top-8 w-[42rem] h-[42rem] rounded-full border border-sky-400/40 shadow-[0_0_45px_rgba(56,189,248,0.3)]"></div>
            <svg class="absolute inset-0 w-full h-full opacity-35" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <pattern id="hex-grid-shell" width="56" height="96" patternUnits="userSpaceOnUse">
                        <path d="M28 0 L56 16 L56 48 L28 64 L0 48 L0 16 Z M28 64 L56 80 L56 112 L28 128 L0 112 L0 80 Z"
                              fill="none" stroke="rgba(56, 189, 248, 0.42)" stroke-width="1.1" />
                    </pattern>
                </defs>
                <rect width="100%" height="100%" fill="url(#hex-grid-shell)" />
            </svg>
        </div>

        <div class="relative z-[1] max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 pt-16 pb-24 sm:pt-20 sm:pb-28 text-center flex flex-col items-center">
            <div class="relative z-10 inline-flex items-center gap-2.5 px-4 py-1.5 bg-sky-500/10 rounded-full text-xs sm:text-sm font-medium mb-6 border border-sky-400/30 shadow-[0_0_20px_rgba(56,189,248,0.25)]">
                <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-green-400 shadow-[0_0_10px_#4ade80]"></span>
                <span class="text-white">Plataforma educativa en vivo</span>
            </div>

            <h1 class="text-4xl sm:text-5xl md:text-6xl lg:text-7xl font-black leading-[1.05] tracking-tight text-center mb-4 md:mb-6">
                <span class="block text-white">Aprende con</span>
                <span class="block text-amber-400">los mejores</span>
                <span class="block text-white">profesores</span>
            </h1>

            <p class="text-xs sm:text-sm md:text-base lg:text-lg text-white/80 leading-relaxed mb-6 md:mb-8 max-w-[290px] sm:max-w-md md:max-w-xl mx-auto text-center font-normal">
                Conectamos estudiantes con profesores particulares expertos para clases en vivo por videollamada. Personalizado, flexible y efectivo.
            </p>

            <div class="hidden md:flex flex-wrap items-center justify-center gap-4 mb-10">
                <a href="{{ route('marketplace') }}" class="px-8 py-3.5 bg-white text-slate-900 font-bold rounded-full shadow-[0_10px_25px_rgba(0,0,0,0.25)] flex items-center gap-2.5">
                    <span>Buscar clases</span>
                    <span class="text-lg leading-none font-bold" aria-hidden="true">→</span>
                </a>
                <a href="/register?role=teacher" class="px-8 py-3.5 bg-white/5 text-white font-semibold rounded-full border border-white/25">Soy profesor</a>
            </div>

            <div class="md:hidden flex flex-col items-center gap-3 w-full max-w-[295px] mx-auto mb-6">
                <a href="{{ route('marketplace') }}" class="w-full py-3.5 px-6 bg-white text-slate-900 font-bold rounded-full text-sm shadow-[0_8px_20px_rgba(0,0,0,0.2)] flex items-center justify-center gap-2">
                    <span>Buscar clases</span>
                    <span class="text-base leading-none font-bold" aria-hidden="true">→</span>
                </a>
                <a href="/register?role=teacher" class="w-full py-3.5 px-6 bg-white/10 text-white font-bold rounded-full text-sm border border-white/25 flex items-center justify-center gap-2 shadow-[0_4px_15px_rgba(0,0,0,0.15)]">
                    <span>Soy profesor</span>
                </a>
            </div>

            <div class="flex items-center justify-center gap-4 w-full max-w-lg mx-auto mb-8">
                <div class="h-px flex-1 bg-gradient-to-r from-transparent via-white/20 to-white/30"></div>
                <p class="text-center text-xs sm:text-sm text-white/80 sm:whitespace-nowrap">
                    Cada profesor pasa por verificación antes de dictar su primera clase.
                </p>
                <div class="h-px flex-1 bg-gradient-to-l from-transparent via-white/20 to-white/30"></div>
            </div>

            @if ($shellShowStats)
                <div class="hidden md:flex items-center justify-center gap-6 sm:gap-10">
                    @foreach ([[$shellStats['teachers'] ?? 0, 'profesores'], [$shellSubjects, 'materias'], [$shellStats['students'] ?? 0, 'familias'], [$shellStats['completed'] ?? 0, 'clases dictadas']] as $i => [$value, $label])
                        @if ($i > 0)<div class="h-8 w-px bg-white/20"></div>@endif
                        <div class="flex flex-col items-center">
                            <span class="text-2xl sm:text-3xl font-black text-white tabular-nums">{{ $value }}</span>
                            <span class="text-xs sm:text-sm text-white/60 mt-1">{{ $label }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="md:hidden w-full max-w-[325px] mx-auto bg-white/10 rounded-2xl border border-white/15 p-3.5 shadow-xl">
                    <div class="grid grid-cols-4 items-center text-center divide-x divide-white/15">
                        @foreach ([[$shellStats['teachers'] ?? 0, 'profesores'], [$shellSubjects, 'materias'], [$shellStats['students'] ?? 0, 'familias'], [$shellStats['completed'] ?? 0, 'clases dictadas']] as [$value, $label])
                            <div class="flex flex-col items-center px-1">
                                <span class="text-xl font-black text-white tabular-nums leading-none">{{ $value }}</span>
                                <span class="text-[10px] text-white/70 mt-1 leading-tight font-medium">{{ $label }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </section>
</div>
