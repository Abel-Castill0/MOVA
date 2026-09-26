<template>
  <div class="hidden lg:flex lg:w-[47%] xl:w-[48%] relative flex-col justify-between overflow-hidden bg-gradient-to-br from-[#1B64CC] via-[#1350A5] to-[#09306E] p-6 xl:p-8 2xl:p-10 select-none">
    <!-- Capa de textura decorativa de fondo exclusiva del registro (sutil y no dominante) -->
    <img
      src="/images/brand/register-blue-decoration.png"
      alt=""
      class="absolute inset-0 w-full h-full object-cover object-center opacity-[0.16] pointer-events-none z-0"
      loading="eager"
      decoding="async"
    />

    <!-- Header superior izquierdo: Logo MOVA oficial en blanco -->
    <div class="relative z-10 pt-1">
      <Link href="/" class="inline-flex items-center focus:outline-none focus:ring-2 focus:ring-white/40 rounded-lg">
        <MovaLogo theme="blanco" class="h-8 xl:h-9 w-auto" />
      </Link>
    </div>

    <!-- Titulares de Marca aprobados en el mockup -->
    <div class="relative z-10 max-w-lg mt-3 xl:mt-4">
      <h1 class="text-2xl xl:text-3xl 2xl:text-[34px] font-extrabold text-white leading-[1.18] tracking-tight">
        Clases particulares<br />
        en vivo, con profesores<br />
        <span class="text-[#67C3F3]">verificados.</span>
      </h1>
      <p class="mt-2 xl:mt-2.5 text-white/85 text-xs xl:text-[14px] 2xl:text-[15px] leading-relaxed max-w-md">
        Únete a una comunidad que cree en<br class="hidden xl:inline" />
        el poder de aprender.
      </p>
    </div>

    <!-- Composición de Ilustración: Laptop (foco principal) + Planta/Libros + Cards flotantes + Nota manuscrita -->
    <div class="relative z-10 w-full flex-1 flex flex-col justify-center items-center py-2 xl:py-4">
      <div class="relative w-[78%] min-w-[340px] max-w-[470px] xl:max-w-[550px] 2xl:max-w-[630px]">
        <!-- 1. Nota manuscrita: 'Tu próxima oportunidad te espera' (Blanca, discreta y alineada con Imagen B) -->
        <img
          src="/images/brand/register-opportunity-note.png"
          alt="Tu próxima oportunidad te espera"
          class="absolute -top-[14%] xl:-top-[15%] left-[9%] xl:left-[10%] w-[26%] xl:w-[27%] -rotate-[6deg] h-auto object-contain z-20 pointer-events-none drop-shadow-sm brightness-0 invert opacity-95"
          loading="eager"
          decoding="async"
        />

        <!-- 2. Card flotante: 'Aprende a tu ritmo' -->
        <img
          src="/images/brand/register-feature-learn.png"
          alt="Aprende a tu ritmo"
          class="absolute top-[31%] xl:top-[32%] -left-[5%] xl:-left-[6%] w-[25%] xl:w-[26%] h-auto object-contain z-20 drop-shadow-md pointer-events-none"
          loading="eager"
          decoding="async"
        />

        <!-- 3. Card flotante: 'Profesores verificados' -->
        <img
          src="/images/brand/register-feature-verified.png"
          alt="Profesores verificados"
          class="absolute -top-[5%] xl:-top-[6%] right-[3%] xl:right-[4%] w-[27%] xl:w-[28%] h-auto object-contain z-20 drop-shadow-md pointer-events-none"
          loading="eager"
          decoding="async"
        />

        <!-- 4. Planta sobre libros: en la base inferior izquierda de la laptop -->
        <img
          src="/images/brand/register-plant-books.png"
          alt=""
          class="absolute -left-[3%] xl:-left-[4%] -bottom-[2%] xl:-bottom-[3%] w-[31%] xl:w-[32%] h-auto object-contain z-30 drop-shadow-lg pointer-events-none"
          loading="eager"
          decoding="async"
        />

        <!-- 5. Laptop con videollamada interactiva (foco visual principal, elemento base del contenedor) -->
        <img
          src="/images/brand/register-laptop-videocall.png"
          alt="Clases particulares en vivo"
          class="w-full h-auto object-contain z-10 drop-shadow-2xl block"
          loading="eager"
          decoding="async"
        />
      </div>
    </div>

    <!-- Footer inferior izquierdo: Copyright -->
    <div class="relative z-10 pt-2 text-white/50 text-xs">
      © {{ year }} MOVA
    </div>
  </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import MovaLogo from '@/Components/MovaLogo.vue'

defineProps({
  year: {
    type: [Number, String],
    default: () => new Date().getFullYear(),
  },
})
</script>
