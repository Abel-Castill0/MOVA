<template>
  <footer class="bg-brand-900 text-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-10">

        <!-- Brand -->
        <div class="lg:col-span-1">
          <div class="flex items-center gap-2 mb-4">
            <div class="w-8 h-8 bg-gradient-to-br from-brand-500 to-brand-600 rounded-lg flex items-center justify-center shadow">
              <span class="text-white font-black text-sm">M</span>
            </div>
            <span class="font-black text-xl">MOVA</span>
          </div>
          <p class="text-slate-400 text-sm leading-relaxed mb-4">
            Plataforma educativa creada por dos socios apasionados por la educación. Conectamos estudiantes con los mejores profesores particulares para aprender en vivo por Zoom.
          </p>
          <p class="text-slate-500 text-xs mb-4">Fundada por Abel Castillo & Elias Paz</p>
          <div class="flex gap-3">
            <a href="#" class="w-9 h-9 bg-white/10 hover:bg-brand-600 rounded-lg flex items-center justify-center transition-colors" aria-label="Facebook">
              <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
            </a>
            <a href="#" class="w-9 h-9 bg-white/10 hover:bg-brand-600 rounded-lg flex items-center justify-center transition-colors" aria-label="Instagram">
              <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
            </a>
            <a href="#" class="w-9 h-9 bg-white/10 hover:bg-brand-600 rounded-lg flex items-center justify-center transition-colors" aria-label="LinkedIn">
              <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
            </a>
          </div>
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
              <span class="text-sm text-slate-400">hola@mova.education</span>
            </li>
            <li class="flex items-start gap-2">
              <svg class="w-4 h-4 text-brand-400 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
              <span class="text-sm text-slate-400">España · Online</span>
            </li>
          </ul>
        </div>

      </div>

      <div class="border-t border-white/10 mt-12 pt-6 flex flex-col sm:flex-row justify-between items-center gap-3">
        <p class="text-sm text-slate-500">© {{ year }} MOVA · Abel Castillo & Elias Paz · Todos los derechos reservados.</p>
        <div class="flex gap-4">
          <a href="#" class="text-xs text-slate-500 hover:text-slate-400 transition-colors">Privacidad</a>
          <a href="#" class="text-xs text-slate-500 hover:text-slate-400 transition-colors">Términos de uso</a>
        </div>
      </div>
    </div>
  </footer>
</template>

<script setup>
const year = new Date().getFullYear()

const quickLinks = [
  { href: '/', label: 'Inicio' },
  { href: '/#como-funciona', label: 'Cómo funciona' },
  { href: '/marketplace', label: 'Marketplace' },
  { href: '/quienes-somos', label: 'Quiénes somos' },
]
</script>
