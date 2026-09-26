<script setup>
import { ref, computed, nextTick, watch } from 'vue'
import axios from 'axios'

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: undefined,
  },
})

const emit = defineEmits(['update:modelValue', 'close'])

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

// Lista de mensajes con estado interactivo
const messages = ref([
  {
    id: 1,
    sender: 'bot',
    text: '¡Hola! 👋 Soy **Movi**, tu asistente inteligente en MOVA 🐿️. ¿En qué te puedo ayudar hoy?',
    time: 'Ahora',
    isWelcome: true,
  },
])

// Preguntas sugeridas interactivas
const suggestions = [
  { label: '🔍 ¿Cómo busco un profesor?', prompt: '¿Cómo busco un profesor para mi nivel?' },
  { label: '💳 ¿Cómo funcionan los créditos?', prompt: '¿Cómo funciona el sistema de créditos en MOVA?' },
  { label: '🎁 ¿Cómo pido mi primera clase?', prompt: '¿Cómo puedo agendar mi primera clase?' },
  { label: '👨‍🏫 ¿Cómo ser profesor en MOVA?', prompt: 'Quiero enseñar en MOVA, ¿qué requisitos necesito?' },
]

function scrollToBottom() {
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
    }
  })
}

watch(isOpen, (open) => {
  if (open) {
    scrollToBottom()
  }
})

function restartChat() {
  messages.value = [
    {
      id: Date.now(),
      sender: 'bot',
      text: '¡Conversación reiniciada! 👋 Soy **Movi**, tu asistente inteligente en MOVA 🐿️. ¿En qué te puedo orientar hoy?',
      time: getCurrentTime(),
      isWelcome: true,
    },
  ]
  scrollToBottom()
}

// Formateador simple y seguro de Markdown para Movi (negritas, cursivas, saltos)
function formatMessageText(raw) {
  if (!raw) return ''
  const escaped = raw
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;')

  let formatted = escaped
    .replace(/\*\*(.*?)\*\*/g, '<strong class="font-bold text-slate-900 dark:text-white">$1</strong>')
    .replace(/\*(.*?)\*/g, '<em class="italic">$1</em>')

  // Enlaces interactivos con diseño de botón para Movi
  formatted = formatted.replace(
    /\[Crear cuenta\]/gi,
    '<a href="/register" class="inline-flex items-center gap-1 px-3 py-1 my-1 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs shadow-xs transition-all hover:scale-105 active:scale-95 no-underline">Crear cuenta <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg></a>'
  )
  formatted = formatted.replace(
    /\[Solicitar Clase\]/gi,
    '<a href="/class-requests/create" class="inline-flex items-center gap-1 px-3 py-1 my-1 rounded-xl bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs shadow-xs transition-all hover:scale-105 active:scale-95 no-underline">Solicitar Clase <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg></a>'
  )
  formatted = formatted.replace(
    /\[Voluntariado\]/gi,
    '<a href="/invitacion/profesor" class="inline-flex items-center gap-1 px-3 py-1 my-1 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs shadow-xs transition-all hover:scale-105 active:scale-95 no-underline">Voluntariado <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg></a>'
  )

  return formatted
}

