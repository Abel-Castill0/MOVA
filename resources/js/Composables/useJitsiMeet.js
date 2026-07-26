import { ref } from 'vue'

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
  let api = null

  // lesson.jitsi_room / lesson.jitsi_password nunca viajan en el listado de
  // clases (Lesson::$hidden) — se piden aquí, justo antes de entrar, contra
  // GET /lessons/{id}/join, que revalida dueño/profesor asignado y estado de
  // la clase en el servidor. Si alguien adivina o comparte la URL de la sala
  // sin pasar por esta verificación, no obtiene las credenciales.
  async function openJitsi(lesson, containerId = 'jitsi-container') {
    joinError.value = ''
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
      configOverwrite: { prejoinPageEnabled: false },
    })

    if (credentials.jitsi_password) {
      const lockRoom = () => api?.executeCommand('password', credentials.jitsi_password)
      // Si somos el primer participante (moderador), fijamos la contraseña de la sala.
      api.addEventListener('videoConferenceJoined', lockRoom)
      // Si la sala ya estaba bloqueada por otro participante, la enviamos automáticamente.
      api.addEventListener('passwordRequired', lockRoom)
    }
  }

  function closeJitsi() {
    showingJitsiModal.value = false
    joinError.value = ''
    if (api) {
      api.dispose()
      api = null
    }
  }

  return { showingJitsiModal, joinError, openJitsi, closeJitsi }
}
