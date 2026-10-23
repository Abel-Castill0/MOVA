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
          <p class="text-slate-300 text-xs mb-4">Fundada por Abel Castillo & Elias Paz</p>
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
              <a :href="`mailto:${$page.props.support.email}`" class="text-sm text-slate-400 hover:text-white transition-colors">{{ $page.props.support.email }}</a>
            </li>
            <li class="flex items-start gap-2">
              <svg class="w-4 h-4 text-brand-400 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
              <span class="text-sm text-slate-400">{{ $page.props.merchant?.address || 'Perú · Online' }}</span>
            </li>
            <li v-if="$page.props.support.phone" class="flex items-start gap-2">
              <span class="text-sm text-slate-400">Tel.: <a :href="`tel:${$page.props.support.phone.replace(/\s+/g, '')}`" class="hover:text-white transition-colors">{{ $page.props.support.phone }}</a></span>
            </li>
          </ul>
          <ul v-if="socialLinks.length" class="mt-4 flex flex-wrap gap-3" aria-label="Redes sociales de MOVA">
            <li v-for="s in socialLinks" :key="s.key">
              <a :href="s.url" target="_blank" rel="noopener noreferrer" class="text-sm text-slate-300 underline-offset-2 hover:text-white hover:underline transition-colors">{{ s.label }}</a>
            </li>
          </ul>
        </div>

      </div>

      <div class="border-t border-white/10 mt-12 pt-6 flex flex-col sm:flex-row justify-between items-center gap-3">
        <div class="text-sm text-slate-300">
          <p>© {{ year }} MOVA · Abel Castillo & Elias Paz · Todos los derechos reservados.</p>
          <p v-if="$page.props.merchant?.business_name" class="mt-1 text-xs text-slate-400" data-testid="merchant-identity">
            {{ $page.props.merchant.business_name }}<template v-if="$page.props.merchant.ruc"> · RUC {{ $page.props.merchant.ruc }}</template><template v-if="$page.props.merchant.address"> · {{ $page.props.merchant.address }}</template>
          </p>
        </div>
        <div class="flex items-center gap-4">
          <a href="/privacidad" class="text-xs text-slate-300 hover:text-slate-400 transition-colors">Política de Privacidad</a>
          <a href="/terminos" class="text-xs text-slate-300 hover:text-slate-400 transition-colors">Términos y Condiciones</a>
          <a href="/politica-de-devoluciones" class="text-xs text-slate-300 hover:text-slate-400 transition-colors">Cambios y devoluciones</a>
          <a href="/libro-de-reclamaciones" class="text-xs text-slate-300 hover:text-slate-400 transition-colors">Libro de Reclamaciones</a>
        </div>
      </div>
    </div>
  </footer>
</template>

<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import MovaLogo from '@/Components/MovaLogo.vue'

const year = new Date().getFullYear()

// Solo las redes oficiales configuradas en el backend (config/legal.php): nunca se inventan.
const SOCIAL_LABELS = { instagram: 'Instagram', facebook: 'Facebook', tiktok: 'TikTok', linkedin: 'LinkedIn', youtube: 'YouTube' }
const page = usePage()
const socialLinks = computed(() => Object.entries(page.props.support?.social ?? {}).map(([key, url]) => ({ key, url, label: SOCIAL_LABELS[key] ?? key })))

const quickLinks = [
  { href: '/', label: 'Inicio' },
  { href: '/#como-funciona', label: 'Cómo funciona' },
  { href: '/servicios', label: 'Servicios y precios' },
  { href: '/marketplace', label: 'Marketplace' },
  { href: '/quienes-somos', label: 'Quiénes somos' },
]
</script>