async function sendUserPrompt(promptText) {
  if (!promptText || !promptText.trim() || isTyping.value) return

  const userText = promptText.trim()
  messages.value.push({
    id: Date.now(),
    sender: 'user',
    text: userText,
    time: getCurrentTime(),
  })
  inputMessage.value = ''
  scrollToBottom()

  isTyping.value = true
  scrollToBottom()

  try {
    // Historial previo para contexto conversacional (hasta 8 mensajes anteriores)
    const historyPayload = messages.value
      .slice(0, -1)
      .slice(-8)
      .map(m => ({
        sender: m.sender,
        text: m.text,
      }))

    const response = await axios.post('/chatbot/message', {
      message: userText,
      history: historyPayload,
    })

    const replyText = response.data?.reply || '¡Listo! ¿En qué más te puedo orientar sobre MOVA?'

    messages.value.push({
      id: Date.now() + 1,
      sender: 'bot',
      text: replyText,
      time: getCurrentTime(),
    })
  } catch (err) {
    console.error('Error al comunicarse con Movi:', err)
    const errorMsg = err.response?.data?.message || 'Tuve una pequeña dificultad para responder en este instante. Por favor reintenta tu pregunta.'
    messages.value.push({
      id: Date.now() + 1,
      sender: 'bot',
      text: errorMsg,
      time: getCurrentTime(),
      isError: true,
    })
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
  const now = new Date()
  return now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <div class="chatbot-wrapper">
    <!-- ── Mascota Ardilla Chatbot SIEMPRE FIJA en la esquina inferior derecha ── -->
    <div
      class="fixed bottom-0 right-2 sm:right-4 md:right-6 lg:right-8 z-40 select-none group cursor-pointer transition-all duration-300"
      :class="isOpen ? 'opacity-0 pointer-events-none scale-90' : 'opacity-100 scale-100'"
      @click="toggleChat"
      role="button"
      tabindex="0"
      :aria-expanded="isOpen"
      aria-label="Abrir chat con Movi"
      @keydown.enter="toggleChat"
      @keydown.space.prevent="toggleChat"
    >
      <!-- Tooltip flotante interactivo en hover -->
      <div class="absolute -top-10 right-6 sm:right-1/2 sm:translate-x-1/2 opacity-0 group-hover:opacity-100 transition-all duration-300 pointer-events-none whitespace-nowrap z-30 transform group-hover:-translate-y-1">
        <div class="px-3 py-1 bg-white/95 dark:bg-slate-800 text-slate-900 dark:text-white text-xs font-bold rounded-full shadow-lg border border-sky-300/60 dark:border-slate-700 flex items-center gap-1.5 backdrop-blur-md">
          <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
          <span>¡Pregúntale a Movi!</span>
        </div>
      </div>

      <!-- Contenedor responsive con transición al pasar el cursor -->
      <div class="relative w-28 sm:w-36 md:w-44 lg:w-48 xl:w-52 transition-transform duration-300 ease-out group-hover:scale-105 active:scale-95">
        <!-- Ardilla 1: Leyendo libro (estado default) -->
        <img
          src="/images/brand/ardillachatbot1.png"
          alt="Movi Chatbot - Asistente MOVA"
          class="w-full h-auto object-contain transition-opacity duration-200 pointer-events-none drop-shadow-[0_10px_25px_rgba(0,0,0,0.35)] group-hover:opacity-0"
          draggable="false"
        />
        <!-- Ardilla 2: Saludando con burbuja (estado hover) -->
        <img
          src="/images/brand/ardillachatbot2.png"
          alt="Movi Chatbot - Asistente MOVA Saludo"
          class="absolute inset-0 w-full h-auto object-contain transition-opacity duration-200 pointer-events-none drop-shadow-[0_14px_30px_rgba(37,99,235,0.4)] opacity-0 group-hover:opacity-100"
          draggable="false"
        />
      </div>
    </div>

    <!-- ── Ventana de Chat Flotante (En la esquina derecha abajo, encima de la ardilla) ── -->
    <Transition
      enter-active-class="transition duration-300 cubic-bezier(0.16, 1, 0.3, 1)"
      enter-from-class="opacity-0 translate-y-6 scale-95 origin-bottom-right"
      enter-to-class="opacity-100 translate-y-0 scale-100 origin-bottom-right"
      leave-active-class="transition duration-200 ease-in"
      leave-from-class="opacity-100 translate-y-0 scale-100 origin-bottom-right"
      leave-to-class="opacity-0 translate-y-4 scale-95 origin-bottom-right"
    >
      <div
        v-if="isOpen"
        class="fixed bottom-3 right-3 sm:bottom-5 sm:right-5 md:bottom-6 md:right-6 z-50 w-[calc(100vw-1.5rem)] sm:w-[400px] md:w-[415px] h-[580px] max-h-[calc(100vh-2rem)] sm:max-h-[calc(100vh-3rem)] flex flex-col bg-white dark:bg-slate-900 rounded-3xl shadow-[0_20px_60px_rgba(2,24,64,0.45)] border-2 border-slate-600 dark:border-slate-600 overflow-hidden font-sans select-text"
        role="dialog"
        aria-label="Ventana de chat con Movi"
        aria-modal="true"
      >
        <!-- ── Encabezado de la ventana de chat ────────────────────────── -->
        <header class="relative px-5 py-3.5 bg-gradient-to-r from-[#021840] via-[#073D91] to-[#0D409A] text-white flex items-center justify-between shadow-md select-none">
          <!-- Ambient subtle glow -->
          <div class="absolute -right-8 -top-8 w-28 h-28 bg-sky-400/20 rounded-full blur-xl pointer-events-none"></div>

          <div class="flex items-center gap-3 relative z-10">
            <!-- Avatar Movi con indicador de estado -->
            <div class="relative w-11 h-11 rounded-full bg-white/10 p-0.5 border border-white/20 shadow-inner flex items-center justify-center flex-shrink-0">
              <img
                src="/images/brand/ardillachatbot2.png"
                alt="Avatar Movi"
                class="w-full h-full object-contain rounded-full"
              />
              <span class="absolute bottom-0 right-0 w-3 h-3 bg-emerald-400 border-2 border-[#073D91] rounded-full shadow-[0_0_8px_#34d399]" title="En línea"></span>
            </div>

            <div>
              <div class="flex items-center gap-2">
                <h2 class="font-extrabold text-base tracking-tight text-white leading-tight">Movi</h2>
                <span class="px-2 py-0.5 text-[10px] font-bold tracking-wider uppercase bg-amber-400/20 text-amber-300 border border-amber-400/40 rounded-full">
                  IA MOVA
                </span>
              </div>
              <p class="text-xs text-sky-200/90 font-medium flex items-center gap-1.5 mt-0.5">
                <span class="inline-block w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                En línea • Asistente educativo
              </p>
            </div>
          </div>

          <!-- Acciones del Header -->
          <div class="flex items-center gap-1 relative z-10">
            <!-- Botón reiniciar conversación -->
            <button
              type="button"
              @click="restartChat"
              class="p-2 text-white/70 hover:text-white hover:bg-white/10 rounded-full transition-colors active:scale-95"
              title="Reiniciar chat"
              aria-label="Reiniciar conversación"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
              </svg>
            </button>

            <!-- Botón cerrar chat -->
            <button
              type="button"
              @click="closeChat"
              class="p-2 text-white/80 hover:text-white hover:bg-white/15 rounded-full transition-colors active:scale-95"
              title="Cerrar chat"
              aria-label="Cerrar chat"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
        </header>

        <!-- ── Contenedor de Mensajes ──────────────────────────────────── -->
        <main
          ref="messagesContainer"
          class="flex-1 overflow-y-auto px-4 py-4 space-y-3.5 bg-slate-50/80 dark:bg-slate-900/60 scroll-smooth"
        >
          <!-- Badge de fecha / aviso del asistente -->
          <div class="flex justify-center select-none my-1">
            <span class="px-3 py-1 bg-slate-200/70 dark:bg-slate-800 text-slate-600 dark:text-slate-400 text-[11px] font-semibold rounded-full border border-slate-300/40 dark:border-slate-700/60 shadow-2xs">
              Asistente Educativo Virtual • MOVA
            </span>
          </div>

          <!-- Render de Mensajes -->
          <div
            v-for="msg in messages"
            :key="msg.id"
            class="flex flex-col"
            :class="msg.sender === 'user' ? 'items-end' : 'items-start'"
          >
            <div class="flex items-end gap-2 max-w-[85%]" :class="msg.sender === 'user' ? 'flex-row-reverse' : 'flex-row'">
              <!-- Avatar pequeño para el bot -->
              <div
                v-if="msg.sender === 'bot'"
                class="w-7 h-7 rounded-full bg-brand-100 dark:bg-slate-800 border border-brand-200 dark:border-slate-700 flex items-center justify-center flex-shrink-0 mb-1"
              >
                <img
                  src="/images/brand/ardillachatbot2.png"
                  alt="Movi"
                  class="w-5 h-5 object-contain"
                />
              </div>

              <!-- Burbuja de mensaje -->
              <div
                class="px-4 py-3 rounded-2xl text-sm leading-relaxed shadow-sm transition-all"
                :class="[
                  msg.sender === 'user'
                    ? 'bg-gradient-to-r from-blue-600 to-[#0D409A] text-white rounded-br-xs shadow-blue-500/10'
                    : (msg.isError
                        ? 'bg-red-50 dark:bg-red-950/40 text-red-800 dark:text-red-200 rounded-bl-xs border border-red-200 dark:border-red-800 shadow-xs'
                        : 'bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 rounded-bl-xs border border-slate-200/70 dark:border-slate-700/60 shadow-slate-200/50')
                ]"
              >
                <div class="whitespace-pre-line break-words" v-html="formatMessageText(msg.text)"></div>
              </div>
            </div>

            <!-- Timestamp y estado -->
            <div
              class="flex items-center gap-1 text-[10px] text-slate-400 dark:text-slate-500 mt-1 px-1"
              :class="msg.sender === 'user' ? 'justify-end pr-1' : 'justify-start pl-9'"
            >
              <span>{{ msg.time }}</span>
              <span v-if="msg.sender === 'user'" class="text-blue-500 font-bold" title="Enviado">✓✓</span>
            </div>

            <!-- Chips de preguntas sugeridas (debajo del mensaje de bienvenida) -->
            <div
              v-if="msg.isWelcome"
              class="mt-3.5 pl-9 pr-2 space-y-2 select-none w-full"
            >
              <p class="text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                Preguntas sugeridas:
              </p>
              <div class="flex flex-wrap gap-1.5">
                <button
                  v-for="(chip, index) in suggestions"
                  :key="index"
                  type="button"
                  @click="sendUserPrompt(chip.prompt)"
                  class="text-xs px-3 py-1.5 rounded-full bg-white dark:bg-slate-800 hover:bg-brand-50 hover:text-brand-600 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 shadow-2xs transition-all hover:scale-[1.02] active:scale-95 text-left font-medium"
                >
                  {{ chip.label }}
                </button>
              </div>
            </div>
          </div>

          <!-- Indicador de que el bot está escribiendo -->
          <div v-if="isTyping" class="flex items-end gap-2 items-start pl-0">
            <div class="w-7 h-7 rounded-full bg-brand-100 dark:bg-slate-800 border border-brand-200 dark:border-slate-700 flex items-center justify-center flex-shrink-0">
              <img src="/images/brand/ardillachatbot2.png" alt="Movi" class="w-5 h-5 object-contain" />
            </div>
            <div class="px-3.5 py-2.5 bg-white dark:bg-slate-800 rounded-2xl rounded-bl-xs border border-slate-200/70 dark:border-slate-700/60 shadow-xs flex items-center gap-1.5">
              <span class="w-2 h-2 rounded-full bg-blue-500 animate-bounce" style="animation-delay: 0ms"></span>
              <span class="w-2 h-2 rounded-full bg-blue-500 animate-bounce" style="animation-delay: 150ms"></span>
              <span class="w-2 h-2 rounded-full bg-blue-500 animate-bounce" style="animation-delay: 300ms"></span>
            </div>
          </div>
        </main>

        <!-- ── Pie de Entrada de Mensaje ────────────────────────────────── -->
        <footer class="p-3 bg-white dark:bg-slate-900 border-t border-slate-200 dark:border-slate-800 select-none">
          <form @submit.prevent="handleSend" class="flex items-center gap-2">
            <!-- Campo de texto -->
            <div class="relative flex-1">
              <input
                v-model="inputMessage"
                type="text"
                placeholder="Escribe tu consulta a Movi..."
                class="w-full pl-3.5 pr-9 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-900 dark:text-slate-100 text-sm rounded-full border border-transparent focus:border-blue-500 focus:bg-white dark:focus:bg-slate-850 focus:outline-none focus:ring-2 focus:ring-blue-500/20 transition-all placeholder:text-slate-400"
              />
              <!-- Ícono sutil de ayuda / clip -->
              <button
                type="button"
                class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors p-1"
                title="Escribe cualquier duda académica o de MOVA"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                </svg>
              </button>
            </div>

            <!-- Botón de enviar -->
            <button
              type="submit"
              :disabled="!inputMessage.trim() || isTyping"
              class="w-10 h-10 rounded-full bg-gradient-to-r from-blue-600 to-[#0D409A] text-white flex items-center justify-center shadow-md hover:shadow-blue-500/30 transition-all disabled:opacity-45 disabled:cursor-not-allowed hover:scale-105 active:scale-95 flex-shrink-0"
              aria-label="Enviar mensaje"
            >
              <svg class="w-4 h-4 -mr-0.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3 21l18-9L3 3l3 9zm0 0h9" />
              </svg>
            </button>
          </form>

          <div class="mt-2 text-center">
            <p class="text-[10px] text-slate-400 dark:text-slate-500">
              Movi puede cometer errores. Considera verificar la información importante.
            </p>
          </div>
        </footer>
      </div>
    </Transition>
  </div>
</template>
