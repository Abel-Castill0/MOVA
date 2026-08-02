import { ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'

let scriptPromise = null

function loadJitsiScript() {
  if (window.JitsiMeetExternalAPI) return Promise.resolve()
  if (scriptPromise) return scriptPromise

  scriptPromise = new Promise((resolve, reject) => {
    const script = document.createElement('script')
    script.src = 'https://meet.jit.si/external_api.js'
    script.async = true
    script.onload = () => resolve()
    script.onerror = () => { scriptPromise = null; reject(new Error('No se pudo cargar Jitsi.')) }
    document.head.appendChild(script)
  })

  return scriptPromise
}

export function useJitsiMeet() {
  const showingJitsiModal = ref(false)
  const joinError = ref('')
  const activeLesson = ref(null)
  let api = null

  // usePage() debe llamarse en el momento síncrono de setup() — se guarda la
  // referencia reactiva aquí y se lee auth.user.name más tarde, dentro de
  // openJitsi() (async), en vez de volver a llamar usePage() ahí.
  const page = usePage()

  // El modal de Jitsi es a pantalla completa — igual que el Modal genérico,
  // bloqueamos el scroll del body mientras está abierto.
  watch(showingJitsiModal, (show) => {
    document.body.style.overflow = show ? 'hidden' : ''
  })

  // lesson.jitsi_room / lesson.jitsi_password nunca viajan en el listado de
  // clases (Lesson::$hidden) — se piden aquí, justo antes de entrar, contra
  // GET /lessons/{id}/join, que revalida dueño/profesor asignado y estado de
  // la clase en el servidor. Si alguien adivina o comparte la URL de la sala
  // sin pasar por esta verificación, no obtiene las credenciales.
  async function openJitsi(lesson, containerId = 'jitsi-container') {
    joinError.value = ''
    activeLesson.value = lesson
    showingJitsiModal.value = true

    let credentials
    try {
      const response = await window.axios.get(route('lessons.join', lesson.id))
      credentials = response.data
    } catch (error) {
      // El modal se queda abierto mostrando el error (ver joinError en las
      // vistas) — cerrarlo aquí lo haría desaparecer antes de que el usuario
      // pueda leer por qué no pudo entrar.
      joinError.value = error.response?.data?.message || 'No se pudo verificar el acceso a esta clase.'
      return
    }

    await loadJitsiScript()

    const container = document.getElementById(containerId)
    if (!container || !window.JitsiMeetExternalAPI) return

    api = new window.JitsiMeetExternalAPI('meet.jit.si', {
      roomName: credentials.jitsi_room,
      width: '100%',
      height: '100%',
      parentNode: container,
      // Sin displayName, Jitsi puede empujar al primer participante a la
      // pantalla de "Continuar con Google" para autenticarse como moderador
      // en vez de entrar directo — pasarlo evita ese desvío.
      userInfo: { displayName: page.props.auth?.user?.name || 'Invitado MOVA' },
      configOverwrite: {
        prejoinPageEnabled: false,
        disableDeepLinking: true,
      },
    })

    if (credentials.jitsi_password) {
      const lockRoom = () => api?.executeCommand('password', credentials.jitsi_password)
      // Si somos el primer participante (moderador), fijamos la contraseña de la sala.
      api.addEventListener('videoConferenceJoined', lockRoom)
      // Si la sala ya estaba bloqueada por otro participante, la enviamos automáticamente.
      api.addEventListener('passwordRequired', lockRoom)
    }

    // El botón nativo de "colgar" dentro del iframe de Jitsi dispara este
    // evento antes de que nosotros hagamos nada — lo tratamos igual que
    // nuestro botón "Cerrar sala", así el regreso a MOVA es automático sin
    // importar cómo la persona salga de la llamada.
    api.addEventListener('readyToClose', closeJitsi)
  }

  function closeJitsi() {
    const lesson = activeLesson.value
    const hadError = !!joinError.value

    showingJitsiModal.value = false
    joinError.value = ''
    activeLesson.value = null
    if (api) {
      api.dispose()
      api = null
    }

    // Si el cierre viene de un error de acceso, no hubo clase real que
    // "terminó" — no tiene sentido mandar al padre/profesor a confirmar pago
    // o escribir un reporte sobre algo a lo que nunca llegaron a entrar.
    // end_time viaja en la URL para que el dashboard sepa si la clase ya
    // terminó de verdad o si solo salieron de la llamada antes de tiempo
    // (la persona puede colgar minutos antes del horario programado).
    if (lesson && !hadError) {
      router.visit(route('dashboard', { post_class: lesson.id, post_class_ends_at: lesson.end_time }))
    }
  }

  return { showingJitsiModal, joinError, activeLesson, openJitsi, closeJitsi }
}
