<script setup>
import InputError from '@/Components/InputError.vue';
import MovaLogo from '@/Components/MovaLogo.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { onMounted, onUnmounted } from 'vue';

defineProps({
    status: {
        type: String,
        default: null,
    },
});

const form = useForm({
    email: '',
});

const year = new Date().getFullYear();

// En la vista de recuperación en desktop, mantenemos el viewport limpio sin scrollbars no deseados
let bodyObserver = null;
onMounted(() => {
    if (window.innerWidth >= 1024) {
        document.body.style.paddingBottom = '0px';
        document.body.style.overflow = 'hidden';
        bodyObserver = new MutationObserver(() => {
            if (document.body.style.paddingBottom && document.body.style.paddingBottom !== '0px') {
                document.body.style.paddingBottom = '0px';
            }
            if (document.body.style.overflow !== 'hidden') {
                document.body.style.overflow = 'hidden';
            }
        });
        bodyObserver.observe(document.body, { attributes: true, attributeFilter: ['style'] });
    }
});

onUnmounted(() => {
    if (bodyObserver) {
        bodyObserver.disconnect();
    }
    document.body.style.paddingBottom = '';
    document.body.style.overflow = '';
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <Head title="¿Olvidaste tu contraseña? – MOVA" />

    <div class="min-h-screen lg:h-screen flex flex-col lg:flex-row bg-[#F8FAFC] overflow-x-hidden lg:overflow-hidden select-none">
        <!-- ========================================================= -->
        <!-- PANEL IZQUIERDO: Branding & Ilustración (~45%)            -->
        <!-- ========================================================= -->
        <div class="hidden lg:flex lg:w-[46%] xl:w-[45%] relative flex-col justify-between overflow-hidden bg-gradient-to-br from-[#1B64CC] via-[#1350A5] to-[#09306E] p-6 xl:p-9 2xl:p-12">
            <!-- Capas decorativas de fondo -->
            <img
                src="/images/brand/forgot-password-blue-shapes-opt.png"
                alt=""
                class="absolute inset-0 w-full h-full object-cover object-center opacity-30 mix-blend-screen pointer-events-none z-0"
                loading="eager"
                decoding="async"
            />
            <img
                src="/images/brand/forgot-password-light-bubbles-opt.png"
                alt=""
                class="absolute -top-10 -right-10 w-96 h-auto opacity-20 mix-blend-screen pointer-events-none z-0 blur-sm"
                loading="eager"
                decoding="async"
            />

            <!-- Header superior izquierdo: Logo MOVA original en blanco -->
            <div class="relative z-10 pt-1">
                <Link href="/" class="inline-flex items-center focus:outline-none focus:ring-2 focus:ring-white/40 rounded-lg">
                    <MovaLogo theme="blanco" class="h-8 xl:h-9 w-auto" />
                </Link>
            </div>

            <!-- Centro: Composición de Ilustración Central + Badges Flotantes -->
            <div class="relative z-10 w-full max-w-[390px] xl:max-w-[450px] 2xl:max-w-[490px] mx-auto my-auto py-6 flex items-center justify-center">
                <!-- Wrapper relativo donde todos los elementos flotantes están anclados 1:1 -->
                <div class="relative w-full">
                    <!-- Patrón de matriz de puntos decorativo (esquina superior derecha, idéntico al mockup) -->
                    <svg class="absolute -top-6 -right-5 w-28 h-20 text-white/20 pointer-events-none z-0" fill="currentColor">
                        <pattern id="mova-dot-grid" x="0" y="0" width="14" height="14" patternUnits="userSpaceOnUse">
                            <circle cx="2" cy="2" r="1.5" />
                        </pattern>
                        <rect width="100%" height="100%" fill="url(#mova-dot-grid)" />
                    </svg>

                    <!-- Badge Reporte Naranja (Esquina superior derecha de la ventana, ligeramente superpuesto) -->
                    <img
                        src="/images/brand/forgot-password-report-opt.png"
                        alt="Reporte"
                        class="absolute -top-3.5 xl:-top-4 right-[1%] xl:right-[2%] w-[26%] xl:w-[27%] h-auto object-contain z-20 drop-shadow-md pointer-events-none"
                        loading="eager"
                        decoding="async"
                    />

                    <!-- Widget Analytics (Lateral izquierdo de la ventana) -->
                    <img
                        src="/images/brand/forgot-password-analytics-opt.png"
                        alt=""
                        class="absolute -left-6 xl:-left-8 top-[34%] xl:top-[35%] w-[14%] xl:w-[15%] h-auto object-contain z-20 drop-shadow-lg pointer-events-none"
                        loading="eager"
                        decoding="async"
                    />

                    <!-- Badge Rating 5.0 (Inferior izquierdo de la ventana) -->
                    <img
                        src="/images/brand/forgot-password-rating-opt.png"
                        alt="5.0 estrellas"
                        class="absolute left-[8%] xl:left-[10%] -bottom-3.5 xl:-bottom-4 w-[29%] xl:w-[30%] h-auto object-contain z-20 drop-shadow-lg pointer-events-none"
                        loading="eager"
                        decoding="async"
                    />

                    <!-- Widget Video (Lateral inferior derecho de la ventana) -->
                    <img
                        src="/images/brand/forgot-password-video-widget-opt.png"
                        alt=""
                        class="absolute -right-6 xl:-right-7 bottom-[11%] xl:bottom-[12%] w-[26%] xl:w-[27%] h-auto object-contain z-20 drop-shadow-lg pointer-events-none"
                        loading="eager"
                        decoding="async"
                    />

                    <!-- Ventana principal con videollamada -->
                    <img
                        src="/images/brand/forgot-password-videocall-window-opt.png"
                        alt="Videollamada con profesores particulares"
                        class="w-full h-auto object-contain z-10 drop-shadow-2xl block"
                        loading="eager"
                        decoding="async"
                    />
                </div>
            </div>

            <!-- Zona inferior: Titulares de Marca + Copyright -->
            <div class="relative z-10">
                <div class="max-w-lg mb-4 xl:mb-6">
                    <h1 class="text-2xl xl:text-3xl 2xl:text-[34px] font-extrabold text-white leading-[1.18] tracking-tight">
                        Profesores particulares<br />
                        verificados, en vivo<br />
                        por videollamada.
                    </h1>
                    <p class="mt-2 xl:mt-2.5 text-white/85 text-xs xl:text-[14px] 2xl:text-[15px] leading-relaxed max-w-md font-normal">
                        Inicia sesión para gestionar tus clases,<br class="hidden xl:inline" />
                        solicitudes o tu perfil.
                    </p>
                </div>

                <!-- Footer inferior izquierdo: Copyright -->
                <div class="pt-1 text-white/50 text-xs">
                    © {{ year }} MOVA
                </div>
            </div>
        </div>

        <!-- ========================================================= -->
        <!-- PANEL DERECHO: Card de Recuperación (~55%)                -->
        <!-- ========================================================= -->
        <div class="flex-1 flex flex-col justify-between p-4 sm:p-6 lg:p-8 xl:p-10 min-h-screen lg:min-h-0 lg:overflow-y-auto relative bg-[#F8FAFC] overflow-x-hidden select-text">
            <!-- Decoraciones sutiles de fondo en panel derecho -->
            <img
                src="/images/brand/forgot-password-light-bubbles-opt.png"
                alt=""
                class="hidden lg:block absolute -top-16 -right-16 w-80 h-auto opacity-35 pointer-events-none blur-sm z-0 select-none"
            />
            <div class="hidden lg:block absolute -bottom-24 -left-20 w-80 h-80 rounded-full bg-blue-100/30 pointer-events-none blur-3xl z-0 select-none"></div>

            <!-- Header Móvil: Solo visible en pantallas pequeñas -->
            <header class="lg:hidden flex items-center justify-between pb-3 pt-1 border-b border-slate-200/70 relative z-10 select-none">
                <Link href="/" class="flex items-center">
                    <MovaLogo class="h-7 w-auto" />
                </Link>
                <Link :href="route('login')" class="text-xs font-bold text-[#1B60C4] hover:text-[#154FA6]">
                    Iniciar sesión
                </Link>
            </header>

            <!-- Contenedor central con la Card blanca de Recuperación -->
            <main class="flex-1 flex items-center justify-center py-4 sm:py-6 relative z-10">
                <div class="w-full max-w-[460px] xl:max-w-[480px] bg-white rounded-2xl sm:rounded-[26px] border border-slate-100/90 shadow-[0_16px_50px_-12px_rgba(15,23,42,0.06)] p-6 sm:p-9 xl:p-10">
                    <!-- Ilustración superior: Correo con candado 3D y halo luminoso -->
                    <div class="relative flex justify-center items-center mb-4 sm:mb-5 select-none">
                        <div class="absolute w-28 h-28 rounded-full bg-blue-100/60 blur-xl pointer-events-none"></div>
                        <img
                            src="/images/brand/forgot-password-email-lock-opt.png"
                            alt="Recuperación de contraseña"
                            class="relative z-10 w-28 sm:w-32 xl:w-36 h-auto object-contain drop-shadow-sm select-none pointer-events-none"
                            loading="eager"
                            decoding="async"
                        />
                    </div>

                    <!-- Título y descripción -->
                    <div class="text-center mb-6">
                        <h2 class="text-2xl sm:text-[27px] font-black text-slate-900 tracking-tight">
                            ¿Olvidaste tu contraseña?
                        </h2>
                        <p class="text-sm text-slate-500 mt-2 max-w-xs sm:max-w-sm mx-auto leading-relaxed font-normal">
                            No te preocupes. Ingresa tu correo electrónico y te enviaremos un enlace para restablecerla.
                        </p>
                    </div>

                    <!-- Mensaje de estado/éxito (Status backend) -->
                    <div v-if="status" class="mb-5 p-4 rounded-xl bg-blue-50/90 border border-blue-200 text-blue-950 text-sm flex items-start gap-3 text-left">
                        <div class="w-6 h-6 rounded-full bg-[#1B60C4] flex items-center justify-center text-white flex-shrink-0 mt-0.5">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                        <div>
                            <p class="font-bold text-slate-900">✓ Enlace enviado</p>
                            <p class="text-xs sm:text-[13px] text-slate-600 mt-0.5 leading-normal">
                                Hemos enviado las instrucciones de recuperación a tu correo. Revisa también tu carpeta de spam.
                            </p>
                        </div>
                    </div>

                    <!-- Formulario de Recuperación -->
                    <form @submit.prevent="submit" class="space-y-4">
                        <!-- Campo: Correo Electrónico -->
                        <div>
                            <label for="email" class="block text-xs sm:text-[13px] font-semibold text-slate-800 mb-1.5 text-left">
                                Correo electrónico
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                    </svg>
                                </div>
                                <input
                                    id="email"
                                    type="email"
                                    v-model="form.email"
                                    required
                                    autofocus
                                    autocomplete="email"
                                    placeholder="tu@correo.com"
                                    aria-describedby="email-error"
                                    class="w-full h-[50px] sm:h-[52px] pl-11 pr-4 rounded-xl border border-slate-200 text-slate-900 text-sm placeholder:text-slate-400 bg-white transition-all duration-150 hover:border-slate-300 focus:border-[#1B60C4] focus:ring-2 focus:ring-[#1B60C4]/20 focus:outline-none"
                                    :class="{ 'border-rose-400 bg-rose-50/40 focus:border-rose-500 focus:ring-rose-500/20': form.errors.email }"
                                />
                            </div>
                            <InputError id="email-error" class="mt-1.5 text-left" :message="form.errors.email" />
                        </div>

                        <!-- Botón Principal: Enviar enlace de recuperación -->
                        <div class="pt-1">
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="w-full h-[50px] sm:h-[52px] flex items-center justify-center gap-2 rounded-xl bg-[#1B60C4] hover:bg-[#154FA6] active:bg-[#104088] text-white text-sm sm:text-base font-semibold shadow-sm transition-all duration-150 active:scale-[0.99] disabled:opacity-50 disabled:cursor-not-allowed focus:outline-none focus:ring-2 focus:ring-[#1B60C4]/40"
                            >
                                <svg v-if="form.processing" class="animate-spin h-5 w-5 text-white mr-1" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>Enviar enlace de recuperación</span>
                                <span v-if="!form.processing" class="text-lg leading-none">→</span>
                            </button>
                        </div>
                    </form>

                    <!-- Divisor con enlace para volver al login -->
                    <div class="my-5 flex items-center gap-3 select-none">
                        <div class="h-px flex-1 bg-slate-200"></div>
                        <Link
                            :href="route('login')"
                            class="text-xs sm:text-sm font-semibold text-[#1B60C4] hover:text-[#154FA6] transition-colors focus:outline-none focus:underline"
                        >
                            Volver al inicio de sesión
                        </Link>
                        <div class="h-px flex-1 bg-slate-200"></div>
                    </div>

                    <!-- Mensaje informativo inferior -->
                    <div class="p-3 sm:p-3.5 rounded-xl bg-[#EFF6FF] border border-[#DBEAFE] flex items-center gap-3 text-left select-none">
                        <div class="w-6 h-6 rounded-full bg-[#1B60C4] flex items-center justify-center text-white text-xs font-bold flex-shrink-0">
                            <svg class="w-3.5 h-3.5 fill-current" viewBox="0 0 24 24">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/>
                            </svg>
                        </div>
                        <span class="text-xs sm:text-[13px] font-medium text-slate-700">
                            Revisa tu bandeja de entrada y spam.
                        </span>
                    </div>
                </div>
            </main>

            <!-- Footer con enlaces legales reales -->
            <footer class="pt-3 pb-2 text-center text-xs text-slate-400 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 relative z-10 select-none">
                <Link :href="route('legal.terms')" class="hover:text-slate-600 transition-colors">Términos y Condiciones</Link>
                <span class="text-slate-300">|</span>
                <Link :href="route('legal.privacy')" class="hover:text-slate-600 transition-colors">Política de Privacidad</Link>
                <span class="text-slate-300">|</span>
                <a href="mailto:m0v4class@gmail.com" class="hover:text-slate-600 transition-colors">Soporte</a>
                <span class="text-slate-300">|</span>
                <span>© {{ year }} MOVA. Todos los derechos reservados.</span>
            </footer>
        </div>
    </div>
</template>
