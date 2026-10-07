<script setup>
import { ref, computed, nextTick, watch, onMounted, onBeforeUnmount } from 'vue'
import axios from 'axios'
import { usePage } from '@inertiajs/vue3'
import Icon from '@/Components/Icon.vue'
import MoviMascot from '@/Components/MoviMascot.vue'
import { formatMessageText } from '@/utils/chatbotFormat.js'

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: undefined,
  },
  // false cuando el lanzador vive en otra parte (cabecera de la app): evita un botón flotante sobre acciones primarias.
  floating: { type: Boolean, default: true },
})

const emit = defineEmits(['update:modelValue', 'close'])

const page = usePage()
const supportEmail = computed(() => page.props.support?.email ?? '')

const internalOpen = ref(false)

const isOpen = computed({
  get: () => (props.modelValue !== undefined ? props.modelValue : internalOpen.value),
  set: (val) => {
    internalOpen.value = val
    emit('update:modelValue', val)
    if (!val) emit('close')
  },
})

function toggleChat() {
  isOpen.value = !isOpen.value
}

function closeChat() {
  isOpen.value = false
}

const messagesContainer = ref(null)
const inputMessage = ref('')
const isTyping = ref(false)

const WELCOME = 'Hola, soy **Movi**, el asistente de MOVA. Te ayudo a pedir una clase, entender los créditos o resolver dudas de tu cuenta. ¿Qué necesitas?'

const messages = ref([{ id: 1, sender: 'bot', text: WELCOME, time: '', isWelcome: true }])

// Preguntas sugeridas: cada una es una consulta real que Movi sabe responder.
const suggestions = [
  { icon: 'teachers', label: 'Cómo pedir una clase', prompt: '¿Cómo pido una clase para mi hijo?' },
  { icon: 'credits', label: 'Créditos y precios', prompt: '¿Cómo funcionan los créditos en MOVA?' },
  { icon: 'verified-badge', label: 'Profesores verificados', prompt: '¿Los profesores son verificados y seguros?' },
  { icon: 'topic', label: 'Quiero ser profesor', prompt: 'Quiero enseñar en MOVA, ¿qué requisitos necesito?' },
]

function scrollToBottom() {
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
    }
  })
}

// Diálogo modal (aria-modal): al abrir, el foco entra al campo de texto; al
// cerrar vuelve al lanzador. Escape cierra y Tab no escapa del diálogo.
const launcherRef = ref(null)
const dialogRef = ref(null)

let lastFocused = null

watch(isOpen, (open) => {
  if (open) {
    lastFocused = document.activeElement
    nudgeVisible.value = false
    scrollToBottom()
    nextTick(() => dialogRef.value?.querySelector('#movi-chat-input')?.focus())
  } else {
    // Devuelve el foco a quien abrió el chat (lanzador flotante o botón de la cabecera).
    nextTick(() => (launcherRef.value ?? lastFocused)?.focus?.())
  }
})

