
<script setup>
import Icon from '@/Components/Icon.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import MovaLogo from '@/Components/MovaLogo.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';

const showGoogleModal = ref(false);
const showPassword = ref(false);

defineProps({
    canResetPassword: {
        type: Boolean,
        default: true,
    },
    status: {
        type: String,
        default: null,
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const year = new Date().getFullYear();

// En la vista de login en desktop, mantenemos el viewport limpio sin scrollbars no deseados
let bodyObserver = null;
onMounted(() => {
    if (window.innerWidth >= 1024) {
        document.body.style.paddingBottom = '0px';
        document.body.style.overflow = 'hidden';
        bodyObserver = new MutationObserver(() => {
            if (document.body.style.paddingBottom && document.body.style.paddingBottom !== '0px') {
                document.body.style.paddingBottom = '0px';
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
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Iniciar sesión – MOVA" />

    <div class="min-h-screen lg:h-screen flex flex-col lg:flex-row bg-[#F8FAFC] overflow-x-hidden lg:overflow-hidden">
        <!-- ========================================================= -->
        <!-- PANEL IZQUIERDO: Branding & Ilustración (~46%)            -->
        <!-- ========================================================= -->
        <div class="hidden lg:flex lg:w-[46%] xl:w-[45%] relative flex-col justify-between overflow-hidden bg-gradient-to-br from-[#1B64CC] via-[#1350A5] to-[#09306E] p-6 xl:p-9 2xl:p-12 select-none">
            <!-- Capa de textura decorativa de fondo -->
            <img
                src="/images/brand/login-blue-decoration.png"
                alt=""
                class="absolute inset-0 w-full h-full object-cover object-right-top opacity-30 mix-blend-screen pointer-events-none z-0"
                loading="eager"
                decoding="async"
            />

            <!-- Header superior izquierdo: Logo MOVA original en blanco -->
            <div class="relative z-10 pt-1">
                <Link href="/" class="inline-flex items-center focus:outline-none focus:ring-2 focus:ring-white/40 rounded-lg">
                    <MovaLogo theme="blanco" class="h-8 xl:h-9 w-auto" />
                </Link>
            </div>

            <!-- Centro: Titulares de Marca -->
            <div class="relative z-10 max-w-lg mt-3 xl:mt-4">
                <h1 class="text-2xl xl:text-3xl 2xl:text-[36px] font-extrabold text-white leading-[1.18] tracking-tight">
                    Profesores particulares<br />
                    verificados, en vivo<br />
                    <span class="text-[#67C3F3]">por videollamada.</span>
                </h1>
                <p class="mt-2.5 xl:mt-3 text-white/85 text-xs xl:text-[14px] 2xl:text-[15px] leading-relaxed max-w-md">
                    Inicia sesión para gestionar tus clases,<br class="hidden xl:inline" />
                    solicitudes o tu perfil.
                </p>
            </div>

            <!-- Zona inferior: Composición de Ilustración + Nota manuscrita -->
            <div class="relative z-10 w-full max-w-[420px] xl:max-w-[460px] 2xl:max-w-[500px] mx-auto mt-auto pt-8 pb-1 flex flex-col items-center">
                <!-- Anotación decorativa manuscrita con flecha (ubicada arriba a la derecha de la laptop) -->
                <div class="absolute -top-10 xl:-top-12 right-4 xl:right-8 flex flex-col items-center pointer-events-none z-30">
                    <span class="text-[#93C5FD] text-xs xl:text-[13px] font-medium tracking-wide leading-tight text-center -rotate-6 select-none opacity-95" style="font-family: 'Segoe Print', 'Bradley Hand', 'Comic Sans MS', cursive, sans-serif;">
                        Aprender<br />también<br />transforma
                    </span>
                    <!-- Flecha curva orgánica apuntando hacia la videollamada -->
                    <svg class="w-5 h-5 text-[#93C5FD] mt-0.5 -rotate-6 opacity-85" viewBox="0 0 28 28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 4 C 22 12, 16 19, 8 21" />
                        <path d="M8 21 L 14 20" />
                        <path d="M8 21 L 11 15" />
                    </svg>
                </div>

                <!-- Contenedor Laptop + Elementos superpuestos -->
                <div class="relative w-full flex items-end justify-center">
                    <!-- Wrapper relativo exclusivo de la laptop para anclaje 1:1 -->
                    <div class="relative w-full max-w-[340px] xl:max-w-[390px] 2xl:max-w-[430px]">
                        <!-- Badge Reporte Naranja (anclado a la esquina superior derecha de la pantalla de la laptop) -->
                        <img
                            src="/images/brand/login-report-badge-opt.png"
                            alt="Reporte"
                            class="absolute -top-3.5 right-[16%] xl:right-[15%] w-[23%] h-auto object-contain z-20 drop-shadow-md pointer-events-none"
                            loading="eager"
                            decoding="async"
                        />

                        <!-- Libros + Planta (en la base izquierda de la laptop) -->
                        <img
                            src="/images/brand/login-books-plant-opt.png"
                            alt=""
                            class="absolute -left-3 xl:-left-5 bottom-0 w-[27%] h-auto object-contain z-20 drop-shadow-lg pointer-events-none"
                            loading="eager"
                            decoding="async"
                        />

                        <!-- Laptop con videollamada (ilustración principal) -->
                        <img
                            src="/images/brand/login-videocall-laptop-opt.png"
                            alt="Clases por videollamada"
                            class="w-full h-auto object-contain z-10 drop-shadow-2xl block"
                            loading="eager"
                            decoding="async"
                        />
                    </div>
                </div>
            </div>

            <!-- Footer inferior izquierdo: Copyright -->
            <div class="relative z-10 pt-2 text-white/50 text-xs">
                © {{ year }} MOVA
            </div>
        </div>

        <!-- ========================================================= -->
        <!-- PANEL DERECHO: Formulario de Login (~53%)                 -->
        <!-- ========================================================= -->
        <div class="flex-1 flex flex-col justify-between p-4 sm:p-6 lg:p-8 xl:p-10 min-h-screen lg:min-h-0 lg:overflow-y-auto relative bg-[#F8FAFC] overflow-x-hidden">
            <!-- Decoración circular muy sutil en esquina superior derecha -->
            <div class="hidden lg:block absolute -top-24 -right-24 w-96 h-96 rounded-full bg-blue-100/40 pointer-events-none blur-3xl z-0"></div>

            <!-- Header Móvil: Solo visible en pantallas pequeñas -->
            <header class="lg:hidden flex items-center justify-between pb-3 pt-1 border-b border-slate-200/70 relative z-10">
                <Link href="/" class="flex items-center">
                    <MovaLogo class="h-7 w-auto" />
                </Link>
                <Link :href="route('register')" class="text-xs font-bold text-brand-600 hover:text-brand-700">
                    Registrarse
                </Link>
            </header>

            <!-- Contenedor central con la Card blanca de Login -->
            <main class="flex-1 flex items-center justify-center py-4 sm:py-6 relative z-10">
                <div class="w-full max-w-[480px] xl:max-w-[500px] bg-white rounded-2xl sm:rounded-[22px] border border-slate-100/90 shadow-[0_12px_40px_-10px_rgba(15,23,42,0.06)] p-5 sm:p-8 xl:p-10">
                    <!-- Título y subtítulo -->
                    <div class="mb-6">
                        <h2 class="text-2xl sm:text-[27px] font-black text-slate-900 tracking-tight">
                            Bienvenido de vuelta
                        </h2>
                        <p class="text-sm text-slate-500 mt-1.5 font-normal">
                            Inicia sesión para continuar en MOVA.
                        </p>
                    </div>

                    <!-- Mensaje de estado/éxito -->
                    <div v-if="status" class="mb-4 p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm font-medium flex items-center gap-2">
                        <svg class="w-5 h-5 text-emerald-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        <span>{{ status }}</span>
                    </div>

                    <!-- Botón Google Login -->
                    <button
                        type="button"
                        @click="showGoogleModal = true"
                        class="w-full h-[50px] sm:h-[52px] flex items-center justify-center gap-3 rounded-xl border border-slate-200 bg-white px-4 text-sm font-medium sm:font-semibold text-slate-700 shadow-sm transition-all duration-150 hover:bg-slate-50 hover:border-slate-300 active:scale-[0.99] focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                    >
                        <svg class="h-5 w-5 flex-shrink-0" viewBox="0 0 48 48">
                            <path fill="#FFC107" d="M43.611,20.083H42V20H24v8h11.303c-1.649,4.657-6.08,8-11.303,8c-6.627,0-12-5.373-12-12c0-6.627,5.373-12,12-12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C12.955,4,4,12.955,4,24c0,11.045,8.955,20,20,20c11.045,0,20-8.955,20-20C44,22.659,43.862,21.35,43.611,20.083z" />
                            <path fill="#FF3D00" d="M6.306,14.691l6.571,4.819C14.655,15.108,18.961,12,24,12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C16.318,4,9.656,8.337,6.306,14.691z" />
                            <path fill="#4CAF50" d="M24,44c5.166,0,9.86-1.977,13.409-5.192l-6.19-5.238C29.211,35.091,26.715,36,24,36c-5.202,0-9.619-3.317-11.283-7.946l-6.522,5.025C9.505,39.556,16.227,44,24,44z" />
                            <path fill="#1976D2" d="M43.611,20.083H42V20H24v8h11.303c-0.792,2.237-2.231,4.166-4.087,5.571c0.001-0.001,0.002-0.001,0.003-0.002l6.19,5.238C36.971,39.205,44,34,44,24C44,22.659,43.862,21.35,43.611,20.083z" />
                        </svg>
                        <span>Continuar con Google</span>
                    </button>

                    <!-- Modal Informativo de Google (conserva lógica existente) -->
                    <Modal :show="showGoogleModal" max-width="sm" @close="showGoogleModal = false">
                        <div class="p-6 text-center">
                            <div class="w-12 h-12 mx-auto rounded-full bg-amber-50 flex items-center justify-center text-amber-600 mb-3">
                                <Icon name="pending" :size="24" />
                            </div>
                            <h3 class="text-lg font-bold text-slate-900">Opción temporalmente no disponible</h3>
                            <p class="text-sm text-slate-500 mt-2">
                                Estamos trabajando para ofrecerte esta opción. Por ahora, inicia sesión con tu correo electrónico.
                            </p>
                            <button
                                type="button"
                                @click="showGoogleModal = false"
                                class="mt-5 w-full px-4 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-xl hover:bg-brand-700 transition-colors"
                            >
                                Entendido
                            </button>
                        </div>
                    </Modal>

                    <!-- Divisor -->
                    <div class="my-5 flex items-center gap-3">
                        <div class="h-px flex-1 bg-slate-200"></div>
                        <span class="text-xs font-medium text-slate-400">o con tu correo</span>
                        <div class="h-px flex-1 bg-slate-200"></div>
                    </div>

                    <!-- Formulario de Autenticación -->
                    <form @submit.prevent="submit" class="space-y-4">
                        <!-- Campo: Correo Electrónico -->
                        <div>
                            <label for="email" class="block text-xs sm:text-[13px] font-semibold text-slate-800 mb-1.5">
                                Correo electrónico
                            </label>
                            <input
                                id="email"
                                type="email"
                                v-model="form.email"
                                required
                                autofocus
                                autocomplete="username"
                                class="w-full h-[50px] sm:h-[52px] px-4 rounded-xl bg-[#EEF3FA] border border-slate-200/90 text-slate-900 text-sm placeholder:text-slate-400 transition-all duration-150 focus:bg-white focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 focus:outline-none"
                                :class="{ 'border-rose-400 bg-rose-50/40': form.errors.email }"
                            />
                            <InputError class="mt-1.5" :message="form.errors.email" />
                        </div>

                        <!-- Campo: Contraseña -->
                        <div>
                            <label for="password" class="block text-xs sm:text-[13px] font-semibold text-slate-800 mb-1.5">
                                Contraseña
                            </label>
                            <div class="relative">
                                <input
                                    id="password"
                                    :type="showPassword ? 'text' : 'password'"
                                    v-model="form.password"
                                    required
                                    autocomplete="current-password"
                                    class="w-full h-[50px] sm:h-[52px] pl-4 pr-12 rounded-xl bg-[#EEF3FA] border border-slate-200/90 text-slate-900 text-sm placeholder:text-slate-400 transition-all duration-150 focus:bg-white focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 focus:outline-none"
                                    :class="{ 'border-rose-400 bg-rose-50/40': form.errors.password }"
                                />
                                <button
                                    type="button"
                                    @click="showPassword = !showPassword"
                                    :aria-label="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 p-1.5 text-slate-400 hover:text-slate-600 focus:outline-none focus:ring-2 focus:ring-brand-500/20 rounded-lg transition-colors"
                                >
                                    <!-- Ojo abierto / cerrado con estilo exacto -->
                                    <svg v-if="showPassword" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                    <svg v-else class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18" />
                                    </svg>
                                </button>
                            </div>
                            <InputError class="mt-1.5" :message="form.errors.password" />
                        </div>

                        <!-- Fila: Recordarme + Olvidaste contraseña -->
                        <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
                            <label class="inline-flex items-center select-none cursor-pointer">
                                <input
                                    type="checkbox"
                                    v-model="form.remember"
                                    class="w-4 h-4 rounded text-brand-600 border-slate-300 focus:ring-brand-500/30 focus:ring-offset-0 transition-colors"
                                />
                                <span class="ms-2 text-xs sm:text-sm text-slate-700 font-medium">Recordarme</span>
                            </label>

                            <Link
                                v-if="canResetPassword !== false"
                                :href="route('password.request')"
                                class="text-xs sm:text-sm font-semibold text-brand-600 hover:text-brand-700 transition-colors"
                            >
                                ¿Olvidaste tu contraseña?
                            </Link>
                        </div>

                        <!-- Botón Principal: Iniciar sesión -->
                        <div class="pt-2">
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="w-full h-[50px] sm:h-[52px] flex items-center justify-center gap-2 rounded-xl bg-[#1B60C4] hover:bg-[#154FA6] active:bg-[#104088] text-white text-base font-semibold shadow-sm transition-all duration-150 active:scale-[0.99] disabled:opacity-50 disabled:cursor-not-allowed focus:outline-none focus:ring-2 focus:ring-brand-500/40"
                            >
                                <svg v-if="form.processing" class="animate-spin h-5 w-5 text-white mr-1" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>Iniciar sesión</span>
                                <span v-if="!form.processing" class="text-lg leading-none">→</span>
                            </button>
                        </div>
                    </form>

                    <!-- Enlace a Registro -->
                    <p class="mt-5 sm:mt-6 text-center text-sm text-slate-600 font-normal">
                        ¿Aún no tienes cuenta?
                        <Link :href="route('register')" class="text-brand-600 hover:text-brand-700 hover:underline font-bold ml-1">
                            Regístrate gratis
                        </Link>
                    </p>
                </div>
            </main>

            <!-- Footer con enlaces legales reales -->
            <footer class="pt-3 pb-2 text-center text-xs text-slate-400 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 relative z-10">
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

