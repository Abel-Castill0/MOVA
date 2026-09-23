<template>
  <footer class="bg-brand-900 text-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-10">

        <!-- Brand -->
        <div class="lg:col-span-1">
          <!-- Footer sobre brand-900 → versión blanca del imagotipo -->
          <div class="flex items-center mb-4">
            <MovaLogo theme="blanco" class="h-9 w-auto" />
          </div>
          <p class="text-slate-400 text-sm leading-relaxed mb-4">
            Plataforma educativa creada por dos socios apasionados por la educación. Conectamos estudiantes con los mejores profesores particulares para aprender en vivo por videollamada.
          </p>
          <p class="text-slate-500 text-xs mb-4">Fundada por Abel Castillo & Elias Paz</p>
          <p class="text-slate-500 text-xs mt-1">Próximamente en redes sociales.</p>
        </div>

        <!-- Quick links -->
        <div>
          <h4 class="font-semibold text-white mb-4">Plataforma</h4>
          <ul class="space-y-2">
            <li v-for="link in quickLinks" :key="link.href">
              <a :href="link.href" class="text-sm text-slate-400 hover:text-white transition-colors">{{ link.label }}</a>
            </li>
          </ul>
        </div>

        <!-- Join -->
        <div>
          <h4 class="font-semibold text-white mb-4">Únete a MOVA</h4>
          <ul class="space-y-2">
            <li><a href="/invitacion/profesor" class="text-sm text-slate-400 hover:text-white transition-colors">Soy profesor</a></li>
            <li><a href="/invitacion/alumno" class="text-sm text-slate-400 hover:text-white transition-colors">Soy estudiante / padre</a></li>
            <li><a href="/register" class="text-sm text-slate-400 hover:text-white transition-colors">Crear cuenta gratis</a></li>
            <li><a href="/login" class="text-sm text-slate-400 hover:text-white transition-colors">Iniciar sesión</a></li>
            <li><a href="/quienes-somos" class="text-sm text-slate-400 hover:text-white transition-colors">Quiénes somos</a></li>
          </ul>
        </div>

        <!-- Contact -->
        <div>
          <h4 class="font-semibold text-white mb-4">Contacto</h4>
          <ul class="space-y-3">
            <li class="flex items-start gap-2">
              <svg class="w-4 h-4 text-brand-400 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
              <a href="mailto:m0v4class@gmail.com" class="text-sm text-slate-400 hover:text-white transition-colors">m0v4class@gmail.com</a>
            </li>
            <li class="flex items-start gap-2">
              <svg class="w-4 h-4 text-brand-400 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
              <span class="text-sm text-slate-400">Perú · Online</span>
            </li>
          </ul>
        </div>

      </div>

      <div class="border-t border-white/10 mt-12 pt-6 flex flex-col sm:flex-row justify-between items-center gap-3">
        <p class="text-sm text-slate-500">© {{ year }} MOVA · Abel Castillo & Elias Paz · Todos los derechos reservados.</p>
        <div class="flex items-center gap-4">
          <a href="/privacidad" class="text-xs text-slate-500 hover:text-slate-400 transition-colors">Política de Privacidad</a>
          <a href="/terminos" class="text-xs text-slate-500 hover:text-slate-400 transition-colors">Términos y Condiciones</a>
          <a href="/libro-de-reclamaciones" class="text-xs text-slate-500 hover:text-slate-400 transition-colors">Libro de Reclamaciones</a>
          <span class="text-slate-700">·</span>
          <a href="https://portafolio-henna-mu.vercel.app/" target="_blank" rel="noopener noreferrer"
            class="text-xs text-slate-500 hover:text-white transition-colors">
            Desarrollado con <span class="text-red-400">❤</span> por Abel Castillo
          </a>
        </div>
      </div>
    </div>
  </footer>
</template>

<script setup>
import MovaLogo from '@/Components/MovaLogo.vue'

const year = new Date().getFullYear()

const quickLinks = [
  { href: '/', label: 'Inicio' },
  { href: '/#como-funciona', label: 'Cómo funciona' },
  { href: '/marketplace', label: 'Marketplace' },
  { href: '/quienes-somos', label: 'Quiénes somos' },
]
</script>
