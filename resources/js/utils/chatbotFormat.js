// Formateador seguro para los mensajes de Movi (ChatbotWidget.vue).
//
// El texto del proveedor de IA (y el del propio usuario) es NO confiable: se
// escapa COMPLETO primero (& < > " '), y solo después se generan etiquetas
// conocidas — <strong>/<em> alrededor de texto ya escapado y tres "pills"
// de acción con HTML fijo. Nunca se inserta HTML arbitrario del proveedor,
// así que `<script>`, `<img onerror>` y similares llegan al DOM como texto.
// Regresión: scripts/check-chatbot-escape.mjs y qa/tests/chatbot-xss.spec.js.

const ARROW = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>'

const PILL = 'inline-flex items-center gap-1 px-3 py-1 my-1 rounded-xl text-white font-bold text-xs shadow-xs transition-all hover:scale-105 active:scale-95 no-underline'

const ACTIONS = [
  [/\[Crear cuenta\]/gi, '/register', 'bg-blue-600 hover:bg-blue-700', 'Crear cuenta'],
  [/\[Solicitar Clase\]/gi, '/class-requests/create', 'bg-amber-500 hover:bg-amber-600', 'Solicitar Clase'],
  [/\[Voluntariado\]/gi, '/invitacion/profesor', 'bg-emerald-600 hover:bg-emerald-700', 'Voluntariado'],
]

export function escapeHtml(raw) {
  return String(raw)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;')
}

export function formatMessageText(raw) {
  if (!raw) return ''

  let formatted = escapeHtml(raw)
    .replace(/\*\*(.*?)\*\*/g, '<strong class="font-bold text-slate-900 dark:text-white">$1</strong>')
    .replace(/\*(.*?)\*/g, '<em class="italic">$1</em>')

  for (const [pattern, href, color, label] of ACTIONS) {
    formatted = formatted.replace(pattern, `<a href="${href}" class="${PILL} ${color}">${label} ${ARROW}</a>`)
  }

  return formatted
}