function trapFocus(event) {
  const dialog = dialogRef.value
  if (!dialog) return
  const focusables = [...dialog.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
  if (!focusables.length) return
  const first = focusables[0]
  const last = focusables[focusables.length - 1]
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

function restartChat() {
  messages.value = [{ id: Date.now(), sender: 'bot', text: WELCOME, time: getCurrentTime(), isWelcome: true }]
  scrollToBottom()
}

// Aviso discreto junto al lanzador: una sola vez por sesión del navegador, sin tapar nada ni repetirse.
const nudgeVisible = ref(false)
let nudgeTimer = null
let nudgeHideTimer = null

onMounted(() => {
  let seen = false
  try {
    seen = sessionStorage.getItem('movi-nudge') === '1'
  } catch {
    seen = true
  }
  if (seen) return
  nudgeTimer = setTimeout(() => {
    if (isOpen.value) return
    nudgeVisible.value = true
    try { sessionStorage.setItem('movi-nudge', '1') } catch { /* sin almacenamiento: se muestra sin recordar */ }
    nudgeHideTimer = setTimeout(() => { nudgeVisible.value = false }, 9000)
  }, 7000)
})

onBeforeUnmount(() => {
  clearTimeout(nudgeTimer)
  clearTimeout(nudgeHideTimer)
})

// Escape + formato seguro vive en utils/chatbotFormat.js (probado aparte).

async function sendUserPrompt(promptText) {
  if (!promptText || !promptText.trim() || isTyping.value) return

  const userText = promptText.trim()
  messages.value.push({ id: Date.now(), sender: 'user', text: userText, time: getCurrentTime() })
  inputMessage.value = ''
  isTyping.value = true
  scrollToBottom()

  try {
    // Historial previo para contexto conversacional (hasta 8 mensajes anteriores).
    // Los avisos de error locales nunca viajan como contexto.
    const historyPayload = messages.value
      .slice(0, -1)
      .filter((m) => !m.isError)
      .slice(-8)
      .map((m) => ({ sender: m.sender, text: m.text }))

    const response = await axios.post('/chatbot/message', { message: userText, history: historyPayload })

    const replyText = response.data?.ok && response.data?.reply
      ? response.data.reply
      : 'Movi tuvo una dificultad para responder. Por favor, reintenta tu pregunta en unos segundos.'

    messages.value.push({ id: Date.now() + 1, sender: 'bot', text: replyText, time: getCurrentTime() })
  } catch (err) {
    // 429 = límite de uso; 503 = no disponible (mensaje neutral del servidor, sin
    // detalles de configuración). Nunca se muestra el cuerpo crudo de otros errores.
    const status = err.response?.status
    let errorMsg = 'Tuve una pequeña dificultad para responder en este instante. Por favor reintenta tu pregunta.'
    if (status === 429) {
      errorMsg = 'Estás enviando mensajes muy rápido. Espera un momento e inténtalo de nuevo.'
    } else if (status === 503 && typeof err.response?.data?.message === 'string') {
      errorMsg = err.response.data.message
    } else if (status === 422) {
      errorMsg = 'Tu mensaje es demasiado largo. Intenta con una pregunta más corta.'
    }
    messages.value.push({ id: Date.now() + 1, sender: 'bot', text: errorMsg, time: getCurrentTime(), isError: true })
  } finally {
    isTyping.value = false
    scrollToBottom()
  }
}

function handleSend() {
  if (!inputMessage.value.trim() || isTyping.value) return
  sendUserPrompt(inputMessage.value)
}

function getCurrentTime() {
  return new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <div class="movi-widget">
    <!-- Lanzador: botón circular con Movi. Discreto en móvil, sin tapar contenido; el aviso aparece una sola vez. -->
    <div
      v-if="floating"
      class="fixed z-30 flex items-center gap-3 transition duration-200 ease-out"
      :class="isOpen ? 'pointer-events-none translate-y-2 opacity-0' : 'opacity-100'"
      style="right: max(1rem, env(safe-area-inset-right)); bottom: max(1rem, env(safe-area-inset-bottom))"
    >
      <Transition
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="opacity-0 translate-x-2"
        leave-active-class="transition duration-200 ease-in"
        leave-to-class="opacity-0"
      >
        <div
          v-if="nudgeVisible && !isOpen"
          class="hidden sm:flex max-w-[15rem] items-center gap-2 rounded-2xl border border-line bg-surface-raised px-3.5 py-2 text-sm font-semibold text-ink shadow-lg"
          role="status"
        >
          ¿Dudas? Pregúntale a Movi
        </div>
      </Transition>

      <button
        ref="launcherRef"
        type="button"
        class="group relative flex h-16 w-16 items-center justify-center rounded-full border border-line bg-surface-raised shadow-lg shadow-brand-900/15 transition duration-200 ease-out hover:-translate-y-0.5 hover:shadow-xl focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-focus-ring/60 active:scale-95 motion-reduce:transition-none"
        :aria-expanded="isOpen"
        aria-label="Abrir chat con Movi"
        @click="toggleChat"
      >
        <MoviMascot pose="saluda" :size="52" class="transition-transform duration-200 ease-out group-hover:scale-110 motion-reduce:transition-none" />
      </button>
    </div>

    <!-- Ventana de chat: hoja inferior en móvil, tarjeta flotante desde sm. -->
    <Transition
      enter-active-class="transition duration-250 ease-out motion-reduce:transition-none"
      enter-from-class="opacity-0 translate-y-6"
      enter-to-class="opacity-100 translate-y-0"
      leave-active-class="transition duration-150 ease-in motion-reduce:transition-none"
      leave-from-class="opacity-100 translate-y-0"
      leave-to-class="opacity-0 translate-y-4"
    >
      <div
        v-if="isOpen"
        ref="dialogRef"
        class="fixed inset-x-0 bottom-0 z-40 flex h-[min(82dvh,640px)] flex-col overflow-hidden rounded-t-3xl border border-line bg-surface font-sans shadow-2xl shadow-brand-950/30 sm:inset-x-auto sm:bottom-5 sm:right-5 sm:h-[600px] sm:max-h-[calc(100dvh-2.5rem)] sm:w-[400px] sm:rounded-3xl"
        role="dialog"
        aria-label="Ventana de chat con Movi"
        aria-modal="true"
        @keydown.esc="closeChat"
        @keydown.tab="trapFocus"
      >
        <header class="flex items-center justify-between gap-3 bg-brand-800 px-4 py-3 text-white">
          <div class="flex min-w-0 items-center gap-3">
            <span class="relative flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white/95">
              <MoviMascot pose="saluda" :size="38" />
            </span>
            <div class="min-w-0">
              <h2 class="text-base font-extrabold leading-tight">Movi</h2>
              <p class="text-xs font-medium text-brand-100">Asistente virtual de MOVA</p>
            </div>
          </div>

          <div class="flex items-center gap-1">
            <button
              type="button"
              class="rounded-full p-2 text-white/80 transition-colors hover:bg-white/10 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
              title="Reiniciar chat"
              aria-label="Reiniciar conversación"
              @click="restartChat"
            >
              <Icon name="flexible" :size="18" />
            </button>
            <button
              type="button"
              class="rounded-full p-2 text-white/80 transition-colors hover:bg-white/10 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
              title="Cerrar chat"
              aria-label="Cerrar chat"
              @click="closeChat"
            >
              <Icon name="close" :size="20" />
            </button>
          </div>
        </header>

        <div
          ref="messagesContainer"
          class="flex-1 space-y-4 overflow-y-auto bg-canvas px-4 py-4"
          role="log"
          aria-live="polite"
          aria-label="Conversación con Movi"
        >
          <div
            v-for="msg in messages"
            :key="msg.id"
            class="flex flex-col"
            :class="msg.sender === 'user' ? 'items-end' : 'items-start'"
          >
            <div class="flex max-w-[88%] items-end gap-2" :class="msg.sender === 'user' ? 'flex-row-reverse' : 'flex-row'">
              <span
                v-if="msg.sender === 'bot'"
                class="mb-1 flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-line bg-surface"
              >
                <MoviMascot pose="saluda" :size="22" />
              </span>

              <div
                class="rounded-2xl px-4 py-2.5 text-sm leading-relaxed"
                :class="msg.sender === 'user'
                  ? 'rounded-br-md bg-brand-600 text-white'
                  : msg.isError
                    ? 'rounded-bl-md border border-danger-border bg-danger-bg text-danger-text'
                    : 'rounded-bl-md border border-line bg-surface text-ink'"
              >
                <div class="whitespace-pre-line break-words" v-html="formatMessageText(msg.text)"></div>
              </div>
            </div>

            <div
              v-if="msg.time"
              class="mt-1 px-1 text-[11px] text-ink-muted"
              :class="msg.sender === 'user' ? 'pr-1' : 'pl-10'"
            >
              {{ msg.time }}
            </div>

            <div v-if="msg.isWelcome" class="mt-3 w-full pl-10 pr-1">
              <p class="mb-2 text-xs font-semibold text-ink-muted">Puedo ayudarte con:</p>
              <div class="flex flex-wrap gap-2">
                <button
                  v-for="chip in suggestions"
                  :key="chip.label"
                  type="button"
                  class="inline-flex items-center gap-1.5 rounded-full border border-line-strong bg-surface px-3 py-1.5 text-left text-xs font-semibold text-ink transition-colors hover:border-brand-400 hover:bg-brand-50 hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring/70 active:scale-[0.98] motion-reduce:transition-none"
                  @click="sendUserPrompt(chip.prompt)"
                >
                  <Icon :name="chip.icon" :size="14" />
                  {{ chip.label }}
                </button>
              </div>
            </div>
          </div>

          <div v-if="isTyping" class="flex items-end gap-2" role="status" aria-label="Movi está escribiendo">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-line bg-surface">
              <MoviMascot pose="lee" :size="22" />
            </span>
            <div class="flex items-center gap-1.5 rounded-2xl rounded-bl-md border border-line bg-surface px-4 py-3">
              <span class="h-1.5 w-1.5 rounded-full bg-brand-500 motion-safe:animate-bounce" style="animation-delay: 0ms"></span>
              <span class="h-1.5 w-1.5 rounded-full bg-brand-500 motion-safe:animate-bounce" style="animation-delay: 150ms"></span>
              <span class="h-1.5 w-1.5 rounded-full bg-brand-500 motion-safe:animate-bounce" style="animation-delay: 300ms"></span>
            </div>
          </div>
        </div>

        <footer class="border-t border-line bg-surface p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
          <form class="flex items-center gap-2" @submit.prevent="handleSend">
            <label for="movi-chat-input" class="sr-only">Escribe tu consulta a Movi</label>
            <input
              id="movi-chat-input"
              v-model="inputMessage"
              type="text"
              maxlength="1000"
              autocomplete="off"
              enterkeyhint="send"
              placeholder="Escribe tu consulta…"
              class="min-w-0 flex-1 rounded-full border border-line-strong bg-canvas px-4 py-2.5 text-sm text-ink placeholder:text-ink-muted focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-focus-ring/40"
            />
            <button
              type="submit"
              :disabled="!inputMessage.trim() || isTyping"
              class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-600 text-white transition-colors hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring/70 disabled:cursor-not-allowed disabled:opacity-45"
              aria-label="Enviar mensaje"
            >
              <Icon name="send" :size="17" />
            </button>
          </form>
          <p class="mt-2 text-center text-[11px] leading-snug text-ink-muted">
            Movi responde con información de MOVA y no resuelve tareas.
            <template v-if="supportEmail">Para casos personales escribe a {{ supportEmail }}.</template>
          </p>
        </footer>
      </div>
    </Transition>
  </div>
</template>
