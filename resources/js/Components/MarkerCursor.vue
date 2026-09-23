<template>
  <!--
    MarkerCursor — cursor de plumón de marca MOVA.
    Una sola instancia montada en app.js junto a CookieConsent.
    Solo activo en dispositivos con puntero fino (mouse/trackpad).
    El wrapper se posiciona en (0,0); el transform lo mueve al cursor.
    La imagen se desplaza para que su PUNTA (inferior-izquierda) quede
    alineada con la posición real del puntero.
  -->
  <div
    ref="wrapperRef"
    class="marker-cursor-wrapper"
    :class="{ 'marker-cursor--hidden': isHidden }"
    aria-hidden="true"
  >
    <img
      src="/images/brand/Plumon-rojo.png"
      alt=""
      draggable="false"
      class="marker-cursor-img"
    />
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { usePage } from '@inertiajs/vue3'

// ── Constantes de configuración ──────────────────────────────────────────────
// Ajusta aquí si necesitas afinar el hotspot sin tocar la lógica.
// La punta inferior-izquierda del PNG está aprox. en (12%, 92%) del canvas.
// Con el wrapper en (clientX, clientY) desplazamos la imagen -12% X / -92% Y
// para que la punta quede justo sobre el cursor real.
const MARKER_WIDTH    = 88   // px — ancho visible del plumón
const HOTSPOT_X_FRAC  = 0.12 // fracción desde el borde izquierdo del PNG
const HOTSPOT_Y_FRAC  = 0.92 // fracción desde el borde superior del PNG

const page = usePage()

// Ocultar el plumón únicamente en las sesiones de dashboard (profesor, estudiante/apoderado y administración)
const isHidden = computed(() => {
  const component = page.component || ''
  const url = page.url || ''

  // 1. Dashboards principales (Profesor, Alumno/Apoderado, Admin)
  if (component.startsWith('Dashboard/')) return true

  // 2. Ruta directa /dashboard
  if (url === '/dashboard' || url.startsWith('/dashboard?') || url.startsWith('/dashboard/')) return true

  // 3. Vistas internas del panel de profesor
  if (url.startsWith('/teacher/')) return true

  // 4. Vistas internas del panel de estudiante / apoderado
  if (
    url.startsWith('/students') ||
    url.startsWith('/my-reports') ||
    url.startsWith('/my-classes') ||
    url.startsWith('/class-requests')
  ) {
    return true
  }

  // 5. Panel de administración y ajustes de perfil
  if (url.startsWith('/admin/') || url.startsWith('/profile')) return true

  return false
})

const wrapperRef = ref(null)

onMounted(() => {
  // Solo activar en dispositivos con puntero fino (mouse/trackpad).
  // matchMedia es seguro aquí porque estamos en onMounted (cliente).
  const mq = window.matchMedia('(hover: hover) and (pointer: fine)')
  if (!mq.matches) return

  // Desactivar también con prefers-reduced-motion.
  const mqMotion = window.matchMedia('(prefers-reduced-motion: reduce)')
  if (mqMotion.matches) return

  const el = wrapperRef.value
  if (!el) return

  // Imagen pre-calculada: aspect ratio cuadrado del PNG (1083×1106 px aprox.)
  // El offset fijo para posicionar la punta sobre el cursor:
  const offsetX = -(MARKER_WIDTH * HOTSPOT_X_FRAC)
  const offsetY = -(MARKER_WIDTH * (1106 / 1083) * HOTSPOT_Y_FRAC)

  let targetX = 0
  let targetY = 0
  let visible = false
  let rafId   = null

  // Si cambia a una página donde debe ocultarse, apagar visibilidad inmediatamente
  watch(isHidden, (hidden) => {
    if (hidden && visible) {
      visible = false
      el.style.opacity = '0'
    }
  })

  function onPointerMove(e) {
    if (isHidden.value) {
      if (visible) {
        visible = false
        el.style.opacity = '0'
      }
      return
    }

    targetX = e.clientX
    targetY = e.clientY
    if (!visible) {
      visible = true
      el.style.opacity = '1'
    }
  }

  function onPointerLeave() {
    visible = false
    el.style.opacity = '0'
  }

  function loop() {
    // Sin interpolación (0.0 = instante, 1.0 = nunca llega).
    // Ponemos 1.0 para respuesta de 1 frame exacto — precisión máxima.
    el.style.transform = `translate3d(${targetX + offsetX}px, ${targetY + offsetY}px, 0)`
    rafId = requestAnimationFrame(loop)
  }

  document.addEventListener('pointermove',  onPointerMove,  { passive: true })
  document.addEventListener('pointerleave', onPointerLeave, { passive: true })

  rafId = requestAnimationFrame(loop)

  // Limpiar al desmontar
  onBeforeUnmount(() => {
    document.removeEventListener('pointermove',  onPointerMove)
    document.removeEventListener('pointerleave', onPointerLeave)
    cancelAnimationFrame(rafId)
  })
})
</script>

<style scoped>
/*
  El wrapper está en position:fixed en (0,0).
  El JS le aplica translate3d(x + offsetX, y + offsetY, 0) cada frame.
  Así la punta de la imagen cae exactamente sobre el cursor real.
*/
.marker-cursor-wrapper {
  position: fixed;
  top: 0;
  left: 0;
  /* Oculto hasta el primer pointermove */
  opacity: 0;
  /* Solo animar opacidad, nunca transform (causaría retraso visual) */
  transition: opacity 120ms ease;
  pointer-events: none;
  user-select: none;
  /* z-index sobre navbar (z-50) y modales (z-50), bajo CookieConsent (z-[60]) */
  z-index: 55;
  will-change: transform;
}

.marker-cursor--hidden {
  display: none !important;
  opacity: 0 !important;
  visibility: hidden !important;
  pointer-events: none !important;
}

.marker-cursor-img {
  /* --marker-width permite ajuste fino desde DevTools o CSS padre */
  width: 88px;
  height: auto;
  display: block;
  pointer-events: none;
  user-select: none;
  -webkit-user-drag: none;
  /* Mantener nitidez del PNG */
  image-rendering: crisp-edges;
}
</style>
