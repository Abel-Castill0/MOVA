<script setup>
import { Link } from '@inertiajs/vue3'
import { Swiper, SwiperSlide } from 'swiper/vue'
import { Pagination, Navigation, A11y } from 'swiper/modules'
import 'swiper/css'
import 'swiper/css/pagination'
import 'swiper/css/navigation'

/**
 * Carrusel de profesores destacados de la portada. Vive en su propio chunk (Welcome.vue lo carga con
 * defineAsyncComponent) para que Swiper y su CSS no viajen en la descarga inicial de la portada:
 * la sección está bajo el pliegue. Los botones anterior/siguiente siguen en Welcome.vue (.teachers-prev/.teachers-next).
 */
defineProps({
  teachers: { type: Array, required: true },
})
</script>

<template>
  <div>
    <Swiper
      :modules="[Pagination, Navigation, A11y]"
      :space-between="24"
      :slides-per-view="1.15"
      :breakpoints="{ 640: { slidesPerView: 2, spaceBetween: 24 }, 1024: { slidesPerView: 3, spaceBetween: 28 } }"
      :pagination="{ el: '.teachers-pagination', clickable: true }"
      :navigation="{ prevEl: '.teachers-prev', nextEl: '.teachers-next' }"
      class="teachers-swiper !pb-4"
    >
      <SwiperSlide v-for="t in teachers" :key="t.id" class="h-auto py-1">
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
                <span class="text-xs text-slate-500">{{ Number(t.avg_rating).toFixed(1) }}</span>
              </div>
              <span v-else class="inline-block mt-0.5 text-xs px-2 py-0.5 bg-brand-50 text-brand-600 rounded-full font-medium">Nuevo en MOVA</span>
              <p class="text-xs text-slate-500 mt-0.5">{{ t.classes_count }} clases impartidas</p>
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
          <div class="flex items-center justify-end pt-4 border-t border-gray-50">
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
</template>

<style scoped>
/* Swiper — paginación sutil acorde a la paleta de marca */
.teachers-swiper {
  overflow-x: clip;
  overflow-y: visible;
}
.teachers-pagination :deep(.swiper-pagination-bullet) {
  /* Punto visible de 6 px dentro de un área táctil de 24 px (WCAG 2.2 · 2.5.8). */
  width: 24px;
  height: 24px;
  padding: 9px;
  box-sizing: border-box;
  background: theme('colors.brand.300');
  background-clip: content-box;
  opacity: 1;
  margin: 0 !important;
  transition: width 0.25s ease, background-color 0.25s ease;
  border-radius: 999px;
}
.teachers-pagination :deep(.swiper-pagination-bullet-active) {
  width: 40px;
  background: theme('colors.brand.600');
  background-clip: content-box;
}
</style>
