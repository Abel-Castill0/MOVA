<template>
  <Head>
    <title>{{ title }}</title>
    <meta name="description" :content="description" />
    <meta property="og:title" :content="title" />
    <meta property="og:description" :content="description" />
    <meta property="og:type" content="video.other" />
    <meta property="og:image" :content="absolute(poster)" />
    <meta property="og:video" :content="absolute(video)" />
    <link rel="preload" as="image" :href="poster" />
  </Head>

  <!-- Una sola pantalla: logo, video y botón. Sin navegación ni distracciones (llegan desde un QR impreso). -->
  <main class="qr-page flex flex-col items-center justify-center bg-gradient-to-b from-brand-50 via-white to-white px-4">
    <header class="flex h-14 shrink-0 items-center justify-center sm:h-20">
      <MovaLogo class="h-7 w-auto sm:h-9" />
    </header>

    <section class="flex w-full flex-col items-center gap-4 pb-6 sm:gap-6 sm:pb-10" :aria-label="audience === 'teacher' ? 'Video para profesores' : 'Video para familias'">
      <div class="qr-video relative overflow-hidden rounded-3xl bg-slate-900 shadow-xl shadow-brand-900/15 ring-1 ring-black/5">
        <video
          ref="player"
          class="h-full w-full object-cover"
          :src="video"
          :poster="poster"
          :controls="started"
          playsinline
          webkit-playsinline
          preload="metadata"
          data-testid="qr-video"
          @play="onPlay"
        >
          <!-- Subtítulos en español disponibles desde el menú del reproductor (el video ya trae textos en pantalla). -->
          <track kind="subtitles" srclang="es" label="Español" :src="captions" />
        </video>

        <!-- Botón de reproducción grande hasta el primer toque; luego quedan los controles nativos. -->
        <button
          v-if="!started"
          type="button"
          class="absolute inset-0 flex items-center justify-center bg-black/10 transition-colors hover:bg-black/20 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-inset focus-visible:ring-accent-400"
          aria-label="Reproducir video"
          data-testid="qr-play"
          @click="play"
        >
          <span class="flex h-20 w-20 items-center justify-center rounded-full bg-white/95 shadow-2xl ring-8 ring-white/30 motion-safe:transition-transform motion-safe:hover:scale-105 sm:h-24 sm:w-24">
            <svg class="ml-1.5 h-9 w-9 text-brand-600 sm:h-11 sm:w-11" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.14v13.72a1 1 0 0 0 1.5.86l11-6.86a1 1 0 0 0 0-1.72l-11-6.86A1 1 0 0 0 8 5.14z" /></svg>
          </span>
        </button>
      </div>

      <a
        :href="startUrl"
        class="flex min-h-14 w-full max-w-md items-center justify-center gap-2 rounded-2xl bg-brand-600 px-6 text-lg font-black text-white shadow-lg shadow-brand-600/30 transition-colors hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-brand-300 active:bg-brand-800 sm:min-h-16 sm:text-xl"
        data-testid="qr-cta"
      >
        Empieza ahora <span aria-hidden="true">→</span>
      </a>
    </section>
  </main>
</template>

<script setup>
import { ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import MovaLogo from '@/Components/MovaLogo.vue'

const props = defineProps({
  campaign: { type: String, required: true },
  audience: { type: String, required: true },
  title: { type: String, required: true },
  description: { type: String, required: true },
  video: { type: String, required: true },
  poster: { type: String, required: true },
  captions: { type: String, required: true },
  startUrl: { type: String, required: true },
})

const player = ref(null)
const started = ref(false)
let playCounted = false

const absolute = (path) => (typeof window === 'undefined' ? path : new URL(path, window.location.origin).href)

function play() {
  started.value = true
  player.value?.play()?.catch(() => { /* el navegador lo bloqueó: quedan los controles nativos */ })
}

// Se cuenta solo el primer «Play» de la visita; medir nunca bloquea ni muestra errores.
function onPlay() {
  started.value = true
  if (playCounted) return
  playCounted = true
  axios.post(route('campaign.event', props.campaign), { event: 'play' }).catch(() => {})
}
</script>

<style scoped>
/* Alto real de la pantalla del móvil (sin la barra del navegador). */
.qr-page {
  min-height: 100vh;
  min-height: 100dvh;
}

/* Video cuadrado (1:1) lo más grande posible sin empujar el botón fuera de la primera pantalla:
   descuenta el logo (56 px), el botón (56 px) y los márgenes. En escritorio, hasta 640 px. */
.qr-video {
  aspect-ratio: 1 / 1;
  width: min(100%, calc(100vh - 184px), 640px); /* navegadores sin dvh */
  width: min(100%, calc(100dvh - 184px), 640px);
}

@media (min-width: 640px) {
  .qr-video {
    width: min(100%, calc(100vh - 248px), 640px);
    width: min(100%, calc(100dvh - 248px), 640px);
  }
}
</style>
